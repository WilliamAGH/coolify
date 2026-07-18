#!/usr/bin/env bash

# This source-owned adapter materializes immutable input into the real
# Coolify/Laravel state shape expected by the production probes. It is sourced
# only by live-stack-adapter.sh inside the disposable inner host.

live_stack_contract_keys()
{
    printf '%s\n' \
        version operation_id proxy_container blue_web_a_container blue_web_b_container \
        green_web_a_container green_web_b_container proxy_image_reference \
        web_a_image_reference web_b_image_reference image_transport_operation_id \
        image_transport_manifest_sha256 web_archive_sha256 web_inner_image_digest \
        web_contract_sha256 proxy_archive_sha256 proxy_inner_image_digest \
        proxy_contract_sha256 web_a_network_ids web_b_network_ids \
        escape_network_name coordination_volume blue_web_a_private_volume \
        blue_web_b_private_volume green_web_a_private_volume green_web_b_private_volume \
        pool_label_key pool_label_value web_a_route_identity web_b_route_identity \
        web_a_loopback_port web_b_loopback_port backend_port writer_member runtime_uid runtime_gid \
        mutation_freeze_epoch mutation_freeze_marker_path mutation_lease_path \
        web_a_web_epoch web_b_web_epoch web_a_route_drain_epoch web_b_route_drain_epoch \
        web_a_writer_epoch web_a_writer_marker_path route_health_token_file \
        route_health_runtime_file pool_ack_file pool_ack_runtime_file \
        web_a_direct_probe_token_file web_a_direct_probe_runtime_file \
        web_b_direct_probe_token_file web_b_direct_probe_runtime_file \
        web_a_applied_ack_file web_a_applied_ack_runtime_file \
        web_b_applied_ack_file web_b_applied_ack_runtime_file web_a_ingress_address \
        web_b_ingress_address terminal_https_url terminal_local_ingress_url management_endpoints \
        self_ssh_target provider_api_url provider_router provider_service provider_legacy_port \
        provider_header_file
}

live_stack_token()
{
    openssl rand -hex 24
}

live_stack_write_file()
{
    local destination=$1 owner=$2 mode=$3 content=$4 candidate

    candidate="${destination}.candidate.$$"
    printf '%s' "$content" > "$candidate"
    chown "$owner" "$candidate"
    chmod "$mode" "$candidate"
    mv -f -- "$candidate" "$destination"
}

live_stack_write_token_pair()
{
    local filename=$1 token

    token=$(live_stack_token)
    live_stack_write_file "$LIVE_STACK_ARTIFACT_DIRECTORY/$filename" root:root 600 "$token"
    live_stack_write_file "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/$filename" 9999:9999 400 "$token"
    printf '%s' "$token"
}

live_stack_prepare_tls()
{
    local extension_file ca_destination

    extension_file=$LIVE_STACK_TLS_DIRECTORY/leaf.ext
    openssl req -x509 -new -nodes -newkey rsa:2048 -days 2 \
        -subj '/CN=coolify-runtime-fence-local-ca' \
        -keyout "$LIVE_STACK_TLS_DIRECTORY/ca.key" \
        -out "$LIVE_STACK_TLS_DIRECTORY/ca.crt" >/dev/null 2>&1
    openssl req -new -nodes -newkey rsa:2048 \
        -subj '/CN=127.0.0.1' \
        -keyout "$LIVE_STACK_TLS_DIRECTORY/leaf.key" \
        -out "$LIVE_STACK_TLS_DIRECTORY/leaf.csr" >/dev/null 2>&1
    printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\n' > "$extension_file"
    openssl x509 -req -days 2 \
        -in "$LIVE_STACK_TLS_DIRECTORY/leaf.csr" \
        -CA "$LIVE_STACK_TLS_DIRECTORY/ca.crt" \
        -CAkey "$LIVE_STACK_TLS_DIRECTORY/ca.key" \
        -CAcreateserial -extfile "$extension_file" \
        -out "$LIVE_STACK_TLS_DIRECTORY/leaf.crt" >/dev/null 2>&1
    chmod 0600 "$LIVE_STACK_TLS_DIRECTORY/ca.key" "$LIVE_STACK_TLS_DIRECTORY/leaf.key"
    chmod 0644 "$LIVE_STACK_TLS_DIRECTORY/ca.crt" "$LIVE_STACK_TLS_DIRECTORY/leaf.crt"
    ca_destination="/usr/local/share/ca-certificates/coolify-runtime-fence-${LIVE_STACK_OPERATION}.crt"
    install -m 0644 "$LIVE_STACK_TLS_DIRECTORY/ca.crt" "$ca_destination"
    update-ca-certificates >/dev/null
}

