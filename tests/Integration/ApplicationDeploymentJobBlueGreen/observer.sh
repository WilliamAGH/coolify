#!/bin/sh

set -eu

coolify_data_directory=${OBSERVER_COOLIFY_DATA_DIRECTORY:-/data/coolify}
runtime_evidence_directory=${OBSERVER_RUNTIME_EVIDENCE_DIRECTORY:-/runtime-evidence}
events_file="$runtime_evidence_directory/docker-events.jsonl"
snapshots_file="$runtime_evidence_directory/candidate-health.jsonl"
compose_manifest="$runtime_evidence_directory/generated-compose.jsonl"
route_manifest="$runtime_evidence_directory/proxy-route.jsonl"
heartbeat_file="$runtime_evidence_directory/observer-heartbeat"
flush_request="$runtime_evidence_directory/observer-final-flush.request"
flush_ack="$runtime_evidence_directory/observer-final-flush.ack"
snapshot_directory=$(mktemp -d "${TMPDIR:-/tmp}/application-deployment-observer.XXXXXX")
: >"$events_file"
: >"$snapshots_file"
: >"$compose_manifest"
: >"$route_manifest"

docker events --filter type=container \
    --format '{"action":{{json .Action}},"attributes":{{json .Actor.Attributes}},"id":{{json .Actor.ID}},"timeNano":{{json .TimeNano}},"type":{{json .Type}}}' \
    >>"$events_file" &
events_pid=$!

file_identity()
{
    stat -c '%d:%i:%s:%y:%z' "$1" 2>/dev/null \
        || stat -f '%d:%i:%z:%m:%c' "$1" 2>/dev/null
}

copy_source_snapshot()
{
    snapshot_source=$1
    snapshot_target=$2
    snapshot_identity=$(file_identity "$snapshot_source" || true)
    [ -n "$snapshot_identity" ] || return 2
    if cp "$snapshot_source" "$snapshot_target" 2>/dev/null; then
        copied_identity=$(file_identity "$snapshot_source" || true)
        [ "$copied_identity" = "$snapshot_identity" ] && return 0
        rm -f "$snapshot_target"
        snapshot_identity=$copied_identity
        [ -n "$snapshot_identity" ] || return 2
    fi
    current_identity=$(file_identity "$snapshot_source" || true)
    [ "$current_identity" = "$snapshot_identity" ] || return 2

    sleep 0.02
    stable_identity=$(file_identity "$snapshot_source" || true)
    [ "$stable_identity" = "$current_identity" ] || return 2
    if cp "$snapshot_source" "$snapshot_target" 2>/dev/null; then
        copied_identity=$(file_identity "$snapshot_source" || true)
        [ "$copied_identity" = "$stable_identity" ] && return 0
        rm -f "$snapshot_target"
        return 2
    fi
    final_identity=$(file_identity "$snapshot_source" || true)
    [ "$final_identity" = "$stable_identity" ] || return 2
    return 1
}

capture_routes()
{
    previous=initial
    acknowledged_flush=
    while :; do
        route_file="$(find "$coolify_data_directory/proxy/dynamic" -maxdepth 1 -type f -name 'coolify-blue-green-*.yaml' 2>/dev/null | head -1)"
        if [ -n "$route_file" ]; then
            route_snapshot=$(mktemp "$snapshot_directory/route.XXXXXX")
            if copy_source_snapshot "$route_file" "$route_snapshot"; then
                route_sha="$(sha256sum "$route_snapshot" | awk '{print $1}')"
                signature="present:$route_sha"
            else
                snapshot_status=$?
                if [ "$snapshot_status" -ne 2 ]; then
                    printf 'Route snapshot failed while exact source still exists: %s\n' "$route_file" >&2
                    return 1
                fi
                rm -f "$route_snapshot"
                route_file=
                route_sha=
                signature=absent
            fi
        else
            route_snapshot=
            route_sha=
            signature=absent
        fi
        requested_flush=
        if [ -f "$flush_request" ]; then
            requested_flush=$(cat "$flush_request")
        fi
        final_flush=false
        if [ -n "$requested_flush" ] && [ "$requested_flush" != "$acknowledged_flush" ]; then
            final_flush=true
        fi
        if [ "$signature" != "$previous" ] || [ "$final_flush" = true ]; then
            observed_at_nano="$(date +%s%N)"
            if [ -n "$route_file" ]; then
                target="$runtime_evidence_directory/proxy-route-$route_sha.yaml"
                [ -e "$target" ] || cp "$route_snapshot" "$target"
                jq -cn --arg path "$route_file" --arg sha256 "$route_sha" --arg observedAtNano "$observed_at_nano" \
                    --argjson finalFlush "$final_flush" \
                    '{finalFlush: $finalFlush, observedAtNano: $observedAtNano, path: $path, present: true, sha256: $sha256}' >>"$route_manifest"
            else
                jq -cn --arg observedAtNano "$observed_at_nano" \
                    --argjson finalFlush "$final_flush" \
                    '{finalFlush: $finalFlush, observedAtNano: $observedAtNano, path: null, present: false, sha256: null}' >>"$route_manifest"
            fi
            previous="$signature"
        fi
        [ -z "$route_snapshot" ] || rm -f "$route_snapshot"
        if [ "$final_flush" = true ]; then
            sync
            flush_ack_snapshot=$(mktemp "$snapshot_directory/flush-ack.XXXXXX")
            printf '%s\n' "$requested_flush" >"$flush_ack_snapshot"
            mv "$flush_ack_snapshot" "$flush_ack"
            sync
            acknowledged_flush=$requested_flush
        fi
        sleep 0.02
    done
}

