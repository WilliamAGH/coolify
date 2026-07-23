#!/usr/bin/env bash
# Functional contract tests for scripts/control-plane-migrate.sh (fork issue #153).
# All docker, pg_restore, and hostname interactions are stubbed; no network calls.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
SCRIPT="$ROOT/scripts/control-plane-migrate.sh"
STATE="$(mktemp -d "${TMPDIR:-/tmp}/control-plane-migrate-test.XXXXXX")"
MOCK_BIN="$STATE/bin"
DOCKER_LOG="$STATE/docker.log"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'ok: %s\n' "$1"
}

cleanup() {
  rm -rf "$STATE"
}
trap cleanup EXIT INT TERM

mkdir -p "$MOCK_BIN"
: > "$DOCKER_LOG"

# --- stubs -----------------------------------------------------------------

cat > "$MOCK_BIN/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker:%s\n' "$*" >> "${MOCK_DOCKER_LOG:?}"
cmd="${1:-}"; shift || true
case "$cmd" in
  ps)
    printf '%s\n' ${MOCK_RUNNING_CONTAINERS:-}
    ;;
  exec)
    # docker exec [-i] <container> <args...>
    local_i="${1:-}"
    if [ "$local_i" = "-i" ]; then shift; fi
    container="${1:-}"; shift
    case "${1:-}" in
      pg_dump)
        printf 'PGDMP-fake-custom-format\n'
        ;;
      pg_restore)
        cat >/dev/null
        exit "${MOCK_MIGRATION_PG_RESTORE_EXIT:-0}"
        ;;
      psql)
        last="${*: -1}"
        case "$last" in
          'SHOW server_version_num') printf '%s\n' "${MOCK_PG_VERSION_NUM:-150002}" ;;
          'SELECT 1') printf '1\n' ;;
          'SELECT count(*) FROM '*)
            table="${last#SELECT count(*) FROM }"
            printf '%s\n' "${MOCK_COUNT_users:-3}" >/dev/null
            case "$table" in
              users) printf '%s\n' "${MOCK_COUNT_users:-3}" ;;
              servers) printf '%s\n' "${MOCK_COUNT_servers:-11}" ;;
              applications) printf '%s\n' "${MOCK_COUNT_applications:-42}" ;;
              *) printf '0\n' ;;
            esac
            ;;
          *) exit 0 ;;
        esac
        ;;
      php)
        # php artisan start:migration | php artisan config:show app.key
        if printf '%s' "$*" | grep -q 'config:show'; then
          # Laravel's twoColumnDetail output: blank line, padded value row
          # with trailing spaces, blank line.
          printf '\n  app.key ..................................... %s  \n\n' "${MOCK_EFFECTIVE_APP_KEY:-base64:sourcekey}"
          exit 0
        fi
        exit "${MOCK_MIGRATION_EXIT:-0}"
        ;;
      redis-cli)
        cat >/dev/null
        printf 'Background saving started\n'
        ;;
      *)
        exit 0
        ;;
    esac
    ;;
  run)
    # docker run --rm -i --network none --pull never -v dump:ro postgres:N-alpine pg_restore -l ...
    [ "${MOCK_PG_RESTORE_FAIL:-false}" = true ] && exit 1
    exit 0
    ;;
  inspect)
    # docker inspect --format '{{.Config.Image}}' coolify
    printf '%s\n' "${MOCK_APP_IMAGE_REF:-registry.example.test/williamagh/coolify:4.13.23-fork}"
    ;;
  image)
    # docker image inspect [--format fmt] <ref>
    ref="${*: -1}"
    case "$ref" in
      postgres:*-alpine)
        [ "${MOCK_PG_CLIENT_IMAGE_MISSING:-false}" = true ] && exit 1
        ;;
      *)
        printf '%s\n' "${MOCK_APP_REPO_DIGESTS:-[\"registry.example.test/williamagh/coolify@sha256:expecteddigest\"]}"
        ;;
    esac
    ;;
  stop|start)
    exit 0
    ;;
  cp)
    # docker cp coolify-redis:/data/dump.rdb <dest>
    dest="${*: -1}"
    printf 'RDB-fake\n' > "$dest"
    ;;
  *)
    printf 'unexpected docker arguments: %s\n' "$cmd $*" >&2
    exit 2
    ;;
