#!/bin/sh

set -eu

state_file=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE:?}
log_file=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE:?}
proxy_container=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_CONTAINER:?}
blue_container=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_BLUE_CONTAINER:?}
proxy_project=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_PROJECT:?}
native_proxy_compose=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_NATIVE_COMPOSE:?}
legacy_proxy_compose=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_LEGACY_COMPOSE:?}
static_config=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATIC_CONFIG:?}
native_static_config=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_NATIVE_STATIC_CONFIG:?}
legacy_static_config=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LEGACY_STATIC_CONFIG:?}
compose_override=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_COMPOSE_OVERRIDE:?}
dynamic_directory=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_DYNAMIC_DIRECTORY:?}
source_compose_files=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES:?}
enrollment_action=${CONTROL_PLANE_PROXY_ENROLLMENT_ACTION:?}
operation_id=${CONTROL_PLANE_PROXY_ENROLLMENT_OPERATION:?}
enrollment_token=${CONTROL_PLANE_PROXY_ENROLLMENT_TOKEN:?}
app_port=${CONTROL_PLANE_PROXY_ENROLLMENT_APP_PORT:?}
public_url=${CONTROL_PLANE_PROXY_ENROLLMENT_PUBLIC_URL:?}
public_host=${CONTROL_PLANE_PROXY_ENROLLMENT_PUBLIC_HOST:?}
dynamic_filename=${CONTROL_PLANE_PROXY_ENROLLMENT_DYNAMIC_FILENAME:?}
dynamic_sha256=${CONTROL_PLANE_PROXY_ENROLLMENT_DYNAMIC_SHA256:-}
operator_pid=${CONTROL_PLANE_PROXY_ENROLLMENT_OPERATOR_PID:?}
enrollment_mode=${CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE:-normal}

fail()
{
    exit 1
}

state_value()
{
    state_key=$1
    [ -f "$state_file" ] || return 1
    awk -F= -v key="$state_key" '
        $1 == key {
            count++
            value = substr($0, index($0, "=") + 1)
        }
        END {
            if (count == 1) {
                print value
                exit 0
            }
            exit 1
        }
    ' "$state_file"
}

local_url_for_public_url()
{
    public_authority_and_path=${public_url#https://}
    case "$public_authority_and_path" in
        */*) public_path=/${public_authority_and_path#*/} ;;
        *) public_path=/ ;;
    esac
    printf 'http://127.0.0.1:%s%s\n' "$app_port" "$public_path"
}

write_state()
{
    next_phase=$1
    stored_override_sha256=$(state_value compose_override_sha256 2>/dev/null || true)
    if [ -f "$compose_override" ] && [ ! -L "$compose_override" ]; then
        stored_override_sha256=$(sha256sum "$compose_override" | awk '{print $1}')
    fi
    [ -n "$stored_override_sha256" ] || fail
    candidate_state="$state_file.tmp.$$"
    umask 077
    {
        printf 'phase=%s\n' "$next_phase"
        printf 'operation_id=%s\n' "$operation_id"
        printf 'token_sha256=%s\n' "$(printf '%s' "$enrollment_token" | sha256sum | awk '{print $1}')"
        printf 'compose_override_sha256=%s\n' "$stored_override_sha256"
    } > "$candidate_state"
    chmod 600 "$candidate_state"
    mv "$candidate_state" "$state_file"
}

record_action()
{
    umask 077
    printf '%s\n' "$enrollment_action" >> "$log_file"
    chmod 600 "$log_file"
}

emit_absent()
{
    printf '%s\n' '{"ok":false,"error":"No exact token-owned managed proxy enrollment state exists."}'
}

