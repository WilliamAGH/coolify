#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

TEST_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$TEST_DIR/../../.." && pwd)
SUBJECT=$REPO_ROOT/scripts/fork-deploy
PASS=0
FAIL=0
DIGEST_A=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
DIGEST_B=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
DIGEST_C=sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
DIGEST_D=sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
REVISION=29891220cab933e9d35af952a3d905db7aa8f808
REAL_DOCKER=$(command -v docker || true)
FIXTURE=

pass() {
    printf 'ok - %s\n' "$1"
    PASS=$((PASS + 1))
}

fail() {
    printf 'not ok - %s\n' "$1"
    FAIL=$((FAIL + 1))
}

hash_file() {
    sha256sum "$1" | awk '{print $1}'
}

file_mode() {
    local path=$1 mode

    if mode=$(stat -c '%a' "$path" 2>/dev/null); then
        :
    else
        mode=$(stat -f '%Lp' "$path" 2>/dev/null) || return 1
    fi
    printf '%s\n' "$mode"
}

manifest_key_id() {
    "$FORK_DEPLOY_REAL_OPENSSL" pkey \
        -pubin \
        -in "$FORK_DEPLOY_MANIFEST_PUBLIC_KEY" \
        -pubout \
        -outform DER \
        | sha256sum \
        | awk '{print "sha256:" $1}'
}

new_fixture() {
    local base

    unset BASH_ENV
    base=$(cd -P "${TMPDIR:-/tmp}" && pwd -P)
    FIXTURE=$(mktemp -d "$base/fork-deploy.XXXXXX")
    ROOT=$FIXTURE/data/coolify
    BIN=$FIXTURE/bin
    LOG=$FIXTURE/commands.log
    ASSETS=$FIXTURE/assets
    MANIFEST_FILE=$FIXTURE/release.manifest
    mkdir -p "$BIN" "$ASSETS" "$FIXTURE/trust"
    : >"$LOG"
    for command in docker curl uname openssl sleep ssh-keygen chown gpg; do
        ln -s "$TEST_DIR/fixtures/command" "$BIN/$command"
    done
    cp "$TEST_DIR/fixtures/command" "$FIXTURE/control-plane-blue-green"
    chmod 0700 "$FIXTURE/control-plane-blue-green"
    export FORK_DEPLOY_TEST_LOG=$LOG
    export FORK_DEPLOY_REAL_OPENSSL
    FORK_DEPLOY_REAL_OPENSSL=$(command -v openssl)
    "$FORK_DEPLOY_REAL_OPENSSL" genpkey -algorithm ED25519 -out "$FIXTURE/trust/private.pem"
    "$FORK_DEPLOY_REAL_OPENSSL" pkey -in "$FIXTURE/trust/private.pem" -pubout -out "$FIXTURE/trust/release-signing-ed25519.pub"
    export PATH="$BIN:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
    export FORK_DEPLOY_TEST_MODE=true
    export COOLIFY_ROOT=$ROOT COOLIFY_ALLOW_NON_ROOT=true COOLIFY_HEALTH_ATTEMPTS=1
    export COOLIFY_AUTHORIZED_KEYS=$FIXTURE/root/.ssh/authorized_keys
    export FORK_DEPLOY_MANIFEST_PUBLIC_KEY=$FIXTURE/trust/release-signing-ed25519.pub
    export FORK_DEPLOY_RUNTIME_MARKER=$FIXTURE/runtime-active
    export FORK_DEPLOY_CANDIDATE_STARTED_MARKER=$FIXTURE/candidate-started
    export FORK_DEPLOY_DB_MARKER=$FIXTURE/database-active
    export FORK_DEPLOY_REDIS_MARKER=$FIXTURE/redis-active
    export FORK_DEPLOY_QUIESCE_MARKER=$FIXTURE/quiesce-active
    export FORK_DEPLOY_QUIESCE_OPERATOR=$FIXTURE/control-plane-blue-green
    export FORK_DEPLOY_ACTIVE_SOURCE=$ROOT/source
    export GNUPGHOME=$FIXTURE/recovery-gnupg
    export CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT=0123456789ABCDEF0123456789ABCDEF01234567
    mkdir -p "$GNUPGHOME"
    chmod 0700 "$GNUPGHOME"
    unset FORK_DEPLOY_MIGRATION_FINGERPRINT FORK_DEPLOY_HOST_IP FORK_DEPLOY_EXTRA_BINDING \
        FORK_DEPLOY_FAIL_COMPOSE_UP FORK_DEPLOY_OPENSSL_VERIFY_FAIL \
        FORK_DEPLOY_USE_REAL_OPENSSL FORK_DEPLOY_ENV_EXTRA FORK_DEPLOY_CONTRACT_EXTRA \
        FORK_DEPLOY_COMPOSE_VERSION FORK_DEPLOY_FAIL_PG_DUMP \
        FORK_DEPLOY_FAIL_PG_RESTORE_CHECK FORK_DEPLOY_FAIL_REDIS_SAVE \
        FORK_DEPLOY_FAIL_REDIS_COPY FORK_DEPLOY_FAIL_REDIS_CHECK \
        FORK_DEPLOY_FAIL_QUIESCE_RELEASE FORK_DEPLOY_LEGACY_VOLUMES \
        FORK_DEPLOY_LEGACY_VOLUME_ATTACHMENTS FORK_DEPLOY_DOCKER_UNAVAILABLE \
        FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY || true
    unset FORK_DEPLOY_FAIL_ACTIVATED_CONFIG FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG \
        FORK_DEPLOY_TEST_KILL_AFTER_RESTORE_PREVIOUS_CURRENT || true
}

cleanup_fixture() {
    if [[ -n ${FIXTURE:-} ]]; then
        rm -rf -- "$FIXTURE"
        FIXTURE=
    fi
}

trap cleanup_fixture EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

write_assets() {
    printf 'services: {}\n' >"$ASSETS/docker-compose.yml"
    printf 'services: {}\n' >"$ASSETS/docker-compose.prod.yml"
    printf 'DB_USERNAME=coolify\nDB_DATABASE=coolify\n%s' "${FORK_DEPLOY_ENV_EXTRA:-}" >"$ASSETS/.env.production"
    printf 'release-assets-contract\n' >"$ASSETS/release-assets.contract"
    {
        printf 'services:\n'
        printf '  coolify:\n    image: "%s"\n    ports: !override\n' "docker.iocloudhost.net/williamagh/coolify@$DIGEST_A"
        printf '%s\n' "      - \"127.0.0.1:\${APP_PORT:-8000}:8080\""
        printf '    environment:\n      AUTOUPDATE: "false"\n'
        printf '  soketi:\n    image: "%s"\n    ports: !override\n' "docker.iocloudhost.net/williamagh/coolify-realtime@$DIGEST_B"
        printf '%s\n' "      - \"127.0.0.1:\${SOKETI_PORT:-6001}:6001\""
        printf '      - "127.0.0.1:6002:6002"\n'
        printf '  postgres:\n    image: "%s"\n' "docker.io/library/postgres@$DIGEST_C"
        printf '  redis:\n    image: "%s"\n' "docker.io/library/redis@$DIGEST_D"
    } >"$ASSETS/docker-compose.custom.yml"
}

write_manifest() {
    local version=$1

    write_assets
    {
        printf 'SCHEMA=coolify-fork-release/v1\n'
        printf 'KEY_ID=%s\n' "$(manifest_key_id)"
        printf 'VERSION=%s\n' "$version"
        printf 'SOURCE_REVISION=%s\n' "$REVISION"
        printf 'SOURCE_TAG=%s\n' "$version"
        printf 'PLATFORM=linux/amd64\n'
        printf 'MAIN_IMAGE=docker.iocloudhost.net/williamagh/coolify\n'
        printf 'MAIN_INDEX_DIGEST=%s\n' "$DIGEST_A"
        printf 'MAIN_PLATFORM_DIGEST=%s\n' "$DIGEST_A"
        printf 'REALTIME_IMAGE=docker.iocloudhost.net/williamagh/coolify-realtime\n'
        printf 'REALTIME_INDEX_DIGEST=%s\n' "$DIGEST_B"
        printf 'REALTIME_PLATFORM_DIGEST=%s\n' "$DIGEST_B"
        printf 'POSTGRES_IMAGE=docker.io/library/postgres\n'
        printf 'POSTGRES_DIGEST=%s\n' "$DIGEST_C"
        printf 'POSTGRES_MAJOR=16\n'
        printf 'REDIS_IMAGE=docker.io/library/redis\n'
        printf 'REDIS_DIGEST=%s\n' "$DIGEST_D"
        printf 'REDIS_MAJOR=7\n'
        printf 'MAIN_OCI_LABELS_SHA256=%064d\n' 1
        printf 'REALTIME_OCI_LABELS_SHA256=%064d\n' 2
        printf 'MAIN_SBOM_SHA256=%064d\n' 3
        printf 'REALTIME_SBOM_SHA256=%064d\n' 4
        printf 'MAIN_PROVENANCE_SHA256=%064d\n' 5
        printf 'REALTIME_PROVENANCE_SHA256=%064d\n' 6
        printf 'DOCKERFILE_MAIN_SHA256=%064d\n' 7
        printf 'DOCKERFILE_REALTIME_SHA256=%064d\n' 8
        printf 'COMPOSE_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.yml")"
        printf 'COMPOSE_PROD_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.prod.yml")"
        printf 'COMPOSE_OVERLAY_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.custom.yml")"
        printf 'ENV_PRODUCTION_SHA256=%s\n' "$(hash_file "$ASSETS/.env.production")"
        printf 'RELEASE_ASSET_CONTRACT_SHA256=%s\n' "$(hash_file "$ASSETS/release-assets.contract")"
    } >"$MANIFEST_FILE"
    : >"$MANIFEST_FILE.sig"
}