esac
STUB

cat > "$MOCK_BIN/pg_restore" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[ "${MOCK_PG_RESTORE_FAIL:-false}" = true ] && exit 1
for arg in "$@"; do
  case "$arg" in
    -*) ;;
    *) [ -f "$arg" ] || exit 1 ;;
  esac
done
printf 'fake archive TOC\n'
STUB

chmod +x "$MOCK_BIN/docker" "$MOCK_BIN/pg_restore"

run_migrate() {
  COOLIFY_MIGRATE_TEST_MODE=true \
  COOLIFY_MIGRATE_HOSTNAME="target-host.test" \
  COOLIFY_MIGRATE_PG_RESTORE_BIN="${TEST_PG_RESTORE_BIN:-$MOCK_BIN/pg_restore}" \
  MOCK_DOCKER_LOG="$DOCKER_LOG" \
  PATH="$MOCK_BIN:$PATH" \
  bash "$SCRIPT" "$@"
}

expect_fail() {
  local description="$1"; shift
  if run_migrate "$@" > "$STATE/out.log" 2>&1; then
    cat "$STATE/out.log" >&2
    fail "$description: command unexpectedly succeeded"
  fi
}

expect_output_contains() {
  local needle="$1"
  grep -Fq "$needle" "$STATE/out.log" || {
    cat "$STATE/out.log" >&2
    fail "expected output to contain: $needle"
  }
}

# --- fixtures ---------------------------------------------------------------

write_source_env() {
  local path="$1"
  cat > "$path" <<'EOF'
APP_ID=sourceappid
APP_KEY=base64:sourcekey
APP_PREVIOUS_KEYS=base64:oldkey
APP_NAME=Coolify
APP_URL=https://coolify.iocloudhost.net
DB_PASSWORD=source_db_pass
REDIS_PASSWORD=source_redis_pass
PUSHER_APP_ID=src_pusher_id
PUSHER_APP_KEY=src_pusher_key
PUSHER_APP_SECRET=src_pusher_secret
PUSHER_HOST=coolify.iocloudhost.net
PUSHER_PORT=6001
ROOT_USERNAME=admin
REGISTRY_URL=registry.iocloudhost.net
DOCKER_ADDRESS_POOL_BASE=10.0.0.0/8
DOCKER_ADDRESS_POOL_SIZE=24
AUTOUPDATE=true
SOKETI_PORT=6001
UNCLASSIFIED_KEY=carryme
EOF
}

write_target_env() {
  local path="$1"
  cat > "$path" <<'EOF'
APP_ID=targetappid
APP_KEY=base64:targetkey
APP_URL=https://22.haiku.host
DB_PASSWORD=target_db_pass
REDIS_PASSWORD=target_redis_pass
COOLIFY_FORK_VERSION=4.13.23-fork
VERSIONS_URL=http://127.0.0.1:9/fork-deploy-disabled/versions.json
AUTOUPDATE=false
APP_PORT=8000
PUSHER_PORT=6001
PUSHER_BACKEND_HOST=127.0.0.1
PUSHER_BACKEND_PORT=6001
TERMINAL_PORT=6002
EOF
}

# Builds a fake control-plane root with the given top-level trees.
build_root() {
  local root="$1"; shift
  mkdir -p "$root/source"
  for tree in "$@"; do
    mkdir -p "$root/$tree"
    printf 'payload-%s\n' "$tree" > "$root/$tree/payload.txt"
  done
}

# --- env-merge tests ---------------------------------------------------------