live_stack_write_traefik_static_configuration()
{
    cat > "$LIVE_STACK_TRAEFIK_DIRECTORY/traefik.yml" <<EOF
api:
  dashboard: true
  insecure: false
entryPoints:
  websecure:
    address: ":8443"
  coolify-local:
    address: ":8000"
  provider:
    address: ":18080"
providers:
  docker:
    endpoint: "unix:///var/run/docker.sock"
    exposedByDefault: false
    network: "${LIVE_STACK_NETWORK}"
  file:
    directory: "/etc/traefik/dynamic"
    watch: true
EOF
    cat > "$LIVE_STACK_DYNAMIC_DIRECTORY/tls.yml" <<EOF
tls:
  certificates:
    - certFile: "/etc/traefik/tls/leaf.crt"
      keyFile: "/etc/traefik/tls/leaf.key"
EOF
    chown -R root:root "$LIVE_STACK_TRAEFIK_DIRECTORY"
    chmod 0700 "$LIVE_STACK_TRAEFIK_DIRECTORY" "$LIVE_STACK_DYNAMIC_DIRECTORY"
    chmod 0600 "$LIVE_STACK_TRAEFIK_DIRECTORY/traefik.yml" "$LIVE_STACK_DYNAMIC_DIRECTORY/tls.yml"
}

live_stack_write_application_environment()
{
    local application_key

    application_key=$(openssl rand -base64 32 | tr -d '\n')
    cat > "$LIVE_STACK_APPLICATION_ENVIRONMENT" <<EOF
APP_NAME=coolify-runtime-fence
APP_ENV=production
APP_KEY=base64:${application_key}
APP_DEBUG=false
APP_URL=https://127.0.0.1:8443
LOG_CHANNEL=stderr
LOG_STACK=stderr
LOG_OUTPUT_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=${LIVE_STACK_POSTGRES_CONTAINER}
DB_PORT=5432
DB_DATABASE=coolify
DB_USERNAME=coolify
DB_PASSWORD=${LIVE_STACK_DATABASE_PASSWORD}
REDIS_HOST=${LIVE_STACK_REDIS_CONTAINER}
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
CACHE_DRIVER=redis
SESSION_DRIVER=array
BROADCAST_DRIVER=null
MAIL_MAILER=log
FILESYSTEM_DISK=local
AUTORUN_ENABLED=false
AUTORUN_LARAVEL_CONFIG_CACHE=false
AUTORUN_LARAVEL_EVENT_CACHE=false
AUTORUN_LARAVEL_MIGRATION=false
AUTORUN_LARAVEL_MIGRATION_SEED=false
AUTORUN_LARAVEL_OPTIMIZE=false
AUTORUN_LARAVEL_ROUTE_CACHE=false
AUTORUN_LARAVEL_STORAGE_LINK=false
AUTORUN_LARAVEL_VIEW_CACHE=false
MIGRATION_ENABLED=false
SEEDER_ENABLED=false
INIT_ENABLED=false
HORIZON_ENABLED=false
SCHEDULER_ENABLED=false
NIGHTWATCH_ENABLED=false
TELESCOPE_ENABLED=false
CONTROL_PLANE_MODE=active
CONTROL_PLANE_STARTUP_MODE=web-only
CONTROL_PLANE_BACKEND_PORT=8080
CONTROL_PLANE_DIRECT_PROBE_PATH=/api/control-plane/probe
CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES=${LIVE_STACK_PROXY_IP}
CONTROL_PLANE_MUTATION_FREEZE_EPOCH=${LIVE_STACK_MUTATION_FREEZE_EPOCH}
CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=/var/lib/coolify-control-plane/coordination/mutation-freeze-epoch
CONTROL_PLANE_POOL_ACK_PATH=/run/secrets/pool-ack
EOF
    chown root:root "$LIVE_STACK_APPLICATION_ENVIRONMENT"
    chmod 0644 "$LIVE_STACK_APPLICATION_ENVIRONMENT"
}