capture_routes &
routes_pid=$!
trap 'kill "$events_pid" "$routes_pid" >/dev/null 2>&1 || true; rm -rf "$snapshot_directory"' EXIT INT TERM

inspect_json()
{
    docker inspect --format "$2" "$1" 2>/dev/null || printf 'null\n'
}

capture_compose()
{
    find "$coolify_data_directory/applications" -type f \( -name 'docker-compose.yaml' -o -name 'docker-compose.yml' \) 2>/dev/null |
        while IFS= read -r compose_file; do
            compose_snapshot=$(mktemp "$snapshot_directory/compose.XXXXXX")
            if copy_source_snapshot "$compose_file" "$compose_snapshot"; then
                compose_sha="$(sha256sum "$compose_snapshot" | awk '{print $1}')"
            else
                snapshot_status=$?
                rm -f "$compose_snapshot"
                if [ "$snapshot_status" -eq 2 ]; then
                    continue
                fi
                printf 'Compose snapshot failed while exact source still exists: %s\n' "$compose_file" >&2
                return 1
            fi
            target="$runtime_evidence_directory/generated-compose-$compose_sha.yaml"
            if [ ! -e "$target" ]; then
                cp "$compose_snapshot" "$target"
                jq -cn \
                    --arg path "$compose_file" \
                    --arg sha256 "$compose_sha" \
                    '{path: $path, sha256: $sha256}' >>"$compose_manifest"
            fi
            rm -f "$compose_snapshot"
        done
}

capture_traffic()
{
    traffic_directory="$coolify_data_directory/application-deployment-job-blue-green-traffic"
    [ -d "$traffic_directory" ] || return 0
    for traffic_file in "$traffic_directory"/*; do
        [ -f "$traffic_file" ] || continue
        traffic_snapshot=$(mktemp "$snapshot_directory/traffic.XXXXXX")
        if copy_source_snapshot "$traffic_file" "$traffic_snapshot"; then
            cp "$traffic_snapshot" "$runtime_evidence_directory/$(basename "$traffic_file")"
        else
            snapshot_status=$?
            rm -f "$traffic_snapshot"
            if [ "$snapshot_status" -eq 2 ]; then
                continue
            fi
            printf 'Traffic snapshot failed while exact source still exists: %s\n' "$traffic_file" >&2
            return 1
        fi
        rm -f "$traffic_snapshot"
    done
}

write_heartbeat()
{
    heartbeat_snapshot=$(mktemp "$snapshot_directory/heartbeat.XXXXXX")
    date +%s%N >"$heartbeat_snapshot"
    mv "$heartbeat_snapshot" "$heartbeat_file"
}

while :; do
    if ! kill -0 "$routes_pid" 2>/dev/null; then
        printf 'Route observer exited unexpectedly.\n' >&2
        exit 1
    fi
    capture_compose
    capture_traffic
    for container_id in $(docker ps --all --quiet 2>/dev/null); do
        healthcheck="$(inspect_json "$container_id" '{{json .Config.Healthcheck}}')"
        [ "$healthcheck" != null ] || continue
        container_name="$(inspect_json "$container_id" '{{json .Name}}')"
        image_id="$(inspect_json "$container_id" '{{json .Image}}')"
        image_reference="$(inspect_json "$container_id" '{{json .Config.Image}}')"
        state="$(inspect_json "$container_id" '{{json .State.Status}}')"
        health="$(inspect_json "$container_id" '{{json .State.Health}}')"
        jq -cn \
            --argjson health "$health" \
            --argjson healthcheck "$healthcheck" \
            --argjson imageId "$image_id" \
            --argjson imageReference "$image_reference" \
            --argjson name "$container_name" \
            --argjson state "$state" \
            --arg containerId "$container_id" \
            --arg observedAt "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            '{containerId: $containerId, health: $health, healthcheck: $healthcheck, imageId: $imageId, imageReference: $imageReference, name: $name, observedAt: $observedAt, state: $state}' \
            >>"$snapshots_file"
        if [ "$image_id" != null ]; then
            docker logs "$container_id" >"$runtime_evidence_directory/candidate-$container_id.log" 2>&1 || true
        fi
    done
    write_heartbeat
    sleep 1
done