test_env_merge_provenance() {
  local work="$STATE/merge1"
  mkdir -p "$work"
  write_source_env "$work/source.env"
  write_target_env "$work/target.env"

  run_migrate env-merge --source-env "$work/source.env" --target-env "$work/target.env" --output "$work/merged.env" \
    || fail "env-merge exited non-zero"

  grep -qx 'APP_KEY=base64:sourcekey' "$work/merged.env" || fail "source APP_KEY must win"
  grep -qx 'APP_PREVIOUS_KEYS=base64:oldkey' "$work/merged.env" || fail "APP_PREVIOUS_KEYS must be preserved"
  grep -qx 'DB_PASSWORD=target_db_pass' "$work/merged.env" || fail "target DB_PASSWORD must win"
  grep -qx 'REDIS_PASSWORD=target_redis_pass' "$work/merged.env" || fail "target REDIS_PASSWORD must win"
  grep -qx 'COOLIFY_FORK_VERSION=4.13.23-fork' "$work/merged.env" || fail "target fork version must be retained"
  grep -qx 'PUSHER_HOST=coolify.iocloudhost.net' "$work/merged.env" || fail "source PUSHER_HOST must be preserved"
  grep -qx 'REGISTRY_URL=registry.iocloudhost.net' "$work/merged.env" || fail "source REGISTRY_URL must be preserved"
  grep -qx 'DOCKER_ADDRESS_POOL_BASE=10.0.0.0/8' "$work/merged.env" || fail "source address pool must be preserved"
  grep -qx 'PUSHER_BACKEND_HOST=127.0.0.1' "$work/merged.env" || fail "target managed runtime must be retained"
  grep -qx 'AUTOUPDATE=false' "$work/merged.env" || fail "target-retained AUTOUPDATE must never cross over from source"
  if grep -q '^SOKETI_PORT=' "$work/merged.env"; then
    fail "source-only target-retained SOKETI_PORT must be dropped"
  fi
  grep -qx 'UNCLASSIFIED_KEY=carryme' "$work/merged.env" || fail "unclassified source keys carry over with review flag"
  grep -qx 'APP_ID=sourceappid' "$work/merged.env" || fail "source APP_ID must be preserved"

  local report="$work/merged.env.provenance"
  grep -qx 'DB_PASSWORD=target(retained)' "$report" || fail "provenance must record target retention"
  grep -qx 'APP_KEY=source(preserved)' "$report" || fail "provenance must record source preservation"
  grep -qx 'AUTOUPDATE=target(retained)' "$report" || fail "provenance must record overridden target-retained key"
  grep -qx 'SOKETI_PORT=target(retained-absent)' "$report" || fail "provenance must record dropped source-only target-retained key"
  grep -qx 'UNCLASSIFIED_KEY=source(unclassified-review)' "$report" || fail "provenance must flag unclassified keys"

  [ "$(stat -c '%a' "$work/merged.env" 2>/dev/null || stat -f '%Lp' "$work/merged.env")" = "600" ] \
    || fail "merged .env must be mode 0600"
  pass 'env_merge_provenance'
}

test_env_merge_requires_source_app_key() {
  local work="$STATE/merge2"
  mkdir -p "$work"
  grep -v '^APP_KEY=' "$STATE/merge1/source.env" > "$work/source.env" 2>/dev/null || {
    write_source_env "$work/source.env.full"
    grep -v '^APP_KEY=' "$work/source.env.full" > "$work/source.env"
  }
  write_target_env "$work/target.env"
  expect_fail "env-merge without source APP_KEY" \
    env-merge --source-env "$work/source.env" --target-env "$work/target.env" --output "$work/merged.env"
  expect_output_contains 'missing required key APP_KEY'
  pass 'env_merge_requires_source_app_key'
}

# --- capture tests -----------------------------------------------------------

