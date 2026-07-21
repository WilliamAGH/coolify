#!/usr/bin/env bash
# SSOT local Docker hygiene for Coolify dev on OrbStack.
# Clean targets only known Coolify lab projects or an explicit ephemeral label.
#
# Usage:
#   scripts/dev/docker-ssot-clean.sh status
#   scripts/dev/docker-ssot-clean.sh clean
#   scripts/dev/docker-ssot-clean.sh ensure-builder
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MULTIARCH_BUILDER="${DOCKER_PUSH_BUILDER:-aventure-runtime-multiarch-proxy}"
LAB_CLEAN_LABEL="coolify.integration.ephemeral=true"

log() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

require_docker() {
  command -v docker >/dev/null 2>&1 || die "docker CLI not found"
  docker info >/dev/null 2>&1 || die "docker engine not reachable"
}

is_lab_compose_project() {
  local project="$1"

  [[ "$project" =~ ^cpbg(-[a-z0-9][a-z0-9_-]*)?$ ]] \
    || [[ "$project" =~ ^production-application-blue-green(-[a-z0-9][a-z0-9_-]*)?$ ]]
}

cmd_status() {
  require_docker
  log "=== Coolify SSOT cleanup targets ==="
  log "repo: ${ROOT}"
  log "lab compose project prefixes: cpbg-*, production-application-blue-green-*"
  log "lab resource label: ${LAB_CLEAN_LABEL}"
  log "builder: ${MULTIARCH_BUILDER}"
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

remove_containers_with_filter() {
  local filter="$1" description="$2" id ids

  ids="$(docker ps -aq --filter "$filter")" \
    || die "could not enumerate containers for ${description}"

  while IFS= read -r id; do
    [ -z "$id" ] && continue
    log "rm container (${description}): ${id}"
    docker rm -f "$id" >/dev/null 2>&1 \
      || die "could not remove container ${id} for ${description}"
  done <<< "$ids"
}

remove_volumes_with_filter() {
  local filter="$1" description="$2" volume volumes

  volumes="$(docker volume ls -q --filter "$filter")" \
    || die "could not enumerate volumes for ${description}"

  while IFS= read -r volume; do
    [ -z "$volume" ] && continue
    log "rm volume (${description}): ${volume}"
    docker volume rm -f "$volume" >/dev/null 2>&1 \
      || die "could not remove volume ${volume} for ${description}"
  done <<< "$volumes"
}

remove_networks_with_filter() {
  local filter="$1" description="$2" network networks

  networks="$(docker network ls -q --filter "$filter")" \
    || die "could not enumerate networks for ${description}"

  while IFS= read -r network; do
    [ -z "$network" ] && continue
    log "rm network (${description}): ${network}"
    docker network rm "$network" >/dev/null 2>&1 \
      || die "could not remove network ${network} for ${description}"
  done <<< "$networks"
}

remove_resources_with_filter() {
  local filter="$1" description="$2"

  remove_containers_with_filter "$filter" "$description"
  remove_volumes_with_filter "$filter" "$description"
  remove_networks_with_filter "$filter" "$description"
}

lab_compose_projects() {
  local container_projects volume_projects network_projects

  container_projects="$(docker ps -a --format '{{.Label "com.docker.compose.project"}}')" \
    || die 'could not enumerate container Compose project labels'
  volume_projects="$(docker volume ls --format '{{.Label "com.docker.compose.project"}}')" \
    || die 'could not enumerate volume Compose project labels'
  network_projects="$(docker network ls --format '{{.Label "com.docker.compose.project"}}')" \
    || die 'could not enumerate network Compose project labels'
  printf '%s\n%s\n%s\n' "$container_projects" "$volume_projects" "$network_projects" | sort -u
}

cmd_clean() {
  require_docker

  local proj projects
  projects="$(lab_compose_projects)" || die 'could not enumerate Compose project labels'

  while IFS= read -r proj; do
    [ -z "$proj" ] && continue
    if is_lab_compose_project "$proj"; then
      log "CLEAN compose project: ${proj}"
      remove_resources_with_filter "label=com.docker.compose.project=${proj}" "compose project ${proj}"
    fi
  done <<< "$projects"

  remove_resources_with_filter "label=${LAB_CLEAN_LABEL}" "explicit lab label"

  log ""
  log "=== after clean ==="
  cmd_status
}

usage() {
  cat <<'EOF'
Usage: scripts/dev/docker-ssot-clean.sh <status|clean|ensure-builder>

  status          Print cleanup targets and live inventory
  ensure-builder  Ensure aventure-runtime-multiarch-proxy (Nexus multiarch)
  clean           Remove only cpbg/production blue-green labs or coolify.integration.ephemeral=true
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