install_release() {
    "$SUBJECT" install --offline-manifest "$MANIFEST_FILE"
}

update_release() {
    "$SUBJECT" update --offline-manifest "$MANIFEST_FILE"
}

replace_key_value() {
    local file=$1 key=$2 value=$3 temporary

    temporary=$file.rewrite.$$

    awk -F= -v key="$key" -v value="$value" '
        $1 == key { print key "=" value; replaced = 1; next }
        { print }
        END { exit !replaced }
    ' "$file" >"$temporary"
    chmod 0600 "$temporary"
    mv -f "$temporary" "$file"
}

prepare_post_start_failure() {
    write_manifest 4.13.0-fork.1
    install_release >/dev/null || return 1
    write_manifest 4.13.0-fork.2
    rm -f "$FORK_DEPLOY_CANDIDATE_STARTED_MARKER"
    export FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY=true
    : >"$LOG"
    if FORWARD_FAILURE_OUTPUT=$(update_release 2>&1); then
        unset FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY
        return 1
    fi
    unset FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY
    [[ -e $FORK_DEPLOY_CANDIDATE_STARTED_MARKER ]]
}

candidate_history_count() {
    local version=${1:-4.13.0-fork.2}

    awk -F '\t' -v version="$version" '$3 == version { count++ } END { print count + 0 }' \
        "$ROOT/fork-deploy/history.tsv"
}

forward_parser_rejects() {
    local expected=$1 output
    shift

    if output=$("$SUBJECT" recover-forward "$@" 2>&1); then
        return 1
    fi
    [[ $output == *"$expected"* ]]
}

test_rejects_untrusted_caller_inputs() {
    new_fixture
    local output
    if output=$("$SUBJECT" install --version 4.13.0-fork.1 --main-digest "$DIGEST_A" 2>&1); then
        fail 'caller supplied version and digest inputs are rejected'
    elif [[ $output == *'not accepted'* || $output == *'deploy version must come'* ]]; then
        pass 'caller supplied version and digest inputs are rejected'
    else
        fail 'caller supplied version and digest inputs are rejected'
    fi
    cleanup_fixture
}

test_help_describes_quiesce_recovery() {
    new_fixture
    local output
    if output=$("$SUBJECT" --help 2>&1) \
        && [[ $output == *'recover-quiesce'* \
            && $output == *'writer fence is unresolved'* ]]; then
        pass 'help describes the durable backup-quiesce recovery action'
    else
        fail 'help describes the durable backup-quiesce recovery action'
    fi
    cleanup_fixture
}

test_rejects_production_alternate_root() {
    new_fixture
    local output
    if output=$(env -u FORK_DEPLOY_TEST_MODE -u FORK_DEPLOY_MANIFEST_PUBLIC_KEY COOLIFY_ROOT="$ROOT" "$SUBJECT" status 2>&1); then
        fail 'production accepts only canonical root'
    elif [[ $output == *'canonicalize exactly'* ]]; then
        pass 'production accepts only canonical root'
    else
        fail 'production accepts only canonical root'
    fi
    cleanup_fixture
}

test_rejects_symlinked_root_and_authorized_key_ancestors() {
    new_fixture
    mkdir -p "$FIXTURE/real-root" "$FIXTURE/keys/real"
    ln -s "$FIXTURE/real-root" "$FIXTURE/symlink-root"
    local output
    if output=$(COOLIFY_ROOT=$FIXTURE/symlink-root COOLIFY_AUTHORIZED_KEYS=$FIXTURE/keys/real/authorized_keys "$SUBJECT" status 2>&1); then
        fail 'all root ancestors reject symlinks'
    elif [[ $output != *'symbolic link'* ]]; then
        fail 'all root ancestors reject symlinks'
    else
        ln -s "$FIXTURE/keys/real" "$FIXTURE/keys/link"
        if output=$(COOLIFY_ROOT=$ROOT COOLIFY_AUTHORIZED_KEYS=$FIXTURE/keys/link/nested/authorized_keys "$SUBJECT" status 2>&1); then
            fail 'all authorized_keys ancestors reject symlinks'
        elif [[ $output == *'symbolic link'* ]]; then
            pass 'all root and authorized_keys ancestors reject symlinks'
        else
            fail 'all authorized_keys ancestors reject symlinks'
        fi
    fi
    cleanup_fixture
}

test_rejects_unsafe_state_lock() {
    new_fixture
    mkdir -p "$ROOT/fork-deploy"
    ln -s "$FIXTURE/target" "$ROOT/fork-deploy/deploy.lock"
    local output
    if output=$("$SUBJECT" status 2>&1); then
        fail 'symlinked deployment lock is rejected'
    elif [[ $output == *'symbolic link'* || $output == *'regular non-symlink'* ]]; then
        pass 'symlinked deployment lock is rejected'
    else
        fail 'symlinked deployment lock is rejected'
    fi
    cleanup_fixture
}

test_requires_compose_override_support() {
    new_fixture
    export FORK_DEPLOY_COMPOSE_VERSION=v2.24.3
    local output
    if output=$("$SUBJECT" status 2>&1); then
        fail 'Compose before !override support is rejected'
    elif [[ $output == *'2.24.4 or later'* ]]; then
        pass 'Compose before !override support is rejected'
    else
        fail 'Compose before !override support is rejected'
    fi
    cleanup_fixture
}

test_real_compose_config_when_available() {
    local output

    if [[ -z $REAL_DOCKER ]] || ! "$REAL_DOCKER" info >/dev/null 2>&1; then
        pass 'real Compose configuration test skipped because Docker is unavailable'
        return
    fi

    new_fixture
    write_assets
    printf 'APP_PORT=8010\nSOKETI_PORT=6011\n' >"$FIXTURE/real-compose.env"
    {
        printf 'services:\n'
        printf '  coolify:\n'
        printf '    ports:\n'
        printf '      - "0.0.0.0:9999:8080"\n'
    } >"$FIXTURE/real-compose.yml"
    printf 'services: {}\n' >"$FIXTURE/real-compose.prod.yml"

    if output=$(
        "$REAL_DOCKER" compose \
            --env-file "$FIXTURE/real-compose.env" \
            --file "$FIXTURE/real-compose.yml" \
            --file "$FIXTURE/real-compose.prod.yml" \
            --file "$ASSETS/docker-compose.custom.yml" \
            config --format json 2>&1
    ) \
        && [[ $output == *'"host_ip": "127.0.0.1"'* ]] \
        && [[ $output == *'"published": "8010"'* ]] \
        && [[ $output == *'"published": "6011"'* ]] \
        && [[ $output == *'"published": "6002"'* ]] \
        && [[ $output != *'0.0.0.0'* && $output != *'9999'* ]]; then
        pass 'real Compose config honors !override and loopback-only ports'
    else
        fail 'real Compose config honors !override and loopback-only ports'
    fi
    cleanup_fixture
}