test_capture_requires_quiescence() {
  local root="$STATE/cap1/root"
  build_root "$root" ssh applications databases services backups source proxy
  write_source_env "$root/source/.env"
  expect_fail "capture without quiescence mode" \
    capture --root "$root" --output "$STATE/cap1/out"
  expect_output_contains 'fail-closed'
  pass 'capture_requires_quiescence'
}

test_capture_census_fail_closed() {
  local root="$STATE/cap2/root"
  build_root "$root" ssh applications databases services backups source proxy mystery
  write_source_env "$root/source/.env"
  expect_fail "capture with undecided tree" \
    capture --root "$root" --output "$STATE/cap2/out" --attest-quiesced "test"
  expect_output_contains "undecided top-level directory 'mystery'"

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate capture --root "$root" --output "$STATE/cap2/out" \
    --attest-quiesced "test" --exclude mystery > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture with --exclude mystery failed"; }
  pass 'capture_census_fail_closed'
}

test_capture_require_source_stopped() {
  local root="$STATE/cap3/root"
  build_root "$root" ssh source
  write_source_env "$root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate \
    capture --root "$root" --output "$STATE/cap3/out" --require-source-stopped > "$STATE/out.log" 2>&1 \
    && fail "capture must refuse while the app container is running"
  expect_output_contains "still running"

  MOCK_RUNNING_CONTAINERS="coolify-db" run_migrate \
    capture --root "$root" --output "$STATE/cap3/out" --require-source-stopped > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture with stopped source failed"; }
  pass 'capture_require_source_stopped'
}

test_capture_refuses_missing_app_key() {
  local root="$STATE/cap4/root"
  build_root "$root" ssh source
  write_source_env "$root/source/.env.full"
  grep -v '^APP_KEY=' "$root/source/.env.full" > "$root/source/.env"
  expect_fail "capture without APP_KEY" \
    capture --root "$root" --output "$STATE/cap4/out" --attest-quiesced "test"
  expect_output_contains 'APP_KEY'
  pass 'capture_refuses_missing_app_key'
}

# Performs a full capture into $STATE/archive; used by verify/restore tests.
perform_full_capture() {
  local root="$STATE/full/root"
  build_root "$root" ssh applications databases services backups source proxy
  write_source_env "$root/source/.env"
  printf 'target-compose-sentinel\n' > "$root/source/docker-compose.yml"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" run_migrate \
    capture --root "$root" --output "$STATE/archive" --attest-quiesced "rehearsal snapshot" \
    > "$STATE/out.log" 2>&1 || { cat "$STATE/out.log" >&2; fail "full capture failed"; }
}

test_capture_produces_verifiable_archive() {
  perform_full_capture
  local archive="$STATE/archive"
  for artifact in manifest.env manifest.json SHA256SUMS env.source postgres.dump inventory.json \
      tree-ssh.tar.gz tree-applications.tar.gz tree-databases.tar.gz tree-services.tar.gz \
      tree-backups.tar.gz tree-source.tar.gz tree-proxy.tar.gz; do
    [ -f "$archive/$artifact" ] || fail "archive is missing $artifact"
  done
  grep -qx 'MANIFEST_SCHEMA=coolify-control-plane-migration/1' "$archive/manifest.env" \
    || fail "manifest schema marker missing"
  grep -qx 'SOURCE_PG_MAJOR=15' "$archive/manifest.env" || fail "manifest must record source PG major"
  grep -q '"restore": "never"' "$archive/manifest.json" || fail "source tree must be restore-never in manifest"
  grep -q '"restore": "opt-in"' "$archive/manifest.json" || fail "proxy tree must be restore-opt-in in manifest"
  grep -q '"users": 3' "$archive/inventory.json" || fail "inventory must record stubbed user count"
  grep -q '"servers": 11' "$archive/inventory.json" || fail "inventory must record stubbed server count"
  grep -q '"applications": 42' "$archive/inventory.json" || fail "inventory must record stubbed application count"
  if grep -Fq 'source_db_pass' "$archive/manifest.env" "$archive/manifest.json"; then
    fail "manifest must never contain secrets"
  fi
  if grep -Eq 'docker:.*exec.*(-e |--env )' "$DOCKER_LOG"; then
    fail "credentials must never travel via docker exec environment flags"
  fi
  if grep -Eq 'docker:exec.*-a |docker:exec.*--password' "$DOCKER_LOG"; then
    fail "credentials must never travel via process arguments"
  fi

  run_migrate verify --archive "$archive" > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "verify of fresh archive failed"; }
  expect_output_contains 'archive verified'
  pass 'capture_produces_verifiable_archive'
}