emit_success()
{
    response_phase=$1
    token_sha256=$(printf '%s' "$enrollment_token" | sha256sum | awk '{print $1}')
    state_token_sha256=$(state_value token_sha256 2>/dev/null || true)
    [ -z "$state_token_sha256" ] || [ "$state_token_sha256" = "$token_sha256" ] || fail
    state_operation_id=$(state_value operation_id 2>/dev/null || true)
    [ -z "$state_operation_id" ] || [ "$state_operation_id" = "$operation_id" ] || fail
    override_sha256=$(state_value compose_override_sha256 2>/dev/null || true)
    if [ -f "$compose_override" ] && [ ! -L "$compose_override" ]; then
        override_sha256=$(sha256sum "$compose_override" | awk '{print $1}')
    fi
    [ -n "$override_sha256" ] || fail
    static_config_sha256=$(sha256sum "$native_static_config" | awk '{print $1}')
    rendered_config_sha256=$(printf '%s\n' "$source_compose_files" | sha256sum | awk '{print $1}')
    proof_fingerprint_sha256=$(printf '%s\0%s\0%s\0' \
        "$public_url" "$public_host" "$app_port" | sha256sum | awk '{print $1}')
    dynamic_path="$dynamic_directory/$dynamic_filename"
    if [ -f "$dynamic_path" ]; then
        observed_dynamic_sha256=$(sha256sum "$dynamic_path" | awk '{print $1}')
    else
        observed_dynamic_sha256=$static_config_sha256
    fi
    source_compose_files_json=$(printf '%s' "$source_compose_files" \
        | jq --raw-input --compact-output 'split(",")')
    legacy_binding_json=$(jq --null-input --compact-output \
        --arg container "$blue_container" --arg app_port "$app_port" \
        '[{container: $container, container_port: "8080/tcp", host_ip: "127.0.0.1", host_port: $app_port}]')
    rollback_proxy_json=null
    if rollback_proxy_inspection=$(docker inspect "$proxy_container" 2>/dev/null); then
        rollback_proxy_command=$(printf '%s\n' "$rollback_proxy_inspection" \
            | jq --compact-output '.[0].Config.Cmd // []')
        rollback_proxy_command_sha256=$(printf '%s' "$rollback_proxy_command" \
            | sha256sum | awk '{print $1}')
        rollback_proxy_json=$(printf '%s\n' "$rollback_proxy_inspection" \
            | jq --compact-output --arg command_sha256 "$rollback_proxy_command_sha256" '
                .[0] | {
                    id: .Id,
                    image_id: .Image,
                    started_at: .State.StartedAt,
                    restart_count: .RestartCount,
                    pid: .State.Pid,
                    running: .State.Running,
                    command_sha256: $command_sha256
                }
            ')
    fi
    local_url=$(local_url_for_public_url)
    jq --null-input --compact-output \
        --arg phase "$response_phase" \
        --arg operation_id "$operation_id" \
        --arg token_sha256 "$token_sha256" \
        --arg public_url "$public_url" \
        --arg public_host "$public_host" \
        --arg local_url "$local_url" \
        --arg dynamic_filename "$dynamic_filename" \
        --arg compose_override_path "$compose_override" \
        --arg static_config_sha256 "$static_config_sha256" \
        --arg compose_override_sha256 "$override_sha256" \
        --arg rendered_config_sha256 "$rendered_config_sha256" \
        --arg proof_fingerprint_sha256 "$proof_fingerprint_sha256" \
        --arg dynamic_config_sha256 "$observed_dynamic_sha256" \
        --arg binding_tuple "${proxy_container}|8000/tcp|127.0.0.1|${app_port}" \
        --argjson app_port "$app_port" \
        --argjson source_compose_files "$source_compose_files_json" \
        --argjson legacy_binding_before "$legacy_binding_json" \
        --argjson rollback_proxy "$rollback_proxy_json" \
        '{
            ok: true,
            phase: $phase,
            operation_id: $operation_id,
            token_sha256: $token_sha256,
            app_port: $app_port,
            public_url: $public_url,
            public_host: $public_host,
            local_url: $local_url,
            dynamic_filename: $dynamic_filename,
            compose_override_path: $compose_override_path,
            static_config_sha256: $static_config_sha256,
            compose_override_sha256: $compose_override_sha256,
            proxy_rendered_config_sha256: $rendered_config_sha256,
            source_rendered_config_sha256: $rendered_config_sha256,
            source_compose_files: $source_compose_files,
            proxy_before: {running: true},
            legacy_before: {running: true},
            legacy_binding_before: $legacy_binding_before,
            public_proof_before: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            },
            local_proof_before: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            },
            proxy_after: {binding_tuple: $binding_tuple},
            dynamic_config_sha256: $dynamic_config_sha256,
            route_acknowledgement_sha256: $proof_fingerprint_sha256,
            public_proof_after: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            },
            local_proof_after: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            },
            rollback_proxy_restore_required: false,
            rollback_proxy: $rollback_proxy,
            legacy_restore_required: false,
            legacy_binding_rollback_observed: $legacy_binding_before,
            public_proof_rollback: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            },
            local_proof_rollback: {
                status: 200,
                response_fingerprint_sha256: $proof_fingerprint_sha256
            }
        }'
}

assert_proxy_binding()
{
    expected_binding=$1
    case "$expected_binding" in
        native)
            docker inspect "$proxy_container" | jq --exit-status --arg app_port "$app_port" '
                [ (.[0].NetworkSettings.Ports["8000/tcp"] // [])[]
                    | [.HostIp, .HostPort]
                ] == [["127.0.0.1", $app_port]]
            ' >/dev/null
            ;;
        legacy)
            docker inspect "$proxy_container" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8000/tcp"] // [])[] ] == []
            ' >/dev/null
            ;;
        *)
            fail
            ;;
    esac
}

assert_blue_binding()
{
    expected_binding=$1
    case "$expected_binding" in
        legacy)
            docker inspect "$blue_container" | jq --exit-status --arg app_port "$app_port" '
                [ (.[0].NetworkSettings.Ports["8080/tcp"] // [])[]
                    | [.HostIp, .HostPort]
                ] == [["127.0.0.1", $app_port]]
            ' >/dev/null
            ;;
        absent)
            docker inspect "$blue_container" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8080/tcp"] // [])[] ] == []
            ' >/dev/null
            ;;
        *)
            fail
            ;;
    esac
}