live_stack_prepare_artifacts()
{
    local credentials provider_hash

    install -d -m 0700 -o root -g root "$LIVE_STACK_ARTIFACT_DIRECTORY"
    install -d -m 0755 -o root -g root "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
    install -d -m 0700 -o root -g root "$LIVE_STACK_CONFIGURATION_DIRECTORY"
    install -d -m 0700 -o root -g root "$LIVE_STACK_TLS_DIRECTORY"
    install -d -m 0700 -o root -g root "$LIVE_STACK_TRAEFIK_DIRECTORY" "$LIVE_STACK_DYNAMIC_DIRECTORY"
    install -d -m 0700 -o root -g root "$LIVE_STACK_POSTGRES_DIRECTORY"

    LIVE_STACK_ROUTE_HEALTH_TOKEN=$(live_stack_write_token_pair route-health-token)
    live_stack_write_token_pair pool-ack >/dev/null
    live_stack_write_token_pair web-a-direct-probe-token >/dev/null
    live_stack_write_token_pair web-b-direct-probe-token >/dev/null
    live_stack_write_token_pair web-a-applied-ack >/dev/null
    live_stack_write_token_pair web-b-applied-ack >/dev/null
    LIVE_STACK_WEB_A_ROUTE_IDENTITY=$(live_stack_token)
    LIVE_STACK_WEB_B_ROUTE_IDENTITY=$(live_stack_token)
    LIVE_STACK_WEB_A_EPOCH=$(live_stack_token)
    LIVE_STACK_WEB_B_EPOCH=$(live_stack_token)
    LIVE_STACK_WEB_A_ROUTE_DRAIN_EPOCH=$(live_stack_token)
    LIVE_STACK_WEB_B_ROUTE_DRAIN_EPOCH=$(live_stack_token)
    LIVE_STACK_WEB_A_WRITER_EPOCH=$(live_stack_token)
    LIVE_STACK_MUTATION_FREEZE_EPOCH=$(live_stack_token)
    LIVE_STACK_DATABASE_PASSWORD=$(openssl rand -hex 24)
    LIVE_STACK_PROVIDER_PASSWORD=$(openssl rand -hex 24)
    provider_hash=$(openssl passwd -apr1 "$LIVE_STACK_PROVIDER_PASSWORD")
    credentials=$(printf 'runtime-fence:%s' "$LIVE_STACK_PROVIDER_PASSWORD" | base64 | tr -d '\n')
    LIVE_STACK_PROVIDER_AUTH="runtime-fence:${provider_hash}"
    live_stack_write_file "$LIVE_STACK_ARTIFACT_DIRECTORY/provider-header" root:root 600 \
        "Authorization: Basic ${credentials}"$'\n'
    live_stack_prepare_tls
    live_stack_write_traefik_static_configuration
    live_stack_write_application_environment
    export LIVE_STACK_ROUTE_HEALTH_TOKEN LIVE_STACK_WEB_A_ROUTE_IDENTITY LIVE_STACK_WEB_B_ROUTE_IDENTITY
    export LIVE_STACK_WEB_A_EPOCH LIVE_STACK_WEB_B_EPOCH LIVE_STACK_WEB_A_ROUTE_DRAIN_EPOCH
    export LIVE_STACK_WEB_B_ROUTE_DRAIN_EPOCH LIVE_STACK_WEB_A_WRITER_EPOCH LIVE_STACK_MUTATION_FREEZE_EPOCH
    export LIVE_STACK_DATABASE_PASSWORD LIVE_STACK_PROVIDER_AUTH
}