test_capture_redis_opt_in_uses_stdin_auth() {
  local root="$STATE/capredis/root"
  build_root "$root" source
  write_source_env "$root/source/.env"
  MOCK_RUNNING_CONTAINERS="coolify-db coolify-redis" run_migrate \
    capture --root "$root" --output "$STATE/capredis/out" --attest-quiesced "test" --capture-redis \
    > "$STATE/out.log" 2>&1 || { cat "$STATE/out.log" >&2; fail "redis capture failed"; }
  [ -f "$STATE/capredis/out/redis-dump.rdb" ] || fail "redis dump missing"
  grep -qx 'REDIS_CAPTURED=true' "$STATE/capredis/out/manifest.env" || fail "manifest must record redis capture"
  if grep -Eq 'docker:exec.*-a |docker:exec.*AUTH' "$DOCKER_LOG"; then
    fail "redis password must never appear in docker exec arguments"
  fi
  pass 'capture_redis_opt_in_uses_stdin_auth'
}

# --- verify tests ------------------------------------------------------------

test_verify_detects_tampering() {
  cp -R "$STATE/archive" "$STATE/tampered"
  printf 'tampered\n' >> "$STATE/tampered/tree-ssh.tar.gz"
  expect_fail "verify of tampered archive" verify --archive "$STATE/tampered"
  expect_output_contains 'checksum'
  pass 'verify_detects_tampering'
}

