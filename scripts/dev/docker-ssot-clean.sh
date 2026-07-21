#!/usr/bin/env bash
# SSOT local Docker hygiene for Coolify dev on OrbStack.
# Preserves the Coolify compose control plane; removes lab residue
# (cpbg-*, production-application-*, uuid blue/green deploys, extra buildx builders).
#
# Usage:
#   scripts/dev/docker-ssot-clean.sh status
#   scripts/dev/docker-ssot-clean.sh clean
#   scripts/dev/docker-ssot-clean.sh ensure-builder
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MULTIARCH_BUILDER="${DOCKER_PUSH_BUILDER:-aventure-runtime-multiarch-proxy}"
# Coolify compose project is "coolify" (docker-compose.dev.yml / spin).
# coolify-proxy is a separate long-lived Traefik project on this host.
KEEP_COMPOSE_PROJECTS="coolify coolify-proxy"
KEEP_BUILDERS="orbstack default ${MULTIARCH_BUILDER}"

log() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

require_docker() {
  command -v docker >/dev/null 2>&1 || die "docker CLI not found"
  docker info >/dev/null 2>&1 || die "docker engine not reachable"
}

is_keep_builder() {
  local name="$1" k
  for k in ${KEEP_BUILDERS}; do
    [ "$name" = "$k" ] && return 0
  done
  return 1
}

is_keep_compose_project() {
  local project="$1" k
  [ -z "$project" ] && return 1
  for k in ${KEEP_COMPOSE_PROJECTS}; do
    [ "$project" = "$k" ] && return 0
  done
  return 1
}

is_keep_container() {
  local name="$1" project="$2"
  is_keep_compose_project "$project" && return 0
  case "$name" in
    coolify|coolify-*|"buildx_buildkit_${MULTIARCH_BUILDER}0") return 0 ;;
  esac
  return 1
}

cmd_status() {
  require_docker
  log "=== Coolify SSOT keep-set ==="
  log "repo: ${ROOT}"
  log "compose projects: ${KEEP_COMPOSE_PROJECTS}"
  log "builders: ${KEEP_BUILDERS}"
  log ""
  log "=== running containers ==="
  docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
  log ""
  log "=== builders ==="
  docker buildx ls
  log ""
  log "=== images ==="
  docker images --format 'table {{.Repository}}:{{.Tag}}\t{{.Size}}'
  log ""
  log "volumes: $(docker volume ls -q | wc -l | tr -d ' ')"
  log ""
  docker system df
}

cmd_ensure_builder() {
  require_docker
  if docker buildx inspect --bootstrap "${MULTIARCH_BUILDER}" >/dev/null 2>&1; then
    log "builder ready: ${MULTIARCH_BUILDER}"
    return 0
  fi
  if docker buildx inspect "${MULTIARCH_BUILDER}" >/dev/null 2>&1; then
    docker buildx inspect --bootstrap "${MULTIARCH_BUILDER}"
    log "builder bootstrapped: ${MULTIARCH_BUILDER}"
    return 0
  fi
  log "creating builder: ${MULTIARCH_BUILDER}"
  docker buildx create \
    --name "${MULTIARCH_BUILDER}" \
    --driver docker-container \
    --driver-opt network=host \
    --bootstrap
  log "builder created: ${MULTIARCH_BUILDER}"
}

cmd_clean() {
  require_docker
  cmd_ensure_builder

  local proj
  while IFS= read -r proj; do
    [ -z "$proj" ] && continue
    if is_keep_compose_project "$proj"; then
      log "KEEP compose project: ${proj}"
      continue
    fi
    log "compose down -v: ${proj}"
    docker compose -p "${proj}" down --remove-orphans -v >/dev/null 2>&1 || true
  done < <(docker ps -a --format '{{.Label "com.docker.compose.project"}}' | sort -u)

  local id name project
  while IFS= read -r id; do
    [ -z "$id" ] && continue
    name="$(docker inspect -f '{{.Name}}' "$id" | sed 's#^/##')"
    project="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$id" 2>/dev/null || true)"
    if is_keep_container "$name" "$project"; then
      log "KEEP container: ${name}"
      continue
    fi
    log "rm container: ${name}"
    docker rm -f "$id" >/dev/null 2>&1 || true
  done < <(docker ps -aq)

  local builder
  while IFS= read -r builder; do
    [ -z "$builder" ] && continue
    if is_keep_builder "$builder"; then
      log "KEEP builder: ${builder}"
      continue
    fi
    if docker buildx inspect "$builder" >/dev/null 2>&1; then
      log "buildx rm: ${builder}"
      docker buildx rm -f "$builder" >/dev/null 2>&1 || true
    fi
  done < <(docker buildx ls --format '{{.Name}}' 2>/dev/null | sort -u)

  local keep_vols v
  keep_vols="$(
    docker ps -q | while IFS= read -r cid; do
      docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{println .Name}}{{end}}{{end}}' "$cid"
    done | sort -u
  )"
  while IFS= read -r v; do
    [ -z "$v" ] && continue
    if printf '%s\n' "$keep_vols" | grep -qx "$v"; then
      log "KEEP volume: ${v}"
      continue
    fi
    log "rm volume: ${v}"
    docker volume rm -f "$v" >/dev/null 2>&1 || true
  done < <(docker volume ls -q)

  docker container prune -f >/dev/null
  docker image prune -af >/dev/null
  docker builder prune -af >/dev/null
  docker volume prune -f >/dev/null

  if docker buildx inspect orbstack >/dev/null 2>&1; then
    docker buildx use orbstack >/dev/null 2>&1 || true
  fi

  log ""
  log "=== after clean ==="
  cmd_status
}

usage() {
  cat <<'EOF'
Usage: scripts/dev/docker-ssot-clean.sh <status|clean|ensure-builder>

  status          Print keep-set and live inventory
  ensure-builder  Ensure aventure-runtime-multiarch-proxy (Nexus multiarch)
  clean           Tear down lab stacks/builders/volumes; keep Coolify control plane
EOF
}

main() {
  local cmd="${1:-}"
  case "$cmd" in
    status) cmd_status ;;
    ensure-builder) cmd_ensure_builder ;;
    clean) cmd_clean ;;
    -h | --help | help | "") usage; [ -n "$cmd" ] || exit 2 ;;
    *) die "unknown command: $cmd" ;;
  esac
}

main "$@"
