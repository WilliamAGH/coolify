#!/usr/bin/env bash

set -euo pipefail

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
state="$(mktemp -d "${TMPDIR:-/tmp}/coolify-docker-ssot-clean.XXXXXX")"
mock_bin="$state/bin"
command_log="$state/commands.log"

fail() {
    printf 'DOCKER_SSOT_CLEAN_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

cleanup() {
    rm -rf "$state"
}

assert_logged() {
    local expected="$1"

    grep -Fx "$expected" "$command_log" >/dev/null || fail "missing Docker operation: $expected"
}

assert_not_logged() {
    local unexpected="$1"

    grep -Fx "$unexpected" "$command_log" >/dev/null && fail "unexpected Docker operation: $unexpected"
}

assert_removed() {
    local kind="$1" resource="$2"

    [[ ! -e "$state/resources/$kind/$resource" ]] || fail "targeted $kind resource remains: $resource"
}

assert_preserved() {
    local kind="$1" resource="$2"

    [[ -e "$state/resources/$kind/$resource" ]] || fail "unrelated $kind resource was removed: $resource"
}

trap cleanup EXIT
mkdir -p \
    "$mock_bin" \
    "$state/resources/containers" \
    "$state/resources/volumes" \
    "$state/resources/networks" \
    "$state/resources/builders" \
    "$state/resources/images"
: > "$command_log"
touch \
    "$state/resources/containers/cpbg-container" \
    "$state/resources/containers/production-container" \
    "$state/resources/containers/labelled-container" \
    "$state/resources/containers/customer-container" \
    "$state/resources/containers/stopped-postgres" \
    "$state/resources/volumes/cpbg-volume" \
    "$state/resources/volumes/production-volume" \
    "$state/resources/volumes/labelled-volume" \
    "$state/resources/volumes/customer-volume" \
    "$state/resources/volumes/anonymous-volume" \
    "$state/resources/networks/cpbg-network" \
    "$state/resources/networks/production-network" \
    "$state/resources/networks/labelled-network" \
    "$state/resources/networks/customer-network" \
    "$state/resources/builders/unrelated-builder" \
    "$state/resources/images/customer-image"

cat > "$mock_bin/docker" <<'SH'
#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'FAKE_DOCKER_FAILURE %s\n' "$1" >&2
    exit 95
}

log() {
    printf '%s\n' "$1" >> "${DOCKER_SSOT_COMMAND_LOG:?}"
}

remove_resource() {
    local kind="$1" resource="$2"

    rm -f "${DOCKER_SSOT_RESOURCE_STATE:?}/$kind/$resource"
}

fail_if_requested() {
    local operation="$1"

    if [[ "${DOCKER_SSOT_FAIL_OPERATION:-}" == "$operation" ]]; then
        log "forced-failure:$operation"
        exit 96
    fi
}