replace_static_config()
{
    static_source=$1
    chmod 600 "$static_config"
    cp "$static_source" "$static_config"
    chmod 400 "$static_config"
}

recreate_proxy()
{
    proxy_compose=$1
    recreate_proxy_output="$state_file.compose-output.$$"
    umask 077
    if ! docker compose --ansi never --project-name "$proxy_project" --file "$proxy_compose" \
        up --detach --force-recreate --no-build --pull never --no-deps proxy \
        > "$recreate_proxy_output" 2>&1; then
        cat "$recreate_proxy_output" >&2
        rm -f "$recreate_proxy_output"
        return 1
    fi
    rm -f "$recreate_proxy_output"
}

restore_legacy_proxy()
{
    replace_static_config "$legacy_static_config"
    recreate_proxy "$legacy_proxy_compose"
    assert_proxy_binding legacy
}

activate_native_proxy()
{
    assert_blue_binding absent
    write_state activating
    replace_static_config "$native_static_config"
    recreate_proxy "$native_proxy_compose"
    assert_proxy_binding native
}

assert_dynamic_sha256()
{
    dynamic_path="$dynamic_directory/$dynamic_filename"
    [ -n "$dynamic_sha256" ] && [ -f "$dynamic_path" ] \
        && [ "$(sha256sum "$dynamic_path" | awk '{print $1}')" = "$dynamic_sha256" ]
}

record_action
current_phase=$(state_value phase 2>/dev/null || true)

case "$enrollment_action" in
    status)
        if [ -z "$current_phase" ]; then
            emit_absent
            exit 1
        fi
        emit_success "$current_phase"
        ;;
    prepare)
        case "$current_phase" in
            prepared)
                emit_success prepared
                exit 0
                ;;
            ''|rolled-back|prepare-failed)
                ;;
            *)
                fail
                ;;
        esac
        assert_proxy_binding legacy
        assert_blue_binding legacy
        umask 077
        {
            # shellcheck disable=SC2016
            printf '%s\n' \
                'services:' \
                '  coolify:' \
                '    ports: !reset null' \
                '    labels:' \
                '      - "traefik.enable=true"' \
                '      - "traefik.http.routers.coolify-control-plane-enrollment-local.rule=PathPrefix(`/`)"' \
                '      - "traefik.http.routers.coolify-control-plane-enrollment-local.entrypoints=coolify-local"' \
                '      - "traefik.http.routers.coolify-control-plane-enrollment-local.priority=10"' \
                '      - "traefik.http.routers.coolify-control-plane-enrollment-local.service=coolify-control-plane-enrollment-local"' \
                '      - "traefik.http.services.coolify-control-plane-enrollment-local.loadbalancer.server.port=8080"'
        } > "$compose_override"
        chmod 600 "$compose_override"
        write_state prepared
        emit_success prepared
        ;;
    activate)
        case "$enrollment_mode" in
            fail-activate-before-proxy)
                write_state rollback-required
                exit 1
                ;;
        esac
        case "$current_phase" in
            prepared|activating)
                ;;
            activated|enrolled)
                emit_success "$current_phase"
                exit 0
                ;;
            *)
                fail
                ;;
        esac
        [ -f "$compose_override" ] && [ ! -L "$compose_override" ] || fail
        if [ "$enrollment_mode" = crash-activate-after-proxy-removal ]; then
            write_state activating
            replace_static_config "$native_static_config"
            docker rm --force "$proxy_container" >/dev/null
            kill -KILL "$operator_pid"
            exit 1
        fi
        activate_native_proxy
        if [ "$enrollment_mode" = crash-activate-after-proxy-before-durable ]; then
            kill -KILL "$operator_pid"
            exit 1
        fi
        write_state activated
        emit_success activated
        ;;
    finalize)
        case "$current_phase" in
            enrolled)
                emit_success enrolled
                exit 0
                ;;
            activated)
                ;;
            *)
                fail
                ;;
        esac
        [ -f "$compose_override" ] && [ ! -L "$compose_override" ] || fail
        assert_proxy_binding native
        assert_dynamic_sha256 || fail
        write_state enrolled
        emit_success enrolled
        ;;
    rollback)
        case "$current_phase" in
            rollback-pending-legacy)
                assert_blue_binding legacy
                assert_proxy_binding legacy
                write_state rolled-back
                emit_success rolled-back
                exit 0
                ;;
            rolled-back)
                emit_success rolled-back
                exit 0
                ;;
            preparing|prepared|activating|activated|enrolled|rolling-back|rollback-required|intervention-required)
                ;;
            *)
                fail
                ;;
        esac
        write_state rolling-back
        if [ "$enrollment_mode" = crash-rollback-after-intent ]; then
            kill -KILL "$operator_pid"
            exit 1
        fi
        restore_legacy_proxy
        rm -f "$compose_override"
        write_state rollback-pending-legacy
        emit_success rollback-pending-legacy
        ;;
    *)
        fail
        ;;
esac
