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

staging_directories_absent() {
    local discovered

    [[ -d $ROOT/fork-deploy ]] || return 0
    discovered=$(find "$ROOT/fork-deploy" -maxdepth 1 -name 'staging.*' -print -quit)
    [[ -z $discovered ]]
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

    unset BASH_ENV COOLIFY_ENV_FILE
    base=$(cd -P "${TMPDIR:-/tmp}" && pwd -P)
    FIXTURE=$(mktemp -d "$base/fork-deploy.XXXXXX")
    ROOT=$FIXTURE/data/coolify
    BIN=$FIXTURE/bin
    LOG=$FIXTURE/commands.log
    ASSETS=$FIXTURE/assets
    MANIFEST_FILE=$FIXTURE/release.manifest
    mkdir -p "$BIN" "$ASSETS" "$FIXTURE/trust"
    : >"$LOG"
    for command in docker curl uname openssl sleep ssh-keygen chown; do
        ln -s "$TEST_DIR/fixtures/command" "$BIN/$command"
    done
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
    export FORK_DEPLOY_LEGACY_REALTIME_MARKER=$FIXTURE/legacy-realtime
    export FORK_DEPLOY_LEGACY_REALTIME_STOPPED_MARKER=$FIXTURE/legacy-realtime-stopped
    export FORK_DEPLOY_ACTIVE_SOURCE=$ROOT/source
    unset FORK_DEPLOY_MIGRATION_FINGERPRINT FORK_DEPLOY_HOST_IP FORK_DEPLOY_EXTRA_BINDING \
        FORK_DEPLOY_FAIL_COMPOSE_UP FORK_DEPLOY_OPENSSL_VERIFY_FAIL \
        FORK_DEPLOY_USE_REAL_OPENSSL FORK_DEPLOY_ENV_EXTRA \
        FORK_DEPLOY_COMPOSE_VERSION FORK_DEPLOY_LEGACY_VOLUMES \
        FORK_DEPLOY_APP_HOST_IP FORK_DEPLOY_PUSHER_HOST_IP FORK_DEPLOY_TERMINAL_HOST_IP \
        FORK_DEPLOY_APP_DUAL_STACK FORK_DEPLOY_COMPOSE_APP_LOOPBACK \
        FORK_DEPLOY_COMPOSE_OMIT_APP_HOST_IP \
        FORK_DEPLOY_COMPOSE_SWAP_BINDINGS \
        FORK_DEPLOY_REQUIRE_COMPOSE_ENV_PATH \
        FORK_DEPLOY_DOCKER_UNAVAILABLE \
        FORK_DEPLOY_FAIL_CANDIDATE_RUNTIME_VERIFY \
        FORK_DEPLOY_FAIL_LEGACY_RUNTIME_VERIFY \
        FORK_DEPLOY_FAIL_BUNDLED_ROUTE_PROOF \
        FORK_DEPLOY_FAIL_LEGACY_REALTIME_REMOVE \
        FORK_DEPLOY_LEGACY_CURRENT || true
    unset FORK_DEPLOY_FAIL_ACTIVATED_CONFIG FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG \
        FORK_DEPLOY_TEST_KILL_AFTER_RESTORE_PREVIOUS_CURRENT \
        FORK_DEPLOY_TEST_KILL_AFTER_PENDING_WRITE \
        FORK_DEPLOY_TEST_KILL_AFTER_FORWARD_RECORD \
        FORK_DEPLOY_TEST_SWAP_SSH_KEYS_DURING_LOCK \
        FORK_DEPLOY_TEST_SSH_SWAP_TARGET || true
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
    {
        printf 'services:\n'
        printf '  coolify:\n    image: "%s"\n    ports: !override\n' "docker.iocloudhost.net/williamagh/coolify@$DIGEST_A"
        printf '%s\n' "      - \"\${APP_PORT:-8000}:8080\""
        printf '%s\n' "      - \"127.0.0.1:\${PUSHER_PORT:-\${SOKETI_PORT:-6001}}:6001\""
        printf '%s\n' "      - \"127.0.0.1:\${TERMINAL_PORT:-6002}:6002\""
        printf '    environment:\n      AUTOUPDATE: "false"\n'
        printf '  postgres:\n    image: "%s"\n' "docker.io/library/postgres@$DIGEST_C"
        printf '  redis:\n    image: "%s"\n' "docker.io/library/redis@$DIGEST_D"
    } >"$ASSETS/docker-compose.custom.yml"
}

sign_manifest() {
    "$FORK_DEPLOY_REAL_OPENSSL" pkeyutl \
        -sign \
        -rawin \
        -inkey "$FIXTURE/trust/private.pem" \
        -in "$MANIFEST_FILE" \
        -out "$MANIFEST_FILE.sig"
}

write_v1_assets() {
    printf 'services: {}\n' >"$ASSETS/docker-compose.yml"
    printf 'services: {}\n' >"$ASSETS/docker-compose.prod.yml"
    printf 'DB_USERNAME=coolify\nDB_DATABASE=coolify\n' >"$ASSETS/.env.production"
    {
        printf 'services:\n'
        printf '  coolify:\n    image: "%s"\n    ports: !override\n' "docker.iocloudhost.net/williamagh/coolify@$DIGEST_A"
        printf '%s\n' "      - \"\${APP_PORT:-8000}:8080\""
        printf '  soketi:\n    image: "%s"\n    container_name: coolify-realtime\n    ports:\n' "docker.iocloudhost.net/williamagh/coolify-realtime@$DIGEST_B"
        printf '%s\n' "      - \"127.0.0.1:\${SOKETI_PORT:-6001}:6001\""
        printf '%s\n' '      - "127.0.0.1:6002:6002"'
        printf '  postgres:\n    image: "%s"\n' "docker.io/library/postgres@$DIGEST_C"
        printf '  redis:\n    image: "%s"\n' "docker.io/library/redis@$DIGEST_D"
    } >"$ASSETS/docker-compose.custom.yml"
}

write_v1_manifest() {
    local version=$1

    write_v1_assets
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
    } >"$MANIFEST_FILE"
    sign_manifest
}