test_production_trust_bootstrap_when_available() {
    if [[ -z $REAL_DOCKER ]] || ! "$REAL_DOCKER" info >/dev/null 2>&1; then
        pass 'production trust bootstrap test skipped because Docker is unavailable'
        return
    fi

    local output status
    # shellcheck disable=SC2016
    if output=$(
        "$REAL_DOCKER" run --rm \
            --mount "type=bind,src=$SUBJECT,dst=/input/fork-deploy,readonly" \
            --mount "type=bind,src=$REPO_ROOT/docker/fork-release-signing-ed25519.pub,dst=/input/release-signing-ed25519.pub,readonly" \
            ubuntu:24.04 \
            bash -ceu '
                export DEBIAN_FRONTEND=noninteractive
                if ! command -v openssl >/dev/null 2>&1; then
                    if ! apt-get update >/dev/null || ! apt-get install -y --no-install-recommends openssl >/dev/null; then
                        exit 77
                    fi
                fi
                target=/etc/coolify-fork/release-signing-ed25519.pub
                bash /input/fork-deploy trust --public-key /input/release-signing-ed25519.pub
                cmp -s /input/release-signing-ed25519.pub "$target"
                [[ $(stat -c "%u:%g:%a" "$target") == 0:0:644 ]]
                openssl genpkey -algorithm ED25519 -out /tmp/wrong-private.pem >/dev/null 2>&1
                openssl pkey -in /tmp/wrong-private.pem -pubout -out /tmp/wrong.pub >/dev/null 2>&1
                if bash /input/fork-deploy trust --public-key /tmp/wrong.pub; then
                    exit 1
                fi
                cmp -s /input/release-signing-ed25519.pub "$target"
                [[ $(stat -c "%u:%g:%a" "$target") == 0:0:644 ]]
            ' 2>&1
    ); then
        pass 'production trust bootstrap installs only the pinned public key'
    else
        status=$?
        case $status in
            77|125) pass 'production trust bootstrap test skipped because Docker setup is unavailable' ;;
            *) fail 'production trust bootstrap installs only the pinned public key' ;;
        esac
    fi
}

test_dry_run_is_non_mutating() {
    new_fixture
    write_manifest 4.13.0-fork.1
    local output
    if output=$("$SUBJECT" install --offline-manifest "$MANIFEST_FILE" --dry-run 2>&1) && [[ ! -e $ROOT && $output == *'PLAN:'* ]]; then
        pass 'dry-run preserves host state'
    else
        fail 'dry-run preserves host state'
    fi
    cleanup_fixture
}

test_status_does_not_mutate_authorized_keys() {
    new_fixture
    mkdir -p "${COOLIFY_AUTHORIZED_KEYS%/*}"
    printf 'ssh-ed25519 existing-key existing\n' >"$COOLIFY_AUTHORIZED_KEYS"
    local before output
    before=$(hash_file "$COOLIFY_AUTHORIZED_KEYS")
    : >"$LOG"
    if output=$("$SUBJECT" status 2>&1); then
        fail 'status does not mutate authorized_keys'
    elif [[ $(hash_file "$COOLIFY_AUTHORIZED_KEYS") == "$before" ]] \
        && [[ $output == *'unmanaged'* ]] \
        && ! grep -q '^ssh-keygen ' "$LOG"; then
        pass 'status does not mutate authorized_keys'
    else
        fail 'status does not mutate authorized_keys'
    fi
    cleanup_fixture
}

test_trust_is_production_only_without_deployment_preflight() {
    new_fixture
    local output
    if output=$("$SUBJECT" trust --public-key "$FORK_DEPLOY_MANIFEST_PUBLIC_KEY" 2>&1); then
        fail 'trust is production-only without deployment preflight'
    elif [[ $output == *'available only in production'* ]] \
        && [[ ! -e $ROOT ]] \
        && [[ ! -s $LOG ]]; then
        pass 'trust is production-only without deployment preflight'
    else
        fail 'trust is production-only without deployment preflight'
    fi
    cleanup_fixture
}

test_install_records_signed_immutable_bundle() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if install_release >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && [[ -f $ROOT/fork-deploy/releases/4.13.0-fork.1/release.manifest ]] \
        && [[ -f $ROOT/fork-deploy/releases/4.13.0-fork.1/.env.production ]] \
        && [[ -f $ROOT/fork-deploy/releases/4.13.0-fork.1/release-assets.contract ]] \
        && grep -Fxq 'AUTOUPDATE=false' "$ROOT/source/.env" \
        && grep -Fxq "chown root:root $ROOT" "$LOG" \
        && ! grep -Fxq "chown 9999:root $ROOT" "$LOG"; then
        pass 'install records a signed immutable release bundle'
    else
        fail 'install records a signed immutable release bundle'
    fi
    cleanup_fixture
}

test_install_accepts_bare_fork_version_convention() {
    new_fixture
    write_manifest 4.13.1-fork

    if install_release >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.1-fork ]] \
        && "$SUBJECT" verify >/dev/null; then
        pass 'install accepts the bare fork version convention'
    else
        fail 'install accepts the bare fork version convention'
    fi
    cleanup_fixture
}

test_install_rejects_zero_historical_suffix() {
    new_fixture
    write_manifest 4.13.1-fork.0

    if install_release >/dev/null 2>&1; then
        fail 'install rejects a zero historical suffix'
    elif [[ ! -e $ROOT/fork-deploy/current ]]; then
        pass 'install rejects a zero historical suffix'
    else
        fail 'install rejects a zero historical suffix'
    fi
    cleanup_fixture
}

test_accepts_real_ed25519_raw_signature() {
    new_fixture
    write_manifest 4.13.0-fork.1
    "$FORK_DEPLOY_REAL_OPENSSL" pkeyutl \
        -sign \
        -rawin \
        -inkey "$FIXTURE/trust/private.pem" \
        -in "$MANIFEST_FILE" \
        -out "$MANIFEST_FILE.sig"
    export FORK_DEPLOY_USE_REAL_OPENSSL=true
    if install_release >/dev/null && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]]; then
        pass 'real Ed25519 raw manifest signature is verified'
    else
        fail 'real Ed25519 raw manifest signature is verified'
    fi
    cleanup_fixture
}

test_update_requires_verified_current_release() {
    new_fixture
    write_manifest 4.13.0-fork.1
    local output
    if output=$(update_release 2>&1); then
        fail 'update requires a verified current release'
    elif [[ $output == *'active release state'* ]]; then
        pass 'update requires a verified current release'
    else
        fail 'update requires a verified current release'
    fi
    cleanup_fixture
}

test_update_requires_healthy_current_runtime() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'update requires a healthy current runtime'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_HOST_IP=0.0.0.0
    : >"$LOG"
    local output
    if output=$(update_release 2>&1); then
        fail 'update requires a healthy current runtime'
    elif [[ $output == *'unsafe host IP'* ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'update requires a healthy current runtime'
    else
        fail 'update requires a healthy current runtime'
    fi
    cleanup_fixture
}

test_repair_never_rewrites_verified_bundle() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'repair preserves immutable bundle'
        cleanup_fixture
        return
    fi
    local release=$ROOT/fork-deploy/releases/4.13.0-fork.1 before
    before=$(hash_file "$release/docker-compose.custom.yml")
    printf 'corrupt\n' >"$ROOT/source/docker-compose.custom.yml"
    if "$SUBJECT" repair >/dev/null \
        && [[ $(hash_file "$release/docker-compose.custom.yml") == "$before" ]] \
        && cmp -s "$ROOT/source/docker-compose.custom.yml" "$release/docker-compose.custom.yml"; then
        pass 'repair preserves immutable bundle'
    else
        fail 'repair preserves immutable bundle'
    fi
    cleanup_fixture
}

test_repair_rejects_corrupted_recorded_asset() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'corrupted release assets are rejected before repair'
        cleanup_fixture
        return
    fi
    printf 'tampered\n' >"$ROOT/fork-deploy/releases/4.13.0-fork.1/docker-compose.yml"
    : >"$LOG"
    local output
    if output=$("$SUBJECT" repair 2>&1); then
        fail 'corrupted release assets are rejected before repair'
    elif [[ $output == *'hash mismatch'* ]] && ! grep -q 'compose .* up' "$LOG"; then
        pass 'corrupted release assets are rejected before repair'
    else
        fail 'corrupted release assets are rejected before repair'
    fi
    cleanup_fixture
}

test_help_and_parser_describe_forward_recovery() {
    new_fixture
    local output
    if output=$("$SUBJECT" --help 2>&1) \
        && [[ $output == *'recover-forward'* \
            && $output == *'exact signed candidate'* \
            && $output == *'preserves all'* \
            && $output == *'Snapshot restore is forbidden'* ]] \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' --dry-run \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' --plan \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' \
            --version 4.13.0-fork.2 \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' \
            --manifest https://example.invalid/release.manifest \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' \
            --offline-manifest "$MANIFEST_FILE" \
        && forward_parser_rejects 'does not accept release arguments or --dry-run' \
            --public-key "$FORK_DEPLOY_MANIFEST_PUBLIC_KEY" \
        && [[ ! -e $ROOT ]]; then
        pass 'help and parser expose only argument-free forward recovery'
    else
        fail 'help and parser expose only argument-free forward recovery'
    fi
    cleanup_fixture
}

