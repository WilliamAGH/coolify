#!/bin/sh

set -eu

evidence=/runtime-evidence/traefik-tombstone-matrix.jsonl
probe_evidence=/runtime-evidence/traefik-tombstone-probes.jsonl
token=1c0c3d6e872f367b34c48fbb6d14bf3d6023870fce5feca6547bdf54be290758
rm -f "$evidence" "$probe_evidence"
current_name=
cleanup()
{
    [ -z "$current_name" ] || docker rm -f "$current_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

probe_tombstone()
{
    probe_image=$1
    probe_port=$2
    probe_headers=$3
    probe_token=$4
    probe_attempt=0
    completed_response_observed=false

    while [ "$probe_attempt" -lt 30 ]; do
        probe_attempt=$((probe_attempt + 1))
        : >"$probe_headers"
        probe_status=000
        if probe_status="$(curl --silent --show-error --connect-timeout 2 --max-time 5 \
            --output /dev/null --dump-header "$probe_headers" --write-out '%{http_code}' \
            --header 'Host: tombstone.test' \
            --url "http://127.0.0.1:$probe_port/?matrix=$probe_attempt")"; then
            probe_curl_exit=0
        else
            probe_curl_exit=$?
        fi

        if [ "$probe_curl_exit" -ne 0 ]; then
            printf '{"image":"%s","attempt":%s,"kind":"transport","curlExit":%s,"status":"%s","completedResponseObserved":%s}\n' \
                "$probe_image" "$probe_attempt" "$probe_curl_exit" "$probe_status" \
                "$completed_response_observed" >>"$probe_evidence"
            if [ "$completed_response_observed" = true ]; then
                return 1
            fi
            case "$probe_curl_exit" in
                7|52|56) ;;
                *) return 1 ;;
            esac
            [ "$probe_attempt" -lt 30 ] || return 1
            sleep 1
            continue
        fi

        completed_response_observed=true
        probe_acknowledgements="$(awk -F ': *' 'tolower($1) == "x-coolify-probe-ack" { gsub("\\r", "", $2); if (length($2) > 0) print $2 }' "$probe_headers")"
        probe_acknowledgement_matched=false
        if [ "$probe_acknowledgements" = "$probe_token" ]; then
            probe_acknowledgement_matched=true
        fi
        printf '{"image":"%s","attempt":%s,"kind":"http","curlExit":0,"status":%s,"completedResponseObserved":true,"acknowledgementMatched":%s}\n' \
            "$probe_image" "$probe_attempt" "$probe_status" "$probe_acknowledgement_matched" \
            >>"$probe_evidence"
        case "$probe_status" in
            000|5??) return 1 ;;
        esac
        if [ "$probe_status" = 418 ] && [ "$probe_acknowledgement_matched" = true ]; then
            return 0
        fi
        [ "$probe_attempt" -lt 30 ] || return 1
        sleep 1
    done

    return 1
}

verify()
{
    image=$1
    expected_id=$2
    platform=$3
    name=$4
    current_name=$name
    directory="/data/coolify/$name"
    rm -rf "$directory"
    install -d -m 0755 "$directory/dynamic"
    install -m 0644 /lab/traefik.yml "$directory/traefik.yml"
    cat >"$directory/dynamic/tombstone.yaml" <<EOF
http:
  routers:
    exact-tombstone:
      rule: Host(\`tombstone.test\`) && PathPrefix(\`/\`)
      entryPoints:
        - http
      service: noop@internal
      middlewares:
        - exact-tombstone-ack
  middlewares:
    exact-tombstone-ack:
      headers:
        customResponseHeaders:
          X-Coolify-Probe-Ack: $token
EOF
    [ "$(docker image inspect "$image" --format '{{.Id}}')" = "$expected_id" ]
    docker run --detach --pull never --platform "$platform" --name "$name" --publish 127.0.0.1::80 \
        --volume "$directory/dynamic:/dynamic:ro" \
        --volume "$directory/traefik.yml:/etc/traefik/traefik.yml:ro" \
        "$image" --configFile=/etc/traefik/traefik.yml >/dev/null
    port="$(docker port "$name" 80/tcp | sed -n 's/.*://p')"
    [ -n "$port" ]
    headers="$directory/headers"
    probe_tombstone "$image" "$port" "$headers" "$token"
    version="$(docker exec "$name" traefik version | sed -n 's/^Version:[[:space:]]*//p')"
    printf '{"image":"%s","imageId":"%s","platform":"%s","status":418,"acknowledgement":"%s","version":"%s"}\n' \
        "$image" "$expected_id" "$platform" "$token" "$version" >>"$evidence"
    docker rm -f "$name" >/dev/null
    current_name=
}

: "${TRAEFIK_CANDIDATE_IMAGE_ID:?TRAEFIK_CANDIDATE_IMAGE_ID is required}"
: "${TRAEFIK_MATRIX_PLATFORM:?TRAEFIK_MATRIX_PLATFORM is required}"
: "${TRAEFIK_PRODUCTION_IMAGE_ID:?TRAEFIK_PRODUCTION_IMAGE_ID is required}"
verify traefik:production-3.6.13 "$TRAEFIK_PRODUCTION_IMAGE_ID" "$TRAEFIK_MATRIX_PLATFORM" tombstone-traefik-3-6-13
verify traefik:v3.6.17 "$TRAEFIK_CANDIDATE_IMAGE_ID" "$TRAEFIK_MATRIX_PLATFORM" tombstone-traefik-3-6-17