seed_v1_current() {
    local version=$1 release activation rendered

    export FORK_DEPLOY_USE_REAL_OPENSSL=true
    export FORK_DEPLOY_LEGACY_CURRENT=true
    write_v1_manifest "$version"
    release=$ROOT/fork-deploy/releases/$version
    activation=$ROOT/fork-deploy/activations/$version
    mkdir -p "$release" "$ROOT/fork-deploy/activations" "$ROOT/fork-deploy/prestart" "$ROOT/source"
    chmod 0700 "$ROOT/fork-deploy" "$ROOT/fork-deploy/releases" "$release" \
        "$ROOT/fork-deploy/activations" "$ROOT/fork-deploy/prestart" "$ROOT/source"
    cp -p "$ASSETS/docker-compose.yml" "$ASSETS/docker-compose.prod.yml" \
        "$ASSETS/docker-compose.custom.yml" "$ASSETS/.env.production" "$release/"
    cp -p "$MANIFEST_FILE" "$release/release.manifest"
    cp -p "$MANIFEST_FILE.sig" "$release/release.manifest.sig"
    printf 'VERSION=%s\n' "$version" >"$release/verified"
    cp -p "$ASSETS/docker-compose.yml" "$ASSETS/docker-compose.prod.yml" \
        "$ASSETS/docker-compose.custom.yml" "$ASSETS/.env.production" "$ROOT/source/"
    {
        printf 'DB_USERNAME=coolify\n'
        printf 'DB_DATABASE=coolify\n'
        printf 'APP_PORT=8000\n'
        printf 'SOKETI_PORT=6001\n'
        printf 'AUTOUPDATE=false\n'
        printf 'VERSIONS_URL=http://127.0.0.1:9/fork-deploy-disabled/versions.json\n'
        printf 'UPGRADE_SCRIPT_URL=http://127.0.0.1:9/fork-deploy-disabled/upgrade.sh\n'
        printf 'RELEASES_URL=http://127.0.0.1:9/fork-deploy-disabled/releases\n'
        printf 'COOLIFY_FORK_VERSION=%s\n' "$version"
    } >"$ROOT/source/.env"
    rendered=$(hash_file "$ROOT/source/.env")
    {
        printf 'VERSION=%s\n' "$version"
        printf 'ACTIVATED_AT=2026-07-20T00:00:00Z\n'
        printf 'MIGRATION_FINGERPRINT_BEFORE=uninitialized\n'
        printf 'MIGRATION_FINGERPRINT_AFTER=uninitialized\n'
        printf 'RENDERED_COMPOSE_SHA256=%s\n' "$rendered"
    } >"$activation"
    printf '%s\n' "$version" >"$ROOT/fork-deploy/current"
    chmod 0600 "$release"/* "$release/.env.production" "$activation" \
        "$ROOT/fork-deploy/current" "$ROOT/source"/* "$ROOT/source/.env" "$ROOT/source/.env.production"
    : >"$FORK_DEPLOY_RUNTIME_MARKER"
    : >"$FORK_DEPLOY_DB_MARKER"
    : >"$FORK_DEPLOY_REDIS_MARKER"
    : >"$FORK_DEPLOY_LEGACY_REALTIME_MARKER"
    rm -f "$FORK_DEPLOY_CANDIDATE_STARTED_MARKER" "$FORK_DEPLOY_LEGACY_REALTIME_STOPPED_MARKER"
}

write_manifest() {
    local version=$1

    write_assets
    {
        printf 'SCHEMA=coolify-fork-release/v2\n'
        printf 'KEY_ID=%s\n' "$(manifest_key_id)"
        printf 'VERSION=%s\n' "$version"
        printf 'SOURCE_REVISION=%s\n' "$REVISION"
        printf 'SOURCE_TAG=%s\n' "$version"
        printf 'PLATFORM=linux/amd64\n'
        printf 'MAIN_IMAGE=docker.iocloudhost.net/williamagh/coolify\n'
        printf 'MAIN_INDEX_DIGEST=%s\n' "$DIGEST_A"
        printf 'MAIN_PLATFORM_DIGEST=%s\n' "$DIGEST_A"
        printf 'POSTGRES_IMAGE=docker.io/library/postgres\n'
        printf 'POSTGRES_DIGEST=%s\n' "$DIGEST_C"
        printf 'POSTGRES_MAJOR=16\n'
        printf 'REDIS_IMAGE=docker.io/library/redis\n'
        printf 'REDIS_DIGEST=%s\n' "$DIGEST_D"
        printf 'REDIS_MAJOR=7\n'
        printf 'MAIN_OCI_LABELS_SHA256=%064d\n' 1
        printf 'MAIN_SBOM_SHA256=%064d\n' 3
        printf 'MAIN_PROVENANCE_SHA256=%064d\n' 5
        printf 'DOCKERFILE_MAIN_SHA256=%064d\n' 7
        printf 'COMPOSE_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.yml")"
        printf 'COMPOSE_PROD_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.prod.yml")"
        printf 'COMPOSE_OVERLAY_SHA256=%s\n' "$(hash_file "$ASSETS/docker-compose.custom.yml")"
        printf 'ENV_PRODUCTION_SHA256=%s\n' "$(hash_file "$ASSETS/.env.production")"
    } >"$MANIFEST_FILE"
    if [[ ${FORK_DEPLOY_USE_REAL_OPENSSL:-false} == true ]]; then
        sign_manifest
    else
        : >"$MANIFEST_FILE.sig"
    fi
}

install_release() {
    "$SUBJECT" install --offline-manifest "$MANIFEST_FILE"
}

update_release() {
    "$SUBJECT" update --offline-manifest "$MANIFEST_FILE"
}

control_plane_listener_override_path() {
    printf '%s\n' "$ROOT/source/docker-compose.control-plane-listener.yml"
}

source_compose_mutations_apply_listener_override_last() {
    local override line suffix invocations=0

    override=$(control_plane_listener_override_path)
    while IFS= read -r line; do
        [[ $line == docker\ compose* && $line == *"$ROOT/source/docker-compose.yml"* && $line == *' up '* ]] || continue
        [[ $line == *"--file $override"* ]] || return 1
        suffix=${line#*"--file $override"}
        [[ $suffix != *'--file '* ]] || return 1
        invocations=$((invocations + 1))
    done <"$LOG"
    [[ $invocations -gt 0 ]]
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

test_install_and_update_are_self_contained() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'install and update need only signed release inputs'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if update_release >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 ]] \
        && [[ -z $(find "$ROOT/fork-deploy/prestart" -mindepth 1 -print -quit) ]] \
        && staging_directories_absent; then
        pass 'install and update need only signed release inputs'
    else
        fail 'install and update need only signed release inputs'
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
    local output staged_environment

    if [[ -z $REAL_DOCKER ]] || ! "$REAL_DOCKER" info >/dev/null 2>&1; then
        pass 'real Compose configuration test skipped because Docker is unavailable'
        return
    fi

    new_fixture
    write_assets
    printf 'APP_PORT=8010\nPUSHER_PORT=6011\nTERMINAL_PORT=6012\n' >"$FIXTURE/real-compose.env"
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
        && jq -e '
            ([.services[]?.ports[]?] | length) == 3
            and (.services.coolify.ports | length) == 3
            and ([.services.coolify.ports[]
                | select((.target | tostring) == "8080"
                    and (.published | tostring) == "8010"
                    and ((.host_ip // "") == ""
                        or .host_ip == "0.0.0.0"
                        or .host_ip == "::"))] | length) == 1
            and ([.services.coolify.ports[]
                | select((.target | tostring) == "6001"
                    and (.published | tostring) == "6011"
                    and .host_ip == "127.0.0.1")] | length) == 1
            and ([.services.coolify.ports[]
                | select((.target | tostring) == "6002"
                    and (.published | tostring) == "6012"
                    and .host_ip == "127.0.0.1")] | length) == 1
        ' <<<"$output" >/dev/null \
        && [[ $output != *'9999'* ]]; then
        pass 'real Compose config honors !override, public APP_PORT, and loopback Reverb and terminal ports'
    else
        fail 'real Compose config honors !override, public APP_PORT, and loopback Reverb and terminal ports'
    fi

    staged_environment="$FIXTURE/staged-compose.env"
    {
        printf 'APP_PORT=8010\n'
        printf 'PUSHER_PORT=6011\n'
        printf 'TERMINAL_PORT=6012\n'
        printf 'DB_USERNAME=coolify\n'
        printf 'DB_PASSWORD=staged-password\n'
        printf 'DB_DATABASE=coolify\n'
        printf 'REDIS_PASSWORD=staged-redis-password\n'
        printf 'STAGING_ENV_PROOF=selected\n'
    } >"$staged_environment"

    if output=$(
        COOLIFY_ENV_FILE="$staged_environment" \
            "$REAL_DOCKER" compose \
            --env-file "$staged_environment" \
            --file "$REPO_ROOT/docker-compose.yml" \
            --file "$REPO_ROOT/docker-compose.prod.yml" \
            --file "$ASSETS/docker-compose.custom.yml" \
            config --format json 2>&1
    ) \
        && jq -e --arg staged_environment "$staged_environment" '
            ([.services.coolify.volumes[]
                | select(.target == "/var/www/html/.env"
                    and .source == $staged_environment)] | length) == 1
            and .services.coolify.environment.STAGING_ENV_PROOF == "selected"
        ' <<<"$output" >/dev/null; then
        pass 'real production Compose selects the staged environment'
    else
        fail 'real production Compose selects the staged environment'
    fi

    if output=$(
        unset COOLIFY_ENV_FILE
        "$REAL_DOCKER" compose \
            --env-file "$staged_environment" \
            --file "$REPO_ROOT/docker-compose.yml" \
            --file "$REPO_ROOT/docker-compose.prod.yml" \
            config --no-env-resolution --format json 2>&1
    ) \
        && jq -e '
            ([.services.coolify.volumes[]
                | select(.target == "/var/www/html/.env"
                    and .source == "/data/coolify/source/.env")] | length) == 1
            and (.services.coolify.env_file | length) == 1
            and ((.services.coolify.env_file[0]
                | if type == "object" then .path else . end)
                == "/data/coolify/source/.env")
        ' <<<"$output" >/dev/null; then
        pass 'real production Compose preserves the generic environment default'
    else
        fail 'real production Compose preserves the generic environment default'
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
        && grep -Fxq 'AUTOUPDATE=false' "$ROOT/source/.env" \
        && grep -Fxq "chown root:root $ROOT" "$LOG" \
        && ! grep -Fxq "chown 9999:root $ROOT" "$LOG"; then
        pass 'install records a signed immutable release bundle'
    else
        fail 'install records a signed immutable release bundle'
    fi
    cleanup_fixture
}

test_fresh_install_selects_staged_compose_environment() {
    new_fixture
    write_manifest 4.13.0-fork.1
    export FORK_DEPLOY_REQUIRE_COMPOSE_ENV_PATH=true
    export COOLIFY_ENV_FILE="$FIXTURE/hostile-inherited.env"
    if install_release >/dev/null; then
        pass 'fresh install overrides inherited Compose environment with staging before activation'
    else
        fail 'fresh install overrides inherited Compose environment with staging before activation'
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

test_update_removes_exact_legacy_realtime_container() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'update removes the exact legacy realtime container'
        cleanup_fixture
        return
    fi
    : >"$FORK_DEPLOY_LEGACY_REALTIME_MARKER"
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if update_release >/dev/null \
        && [[ ! -e $FORK_DEPLOY_LEGACY_REALTIME_MARKER ]] \
        && grep -Fxq 'docker container inspect coolify-realtime' "$LOG" \
        && grep -Fxq 'docker container rm --force coolify-realtime' "$LOG"; then
        pass 'update removes the exact legacy realtime container'
    else
        fail 'update removes the exact legacy realtime container'
    fi
    cleanup_fixture
}

test_signed_v1_current_updates_one_way_to_v2() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    local stop_line reverb_line terminal_line remove_line first_up
    if update_release >/dev/null; then
        stop_line=$(grep -nFx 'docker container stop coolify-realtime' "$LOG" | head -1 | cut -d: -f1)
        reverb_line=$(grep -nF 'docker exec coolify curl --fail --silent --show-error http://127.0.0.1:6001/up' "$LOG" | head -1 | cut -d: -f1)
        terminal_line=$(grep -nF 'docker exec coolify curl --fail --silent --show-error http://127.0.0.1:6002/ready' "$LOG" | head -1 | cut -d: -f1)
        remove_line=$(grep -nFx 'docker container rm --force coolify-realtime' "$LOG" | head -1 | cut -d: -f1)
        first_up=$(grep -n 'docker compose .* up ' "$LOG" | head -1)
    fi
    if [[ ${stop_line:-0} -gt 0 \
        && ${reverb_line:-0} -gt $stop_line \
        && ${terminal_line:-0} -gt $stop_line \
        && ${remove_line:-0} -gt $reverb_line \
        && ${remove_line:-0} -gt $terminal_line \
        && ${first_up:-} != *--remove-orphans* \
        && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 \
        && ! -e $FORK_DEPLOY_LEGACY_REALTIME_MARKER \
        && -f $ROOT/fork-deploy/releases/4.13.0-fork.1/release.manifest ]]; then
        pass 'signed v1 current updates one-way to a proven v2 bundled runtime'
    else
        fail 'signed v1 current updates one-way to a proven v2 bundled runtime'
    fi
    cleanup_fixture
}

test_unsigned_v1_current_is_rejected() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    : >"$ROOT/fork-deploy/releases/4.13.0-fork.1/release.manifest.sig"
    : >"$LOG"
    local output
    if output=$("$SUBJECT" verify 2>&1); then
        fail 'unsigned v1 current is rejected before runtime mutation'
    elif [[ $output == *'signature verification failed'* \
        && $output != *'healthy and matches'* ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'unsigned v1 current is rejected before runtime mutation'
    else
        printf 'unsigned-v1 output: %s\n' "$output" >&2
        fail 'unsigned v1 current is rejected before runtime mutation'
    fi
    cleanup_fixture
}

test_tampered_v1_current_is_rejected() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    printf 'TAMPERED=true\n' >>"$ROOT/fork-deploy/releases/4.13.0-fork.1/release.manifest"
    : >"$LOG"
    local output
    if output=$("$SUBJECT" verify 2>&1); then
        fail 'tampered v1 current is rejected before runtime mutation'
    elif [[ $output == *'signature verification failed'* ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'tampered v1 current is rejected before runtime mutation'
    else
        printf 'tampered-v1 output: %s\n' "$output" >&2
        fail 'tampered v1 current is rejected before runtime mutation'
    fi
    cleanup_fixture
}

test_v1_asset_digest_mismatch_is_rejected() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    printf 'tampered\n' >>"$ROOT/fork-deploy/releases/4.13.0-fork.1/docker-compose.custom.yml"
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    local output
    if output=$(update_release 2>&1); then
        fail 'v1 current asset digests are verified before migration'
    elif [[ $output == *'recorded release asset hash mismatch: docker-compose.custom.yml'* \
        && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'v1 current asset digests are verified before migration'
    else
        fail 'v1 current asset digests are verified before migration'
    fi
    cleanup_fixture
}

test_v1_runtime_digest_mismatch_is_rejected() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_LEGACY_RUNTIME_VERIFY=true
    : >"$LOG"
    local output
    if output=$(update_release 2>&1); then
        unset FORK_DEPLOY_FAIL_LEGACY_RUNTIME_VERIFY
        fail 'v1 current runtime digests are verified before migration'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_LEGACY_RUNTIME_VERIFY
    if [[ $output == *'legacy coolify-realtime runs'* \
        && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'v1 current runtime digests are verified before migration'
    else
        fail 'v1 current runtime digests are verified before migration'
    fi
    cleanup_fixture
}

test_v1_candidate_is_rejected() {
    new_fixture
    export FORK_DEPLOY_USE_REAL_OPENSSL=true
    write_v1_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'v1 candidate manifests are rejected for new installs'
    elif [[ $output == *'v1 manifests are accepted only for the active predecessor of a v2 update'* \
        && ! -e $ROOT/fork-deploy/current ]]; then
        pass 'v1 candidate manifests are rejected for new installs'
    else
        fail 'v1 candidate manifests are rejected for new installs'
    fi
    cleanup_fixture
}

test_v1_rollback_is_rejected_after_migration() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    write_manifest 4.13.0-fork.2
    if ! update_release >/dev/null; then
        fail 'v1 recorded releases cannot be rollback targets after migration'
        cleanup_fixture
        return
    fi
    : >"$LOG"
    local output
    if output=$("$SUBJECT" rollback --version 4.13.0-fork.1 2>&1); then
        fail 'v1 recorded releases cannot be rollback targets after migration'
    elif [[ $output == *'v1 manifests are accepted only for the active predecessor of a v2 update'* \
        && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'v1 recorded releases cannot be rollback targets after migration'
    else
        printf 'v1-rollback output: %s\n' "$output" >&2
        fail 'v1 recorded releases cannot be rollback targets after migration'
    fi
    cleanup_fixture
}

test_legacy_removal_waits_for_bundled_route_proof() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_BUNDLED_ROUTE_PROOF=true
    : >"$LOG"
    if update_release >/dev/null 2>&1; then
        unset FORK_DEPLOY_FAIL_BUNDLED_ROUTE_PROOF
        fail 'legacy realtime removal waits for bundled route proof'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_BUNDLED_ROUTE_PROOF
    if [[ -e $FORK_DEPLOY_LEGACY_REALTIME_MARKER \
        && -e $ROOT/fork-deploy/forward-recovery ]] \
        && ! grep -Fxq 'docker container rm --force coolify-realtime' "$LOG"; then
        pass 'legacy realtime removal waits for bundled route proof'
    else
        fail 'legacy realtime removal waits for bundled route proof'
    fi
    cleanup_fixture
}

test_partial_legacy_removal_recovers_forward() {
    new_fixture
    seed_v1_current 4.13.0-fork.1
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_FAIL_LEGACY_REALTIME_REMOVE=true
    : >"$LOG"
    if update_release >/dev/null 2>&1; then
        unset FORK_DEPLOY_FAIL_LEGACY_REALTIME_REMOVE
        fail 'partial legacy realtime removal remains forward-recoverable'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_LEGACY_REALTIME_REMOVE
    local remove_line reverb_line recovered=false partial_state=false
    remove_line=$(grep -nFx 'docker container rm --force coolify-realtime' "$LOG" | head -1 | cut -d: -f1)
    reverb_line=$(grep -nF 'docker exec coolify curl --fail --silent --show-error http://127.0.0.1:6001/up' "$LOG" | head -1 | cut -d: -f1)
    if [[ -e $FORK_DEPLOY_LEGACY_REALTIME_MARKER \
        && -e $FORK_DEPLOY_LEGACY_REALTIME_STOPPED_MARKER \
        && -e $ROOT/fork-deploy/forward-recovery ]]; then
        partial_state=true
    fi
    if "$SUBJECT" recover-forward >/dev/null; then
        recovered=true
    fi
    if [[ ${remove_line:-0} -gt ${reverb_line:-0} \
        && $partial_state == true \
        && $recovered == true \
        && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 \
        && ! -e $FORK_DEPLOY_LEGACY_REALTIME_MARKER \
        && ! -e $ROOT/fork-deploy/forward-recovery ]]; then
        pass 'partial legacy realtime removal remains forward-recoverable'
    else
        fail 'partial legacy realtime removal remains forward-recoverable'
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
            && $output == *'Pre-start undo is forbidden'* ]] \
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
    local journal
    if ! prepare_post_start_failure; then
        fail 'post-start failure records a durable forward-only disposition'
        cleanup_fixture
        return
    fi
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/forward-recovery")
    if [[ ! -e $ROOT/fork-deploy/current \
        && -f $ROOT/fork-deploy/forward-recovery \
        && -f $ROOT/fork-deploy/pending-candidate \
        && -f $ROOT/fork-deploy/releases/4.13.0-fork.2/verified \
        && ! -e $journal ]] \
        && grep -Fxq 'STATE=forward-recovery-required' \
            "$ROOT/fork-deploy/forward-recovery" \
        && grep -Fxq 'CANDIDATE_VERSION=4.13.0-fork.2' \
            "$ROOT/fork-deploy/forward-recovery" \
        && grep -Fxq 'CANDIDATE_STARTED=true' "$ROOT/fork-deploy/pending-candidate" \
        && [[ $FORWARD_FAILURE_OUTPUT == *'Pre-start undo and automatic rollback are forbidden'* \
            && $FORWARD_FAILURE_OUTPUT == *'recover-forward'* ]] \
        && [[ $(grep -c 'compose .* up' "$LOG" || true) -eq 1 ]] \
        && staging_directories_absent; then
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
            && ! -e $ROOT/fork-deploy/forward-recovery \
            && ! -e $ROOT/fork-deploy/pending-candidate ]] \
        && [[ $(candidate_history_count) -eq 1 ]] \
        && [[ $(grep -c 'compose .* up' "$LOG" || true) -eq 1 ]] \
        && staging_directories_absent \
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
    local forward=$ROOT/fork-deploy/forward-recovery journal
    if ! prepare_post_start_failure; then
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi
    cp "$pending" "$FIXTURE/saved-pending"
    cp "$forward" "$FIXTURE/saved-forward"
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$forward")
    mkdir "$journal"
    chmod 0700 "$journal"
    mkdir "$journal/prestart-undo"
    chmod 0700 "$journal/prestart-undo"
    printf 'partially-finalized\n' >"$journal/prestart-undo/authorized_keys"
    printf 'partially-finalized\n' >"$journal/.env"
    chmod 0600 "$journal/prestart-undo/authorized_keys" "$journal/.env"
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"

    export FORK_DEPLOY_FAIL_COMPOSE_UP=true
    if "$SUBJECT" recover-forward >/dev/null 2>&1; then
        unset FORK_DEPLOY_FAIL_COMPOSE_UP
        fail 'forward recovery retries one-sided and interrupted cleanup states idempotently'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_FAIL_COMPOSE_UP
    if [[ ! -f $pending || ! -f $forward || -e $journal \
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
    if "$SUBJECT" recover-forward >/dev/null \
        && [[ $(candidate_history_count) -eq 1 \
            && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 \
            && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
            && ! -e $pending && ! -e $forward ]]; then
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
        && $abort_output == *'pre-start undo are forbidden'* \
        && $mismatch_output == *'state differs between durable records'* \
        && $current_output == *'found active release 4.13.0-fork.1'* \
        && $environment_output == *'does not identify the exact failed candidate'* \
        && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
        && -f $ROOT/fork-deploy/forward-recovery \
        && -f $pending ]] \
        && ! grep -q 'compose .* up' "$LOG" \
        ; then
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
            && ! -e $ROOT/fork-deploy/forward-recovery ]] \
        && "$SUBJECT" verify >/dev/null; then
        pass 'forward recovery replaces a historical rollback activation safely'
    else
        fail 'forward recovery replaces a historical rollback activation safely'
    fi
    cleanup_fixture
}

test_forward_recovery_requires_recorded_bundle() {
    new_fixture
    if ! prepare_post_start_failure; then
        fail 'forward recovery requires the exact recorded bundle'
        cleanup_fixture
        return
    fi
    mv "$ROOT/fork-deploy/releases/4.13.0-fork.2" \
        "$ROOT/fork-deploy/releases/4.13.0-fork.2.not-recorded"
    printf 'accepted-after-candidate-start\n' >"$ROOT/applications/post-start-write"
    : >"$LOG"
    local output
    if output=$("$SUBJECT" recover-forward 2>&1); then
        fail 'forward recovery requires the exact recorded bundle'
    elif [[ $output == *'forward recovery verified release marker'* \
        && $(<"$ROOT/applications/post-start-write") == accepted-after-candidate-start \
        && -f $ROOT/fork-deploy/forward-recovery \
        && -f $ROOT/fork-deploy/pending-candidate ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'forward recovery requires the exact recorded bundle'
    else
        fail 'forward recovery requires the exact recorded bundle'
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
    elif [[ $output == *'APP_PORT must be'* && ! -e $ROOT/source/docker-compose.yml ]] \
        && staging_directories_absent; then
        pass 'invalid APP_PORT fails before activation'
    else
        fail 'invalid APP_PORT fails before activation'
    fi
    cleanup_fixture
}

test_normalizes_legacy_pusher_app_port() {
    new_fixture
    export FORK_DEPLOY_ENV_EXTRA=$'PUSHER_PORT=8080\n'
    write_manifest 4.13.0-fork.1
    if install_release >/dev/null \
        && grep -Fxq 'PUSHER_PORT=6001' "$ROOT/source/.env" \
        && ! grep -Fxq 'PUSHER_PORT=8080' "$ROOT/source/.env"; then
        pass 'legacy PUSHER_PORT 8080 is normalized to the Reverb port'
    else
        fail 'legacy PUSHER_PORT 8080 is normalized to the Reverb port'
    fi
    cleanup_fixture
}

test_effective_compose_rejects_loopback_app_binding() {
    new_fixture
    export FORK_DEPLOY_COMPOSE_APP_LOOPBACK=true
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'effective Compose rejects a loopback APP_PORT binding'
    elif [[ $output == *'publish APP_PORT publicly and Reverb/terminal ports on loopback'* ]] \
        && [[ ! -e $ROOT/source/docker-compose.yml ]]; then
        pass 'effective Compose rejects a loopback APP_PORT binding'
    else
        fail 'effective Compose rejects a loopback APP_PORT binding'
    fi
    cleanup_fixture
}

test_effective_compose_accepts_default_public_app_binding() {
    new_fixture
    export FORK_DEPLOY_COMPOSE_OMIT_APP_HOST_IP=true
    write_manifest 4.13.0-fork.1
    if install_release >/dev/null; then
        pass 'effective Compose accepts the default public APP_PORT binding'
    else
        fail 'effective Compose accepts the default public APP_PORT binding'
    fi
    cleanup_fixture
}

test_effective_compose_rejects_swapped_bindings() {
    new_fixture
    export FORK_DEPLOY_COMPOSE_SWAP_BINDINGS=true
    write_manifest 4.13.0-fork.1
    local output
    if output=$(install_release 2>&1); then
        fail 'effective Compose associates host bindings with their service and target'
    elif [[ $output == *'publish APP_PORT publicly and Reverb/terminal ports on loopback'* ]] \
        && [[ ! -e $ROOT/source/docker-compose.yml ]]; then
        pass 'effective Compose associates host bindings with their service and target'
    else
        fail 'effective Compose associates host bindings with their service and target'
    fi
    cleanup_fixture
}

test_verify_accepts_dual_stack_public_app_binding() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'runtime verifier accepts distinct IPv4 and IPv6 APP_PORT bindings'
        cleanup_fixture
        return
    fi
    export FORK_DEPLOY_APP_DUAL_STACK=true
    if "$SUBJECT" verify >/dev/null 2>&1; then
        pass 'runtime verifier accepts distinct IPv4 and IPv6 APP_PORT bindings'
    else
        fail 'runtime verifier accepts distinct IPv4 and IPv6 APP_PORT bindings'
    fi
    cleanup_fixture
}

test_update_preserves_control_plane_listener_override() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'fork-deploy update preserves enrolled Traefik APP_PORT ownership'
        cleanup_fixture
        return
    fi
    local override source_hash output
    override=$(control_plane_listener_override_path)
    printf 'services:\n  coolify:\n    ports: !reset []\n' >"$override"
    chmod 600 "$override"
    source_hash=$(hash_file "$override")
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if output=$({ update_release && "$SUBJECT" verify; } 2>&1) \
        && [[ $(hash_file "$override") == "$source_hash" ]] \
        && source_compose_mutations_apply_listener_override_last; then
        pass 'fork-deploy update preserves enrolled Traefik APP_PORT ownership'
    else
        printf 'control-plane listener persistence diagnostic: %s\n' "$output" >&2
        fail 'fork-deploy update preserves enrolled Traefik APP_PORT ownership'
    fi
    cleanup_fixture
}

test_update_rejects_symlinked_control_plane_listener_override() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'fork-deploy rejects a symlinked control-plane listener override'
        cleanup_fixture
        return
    fi
    local override output
    override=$(control_plane_listener_override_path)
    printf 'services:\n  coolify:\n    ports: !reset []\n' >"$FIXTURE/listener-target.yml"
    ln -s "$FIXTURE/listener-target.yml" "$override"
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if output=$(update_release 2>&1); then
        fail 'fork-deploy rejects a symlinked control-plane listener override'
    elif [[ $output == *'symbolic link'* && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'fork-deploy rejects a symlinked control-plane listener override'
    else
        printf 'symlinked listener rejection diagnostic: %s\n' "$output" >&2
        fail 'fork-deploy rejects a symlinked control-plane listener override'
    fi
    cleanup_fixture
}

test_update_rejects_partial_control_plane_listener_override() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'fork-deploy rejects a partial control-plane listener override'
        cleanup_fixture
        return
    fi
    local override output
    override=$(control_plane_listener_override_path)
    printf 'services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_MEMBER: blue\n' >"$override"
    chmod 600 "$override"
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    if output=$(update_release 2>&1); then
        fail 'fork-deploy rejects a partial control-plane listener override'
    elif [[ $output == *'must reset Coolify ports'* && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && ! grep -q 'compose .* up' "$LOG"; then
        pass 'fork-deploy rejects a partial control-plane listener override'
    else
        printf 'partial listener rejection diagnostic: %s\n' "$output" >&2
        fail 'fork-deploy rejects a partial control-plane listener override'
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
    elif [[ $output == *'release manifest must begin with SCHEMA'* \
        || $output == *'unexpected, duplicate, or out-of-order field'* ]]; then
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

test_update_uses_private_temporary_prestart_undo() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'update journals exact pre-start state in a private temporary directory'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG=true
    : >"$LOG"
    if update_release >/dev/null 2>&1; then
        fail 'update journals exact pre-start state in a private temporary directory'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_KILL_ON_ACTIVE_CONFIG
    local journal
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    if [[ -d $journal && $(file_mode "$journal") == 700 \
        && $(file_mode "$journal/.env") == 600 \
        && $(file_mode "$journal/prestart-undo.manifest") == 600 \
        && ! -e $ROOT/fork-deploy/current \
        && ! -e $ROOT/fork-deploy/forward-recovery ]] \
        && ! grep -q 'compose .* up' "$LOG" \
        && "$SUBJECT" recover-abort >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
            && ! -e $journal ]]; then
        pass 'update journals exact pre-start state in a private temporary directory'
    else
        fail 'update journals exact pre-start state in a private temporary directory'
    fi
    cleanup_fixture
}


test_refuses_complete_unmanaged_state_before_mutation() {
    new_fixture
    mkdir -p "$ROOT/source" "$ROOT/ssh/keys" "$ROOT/ssh/mux" "$ROOT/applications" \
        "$ROOT/backups" "$ROOT/control-plane-attestor" "$ROOT/databases" "$ROOT/proxy/dynamic" \
        "$ROOT/sentinel" "$ROOT/services"
    printf 'DB_USERNAME=coolify\nDB_DATABASE=coolify\nREDIS_PASSWORD=legacy-secret\n' >"$ROOT/source/.env"
    chmod 0600 "$ROOT/source/.env"
    : >"$FORK_DEPLOY_DB_MARKER"
    : >"$FORK_DEPLOY_REDIS_MARKER"
    export FORK_DEPLOY_LEGACY_VOLUMES=$'coolify-db\ncoolify-redis'
    write_manifest 4.13.0-fork.1
    : >"$LOG"
    local output before
    before=$(hash_file "$ROOT/source/.env")
    if output=$(install_release 2>&1); then
        fail 'complete unmanaged state requires control-plane migration before mutation'
    elif [[ $output == *'control-plane migration and enrollment path'* \
        && $(hash_file "$ROOT/source/.env") == "$before" \
        && ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'compose .* up\|ssh-keygen' "$LOG"; then
        pass 'complete unmanaged state requires control-plane migration before mutation'
    else
        fail 'complete unmanaged state requires control-plane migration before mutation'
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
    elif [[ $output == *'control-plane migration and enrollment path'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'compose .* up\|ssh-keygen' "$LOG"; then
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
    elif [[ $output == *'control-plane migration and enrollment path'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'compose .* up\|ssh-keygen' "$LOG"; then
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
    elif [[ $output == *'control-plane migration and enrollment path'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && ! grep -q 'compose .* up\|ssh-keygen' "$LOG"; then
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
        && ! -e $ROOT/fork-deploy/forward-recovery ]] \
        && [[ $output == *'exact prior configuration and SSH state were restored'* ]]; then
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
    local output journal recovery_output recovery_succeeded=false interrupted_state_valid=false
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
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    interrupted_current=$(<"$ROOT/fork-deploy/current")
    interrupted_phase=$(awk -F= '$1 == "STATE" { print $2 }' "$ROOT/fork-deploy/pending-candidate")
    interrupted_candidate=$(awk -F= '$1 == "CANDIDATE_VERSION" { print $2 }' "$ROOT/fork-deploy/pending-candidate")
    if [[ $interrupted_current == 4.13.0-fork.1 \
        && $interrupted_phase == recover-abort-restoring \
        && $interrupted_candidate == 4.13.0-fork.2 \
        && ! -e $ROOT/fork-deploy/forward-recovery ]]; then
        interrupted_state_valid=true
    fi
    unset FORK_DEPLOY_FAIL_ACTIVATED_CONFIG FORK_DEPLOY_TEST_KILL_AFTER_RESTORE_PREVIOUS_CURRENT
    if recovery_output=$("$SUBJECT" recover-abort 2>&1); then
        recovery_succeeded=true
    fi
    if [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
        && $interrupted_state_valid == true \
        && $recovery_succeeded == true \
        && ! -e $ROOT/fork-deploy/forward-recovery \
        && ! -e $ROOT/fork-deploy/pending-candidate \
        && ! -e $journal ]]; then
        pass 'automatic pre-start recovery resumes after restoring the current pointer'
    else
        printf 'recover-abort output: %s\n' "$recovery_output" >&2
        fail 'automatic pre-start recovery resumes after restoring the current pointer'
    fi
    cleanup_fixture
}

test_recover_abort_accepts_only_exact_previous_pointer_after_pending_write() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'recover-abort reconciles a crash between pending state and current-pointer clear'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_TEST_KILL_AFTER_PENDING_WRITE=true
    if update_release >/dev/null 2>&1; then
        fail 'recover-abort reconciles a crash between pending state and current-pointer clear'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_TEST_KILL_AFTER_PENDING_WRITE

    local journal refusal recovery_output staging_before=false
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    if ! staging_directories_absent; then
        staging_before=true
    fi
    printf '4.13.0-fork.2\n' >"$ROOT/fork-deploy/current"
    refusal=$("$SUBJECT" recover-abort 2>&1 || true)
    printf '4.13.0-fork.1\n' >"$ROOT/fork-deploy/current"

    if recovery_output=$("$SUBJECT" recover-abort 2>&1) \
        && [[ $staging_before == true \
            && $refusal == *'expected prior release 4.13.0-fork.1'* \
            && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 \
            && ! -e $ROOT/fork-deploy/pending-candidate \
            && ! -e $ROOT/fork-deploy/forward-recovery \
            && ! -e $journal ]] \
        && staging_directories_absent; then
        pass 'recover-abort reconciles a crash between pending state and current-pointer clear'
    else
        printf 'recover-abort output: %s\n' "$recovery_output" >&2
        fail 'recover-abort reconciles a crash between pending state and current-pointer clear'
    fi
    cleanup_fixture
}

test_forward_record_reconciles_stale_prestart_pending_state() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'forward recovery treats its durable record as authoritative over stale pending state'
        cleanup_fixture
        return
    fi
    write_manifest 4.13.0-fork.2
    : >"$LOG"
    export FORK_DEPLOY_TEST_KILL_AFTER_FORWARD_RECORD=true
    if update_release >/dev/null 2>&1; then
        fail 'forward recovery treats its durable record as authoritative over stale pending state'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_TEST_KILL_AFTER_FORWARD_RECORD

    local journal staging_before=false
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/forward-recovery")
    if ! staging_directories_absent; then
        staging_before=true
    fi
    if grep -Fxq 'CANDIDATE_STARTED=false' "$ROOT/fork-deploy/pending-candidate" \
        && grep -Fxq 'STATE=forward-recovery-required' "$ROOT/fork-deploy/forward-recovery" \
        && "$SUBJECT" recover-forward >/dev/null \
        && [[ $staging_before == true \
            && $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 \
            && ! -e $ROOT/fork-deploy/pending-candidate \
            && ! -e $ROOT/fork-deploy/forward-recovery \
            && ! -e $journal \
            && $(grep -c 'compose .* up' "$LOG" || true) -eq 1 ]] \
        && staging_directories_absent; then
        pass 'forward recovery treats its durable record as authoritative over stale pending state'
    else
        fail 'forward recovery treats its durable record as authoritative over stale pending state'
    fi
    cleanup_fixture
}

test_privileged_ssh_mutation_rejects_directory_swap() {
    new_fixture
    write_manifest 4.13.0-fork.1
    if ! install_release >/dev/null; then
        fail 'privileged SSH mutation rejects a deterministic key-directory swap'
        cleanup_fixture
        return
    fi
    mkdir "$FIXTURE/swap-target"
    printf 'outside-unchanged\n' >"$FIXTURE/swap-target/sentinel"
    write_manifest 4.13.0-fork.2
    export FORK_DEPLOY_TEST_SWAP_SSH_KEYS_DURING_LOCK=true
    export FORK_DEPLOY_TEST_SSH_SWAP_TARGET=$FIXTURE/swap-target

    local output
    if output=$(update_release 2>&1); then
        fail 'privileged SSH mutation rejects a deterministic key-directory swap'
        cleanup_fixture
        return
    fi
    unset FORK_DEPLOY_TEST_SWAP_SSH_KEYS_DURING_LOCK FORK_DEPLOY_TEST_SSH_SWAP_TARGET
    if [[ $output != *'contains a symbolic link'* \
        || $(<"$FIXTURE/swap-target/sentinel") != outside-unchanged \
        || $(<"$ROOT/fork-deploy/current") != 4.13.0-fork.1 \
        || -e $ROOT/fork-deploy/pending-candidate \
        || -e $ROOT/fork-deploy/forward-recovery ]] \
        || ! staging_directories_absent; then
        fail 'privileged SSH mutation rejects a deterministic key-directory swap'
        cleanup_fixture
        return
    fi

    rm "$ROOT/ssh/keys"
    mv "$ROOT/ssh/keys.raced" "$ROOT/ssh/keys"
    if update_release >/dev/null \
        && [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.2 ]] \
        && staging_directories_absent; then
        pass 'privileged SSH mutation rejects a deterministic key-directory swap'
    else
        fail 'privileged SSH mutation rejects a deterministic key-directory swap'
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
    local journal expected_env_hash guidance
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    expected_env_hash=$(hash_file "$journal/.env")
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
        && [[ ! -e $journal ]] \
        && staging_directories_absent \
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
    local journal output
    journal=$(awk -F= '$1 == "PRESTART_UNDO" { print substr($0, length($1) + 2) }' \
        "$ROOT/fork-deploy/pending-candidate")
    chmod 0500 "$journal/prestart-undo"
    if output=$("$SUBJECT" recover-abort 2>&1); then
        fail 'recover-abort is idempotent after restore cleanup interruption'
        chmod 0700 "$journal/prestart-undo"
        cleanup_fixture
        return
    fi
    chmod 0700 "$journal/prestart-undo"
    if [[ $(<"$ROOT/fork-deploy/current") == 4.13.0-fork.1 ]] \
        && grep -Fxq 'STATE=recover-abort-cleaning' "$ROOT/fork-deploy/pending-candidate" \
        && "$SUBJECT" recover-abort >/dev/null \
        && [[ ! -e $ROOT/fork-deploy/pending-candidate ]] \
        && [[ ! -e $journal ]]; then
        pass 'recover-abort is idempotent after restore cleanup interruption'
    else
        fail 'recover-abort is idempotent after restore cleanup interruption'
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
    elif [[ $output == *'pre-start undo are forbidden after candidate startup'* \
        && $output == *'recover-forward'* ]] \
        && [[ ! -e $ROOT/fork-deploy/current ]] \
        && grep -Fxq 'STATE=forward-recovery-required' \
            "$ROOT/fork-deploy/forward-recovery"; then
        pass 'recover-abort refuses state after candidate startup'
    else
        fail 'recover-abort refuses state after candidate startup'
    fi
    cleanup_fixture
}

test_uses_migrated_github_raw_base() {
    if grep -Fxq 'GITHUB_RAW_BASE=https://raw.githubusercontent.com/williamacallahan/coolify' "$SUBJECT"; then
        pass 'fork deployment downloads release assets from the migrated GitHub repository'
    else
        fail 'fork deployment does not use the migrated GitHub raw base'
    fi
}

if [[ ${FORK_DEPLOY_TEST_FILTER:-} == port-normalization ]]; then
    test_normalizes_legacy_pusher_app_port
    printf '%s passing, %s failing\n' "$PASS" "$FAIL"
    ((FAIL == 0))
    exit
fi

if [[ ${FORK_DEPLOY_TEST_FILTER:-} == legacy-v1-bridge ]]; then
    test_signed_v1_current_updates_one_way_to_v2
    test_unsigned_v1_current_is_rejected
    test_tampered_v1_current_is_rejected
    test_v1_asset_digest_mismatch_is_rejected
    test_v1_runtime_digest_mismatch_is_rejected
    test_v1_candidate_is_rejected
    test_v1_rollback_is_rejected_after_migration
    test_legacy_removal_waits_for_bundled_route_proof
    test_partial_legacy_removal_recovers_forward
    printf '%s passing, %s failing\n' "$PASS" "$FAIL"
    ((FAIL == 0))
    exit
fi

if [[ ${FORK_DEPLOY_TEST_FILTER:-} == control-plane-listener ]]; then
    test_update_preserves_control_plane_listener_override
    test_update_rejects_symlinked_control_plane_listener_override
    test_update_rejects_partial_control_plane_listener_override
    printf '%s passing, %s failing\n' "$PASS" "$FAIL"
    ((FAIL == 0))
    exit
fi

if [[ ${FORK_DEPLOY_TEST_FILTER:-} == fresh-staging-env ]]; then
    test_fresh_install_selects_staged_compose_environment
    printf '%s passing, %s failing\n' "$PASS" "$FAIL"
    ((FAIL == 0))
    exit
fi

test_rejects_untrusted_caller_inputs
test_uses_migrated_github_raw_base
test_install_and_update_are_self_contained
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
test_fresh_install_selects_staged_compose_environment
test_install_accepts_bare_fork_version_convention
test_install_rejects_zero_historical_suffix
test_accepts_real_ed25519_raw_signature
test_update_requires_verified_current_release
test_update_requires_healthy_current_runtime
test_update_removes_exact_legacy_realtime_container
test_signed_v1_current_updates_one_way_to_v2
test_unsigned_v1_current_is_rejected
test_tampered_v1_current_is_rejected
test_v1_asset_digest_mismatch_is_rejected
test_v1_runtime_digest_mismatch_is_rejected
test_v1_candidate_is_rejected
test_v1_rollback_is_rejected_after_migration
test_legacy_removal_waits_for_bundled_route_proof
test_partial_legacy_removal_recovers_forward
test_repair_never_rewrites_verified_bundle
test_repair_rejects_corrupted_recorded_asset
test_help_and_parser_describe_forward_recovery
test_post_start_failure_has_forward_only_disposition
test_forward_recovery_preserves_writes_and_exact_candidate
test_forward_recovery_retries_one_sided_and_cleanup_states
test_forward_recovery_forbids_mismatch_abort_and_rollback
test_forward_recovery_reconciles_historical_rollback_activation
test_forward_recovery_requires_recorded_bundle
test_invalid_ports_fail_before_activation
test_normalizes_legacy_pusher_app_port
test_effective_compose_rejects_loopback_app_binding
test_effective_compose_accepts_default_public_app_binding
test_effective_compose_rejects_swapped_bindings
test_verify_accepts_dual_stack_public_app_binding
test_update_preserves_control_plane_listener_override
test_update_rejects_symlinked_control_plane_listener_override
test_update_rejects_partial_control_plane_listener_override
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
test_update_uses_private_temporary_prestart_undo
test_refuses_complete_unmanaged_state_before_mutation
test_refuses_orphan_legacy_volume_without_complete_adoption
test_refuses_unmanaged_source_asset_without_complete_adoption
test_refuses_partial_unmanaged_state
test_prestart_failure_restores_exact_ssh_state
test_automatic_prestart_recovery_resumes_after_current_restore
test_recover_abort_accepts_only_exact_previous_pointer_after_pending_write
test_forward_record_reconciles_stale_prestart_pending_state
test_privileged_ssh_mutation_rejects_directory_swap
test_recover_abort_restores_only_prestart_pending_state
test_recover_abort_retries_after_restore_cleanup_interruption
test_recover_abort_refuses_after_candidate_start

printf '%s passing, %s failing\n' "$PASS" "$FAIL"
((FAIL == 0))