test_post_start_failure_has_forward_only_disposition() {
    new_fixture
    local backup
    if ! prepare_post_start_failure; then
        fail 'post-start failure records a durable forward-only disposition'
        cleanup_fixture
        return
    fi
    backup=$(awk -F= '$1 == "CONFIGURATION_BACKUP" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/failed-needs-restore")
    if [[ ! -e $ROOT/fork-deploy/current \
        && -f $ROOT/fork-deploy/failed-needs-restore \
        && -f $ROOT/fork-deploy/pending-candidate \
        && -f $ROOT/fork-deploy/releases/4.13.0-fork.2/verified \
        && -f $backup/configuration.tar.gpg \
        && ! -e $backup/.env \
        && ! -e $backup/prestart-undo.manifest ]] \
        && grep -Fxq 'STATE=failed-needs-forward-recovery' \
            "$ROOT/fork-deploy/failed-needs-restore" \
        && grep -Fxq 'CANDIDATE_VERSION=4.13.0-fork.2' \
            "$ROOT/fork-deploy/failed-needs-restore" \
        && grep -Fxq 'CANDIDATE_STARTED=true' "$ROOT/fork-deploy/pending-candidate" \
        && [[ $FORWARD_FAILURE_OUTPUT == *'Snapshot restore and automatic rollback are forbidden'* \
            && $FORWARD_FAILURE_OUTPUT == *'recover-forward'* ]] \
        && [[ $(grep -c 'compose .* up' "$LOG" || true) -eq 1 ]]; then
        pass 'post-start failure records a durable forward-only disposition'
    else
        fail 'post-start failure records a durable forward-only disposition'
    fi
    cleanup_fixture
}

test_forward_recovery_preserves_writes_and_exact_candidate() {
    new_fixture
    local source_hash
    if ! prepare_post_start_failure; then
        fail 'forward recovery preserves writes and activates the exact recorded candidate'
        cleanup_fixture
        return
    fi
    source_hash=$(hash_file "$ROOT/source/docker-compose.custom.yml")
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"
    write_manifest 4.13.0-fork.99
    : >"$LOG"
    if "$SUBJECT" recover-forward >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 ]] \
        && [[ $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start ]] \
        && [[ $(hash_file "$ROOT/source/docker-compose.custom.yml") == "$source_hash" ]] \
        && [[ -f $ROOT/fork-deploy/activations/4.13.0-fork.2 \
            && ! -e $ROOT/fork-deploy/failed-needs-restore \
            && ! -e $ROOT/fork-deploy/pending-candidate ]] \
        && [[ $(candidate_history_count) -eq 1 ]] \
        && [[ $(grep -c 'compose .* up' "$LOG" || true) -eq 1 ]] \
        && ! grep -Eq 'pg_restore .*(--dbname|-d )' "$LOG" \
        && "$SUBJECT" verify >/dev/null; then
        pass 'forward recovery preserves writes and activates the exact recorded candidate'
    else
        fail 'forward recovery preserves writes and activates the exact recorded candidate'
    fi
    cleanup_fixture
}

test_forward_recovery_retries_one_sided_and_cleanup_states() {
    new_fixture
    local pending=$ROOT/fork-deploy/pending-candidate
    local failed=$ROOT/fork-deploy/failed-needs-restore backup
    if ! prepare_post_start_failure; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi
    cp "$pending" "$FIXTURE/saved-pending"
    cp "$failed" "$FIXTURE/saved-failed"
    backup=$(awk -F= '$1 == "CONFIGURATION_BACKUP" { print substr($0, length($1) + 2) }' \
        "$failed")
    mkdir "$backup/prestart-undo"
    printf 'partially-finalized\n' >"$backup/prestart-undo/authorized_keys"
    printf 'partially-finalized\n' >"$backup/.env"
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"

    export FORK_DEPLOY_FAIL_COMPOSE_UP=true
    if "$SUBJECT" recover-forward >/dev/null 2>&1; then
        unset FORK_DEPLOY_FAIL_COMPOSE_UP
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_COMPOSE_UP
    if [[ ! -f $pending || ! -f $failed \
        || -e $backup/prestart-undo || -e $backup/.env \
        || -e $ROOT/fork-deploy/current ]]; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi

    rm -f "$pending"
    if ! "$SUBJECT" recover-forward >/dev/null \
        || [[ $(candidate_history_count) -ne 1 ]]; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi

    cp "$FIXTURE/saved-pending" "$pending"
    chmod 0600 "$pending"
    if ! "$SUBJECT" recover-forward >/dev/null \
        || [[ $(candidate_history_count) -ne 1 ]]; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi

    cp "$FIXTURE/saved-failed" "$failed"
    chmod 0600 "$failed"
    replace_key_value "$failed" STATE failed-needs-restore
    if ! "$SUBJECT" recover-forward >/dev/null \
        || [[ $(candidate_history_count) -ne 1 ]]; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi

    cp "$FIXTURE/saved-failed" "$failed"
    cp "$FIXTURE/saved-pending" "$pending"
    chmod 0600 "$failed" "$pending"
    replace_key_value "$pending" CANDIDATE_STARTED false
    if "$SUBJECT" recover-forward >/dev/null \
        && [[ $(candidate_history_count) -eq 1 \
            && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 \
            && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
            && ! -e $pending && ! -e $failed ]]; then
        pass 'forward recovery retries one-sided and interrupted cleanup states idempotently'
    else
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
    fi
    cleanup_fixture
}

test_forward_recovery_forbids_mismatch_abort_and_rollback() {
    new_fixture
    local pending=$ROOT/fork-deploy/pending-candidate
    local rollback_output abort_output mismatch_output current_output environment_output
    if ! prepare_post_start_failure; then
        fail 'forward recovery fails closed on mismatch, abort, and rollback attempts'
        cleanup_fixture
        return
    fi
    cp "$pending" "$FIXTURE/saved-pending"
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"
    : >"$LOG"

    if rollback_output=$("$SUBJECT" rollback --version 4.13.0-fork.1 2>&1); then
        rollback_output=accepted
    fi
    abort_output=$("$SUBJECT" recover-abort 2>&1 || true)
    replace_key_value "$pending" CANDIDATE_VERSION 4.13.0-fork.3
    mismatch_output=$("$SUBJECT" recover-forward 2>&1 || true)
    cp "$FIXTURE/saved-pending" "$pending"
    chmod 0600 "$pending"
    printf '4.13.0-fork.1\n' >"$ROOT/fork-deploy/current"
    current_output=$("$SUBJECT" recover-forward 2>&1 || true)
    rm -f "$ROOT/fork-deploy/current"
    replace_key_value "$ROOT/source/.env" COOLIFY_FORK_VERSION 4.13.0-fork.1
    environment_output=$("$SUBJECT" recover-forward 2>&1 || true)

    if [[ $rollback_output == *'requires forward recovery'* \
        && $abort_output == *'snapshot restore are forbidden'* \
        && $mismatch_output == *'state differs between pending and failed disposition'* \
        && $current_output == *'found active release 4.13.0-fork.1'* \
        && $environment_output == *'does not identify the exact failed candidate'* \
        && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
        && -f $ROOT/fork-deploy/failed-needs-restore \
        && -f $pending ]] \
        && ! grep -q 'compose .* up' "$LOG" \
        && ! grep -Eq 'pg_restore .*(--dbname|-d )' "$LOG"; then
        pass 'forward recovery fails closed on mismatch, abort, and rollback attempts'
    else
        fail 'forward recovery fails closed on mismatch, abort, and rollback attempts'
    fi
    cleanup_fixture
}