test_verify_detects_unreadable_dump() {
  cp -R "$STATE/archive" "$STATE/baddump"
  printf 'not-a-dump\n' > "$STATE/baddump/postgres.dump"
  # Refresh checksums so only dump readability can fail.
  (cd "$STATE/baddump" && for artifact in ./*; do [ "$artifact" = "./SHA256SUMS" ] || sha256sum "$artifact"; done > SHA256SUMS)
  MOCK_PG_RESTORE_FAIL=true expect_fail "verify of unreadable dump" verify --archive "$STATE/baddump"
  expect_output_contains 'not readable'
  pass 'verify_detects_unreadable_dump'
}

test_verify_reports_missing_pg_client_image() {
  TEST_PG_RESTORE_BIN="$STATE/definitely-missing-pg-restore" MOCK_PG_CLIENT_IMAGE_MISSING=true \
    expect_fail "verify without local pg_restore or client image" verify --archive "$STATE/archive"
  expect_output_contains 'is not present locally'
  if grep -Fq 'not readable' "$STATE/out.log"; then
    cat "$STATE/out.log" >&2
    fail "missing client image must not be misreported as an unreadable dump"
  fi
  pass 'verify_reports_missing_pg_client_image'
}

# --- restore tests -----------------------------------------------------------

build_target_root() {
  local root="$1"
  build_root "$root" ssh applications databases services backups source proxy
  mkdir -p "$root/fork-deploy"
  printf '4.13.23-fork\n' > "$root/fork-deploy/current"
  write_target_env "$root/source/.env"
  printf 'target-docker-compose-sentinel\n' > "$root/source/docker-compose.yml"
  for tree in ssh applications databases services backups proxy; do
    printf 'OLD-%s\n' "$tree" > "$root/$tree/payload.txt"
  done
}

restore_args() {
  printf '%s\n' \
    --archive "$STATE/archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
}

test_restore_refuses_wrong_hostname_authorization() {
  local root="$STATE/res1/root"
  build_target_root "$root"
  expect_fail "restore with wrong authorization hostname" \
    restore --root "$root" --archive "$STATE/archive" \
    --authorize-overwrite "some-other-host" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains 'overwrite authorization mismatch'
  pass 'restore_refuses_wrong_hostname_authorization'
}

test_restore_refuses_unmanaged_target() {
  local root="$STATE/res2/root"
  build_target_root "$root"
  rm -f "$root/fork-deploy/current"
  # shellcheck disable=SC2046
  expect_fail "restore on unmanaged target" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'fork-deploy current-release marker'
  pass 'restore_refuses_unmanaged_target'
}

test_restore_refuses_pg_major_downgrade() {
  local root="$STATE/res3/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" MOCK_PG_VERSION_NUM=140002     expect_fail "restore with older target PostgreSQL" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'PostgreSQL major downgrade refused'
  pass 'restore_refuses_pg_major_downgrade'
}

test_restore_refuses_digest_mismatch() {
  local root="$STATE/res4/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    MOCK_APP_REPO_DIGESTS='["registry.example.test/williamagh/coolify@sha256:otherdigest"]' \
    expect_fail "restore with wrong image digest" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'release proof failed'
  pass 'restore_refuses_digest_mismatch'
}

test_restore_refuses_version_mismatch() {
  local root="$STATE/res5/root"
  build_target_root "$root"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore with wrong fork version" \
    restore --root "$root" --archive "$STATE/archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "9.9.9-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains 'target fork version mismatch'
  pass 'restore_refuses_version_mismatch'
}

test_restore_happy_path_disables_workers_by_default() {
  local root="$STATE/res6/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" run_migrate \
    restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "happy-path restore failed"; }

  grep -qx 'payload-ssh' "$root/ssh/payload.txt" || fail "ssh tree must be restored from the archive"
  grep -qx 'payload-applications' "$root/applications/payload.txt" || fail "applications tree must be restored"
  ls -d "$root"/.pre-migration-*/ssh > /dev/null 2>&1 || fail "pre-migration trees must be set aside"
  grep -qx 'OLD-ssh' "$root"/.pre-migration-*/ssh/payload.txt || fail "set-aside tree must keep old payload"
  grep -qx 'target-docker-compose-sentinel' "$root/source/docker-compose.yml" \
    || fail "source tree must never be restored over the managed target"
  grep -qx '4.13.23-fork' "$root/fork-deploy/current" || fail "fork-deploy state must be untouched"
  ls -d "$root"/control-plane-migrate-target-backup-* > /dev/null 2>&1 || fail "target backup must exist"
  grep -qx 'OLD-ssh' "$root"/control-plane-migrate-target-backup-*/ssh/payload.txt 2>/dev/null \
    || tar -tzf "$root"/control-plane-migrate-target-backup-*/tree-ssh.tar.gz > /dev/null \
    || fail "target backup must contain the pre-restore trees"

  grep -qx 'APP_KEY=base64:sourcekey' "$root/source/.env" || fail "restored .env must carry source APP_KEY"
  grep -qx 'DB_PASSWORD=target_db_pass' "$root/source/.env" || fail "restored .env must keep target DB_PASSWORD"
  grep -qx 'COOLIFY_FORK_VERSION=4.13.23-fork' "$root/source/.env" || fail "restored .env must keep fork version"
  grep -qx 'HORIZON_ENABLED=false' "$root/source/.env" || fail "Horizon must be disabled by default"
  grep -qx 'SCHEDULER_ENABLED=false' "$root/source/.env" || fail "scheduler must be disabled by default"
  [ -f "$root/source/.env.provenance" ] || fail "env-merge provenance report must be written"

  grep -Fq 'docker:stop coolify' "$DOCKER_LOG" || fail "restore must stop the app container"
  grep -Fq 'docker:start coolify' "$DOCKER_LOG" || fail "restore must start the app container"
  grep -Fq 'docker:exec coolify php artisan start:migration' "$DOCKER_LOG" \
    || fail "restore must prove database migrations"
  [ -f "$root/source/.inventory-post-restore.json" ] || fail "post-restore inventory must be written"
  expect_output_contains 'restore complete'
  pass 'restore_happy_path_disables_workers_by_default'
}

test_restore_backs_up_target_database_before_overwrite() {
  local root="$STATE/res9/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" run_migrate \
    restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore for target-db backup test failed"; }
  local backup_dump
  backup_dump=$(ls "$root"/control-plane-migrate-target-backup-*/postgres.pre-restore.dump 2>/dev/null | head -n 1 || true)
  [ -n "$backup_dump" ] || fail "target backup must contain a pre-restore database dump"
  grep -qx 'PGDMP-fake-custom-format' "$backup_dump" || fail "pre-restore dump must come from pg_dump"
  local dump_line drop_line
  dump_line=$(grep -n 'pg_dump' "$DOCKER_LOG" | head -n 1 | cut -d: -f1 || true)
  drop_line=$(grep -n 'DROP DATABASE' "$DOCKER_LOG" | head -n 1 | cut -d: -f1 || true)
  [ -n "$dump_line" ] || fail "restore must pg_dump the target database before overwrite"
  [ -n "$drop_line" ] || fail "mock log must record the database drop"
  [ "$dump_line" -lt "$drop_line" ] || fail "target database dump must happen before the database is dropped"
  pass 'restore_backs_up_target_database_before_overwrite'
}

test_restore_fails_closed_on_stale_effective_app_key() {
  local root="$STATE/res10/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" MOCK_EFFECTIVE_APP_KEY="base64:stale-creation-key" \
    expect_fail "restore with stale effective APP_KEY" restore --root "$root" $(restore_args)
  expect_output_contains 'effective APP_KEY mismatch'
  expect_output_contains 'force-recreate'
  pass 'restore_fails_closed_on_stale_effective_app_key'
}

test_restore_enable_workers_is_explicit() {
  local root="$STATE/res7/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate \
    restore --root "$root" $(restore_args) --enable-workers > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore --enable-workers failed"; }
  if grep -qx 'HORIZON_ENABLED=false' "$root/source/.env"; then
    fail "--enable-workers must not disable Horizon"
  fi
  expect_output_contains 'WARNING: --enable-workers given'
  pass 'restore_enable_workers_is_explicit'
}