live_stack_write_contract()
{
    local network_id=$1 candidate

    candidate="${LIVE_STACK_CONTRACT}.candidate.$$"
    {
        printf 'version=1\noperation_id=%s\n' "$LIVE_STACK_OPERATION"
        printf 'proxy_container=%s\nblue_web_a_container=%s\nblue_web_b_container=%s\n' \
            "$LIVE_STACK_PROXY_CONTAINER" "$LIVE_STACK_BLUE_WEB_A_CONTAINER" "$LIVE_STACK_BLUE_WEB_B_CONTAINER"
        printf 'green_web_a_container=%s\ngreen_web_b_container=%s\n' \
            "$LIVE_STACK_GREEN_WEB_A_CONTAINER" "$LIVE_STACK_GREEN_WEB_B_CONTAINER"
        printf 'proxy_image_reference=%s\nweb_a_image_reference=%s\nweb_b_image_reference=%s\n' \
            "$LIVE_STACK_PROXY_SOURCE_IMAGE" "$LIVE_STACK_WEB_A_SOURCE_IMAGE" "$LIVE_STACK_WEB_B_SOURCE_IMAGE"
        printf 'image_transport_operation_id=%s\nimage_transport_manifest_sha256=%s\n' \
            "$LIVE_STACK_IMAGE_TRANSPORT_OPERATION" "$LIVE_STACK_IMAGE_TRANSPORT_MANIFEST_SHA256"
        printf 'web_archive_sha256=%s\nweb_inner_image_digest=%s\nweb_contract_sha256=%s\n' \
            "$LIVE_STACK_WEB_ARCHIVE_SHA256" "$LIVE_STACK_WEB_IMAGE" "$LIVE_STACK_WEB_CONTRACT_SHA256"
        printf 'proxy_archive_sha256=%s\nproxy_inner_image_digest=%s\nproxy_contract_sha256=%s\n' \
            "$LIVE_STACK_PROXY_ARCHIVE_SHA256" "$LIVE_STACK_PROXY_IMAGE" "$LIVE_STACK_PROXY_CONTRACT_SHA256"
        printf 'web_a_network_ids=%s\nweb_b_network_ids=%s\n' "$network_id" "$network_id"
        printf 'escape_network_name=%s\ncoordination_volume=%s\n' \
            "$LIVE_STACK_ESCAPE_NETWORK" "$LIVE_STACK_COORDINATION_VOLUME"
        printf 'blue_web_a_private_volume=%s\nblue_web_b_private_volume=%s\n' \
            "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME"
        printf 'green_web_a_private_volume=%s\ngreen_web_b_private_volume=%s\n' \
            "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"
        printf 'pool_label_key=%s\npool_label_value=%s\n' \
            "$LIVE_STACK_POOL_LABEL_KEY" "$LIVE_STACK_OPERATION"
        printf 'web_a_route_identity=%s\nweb_b_route_identity=%s\n' \
            "$LIVE_STACK_WEB_A_ROUTE_IDENTITY" "$LIVE_STACK_WEB_B_ROUTE_IDENTITY"
        printf 'web_a_loopback_port=18081\nweb_b_loopback_port=18082\nbackend_port=8080\n'
        printf 'writer_member=web-a\nruntime_uid=9999\nruntime_gid=9999\n'
        printf 'mutation_freeze_epoch=%s\n' "$LIVE_STACK_MUTATION_FREEZE_EPOCH"
        printf 'mutation_freeze_marker_path=/var/lib/coolify-control-plane/coordination/mutation-freeze-epoch\n'
        printf 'mutation_lease_path=/var/lib/coolify-control-plane/coordination/mutation-inflight.lock\n'
        printf 'web_a_web_epoch=%s\nweb_b_web_epoch=%s\n' \
            "$LIVE_STACK_WEB_A_EPOCH" "$LIVE_STACK_WEB_B_EPOCH"
        printf 'web_a_route_drain_epoch=%s\nweb_b_route_drain_epoch=%s\n' \
            "$LIVE_STACK_WEB_A_ROUTE_DRAIN_EPOCH" "$LIVE_STACK_WEB_B_ROUTE_DRAIN_EPOCH"
        printf 'web_a_writer_epoch=%s\n' "$LIVE_STACK_WEB_A_WRITER_EPOCH"
        printf 'web_a_writer_marker_path=/var/lib/coolify-control-plane/private/writer-epoch\n'
        printf 'route_health_token_file=%s/route-health-token\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'route_health_runtime_file=%s/route-health-token\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'pool_ack_file=%s/pool-ack\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'pool_ack_runtime_file=%s/pool-ack\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'web_a_direct_probe_token_file=%s/web-a-direct-probe-token\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'web_a_direct_probe_runtime_file=%s/web-a-direct-probe-token\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'web_b_direct_probe_token_file=%s/web-b-direct-probe-token\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'web_b_direct_probe_runtime_file=%s/web-b-direct-probe-token\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'web_a_applied_ack_file=%s/web-a-applied-ack\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'web_a_applied_ack_runtime_file=%s/web-a-applied-ack\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'web_b_applied_ack_file=%s/web-b-applied-ack\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
        printf 'web_b_applied_ack_runtime_file=%s/web-b-applied-ack\n' "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY"
        printf 'web_a_ingress_address=%s\nweb_b_ingress_address=%s\n' \
            "$LIVE_STACK_GREEN_WEB_A_IP" "$LIVE_STACK_GREEN_WEB_B_IP"
        printf 'terminal_https_url=https://127.0.0.1:8443/api/control-plane/route-health\n'
        printf 'terminal_local_ingress_url=http://127.0.0.1:8000/api/control-plane/route-health\n'
        printf 'management_endpoints=lo=127.0.0.1\nself_ssh_target=127.0.0.1\n'
        printf 'provider_api_url=http://127.0.0.1:18080/api/rawdata\n'
        printf 'provider_router=coolify@docker\nprovider_service=coolify@docker\nprovider_legacy_port=8080\n'
        printf 'provider_header_file=%s/provider-header\n' "$LIVE_STACK_ARTIFACT_DIRECTORY"
    } > "$candidate"
    chown root:root "$candidate"
    chmod 0600 "$candidate"
    mv -f -- "$candidate" "$LIVE_STACK_CONTRACT"
}