test_forward_recovery_reconciles_historical_rollback_activation() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'forward recovery replaces a historical rollback activation safely'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    if ! update_release >/dev/null; then
        fail 'forward recovery replaces a historical rollback activation safely'
        cleanup_fixture
        return
    fi
    replace_key_value "$ROOT/fork-deploy/activations/4.13.0-fork.1" \
        MIGRATION_FINGERPRINT_BEFORE ffffffffffffffffffffffffffffffff

    rm -f "$FORK_DEPLOY_CANDIDATE_STARTED_MARKER"
    export FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY=true
    if "$SUBJECT" rollback --version 4.13.0-fork.1 >/dev/null 2>&1; then
        unset FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY
        fail 'forward recovery replaces a historical rollback activation safely'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY
    printf 'accepted-during-rollback-recovery\n' >"$ROOT/applications/post-start-write"
    : >"$LOG"

    if "$SUBJECT" recover-forward >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
            && $(candidate_history_count 4.13.0-fork.1) -eq 2 \
            && $(awk -F= '$1 == "MIGRATION_FINGERPRINT_BEFORE" { print $2 }' \
                "$ROOT/fork-deploy/activations/4.13.0-fork.1") != ffffffffffffffffffffffffffffffff \
            && $(<"$ROOT/applications/post-start-write") == accepted-during-rollback-recovery \
            && ! -e $ROOT/fork-deploy/pending-candidate \
            && ! -e $ROOT/fork-deploy/failed-needs-restore ]] \
        && ! grep -Eq 'pg_restore .*(--dbname|-d )' "$LOG" \
        && "$SUBJECT" verify >/dev/null; then
        pass 'forward recovery replaces a historical rollback activation safely'
    else
        fail 'forward recovery replaces a historical rollback activation safely'
    fi
    cleanup_fixture
}

test_legacy_post_start_failure_without_bundle_fails_closed() {
    new_fixture
    if ! prepare_post_start_failure; then
        fail 'legacy post-start failure without a recorded bundle fails closed'
        cleanup_fixture
        return
    fi
    replace_key_value "$ROOT/fork-deploy/failed-needs-restore" STATE failed-needs-restore
    mv "$ROOT/fork-deploy/releases/4.13.0-fork.2" \
        "$ROOT/fork-deploy/releases/4.13.0-fork.2.not-recorded"
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"
    : >"$LOG"
    local output
    if output=$("$SUBJECT" recover-forward 2>&1); then
        fail 'legacy post-start failure without a recorded bundle fails closed'
    elif [[ $output == *'snapshot restore is forbidden'* \
        && $output == *'manual forward recovery is required'* \
        && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
        && -f $ROOT/fork-deploy/failed-needs-restore \
        && -f $ROOT/fork-deploy/pending-candidate ]] \
        && ! grep -Eq 'pg_restore .*(--dbname|-d )' "$LOG"; then
        pass 'legacy post-start failure without a recorded bundle fails closed'
    else
        fail 'legacy post-start failure without a recorded bundle fails closed'
    fi
    cleanup_fixture
}

test_invalid_ports_fail_before_activation() {
    new_fixture
    export FORK_DEPLOY_ENV_EXTRA=$'APP_PORT=0\n'
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'invalid APP_PORT fails before activation'
    elif [[ $output == *'APP_PORT must be'* && ! -e $ROOT/source/docker-compose.yml ]]; then
        pass 'invalid APP_PORT fails before activation'
    else
        fail 'invalid APP_PORT fails before activation'
    fi
    cleanup_fixture
}

test_rejects_release_asset_contract_hash_mismatch() {
    new_fixture
    write_manifest 4.13.0-fork.1
    export FORK_DEPLOY_CONTRACT_EXTRA=-tampered
    local output
    if output=$(install_release 2>&1); then
        fail 'release asset contract hash mismatch prevents activation'
    elif [[ $output == *'release-assets.contract does not match'* ]] \
        && [[ ! -e $ROOT/source/docker-compose.yml ]]; then
        pass 'release asset contract hash mismatch prevents activation'
    else
        fail 'release asset contract hash mismatch prevents activation'
    fi
    cleanup_fixture
}

test_verify_rejects_all_unsafe_runtime_bindings() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'runtime verifier inspects every published binding'
        cleanup_fixture
        return
    fi
    local unsafe output all_rejected=true
    for unsafe in 0.0.0.0 :: absent; do
        export FORK_DEPLOY_HOST_IP=$unsafe
        if output=$("$SUBJECT" verify 2>&1); then
            all_rejected=false
        fi
    done
    unset FORK_DEPLOY_HOST_IP
    export FORK_DEPLOY_EXTRA_BINDING=true
    if output=$("$SUBJECT" verify 2>&1); then
        all_rejected=false
    fi
    if "$all_rejected"; then
        pass 'runtime verifier inspects every published binding'
    else
        fail 'runtime verifier inspects every published binding'
    fi
    cleanup_fixture
}

test_rejects_invalid_manifest_signature() {
    new_fixture
    write_manifest 4.13.0-fork.1
    export FORK_DEPLOY_OPENSSL_VERIFY_FAIL=true
    local output
    if output=$(install_release 2>&1); then
        fail 'manifest signature and ordering are required'
    elif [[ $output == *'signature verification failed'* ]]; then
        pass 'manifest signature and ordering are required'
    else
        fail 'manifest signature and ordering are required'
    fi
    cleanup_fixture
}

test_rejects_out_of_order_manifest_schema() {
    new_fixture
    write_manifest 4.13.0-fork.1
    awk '
        NR == 1 {
            schema = $0
            next
        }
        NR == 2 {
            print
            print schema
            next
        }
        {
            print
        }
    ' "$MANIFEST_FILE" >"$MANIFEST_FILE.reordered"
    mv "$MANIFEST_FILE.reordered" "$MANIFEST_FILE"
    local output
    if output=$(install_release 2>&1); then
        fail 'manifest schema fields must be canonical and ordered'
    elif [[ $output == *'unexpected, duplicate, or out-of-order field'* ]]; then
        pass 'manifest schema fields must be canonical and ordered'
    else
        fail 'manifest schema fields must be canonical and ordered'
    fi
    cleanup_fixture
}

test_rejects_mismatched_manifest_source_tag() {
    new_fixture
    write_manifest 4.13.0-fork.1
    sed 's/^SOURCE_TAG=.*/SOURCE_TAG=4.13.0-fork.2/' "$MANIFEST_FILE" >"$MANIFEST_FILE.mismatched"
    mv "$MANIFEST_FILE.mismatched" "$MANIFEST_FILE"
    local output
    if output=$(install_release 2>&1); then
        fail 'manifest source tag must exactly match version'
    elif [[ $output == *'SOURCE_TAG must exactly match VERSION'* ]]; then
        pass 'manifest source tag must exactly match version'
    else
        fail 'manifest source tag must exactly match version'
    fi
    cleanup_fixture
}

test_bootstrap_rejects_preexisting_symlink() {
    new_fixture
    mkdir -p "$ROOT" "$FIXTURE/outside-applications"
    ln -s "$FIXTURE/outside-applications" "$ROOT/applications"
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'bootstrap refuses pre-existing symlinked data directories'
    elif [[ $output == *'symbolic link'* ]] \
        && [[ ! -e $ROOT/source/docker-compose.yml ]] \
        && [[ -z $(find "$FIXTURE/outside-applications" -mindepth 1 -print -quit) ]]; then
        pass 'bootstrap refuses pre-existing symlinked data directories'
    else
        fail 'bootstrap refuses pre-existing symlinked data directories'
    fi
    cleanup_fixture
}

test_rejects_hardlinked_privileged_state_before_chmod() {
    new_fixture
    mkdir -p "$ROOT/fork-deploy"
    printf 'lock\n' >"$FIXTURE/shared-lock"
    chmod 0644 "$FIXTURE/shared-lock"
    ln "$FIXTURE/shared-lock" "$ROOT/fork-deploy/deploy.lock"
    local output
    if output=$("$SUBJECT" status 2>&1); then
        fail 'hardlinked privileged state is rejected before permission mutation'
    elif [[ $output == *'must not be hardlinked'* ]] \
        && [[ $(file_mode "$FIXTURE/shared-lock") == 644 ]]; then
        pass 'hardlinked privileged state is rejected before permission mutation'
    else
        fail 'hardlinked privileged state is rejected before permission mutation'
    fi
    cleanup_fixture
}

test_update_requires_newer_unactivated_version() {
    new_fixture
    write_manifest 4.13.1-fork
    if ! install_release >/dev/null; then
        fail 'update accepts only a newer canonical fork version'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.99
    : >"$LOG"
    local output
    if output=$(update_release 2>&1); then
        fail 'update accepts only a newer canonical fork version'
    elif [[ $output == *'is not newer than active release'* ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.1-fork ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'update accepts only a newer canonical fork version'
    else
        fail 'update accepts only a newer canonical fork version'
    fi
    cleanup_fixture
}

test_compatible_rollback_succeeds() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'rollback succeeds for a fingerprint-compatible recorded release'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    if update_release >/dev/null \
        && "$SUBJECT" rollback --version 4.13.0-fork.1 >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]]; then
        pass 'rollback succeeds for a fingerprint-compatible recorded release'
    else
        fail 'rollback succeeds for a fingerprint-compatible recorded release'
    fi
    cleanup_fixture
}