test_restore_migration_failure_fails_closed() {
  local root="$STATE/res8/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_MIGRATION_EXIT=1 MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    COOLIFY_MIGRATE_MIGRATION_ATTEMPTS=2 COOLIFY_MIGRATE_MIGRATION_RETRY_SECONDS=0 \
    expect_fail "restore with failing migrations" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'database migrations failed'
  pass 'restore_migration_failure_fails_closed'
}

# --- runner ------------------------------------------------------------------

test_env_merge_provenance
test_env_merge_requires_source_app_key
test_capture_requires_quiescence
test_capture_census_fail_closed
test_capture_require_source_stopped
test_capture_refuses_missing_app_key
test_capture_produces_verifiable_archive
test_capture_redis_opt_in_uses_stdin_auth
test_verify_detects_tampering
test_verify_detects_unreadable_dump
test_verify_reports_missing_pg_client_image
test_restore_refuses_wrong_hostname_authorization
test_restore_refuses_unmanaged_target
test_restore_refuses_pg_major_downgrade
test_restore_refuses_digest_mismatch
test_restore_refuses_version_mismatch
test_restore_happy_path_disables_workers_by_default
test_restore_backs_up_target_database_before_overwrite
test_restore_fails_closed_on_stale_effective_app_key
test_restore_enable_workers_is_explicit
test_restore_migration_failure_fails_closed

printf 'all control-plane-migrate integration tests passed\n'