option_value() {
    local option="$1"
    shift

    while (( $# > 0 )); do
        if [[ "$1" == "$option" ]]; then
            printf '%s\n' "${2:-}"
            return 0
        fi
        shift
    done
}

last_argument() {
    local argument last=''

    for argument in "$@"; do
        last="$argument"
    done
    printf '%s\n' "$last"
}

case "${1:-}:${2:-}" in
    info:*)
        log 'info'
        ;;
    buildx:inspect)
        log "buildx-inspect:$(last_argument "$@")"
        ;;
    buildx:ls)
        log 'buildx-ls'
        printf '%s\n' 'orbstack' 'unrelated-builder'
        ;;
    buildx:create)
        log 'buildx-create'
        ;;
    buildx:rm)
        resource="$(last_argument "$@")"
        log "buildx-rm:$resource"
        remove_resource builders "$resource"
        exit 97
        ;;
    ps:-a)
        log 'compose-project-census:containers'
        fail_if_requested 'compose-project-census:containers'
        printf '%s\n' \
            'cpbg-run-42' \
            'production-application-blue-green-run-42' \
            'customer-project' \
            'coolify'
        ;;
    ps:-aq)
        filter="$(option_value --filter "$@")"
        log "container-selector:${filter}"
        case "$filter" in
            'label=com.docker.compose.project=cpbg-run-42')
                printf '%s\n' 'cpbg-container'
                ;;
            'label=com.docker.compose.project=production-application-blue-green-run-42')
                printf '%s\n' 'production-container'
                ;;
            'label=coolify.integration.ephemeral=true')
                printf '%s\n' 'labelled-container'
                ;;
            'label=com.docker.compose.project=customer-project'|'label=com.docker.compose.project=coolify')
                fail "unrelated container selector reached Docker: $filter"
                ;;
            *)
                fail "unexpected container selector: $filter"
                ;;
        esac
        ;;
    ps:*)
        log 'container-status'
        ;;
    rm:*)
        resource="$(last_argument "$@")"
        log "container-rm:$resource"
        fail_if_requested "container-rm:$resource"
        remove_resource containers "$resource"
        ;;
    volume:ls)
        filter="$(option_value --filter "$@")"
        if [[ -z "$filter" ]]; then
            if [[ " $* " == *' --format '* ]]; then
                log 'compose-project-census:volumes'
                fail_if_requested 'compose-project-census:volumes'
                printf '%s\n' \
                    'cpbg-run-42' \
                    'production-application-blue-green-run-42' \
                    'customer-project'
            else
                log 'volume-status'
            fi
            exit 0
        fi
        log "volume-selector:${filter}"
        case "$filter" in
            'label=com.docker.compose.project=cpbg-run-42')
                printf '%s\n' 'cpbg-volume'
                ;;
            'label=com.docker.compose.project=production-application-blue-green-run-42')
                printf '%s\n' 'production-volume'
                ;;
            'label=coolify.integration.ephemeral=true')
                printf '%s\n' 'labelled-volume'
                ;;
            'label=com.docker.compose.project=customer-project')
                fail "unrelated volume selector reached Docker: $filter"
                ;;
            *)
                fail "unexpected volume selector: $filter"
                ;;
        esac
        ;;
    volume:rm)
        resource="$(last_argument "$@")"
        log "volume-rm:$resource"
        fail_if_requested "volume-rm:$resource"
        remove_resource volumes "$resource"
        ;;
    volume:prune)
        log 'volume-prune'
        exit 97
        ;;
    network:ls)
        filter="$(option_value --filter "$@")"
        if [[ -z "$filter" ]]; then
            log 'compose-project-census:networks'
            fail_if_requested 'compose-project-census:networks'
            printf '%s\n' \
                'cpbg-run-42' \
                'production-application-blue-green-run-42' \
                'customer-project'
            exit 0
        fi
        log "network-selector:${filter}"
        case "$filter" in
            'label=com.docker.compose.project=cpbg-run-42')
                printf '%s\n' 'cpbg-network'
                ;;
            'label=com.docker.compose.project=production-application-blue-green-run-42')
                printf '%s\n' 'production-network'
                ;;
            'label=coolify.integration.ephemeral=true')
                printf '%s\n' 'labelled-network'
                ;;
            'label=com.docker.compose.project=customer-project')
                fail "unrelated network selector reached Docker: $filter"
                ;;
            *)
                fail "unexpected network selector: $filter"
                ;;
        esac
        ;;
    network:rm)
        resource="$(last_argument "$@")"
        log "network-rm:$resource"
        fail_if_requested "network-rm:$resource"
        remove_resource networks "$resource"
        ;;
    network:prune)
        log 'network-prune'
        exit 97
        ;;
    images:*)
        log 'images-status'
        ;;
    image:rm)
        resource="$(last_argument "$@")"
        log "image-rm:$resource"
        remove_resource images "$resource"
        exit 97
        ;;
    image:prune)
        log 'image-prune'
        exit 97
        ;;
    container:prune)
        log 'container-prune'
        exit 97
        ;;
    builder:prune)
        log 'builder-prune'
        exit 97
        ;;
    system:df)
        log 'system-df'
        ;;
    *)
        fail "unexpected Docker invocation: $*"
        ;;
esac
SH
chmod +x "$mock_bin/docker"

export DOCKER_SSOT_COMMAND_LOG="$command_log"
export DOCKER_SSOT_RESOURCE_STATE="$state/resources"
export PATH="$mock_bin:$PATH"

bash "$repository_root/scripts/dev/docker-ssot-clean.sh" clean >/dev/null

for operation in \
    'container-rm:cpbg-container' \
    'container-rm:production-container' \
    'container-rm:labelled-container' \
    'volume-rm:cpbg-volume' \
    'volume-rm:production-volume' \
    'volume-rm:labelled-volume' \
    'network-rm:cpbg-network' \
    'network-rm:production-network' \
    'network-rm:labelled-network'; do
    assert_logged "$operation"
done

for resource in cpbg-container production-container labelled-container; do
    assert_removed containers "$resource"
done
for resource in cpbg-volume production-volume labelled-volume; do
    assert_removed volumes "$resource"
done
for resource in cpbg-network production-network labelled-network; do
    assert_removed networks "$resource"
done

for operation in \
    'container-rm:customer-container' \
    'container-rm:stopped-postgres' \
    'volume-rm:customer-volume' \
    'volume-rm:anonymous-volume' \
    'network-rm:customer-network' \
    'buildx-create' \
    'buildx-rm:unrelated-builder' \
    'image-rm:customer-image' \
    'container-prune' \
    'image-prune' \
    'builder-prune' \
    'volume-prune' \
    'network-prune'; do
    assert_not_logged "$operation"
done

for resource in customer-container stopped-postgres; do
    assert_preserved containers "$resource"
done
for resource in customer-volume anonymous-volume; do
    assert_preserved volumes "$resource"
done
assert_preserved networks customer-network
assert_preserved builders unrelated-builder
assert_preserved images customer-image

touch "$state/resources/containers/labelled-container"
export DOCKER_SSOT_FAIL_OPERATION='container-rm:labelled-container'
if bash "$repository_root/scripts/dev/docker-ssot-clean.sh" clean >/dev/null 2>&1; then
    fail 'cleanup succeeded after Docker rejected a targeted container removal'
fi
unset DOCKER_SSOT_FAIL_OPERATION
assert_preserved containers labelled-container

export DOCKER_SSOT_FAIL_OPERATION='compose-project-census:containers'
if bash "$repository_root/scripts/dev/docker-ssot-clean.sh" clean >/dev/null 2>&1; then
    fail 'cleanup succeeded after the Compose project census failed'
fi
unset DOCKER_SSOT_FAIL_OPERATION

printf 'Docker SSOT clean targets only explicitly identified Coolify lab resources.\n'