test_incompatible_rollback_refuses_before_compose() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'rollback refuses a migration-incompatible release before Compose'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    if ! update_release >/dev/null; then
        fail 'rollback refuses a migration-incompatible release before Compose'
        cleanup_fixture
        return
    fi
    export FORK_DEPLOY_MIGRATION_FINGERPRINT=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    : >"$LOG"
    local output
    if output=$("$SUBJECT" rollback --version 4.13.0-fork.1 2>&1); then
        fail 'rollback refuses a migration-incompatible release before Compose'
    elif [[ $output == *'rollback refused: current migration fingerprint'* ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'rollback refuses a migration-incompatible release before Compose'
    else
        fail 'rollback refuses a migration-incompatible release before Compose'
    fi
    cleanup_fixture
}

test_update_rejects_previously_activated_target() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'update rejects every previously activated target'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    if ! update_release >/dev/null \
        || ! "$SUBJECT" rollback --version 4.13.0-fork.1 >/dev/null; then
        fail 'update rejects every previously activated target'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    local output
    if output=$(update_release 2>&1); then
        fail 'update rejects every previously activated target'
    elif [[ $output == *'was already activated; use rollback or repair'* ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'update rejects every previously activated target'
    else
        fail 'update rejects every previously activated target'
    fi
    cleanup_fixture
}

test_update_records_complete_fresh_backup_before_compose() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'update attests a fresh complete backup before candidate startup'
        cleanup_fixture
        return
    fi
    local redis_password backup='' attestation created now acquire_line pg_line redis_line release_line compose_line
    redis_password=$(awk -F= '$1 == "REDIS_PASSWORD" { print substr($0, length($1) + 2) }' "$ROOT/source/.env")
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if ! update_release >/dev/null; then
        fail 'update attests a fresh complete backup before candidate startup'
        cleanup_fixture
        return
    fi
    for attestation in "$ROOT"/fork-deploy/backups/backup.*/attestation; do
        if grep -Fxq 'CANDIDATE_VERSION=4.13.0-fork.2' "$attestation"; then
            backup=${attestation%/attestation}
            break
        fi
    done
    created=$(awk -F= '$1 == "CREATED_UNIX" { print $2 }' "$backup/attestation")
    now=$(date +%s)
    pg_line=$(grep -n 'pg_restore --list' "$LOG" | head -1 | awk -F: '{print $1}')
    redis_line=$(grep -n 'redis-check-rdb' "$LOG" | head -1 | awk -F: '{print $1}')
    acquire_line=$(grep -n 'control-plane-blue-green backup-quiesce acquire' "$LOG" | head -1 | awk -F: '{print $1}')
    release_line=$(grep -n 'control-plane-blue-green backup-quiesce release' "$LOG" | head -1 | awk -F: '{print $1}')
    compose_line=$(grep -n 'compose .* up' "$LOG" | head -1 | awk -F: '{print $1}')
    if [[ -n $backup && -f $backup/database.pgdump.gpg && -f $backup/redis.rdb.gpg \
        && -f $backup/control-plane-state.tar.gpg && -f $backup/configuration.tar.gpg \
        && -f $backup/capture-validation \
        && ! -e $backup/database.pgdump && ! -e $backup/redis.rdb \
        && ! -e $backup/control-plane-state.tar && ! -e $backup/.env ]] \
        && grep -Fxq 'BACKUP_KIND=complete' "$backup/attestation" \
        && grep -Fxq 'SOURCE_VERSION=4.13.0-fork.1' "$backup/attestation" \
        && grep -Fxq 'STATE_INVENTORY=applications,backups,databases,proxy,sentinel,services,ssh' "$backup/attestation" \
        && grep -Eq '^CAPTURE_VALIDATION_SHA256=[0-9a-f]{64}$' "$backup/attestation" \
        && ((now - created <= 900)) \
        && [[ -n $acquire_line && -n $pg_line && -n $redis_line && -n $release_line && -n $compose_line \
            && $acquire_line -lt $pg_line && $pg_line -lt $release_line \
            && $redis_line -lt $release_line && $release_line -lt $compose_line ]] \
        && [[ ! -e $FORK_DEPLOY_QUIESCE_MARKER ]] \
        && [[ -n $redis_password ]] && ! grep -Fq "$redis_password" "$LOG"; then
        pass 'update attests a fresh complete backup before candidate startup'
    else
        fail 'update attests a fresh complete backup before candidate startup'
    fi
    cleanup_fixture
}

test_backup_gate_failures_prevent_candidate_start() {
    local gate output all_rejected=true backup_count_before backup_count_after

    for gate in FORK_DEPLOY_FAIL_PG_DUMP FORK_DEPLOY_FAIL_REDIS_SAVE FORK_DEPLOY_FAIL_REDIS_CHECK; do
        new_fixture
        write_manifest 4.13.0-fork.1
        if ! install_release >/dev/null; then
            all_rejected=false
            cleanup_fixture
            continue
        fi
        write_manifest 4.13.0-fork.2
        export "$gate=true"
        : >"$LOG"
        backup_count_before=$(find "$ROOT/fork-deploy/backups" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d '[:space:]')
        if output=$(update_release 2>&1) \
            || [[ $(<"$ROOT/fork-deploy/current") != 4.13.0-fork.1 ]] \
            || grep -q 'compose .* up' "$LOG" \
            || [[ -e $FORK_DEPLOY_QUIESCE_MARKER ]] \
            || ! grep -q 'control-plane-blue-green backup-quiesce release' "$LOG"; then
            all_rejected=false
        fi
        backup_count_after=$(find "$ROOT/fork-deploy/backups" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d '[:space:]')
        [[ $backup_count_after == "$backup_count_before" ]] || all_rejected=false
        cleanup_fixture
    done
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        all_rejected=false
    else
        ln -s "$FIXTURE" "$ROOT/applications/unsafe-link"
        write_manifest 4.13.0-fork.2
        : >"$LOG"
        if output=$(update_release 2>&1) \
            || [[ $(<"$ROOT/fork-deploy/current") != 4.13.0-fork.1 ]] \
            || grep -q 'compose .* up' "$LOG"; then
            all_rejected=false
        fi
    fi
    if "$all_rejected"; then
        pass 'database, Redis, and control-plane backup gates prevent candidate startup'
    else
        fail 'database, Redis, and control-plane backup gates prevent candidate startup'
    fi
    cleanup_fixture
}

test_adopts_only_complete_live_legacy_state() {
    new_fixture
    mkdir -p "$ROOT/source" "$ROOT/ssh/keys" "$ROOT/ssh/mux" "$ROOT/applications" \
        "$ROOT/backups" "$ROOT/databases" "$ROOT/proxy/dynamic" "$ROOT/sentinel" "$ROOT/services"
    printf 'DB_USERNAME=coolify\nDB_DATABASE=coolify\nREDIS_PASSWORD=legacy-secret\n' >"$ROOT/source/.env"
    chmod 0600 "$ROOT/source/.env"
    : >"$FORK_DEPLOY_DB_MARKER"
    : >"$FORK_DEPLOY_REDIS_MARKER"
    export FORK_DEPLOY_LEGACY_VOLUMES=$'coolify-db\ncoolify-redis'
    write_manifest 4.13.0-fork.1
    local attestation backup=''
    if ! install_release >/dev/null; then
        fail 'legacy adoption requires a complete quiesced backup even with empty workload directories'
        cleanup_fixture
        return
    fi
    for attestation in "$ROOT"/fork-deploy/backups/backup.*/attestation; do
        if grep -Fxq 'CANDIDATE_VERSION=4.13.0-fork.1' "$attestation"; then
            backup=${attestation%/attestation}
            break
        fi
    done
    if [[ -n $backup ]] \
        && grep -Fxq 'SOURCE_VERSION=legacy-unmanaged' "$backup/attestation" \
        && grep -Fxq 'BACKUP_KIND=complete' "$backup/attestation" \
        && [[ -f $backup/database.pgdump.gpg && -f $backup/redis.rdb.gpg \
            && -f $backup/control-plane-state.tar.gpg ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]]; then
        pass 'legacy adoption requires a complete quiesced backup even with empty workload directories'
    else
        fail 'legacy adoption requires a complete quiesced backup even with empty workload directories'
    fi
    cleanup_fixture
}

test_refuses_orphan_legacy_volume_without_complete_adoption() {
    new_fixture
    export FORK_DEPLOY_LEGACY_VOLUMES=coolify-db
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'orphan legacy database volumes refuse fresh configuration-only deployment'
    elif [[ $output == *'unmanaged Coolify state is ambiguous'* \
        && $output == *'legacy_volumes=true/false'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'backup-quiesce acquire\|compose .* up' "$LOG"; then
        pass 'orphan legacy database volumes refuse fresh configuration-only deployment'
    else
        fail 'orphan legacy database volumes refuse fresh configuration-only deployment'
    fi
    cleanup_fixture
}

test_refuses_unmanaged_source_asset_without_complete_adoption() {
    new_fixture
    mkdir -p "$ROOT/source"
    printf 'services: {}\n' >"$ROOT/source/docker-compose.prod.yml"
    chmod 0600 "$ROOT/source/docker-compose.prod.yml"
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'unmanaged source assets refuse fresh configuration-only deployment'
    elif [[ $output == *'unmanaged Coolify state is ambiguous'* \
        && $output == *'source_assets=true/true'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'backup-quiesce acquire\|compose .* up' "$LOG"; then
        pass 'unmanaged source assets refuse fresh configuration-only deployment'
    else
        fail 'unmanaged source assets refuse fresh configuration-only deployment'
    fi
    cleanup_fixture
}

test_refuses_partial_unmanaged_state() {
    new_fixture
    mkdir -p "$ROOT/source"
    printf 'DB_USERNAME=coolify\nDB_DATABASE=coolify\nREDIS_PASSWORD=partial-secret\n' >"$ROOT/source/.env"
    chmod 0600 "$ROOT/source/.env"
    : >"$FORK_DEPLOY_DB_MARKER"
    : >"$FORK_DEPLOY_REDIS_MARKER"
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'partial unmanaged state is refused as ambiguous'
    elif [[ $output == *'unmanaged Coolify state is ambiguous'* \
        && $output == *'canonical_layout=false'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'backup-quiesce acquire\|compose .* up' "$LOG"; then
        pass 'partial unmanaged state is refused as ambiguous'
    else
        fail 'partial unmanaged state is refused as ambiguous'
    fi
    cleanup_fixture
}

test_prestart_failure_restores_exact_ssh_state() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'pre-start failure restores exact authorized_keys and localhost SSH key state'
        cleanup_fixture
        return
    fi
    local private_key=$ROOT/ssh/keys/id.root@host.docker.internal public_key
    local authorized_hash private_hash public_hash authorized_mode private_mode public_mode output
    public_key=$private_key.pub
    printf 'ssh-ed25519 administrator-only administrator\n' >"$COOLIFY_AUTHORIZED_KEYS"
    printf 'preexisting-private-key\n' >"$private_key"
    printf 'preexisting-public-key\n' >"$public_key"
    chmod 0644 "$COOLIFY_AUTHORIZED_KEYS"
    chmod 0640 "$private_key"
    chmod 0660 "$public_key"
    authorized_hash=$(hash_file "$COOLIFY_AUTHORIZED_KEYS")
    private_hash=$(hash_file "$private_key")
    public_hash=$(hash_file "$public_key")
    authorized_mode=$(file_mode "$COOLIFY_AUTHORIZED_KEYS")
    private_mode=$(file_mode "$private_key")
    public_mode=$(file_mode "$public_key")
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_ACTIVATED_CONFIG=true
    if output=$(update_release 2>&1); then
        fail 'pre-start failure restores exact authorized_keys and localhost SSH key state'
    elif [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
        && $(hash_file "$COOLIFY_AUTHORIZED_KEYS") == "$authorized_hash" \
        && $(hash_file "$private_key") == "$private_hash" \
        && $(hash_file "$public_key") == "$public_hash" \
        && $(file_mode "$COOLIFY_AUTHORIZED_KEYS") == "$authorized_mode" \
        && $(file_mode "$private_key") == "$private_mode" \
        && $(file_mode "$public_key") == "$public_mode" \
        && ! -e $ROOT/fork-deploy/pending-candidate \
        && ! -e $ROOT/fork-deploy/failed-needs-restore ]] \
        && [[ $output == *'prior configuration was restored'* ]]; then
        pass 'pre-start failure restores exact authorized_keys and localhost SSH key state'
    else
        fail 'pre-start failure restores exact authorized_keys and localhost SSH key state'
    fi
    cleanup_fixture
}

test_automatic_prestart_recovery_resumes_after_current_restore() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'automatic pre-start recovery resumes after restoring the current pointer'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_ACTIVATED_CONFIG=true
    export FORK_DEPLOY_TEST_KILL_AFTER_RESTORE_PREVIOUS_CURRENT=true
    local output backup recovery_output recovery_succeeded=false interrupted_state_valid=false
    local interrupted_current interrupted_phase interrupted_candidate
    if output=$(update_release 2>&1); then
        fail 'automatic pre-start recovery resumes after restoring the current pointer'
        cleanup_fixture
        return
    fi
    if [[ ! -f $ROOT/fork-deploy/pending-candidate ]]; then
        fail 'automatic pre-start recovery resumes after restoring the current pointer'
        cleanup_fixture
        return
    fi
    backup=$(awk -F= '$1 == "CONFIGURATION_BACKUP" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    interrupted_current=$(<"$ROOT/fork-deploy/current")
    interrupted_phase=$(awk -F= '$1 == "STATE" { print $2 }' "$ROOT/fork-deploy/pending-candidate")
    interrupted_candidate=$(awk -F= '$1 == "CANDIDATE_VERSION" { print $2 }' "$ROOT/fork-deploy/pending-candidate")
    if [[ $interrupted_current == 4.13.0-fork.1 \
        && $interrupted_phase == recover-abort-restoring \
        && $interrupted_candidate == 4.13.0-fork.2 \
        && ! -e $ROOT/fork-deploy/failed-needs-restore ]]; then
        interrupted_state_valid=true
    fi
    unset FORK_DEPLOY_FAIL_ACTIVATED_CONFIG FORK_DEPLOY_TEST_KILL_AFTER_RESTORE_PREVIOUS_CURRENT
    if recovery_output=$("$SUBJECT" recover-abort 2>&1); then
        recovery_succeeded=true
    fi
    if [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
        && $interrupted_state_valid == true \
        && $recovery_succeeded == true \
        && ! -e $ROOT/fork-deploy/failed-needs-restore \
        && ! -e $ROOT/fork-deploy/pending-candidate \
        && ! -e $backup/.env && ! -e $backup/prestart-undo ]]; then
        pass 'automatic pre-start recovery resumes after restoring the current pointer'
    else
        printf 'recover-abort output: %s\n' "$recovery_output" >&2
        fail 'automatic pre-start recovery resumes after restoring the current pointer'
    fi
    cleanup_fixture
}

test_requires_operator_recovery_key_before_mutation() {
    new_fixture
    write_manifest 4.13.0-fork.1
    unset GNUPGHOME
    : >"$LOG"
    local output
    if output=$(install_release 2>&1); then
        fail 'backup encryption requires the operator recovery key before activation'
    elif [[ $output == *'backup recovery GNUPGHOME'* ]] \
        && [[ ! -e $ROOT/source/.env && ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'compose .* up\|ssh-keygen' "$LOG"; then
        pass 'backup encryption requires the operator recovery key before activation'
    else
        fail 'backup encryption requires the operator recovery key before activation'
    fi
    cleanup_fixture
}

test_prunes_only_expired_completed_backups() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'successful deployment retains exactly five newest completed encrypted backups'
        cleanup_fixture
        return
    fi
    local index directory count candidate_backup=''
    for index in 1 2 3 4 5 6; do
        directory=$ROOT/fork-deploy/backups/backup.100000000$index.ABCDE$index
        mkdir "$directory"
        chmod 0700 "$directory"
        printf 'completed=%s\n' "$index" >"$directory/attestation"
        chmod 0600 "$directory/attestation"
    done
    write_manifest 4.13.0-fork.2
    if ! update_release >/dev/null; then
        fail 'successful deployment retains exactly five newest completed encrypted backups'
        cleanup_fixture
        return
    fi
    count=$(find "$ROOT/fork-deploy/backups" -mindepth 1 -maxdepth 1 -type d -name 'backup.*' | wc -l | tr -d '[:space:]')
    for directory in "$ROOT"/fork-deploy/backups/backup.*; do
        if grep -Fxq 'CANDIDATE_VERSION=4.13.0-fork.2' "$directory/attestation" 2>/dev/null; then
            candidate_backup=$directory
            break
        fi
    done
    if [[ $count == 5 && -n $candidate_backup && -f $candidate_backup/configuration.tar.gpg \
        && ! -e $ROOT/fork-deploy/backups/backup.1000000001.ABCDE1 ]]; then
        pass 'successful deployment retains exactly five newest completed encrypted backups'
    else
        fail 'successful deployment retains exactly five newest completed encrypted backups'
    fi
    cleanup_fixture
}

test_recover_abort_restores_only_prestart_pending_state() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'recover-abort restores only a pending candidate proven not started'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG=true
    if update_release >/dev/null 2>&1; then
        fail 'recover-abort restores only a pending candidate proven not started'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG
    local backup expected_env_hash guidance
    backup=$(awk -F= '$1 == "CONFIGURATION_BACKUP" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    expected_env_hash=$(hash_file "$backup/.env")
    if guidance=$("$SUBJECT" verify 2>&1); then
        fail 'recover-abort restores only a pending candidate proven not started'
        cleanup_fixture
        return
    elif [[ $guidance != *'run fork-deploy recover-abort'* ]]; then
        fail 'recover-abort restores only a pending candidate proven not started'
        cleanup_fixture
        return
    fi
    printf 'tampered\n' >"$ROOT/source/.env"
    : >"$LOG"
    if "$SUBJECT" recover-abort >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && [[ $(hash_file "$ROOT/source/.env") == "$expected_env_hash" ]] \
        && [[ ! -e $ROOT/fork-deploy/pending-candidate ]] \
        && [[ ! -e $backup/.env && ! -e $backup/prestart-undo \
            && ! -e $backup/configuration.manifest && ! -e $backup/ssh-undo.manifest \
            && ! -e $backup/prestart-undo.manifest ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'recover-abort restores state, removes plaintext undo, and is guided from pending state'
    else
        fail 'recover-abort restores state, removes plaintext undo, and is guided from pending state'
    fi
    cleanup_fixture
}

test_recover_abort_retries_after_restore_cleanup_interruption() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'recover-abort is idempotent after restore cleanup interruption'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG=true
    if update_release >/dev/null 2>&1; then
        fail 'recover-abort is idempotent after restore cleanup interruption'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG
    local backup output
    backup=$(awk -F= '$1 == "CONFIGURATION_BACKUP" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    chmod 0500 "$backup/prestart-undo"
    if output=$("$SUBJECT" recover-abort 2>&1); then
        fail 'recover-abort is idempotent after restore cleanup interruption'
        chmod 0700 "$backup/prestart-undo"
        cleanup_fixture
        return
    fi
    chmod 0700 "$backup/prestart-undo"
    if [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && grep -Fxq 'STATE=recover-abort-cleaning' "$ROOT/fork-deploy/pending-candidate" \
        && "$SUBJECT" recover-abort >/dev/null \
        && [[ ! -e $ROOT/fork-deploy/pending-candidate ]] \
        && [[ ! -e $backup/.env && ! -e $backup/prestart-undo ]]; then
        pass 'recover-abort is idempotent after restore cleanup interruption'
    else
        fail 'recover-abort is idempotent after restore cleanup interruption'
    fi
    cleanup_fixture
}

test_quiesce_release_failure_requires_durable_recovery() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'failed backup-quiesce release remains blocked until durable recovery succeeds'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_QUIESCE_RELEASE=true
    : >"$LOG"
    local output token status docker_info_before
    if output=$(update_release 2>&1); then
        fail 'failed backup-quiesce release remains blocked until durable recovery succeeds'
        cleanup_fixture
        return
    fi
    token=$(awk -F= '$1 == "FENCING_TOKEN" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/quiesce-release-failed")
    if status=$("$SUBJECT" status 2>&1); then
        fail 'failed backup-quiesce release remains blocked until durable recovery succeeds'
        cleanup_fixture
        return
    fi
    if [[ -f $ROOT/fork-deploy/quiesce-release-failed ]] \
        && grep -Fxq 'STATE=quiesce-release-failed' "$ROOT/fork-deploy/quiesce-release-failed" \
        && [[ -n $token && $status == *'FENCING_TOKEN=[redacted]'* && $status != *"$token"* ]] \
        && [[ -e $FORK_DEPLOY_QUIESCE_MARKER ]] \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && [[ $output == *'recover-quiesce'* ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        unset FORK_DEPLOY_FAIL_QUIESCE_RELEASE
        printf 'corrupt release signing key\n' >"$FORK_DEPLOY_MANIFEST_PUBLIC_KEY"
        export FORK_DEPLOY_DOCKER_UNAVAILABLE=true
        docker_info_before=$(grep -c '^docker info' "$LOG" || true)
        if "$SUBJECT" recover-quiesce >/dev/null \
            && [[ ! -e $ROOT/fork-deploy/quiesce-release-failed ]] \
            && [[ ! -e $FORK_DEPLOY_QUIESCE_MARKER ]] \
            && [[ $(grep -c '^docker info' "$LOG" || true) == "$docker_info_before" ]]; then
            pass 'failed backup-quiesce release remains blocked until durable recovery succeeds'
        else
            fail 'failed backup-quiesce release remains blocked until durable recovery succeeds'
        fi
    else
        fail 'failed backup-quiesce release remains blocked until durable recovery succeeds'
    fi
    cleanup_fixture
}

test_recover_abort_refuses_after_candidate_start() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'recover-abort refuses state after candidate startup'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_COMPOSE_UP=true
    if update_release >/dev/null 2>&1; then
        fail 'recover-abort refuses state after candidate startup'
        cleanup_fixture
        return
    fi
    local output
    if output=$("$SUBJECT" recover-abort 2>&1); then
        fail 'recover-abort refuses state after candidate startup'
    elif [[ $output == *'snapshot restore are forbidden after candidate startup'* \
        && $output == *'recover-forward'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && grep -Fxq 'STATE=failed-needs-forward-recovery' \
            "$ROOT/fork-deploy/failed-needs-restore"; then
        pass 'recover-abort refuses state after candidate startup'
    else
        fail 'recover-abort refuses state after candidate startup'
    fi
    cleanup_fixture
}

test_rejects_untrusted_caller_inputs
test_help_describes_quiesce_recovery
test_rejects_production_alternate_root
test_rejects_symlinked_root_and_authorized_key_ancestors
test_rejects_unsafe_state_lock
test_requires_compose_override_support
test_real_compose_config_when_available
test_production_trust_bootstrap_when_available
test_dry_run_is_non_mutating
test_status_does_not_mutate_authorized_keys
test_trust_is_production_only_without_deployment_preflight
test_install_records_signed_immutable_bundle
test_install_accepts_bare_fork_version_convention
test_install_rejects_zero_historical_suffix
test_accepts_real_ed25519_raw_signature
test_update_requires_verified_current_release
test_update_requires_healthy_current_runtime
test_repair_never_rewrites_verified_bundle
test_repair_rejects_corrupted_recorded_asset
test_help_and_parser_describe_forward_recovery
test_post_start_failure_has_forward_only_disposition
test_forward_recovery_preserves_writes_and_exact_candidate
test_forward_recovery_retries_one_sided_and_cleanup_states
test_forward_recovery_forbids_mismatch_abort_and_rollback
test_forward_recovery_reconciles_historical_rollback_activation
test_legacy_post_start_failure_without_bundle_fails_closed
test_invalid_ports_fail_before_activation
test_rejects_release_asset_contract_hash_mismatch
test_verify_rejects_all_unsafe_runtime_bindings
test_rejects_invalid_manifest_signature
test_rejects_out_of_order_manifest_schema
test_rejects_mismatched_manifest_source_tag
test_bootstrap_rejects_preexisting_symlink
test_rejects_hardlinked_privileged_state_before_chmod
test_update_requires_newer_unactivated_version
test_compatible_rollback_succeeds
test_incompatible_rollback_refuses_before_compose
test_update_rejects_previously_activated_target
test_update_records_complete_fresh_backup_before_compose
test_backup_gate_failures_prevent_candidate_start
test_adopts_only_complete_live_legacy_state
test_refuses_orphan_legacy_volume_without_complete_adoption
test_refuses_unmanaged_source_asset_without_complete_adoption
test_refuses_partial_unmanaged_state
test_prestart_failure_restores_exact_ssh_state
test_automatic_prestart_recovery_resumes_after_current_restore
test_requires_operator_recovery_key_before_mutation
test_prunes_only_expired_completed_backups
test_recover_abort_restores_only_prestart_pending_state
test_recover_abort_retries_after_restore_cleanup_interruption
test_quiesce_release_failure_requires_durable_recovery
test_recover_abort_refuses_after_candidate_start

printf '%s passing, %s failing\n' "$PASS" "$FAIL"
((FAIL == 0))
