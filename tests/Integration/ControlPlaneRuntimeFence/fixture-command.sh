#!/usr/bin/env bash

set -euo pipefail

command_name=$(basename -- "$0")
readonly command_name
readonly proxy_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
readonly legacy_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
readonly candidate_id=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
readonly candidate_b_id=9999999999999999999999999999999999999999999999999999999999999999
readonly network_id=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
readonly replacement_legacy_id=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff

container_json()
{
    local id=$1 image=$2 started=$3 address ipv6_address='' networks image_reference service
    local working_dir=/var/www/html host_port=18080 control_plane_color=green
    local route_identity=absent
    local privileged=false secret_read_write=false startup_source='' token_source='' ack_source=''
    local state_volume='' mounts='[]' binds=null health=healthy pool_label_value='' mount_marker=''
    local restart_policy=unless-stopped
    local coordination_volume=runtime-fence-coordination
    local other_private_volume=''
    [[ ! -e $FIXTURE_ROOT/unhealthy ]] || health=unhealthy
    case "$id" in
        "$proxy_id")
            address=172.18.0.2
            service=proxy
            image_reference=registry.example/proxy@sha256:1111111111111111111111111111111111111111111111111111111111111111
            ;;
        "$legacy_id"|"$replacement_legacy_id")
            address=172.18.0.3
            if [[ -e $FIXTURE_ROOT/provider-a-dual-stack-missing-b \
                || -e $FIXTURE_ROOT/provider-dual-stack-chosen-members ]]; then
                ipv6_address=fd00:18::3
            fi
            service=coolify
            control_plane_color=blue
            startup_source=$FIXTURE_ROOT/legacy-startup.env
            token_source=$FIXTURE_ROOT/legacy-direct-token
            ack_source=$FIXTURE_ROOT/legacy-applied-ack
            state_volume=legacy-control-plane-state
            image_reference=registry.example/legacy@sha256:2222222222222222222222222222222222222222222222222222222222222222
            ;;
        "$candidate_id")
            address=172.18.0.4
            service=coolify
            startup_source=$FIXTURE_ROOT/candidate-startup.env
            token_source=$FIXTURE_ROOT/candidate-direct-token
            ack_source=$FIXTURE_ROOT/candidate-applied-ack
            state_volume=candidate-control-plane-state
            mount_marker=candidate
            image_reference=registry.example/candidate@sha256:4444444444444444444444444444444444444444444444444444444444444444
            if [[ -e $FIXTURE_ROOT/candidate-wrong-image ]]; then
                image=5555555555555555555555555555555555555555555555555555555555555555
                image_reference=registry.example/candidate@sha256:6666666666666666666666666666666666666666666666666666666666666666
            fi
            [[ ! -e $FIXTURE_ROOT/candidate-wrong-control-plane-env ]] \
                || control_plane_color=blue
            [[ ! -e $FIXTURE_ROOT/candidate-wrong-security ]] || privileged=true
            [[ ! -e $FIXTURE_ROOT/candidate-secret-source-drift ]] \
                || ack_source=$FIXTURE_ROOT/rogue-secret-source
            [[ ! -e $FIXTURE_ROOT/candidate-secret-writable ]] \
                || secret_read_write=true
            [[ ! -e $FIXTURE_ROOT/candidate-volume-drift ]] \
                || state_volume=rogue-control-plane-state
            pool_label_value=${FIXTURE_POOL_LABEL_VALUE:-runtime-fence-test}
            route_identity=route-identity-a-01
            [[ ! -e $FIXTURE_ROOT/candidate-restart-always ]] || restart_policy=always
            ;;
        "$candidate_b_id")
            address=172.18.0.5
            [[ ! -e $FIXTURE_ROOT/provider-dual-stack-chosen-members ]] \
                || ipv6_address=fd00:18::5
            host_port=18082
            service=coolify
            startup_source=$FIXTURE_ROOT/candidate-b-startup.env
            token_source=$FIXTURE_ROOT/candidate-b-direct-token
            ack_source=$FIXTURE_ROOT/candidate-b-applied-ack
            state_volume=candidate-b-control-plane-state
            mount_marker=candidate-b
            image_reference=registry.example/candidate-b@sha256:8888888888888888888888888888888888888888888888888888888888888888
            pool_label_value=${FIXTURE_POOL_LABEL_VALUE:-runtime-fence-test}
            route_identity=route-identity-b-01
            [[ ! -e $FIXTURE_ROOT/candidate-b-restart-always ]] || restart_policy=always
            ;;
        *) exit 1 ;;
    esac
    if [[ -n $mount_marker ]]; then
        [[ ! -e $FIXTURE_ROOT/${mount_marker}-private-volume-wrong ]] \
            || state_volume=rogue-${mount_marker}-private-volume
        if [[ -e $FIXTURE_ROOT/${mount_marker}-private-volume-swapped ]]; then
            case "$mount_marker" in
                candidate) state_volume=candidate-b-control-plane-state ;;
                candidate-b) state_volume=candidate-control-plane-state ;;
            esac
        fi
        [[ ! -e $FIXTURE_ROOT/${mount_marker}-coordination-volume-wrong ]] \
            || coordination_volume=rogue-${mount_marker}-coordination-volume
        if [[ -e $FIXTURE_ROOT/${mount_marker}-mounts-swapped ]]; then
            local swapped_private_volume=$state_volume
            state_volume=$coordination_volume
            coordination_volume=$swapped_private_volume
        fi
    fi
    networks=$(printf \
        '{"coolify":{"NetworkID":"%s","IPAddress":"%s","GlobalIPv6Address":"%s"}}' \
        "$network_id" "$address" "$ipv6_address")
    if [[ $id == "$candidate_id" && -e $FIXTURE_ROOT/candidate-second-network ]]; then
        networks=$(printf \
            '{"coolify":{"NetworkID":"%s","IPAddress":"%s","GlobalIPv6Address":""},"rogue":{"NetworkID":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","IPAddress":"172.19.0.4","GlobalIPv6Address":""}}' \
            "$network_id" "$address")
    fi
    [[ $id != "$candidate_id" || ! -e $FIXTURE_ROOT/candidate-wrong-runtime ]] \
        || working_dir=/unexpected
    [[ $id != "$candidate_id" || ! -e $FIXTURE_ROOT/candidate-wrong-bindings ]] \
        || host_port=28080
    [[ $id != "$candidate_id" || ! -e $FIXTURE_ROOT/candidate-coordination-mount-drift ]] \
        || coordination_volume=rogue-coordination
    if [[ -n $startup_source ]]; then
        mounts=$(jq -S -c -n --arg startup "$startup_source" --arg token "$token_source" \
            --arg ack "$ack_source" --arg state "$state_volume" \
            --arg coordination "$FIXTURE_ROOT/coordination" \
            --arg coordination_volume "$coordination_volume" \
            --argjson secret_rw "$secret_read_write" '[
                {Type: "bind", Name: "", Source: $startup,
                    Destination: "/var/www/html/.env", Driver: "", Mode: "ro",
                    RW: false, Propagation: "rprivate"},
                {Type: "volume", Name: $state,
                    Source: ("/var/lib/docker/volumes/" + $state + "/_data"),
                    Destination: "/var/lib/coolify-control-plane/private", Driver: "local",
                    Mode: "z", RW: true, Propagation: ""},
                {Type: "volume", Name: $coordination_volume,
                    Source: $coordination,
                    Destination: "/var/lib/coolify-control-plane/coordination", Driver: "local",
                    Mode: "z", RW: true, Propagation: ""},
                {Type: "bind", Name: "", Source: $token,
                    Destination: "/run/secrets/control-plane-direct-probe-token", Driver: "",
                    Mode: "ro", RW: false, Propagation: "rprivate"},
                {Type: "bind", Name: "", Source: $ack,
                    Destination: "/run/secrets/control-plane-applied-ack", Driver: "",
                    Mode: (if $secret_rw then "rw" else "ro" end),
                    RW: $secret_rw, Propagation: "rprivate"}
            ]')
        binds=$(jq -S -c -n --arg startup "$startup_source" --arg token "$token_source" \
            --arg ack "$ack_source" --arg state "$state_volume" \
            --arg coordination_volume "$coordination_volume" \
            --argjson secret_rw "$secret_read_write" '[
                ($startup + ":/var/www/html/.env:ro"),
                ($state + ":/var/lib/coolify-control-plane/private:rw,z"),
                ($coordination_volume + ":/var/lib/coolify-control-plane/coordination:rw,z"),
                ($token + ":/run/secrets/control-plane-direct-probe-token:ro"),
                ($ack + ":/run/secrets/control-plane-applied-ack:"
                    + (if $secret_rw then "rw" else "ro" end))
            ]')
        if [[ -e $FIXTURE_ROOT/${mount_marker}-private-volume-missing ]]; then
            mounts=$(jq -S -c 'map(select(
                .Destination != "/var/lib/coolify-control-plane/private"))' <<< "$mounts")
        fi
        if [[ -e $FIXTURE_ROOT/${mount_marker}-coordination-volume-missing ]]; then
            mounts=$(jq -S -c 'map(select(
                .Destination != "/var/lib/coolify-control-plane/coordination"))' <<< "$mounts")
        fi
        if [[ -e $FIXTURE_ROOT/${mount_marker}-private-volume-extra-destination ]]; then
            mounts=$(jq -S -c --arg volume "$state_volume" '. + [{
                Type: "volume", Name: $volume,
                Source: ("/var/lib/docker/volumes/" + $volume + "/_data"),
                Destination: "/var/lib/coolify-control-plane/private-shadow",
                Driver: "local", Mode: "z", RW: true, Propagation: ""
            }]' <<< "$mounts")
        fi
        if [[ -e $FIXTURE_ROOT/${mount_marker}-coordination-volume-extra-destination ]]; then
            mounts=$(jq -S -c --arg volume "$coordination_volume" '. + [{
                Type: "volume", Name: $volume,
                Source: ("/var/lib/docker/volumes/" + $volume + "/_data"),
                Destination: "/var/lib/coolify-control-plane/coordination-shadow",
                Driver: "local", Mode: "z", RW: true, Propagation: ""
            }]' <<< "$mounts")
        fi
        if [[ -e $FIXTURE_ROOT/${mount_marker}-other-private-volume-extra-destination ]]; then
            case "$mount_marker" in
                candidate) other_private_volume=candidate-b-control-plane-state ;;
                candidate-b) other_private_volume=candidate-control-plane-state ;;
            esac
            mounts=$(jq -S -c --arg volume "$other_private_volume" '. + [{
                Type: "volume", Name: $volume,
                Source: ("/var/lib/docker/volumes/" + $volume + "/_data"),
                Destination: "/var/lib/coolify-control-plane/foreign-private",
                Driver: "local", Mode: "z", RW: true, Propagation: ""
            }]' <<< "$mounts")
        fi
    fi
    jq -S -c -n --arg id "$id" --arg image_id "sha256:$image" \
        --arg image_reference "$image_reference" --arg working_dir "$working_dir" \
        --arg started "$started" --arg health "$health" --arg host_port "$host_port" \
        --arg color "$control_plane_color" --arg service "$service" \
        --arg route_identity "$route_identity" \
        --arg restart_policy "$restart_policy" \
        --arg pool_label_value "$pool_label_value" \
        --argjson privileged "$privileged" --argjson networks "$networks" \
        --argjson mounts "$mounts" --argjson binds "$binds" '[{
            Id: $id,
            Image: $image_id,
            Config: {
                Image: $image_reference,
                WorkingDir: $working_dir,
                User: "9999:9999",
                AttachStdin: false,
                AttachStdout: true,
                AttachStderr: true,
                ExposedPorts: {"8080/tcp": {}},
                Tty: false,
                OpenStdin: false,
                StdinOnce: false,
                Entrypoint: [],
                Cmd: ["sleep", "3600"],
                Env: ["APP_ENV=production", "APP_FAKE_SECRET=fixture-only",
                    ("CONTROL_PLANE_COLOR=" + $color), "CONTROL_PLANE_MODE=active",
                    ("CONTROL_PLANE_MEMBER_ID=" + $route_identity)],
                Healthcheck: {Test: ["CMD-SHELL", "true"]},
                ArgsEscaped: false,
                Volumes: {
                    "/var/lib/coolify-control-plane/private": {},
                    "/var/lib/coolify-control-plane/coordination": {}
                },
                NetworkDisabled: false,
                OnBuild: null,
                Labels: ({
                    "com.docker.compose.project": "coolify",
                    "com.docker.compose.service": $service,
                    "com.docker.compose.project.config_files": "/data/coolify/source/docker-compose.yml,/data/coolify/source/docker-compose.prod.yml"
                } + (if $pool_label_value == "" then {} else {
                    "coolify.control-plane.pool": $pool_label_value
                } end)),
                StopSignal: "SIGTERM",
                StopTimeout: 10,
                Shell: ["/bin/sh", "-c"]
            },
            State: {StartedAt: $started, Status: "running", Running: true,
                Health: {Status: $health}},
            RestartCount: 0,
            HostConfig: {
                Binds: $binds,
                Mounts: null,
                ContainerIDFile: "",
                LogConfig: {Type: "json-file", Config: {}},
                NetworkMode: "coolify",
                PortBindings: {"8080/tcp": [{HostIp: "127.0.0.1", HostPort: $host_port}]},
                RestartPolicy: {Name: $restart_policy, MaximumRetryCount: 0},
                AutoRemove: false,
                VolumeDriver: "",
                VolumesFrom: null,
                ConsoleSize: [0, 0],
                CapAdd: null,
                CapDrop: [],
                CgroupnsMode: "private",
                Dns: [],
                DnsOptions: [],
                DnsSearch: [],
                ExtraHosts: ["host.docker.internal:host-gateway"],
                GroupAdd: ["9999"],
                IpcMode: "private",
                Cgroup: "",
                Links: null,
                OomScoreAdj: 0,
                PidMode: "",
                Privileged: $privileged,
                PublishAllPorts: false,
                ReadonlyRootfs: false,
                SecurityOpt: [],
                StorageOpt: {},
                Tmpfs: {"/tmp": "rw,noexec,nosuid,size=65536k"},
                UTSMode: "",
                UsernsMode: "",
                ShmSize: 67108864,
                Sysctls: {"net.ipv4.ip_unprivileged_port_start": "0"},
                Runtime: "runc",
                Isolation: "default",
                Init: false,
                Devices: [],
                DeviceCgroupRules: null,
                DeviceRequests: [],
                Ulimits: [{Name: "nofile", Soft: 1024, Hard: 1024}],
                OomKillDisable: false,
                PidsLimit: 256,
                Memory: 536870912,
                MemoryReservation: 268435456,
                MemorySwap: 1073741824,
                MemorySwappiness: null,
                KernelMemoryTCP: 0,
                CpuShares: 512,
                NanoCpus: 1000000000,
                CpuPeriod: 0,
                CpuQuota: 0,
                CpuRealtimePeriod: 0,
                CpuRealtimeRuntime: 0,
                CpusetCpus: "0",
                CpusetMems: "0",
                CpuCount: 0,
                CpuPercent: 0,
                BlkioWeight: 0,
                BlkioWeightDevice: [],
                BlkioDeviceReadBps: [],
                BlkioDeviceWriteBps: [],
                BlkioDeviceReadIOps: [],
                BlkioDeviceWriteIOps: [],
                IOMaximumIOps: 0,
                IOMaximumBandwidth: 0,
                MaskedPaths: ["/proc/kcore"],
                ReadonlyPaths: ["/proc/bus"]
            },
            Mounts: $mounts,
            NetworkSettings: {
                Ports: {"8080/tcp": [{HostIp: "127.0.0.1", HostPort: $host_port}]},
                Networks: $networks
            }
        }]'
}

case "$command_name" in
    docker)
        case "${1:-}" in
            version) printf '28.4.0/28.4.0\n' ;;
            info) printf 'fixture-daemon-id\n' ;;
            inspect)
                shift
                if [[ ${1:-} == --format ]]; then
                    format=$2
                    shift 2
                    case "${1:-}" in
                        proxy|"$proxy_id")
                            if [[ $format == *State.Pid* ]]; then
                                printf '%s|true|2222\n' "$proxy_id"
                            else
                                printf '%s\n' "$proxy_id"
                            fi
                            ;;
                        legacy)
                            [[ ! -e $FIXTURE_ROOT/legacy-absent ]] || exit 1
                            if [[ -e $FIXTURE_ROOT/legacy-replacement ]]; then
                                printf '%s\n' "$replacement_legacy_id"
                            else
                                printf '%s\n' "$legacy_id"
                            fi
                            ;;
                        "$legacy_id")
                            [[ ! -e $FIXTURE_ROOT/legacy-absent \
                                && ! -e $FIXTURE_ROOT/legacy-replacement ]] || exit 1
                            printf '%s\n' "$legacy_id"
                            ;;
                        "$replacement_legacy_id")
                            [[ -e $FIXTURE_ROOT/legacy-replacement ]] || exit 1
                            printf '%s\n' "$replacement_legacy_id"
                            ;;
                        candidate|"$candidate_id")
                            [[ ! -e $FIXTURE_ROOT/candidate-absent ]] || exit 1
                            if [[ $format == *State.Running* ]]; then
                                printf 'true:healthy\n'
                            else
                                printf '%s\n' "$candidate_id"
                            fi
                            ;;
                        candidate-b|"$candidate_b_id")
                            [[ ! -e $FIXTURE_ROOT/candidate-absent ]] || exit 1
                            if [[ $format == *State.Running* ]]; then
                                printf 'true:healthy\n'
                            else
                                printf '%s\n' "$candidate_b_id"
                            fi
                            ;;
                        *) exit 1 ;;
                    esac
                elif [[ $# -eq 2 && $1 == proxy && $2 == legacy ]]; then
                    printf '[{"NetworkSettings":{"Networks":{"coolify":{"NetworkID":"%s"}}}},{"NetworkSettings":{"Networks":{"coolify":{"NetworkID":"%s"}}}}]\n' "$network_id" "$network_id"
                else
                    case "${1:-}" in
                        proxy|"$proxy_id") container_json "$proxy_id" 1111111111111111111111111111111111111111111111111111111111111111 2026-07-14T01:00:00Z ;;
                        legacy)
                            [[ ! -e $FIXTURE_ROOT/legacy-absent ]] || exit 1
                            if [[ -e $FIXTURE_ROOT/legacy-replacement ]]; then
                                container_json "$replacement_legacy_id" 2222222222222222222222222222222222222222222222222222222222222222 2026-07-14T02:01:00Z
                            else
                                container_json "$legacy_id" 2222222222222222222222222222222222222222222222222222222222222222 2026-07-14T01:01:00Z
                            fi
                            ;;
                        "$legacy_id")
                            [[ ! -e $FIXTURE_ROOT/legacy-absent \
                                && ! -e $FIXTURE_ROOT/legacy-replacement ]] || exit 1
                            container_json "$legacy_id" 2222222222222222222222222222222222222222222222222222222222222222 2026-07-14T01:01:00Z
                            ;;
                        "$replacement_legacy_id")
                            [[ -e $FIXTURE_ROOT/legacy-replacement ]] || exit 1
                            container_json "$replacement_legacy_id" 2222222222222222222222222222222222222222222222222222222222222222 2026-07-14T02:01:00Z
                            ;;
                        candidate|"$candidate_id")
                            [[ ! -e $FIXTURE_ROOT/candidate-absent ]] || exit 1
                            container_json "$candidate_id" 3333333333333333333333333333333333333333333333333333333333333333 2026-07-14T01:02:00Z
                            ;;
                        candidate-b|"$candidate_b_id")
                            [[ ! -e $FIXTURE_ROOT/candidate-absent ]] || exit 1
                            container_json "$candidate_b_id" 7777777777777777777777777777777777777777777777777777777777777777 2026-07-14T01:03:00Z
                            ;;
                        *) exit 1 ;;
                    esac
                fi
                ;;
            network)
                [[ $2 == inspect ]]
                if [[ ${4:-} == --format || ${3:-} == --format ]]; then
                    printf 'bridge\n'
                else
                    printf '[{"Id":"%s","Name":"coolify","Driver":"bridge","IPAM":{"Config":[{"Subnet":"172.18.0.0/16","Gateway":"172.18.0.1"},{"Subnet":"fd00:18::/64","Gateway":"fd00:18::1"}]}}]\n' "$network_id"
                fi
                ;;
            top) exit 0 ;;
            exec)
                if [[ $* == *'php -r'* ]]; then
                    printf '0 0 0\n'
                else
                    printf 'ssh: connect to host 172.18.0.1 port 22: Connection timed out\n' >&2
                    exit 255
                fi
                ;;
            ps)
                if [[ -e $FIXTURE_ROOT/legacy-replacement ]]; then
                    printf '%s\n' "$replacement_legacy_id"
                elif [[ ! -e $FIXTURE_ROOT/legacy-absent ]]; then
                    printf '%s\n' "$legacy_id"
                fi
                [[ -e $FIXTURE_ROOT/candidate-absent ]] || printf '%s\n' "$candidate_id"
                [[ -e $FIXTURE_ROOT/candidate-absent ]] || printf '%s\n' "$candidate_b_id"
                ;;
            *) exit 1 ;;
        esac
        ;;
    nft)
        case "${1:-}" in
            --version) printf 'nftables v1.0.9\n' ;;
            --check) exit 0 ;;
            --file)
                handle=1
                [[ ! -f $FIXTURE_ROOT/nft-handle ]] || handle=$(( $(cat "$FIXTURE_ROOT/nft-handle") + 1 ))
                printf '%s\n' "$handle" > "$FIXTURE_ROOT/nft-handle"
                printf '{"nftables":[{"metainfo":{"json_schema_version":1,"release_name":"fixture-%s"}},{"table":{"family":"inet","name":"coolify_control_plane_ssh_fence","handle":%s}},{"chain":{"family":"inet","table":"coolify_control_plane_ssh_fence","name":"container_to_host_ssh","handle":%s}}]}\n' \
                    "$handle" "$handle" "$((handle + 10))" > "$FIXTURE_ROOT/nft.json"
                ;;
            --json)
                [[ -f $FIXTURE_ROOT/nft.json ]] || exit 1
                cat "$FIXTURE_ROOT/nft.json"
                ;;
            list) [[ -f $FIXTURE_ROOT/nft.json ]] ;;
            delete)
                [[ $2 == table && $3 == inet && $4 == coolify_control_plane_ssh_fence ]]
                rm -f "$FIXTURE_ROOT/nft.json"
                ;;
            *) exit 1 ;;
        esac
        ;;
    systemctl)
        case "${1:-}" in
            show)
                unit=${2:-}
                properties=()
                value_only=0
                for argument in "$@"; do
                    case "$argument" in
                        --property=*) properties+=("${argument#--property=}") ;;
                        --value) value_only=1 ;;
                    esac
                done
                [[ ${#properties[@]} -gt 0 ]] || exit 1
                for property in "${properties[@]}"; do
                    [[ $value_only == 1 ]] || printf '%s=' "$property"
                    case "$unit:$property" in
                    docker.service:MainPID) cat "$FIXTURE_ROOT/dockerd-pid" ;;
                    docker.service:InvocationID) cat "$FIXTURE_ROOT/invocation-id" ;;
                    coolify-runtime-attestation-ssh-fence.service:FragmentPath|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:FragmentPath)
                        printf '%s/%s\n' "$CONTROL_PLANE_RUNTIME_TEST_SYSTEMD_DIRECTORY" "$unit"
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:DropInPaths|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:DropInPaths)
                        if [[ -e $FIXTURE_ROOT/systemd-drop-in ]]; then
                            printf '%s/drop-ins/runtime-drift.conf\n' \
                                "$CONTROL_PLANE_RUNTIME_TEST_SYSTEMD_DIRECTORY"
                        else
                            printf '\n'
                        fi
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:LoadState|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:LoadState)
                        printf 'loaded\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:ExecStart)
                        printf '{ path=/usr/local/libexec/coolify-runtime-attestation-ssh-fence ; argv[]=/usr/local/libexec/coolify-runtime-attestation-ssh-fence restore ; ignore_errors=no ; start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:ExecStart)
                        printf '{ path=/usr/local/libexec/coolify-runtime-attestation-ssh-fence ; argv[]=/usr/local/libexec/coolify-runtime-attestation-ssh-fence watch ; ignore_errors=no ; start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:EnvironmentFiles|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:EnvironmentFiles)
                        printf '{ path=/etc/coolify-runtime-attestation-ssh-fence/runtime.env ; ignore_errors=no }\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:Before)
                        printf 'docker.socket network-pre.target docker.service\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:After)
                        printf 'local-fs.target\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:After)
                        printf 'docker.service\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:PartOf)
                        printf 'docker.socket docker.service\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:BindsTo|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:PartOf)
                        printf 'docker.service\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:User|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:User)
                        printf 'root\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:Group|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:Group)
                        printf 'root\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:CapabilityBoundingSet|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:CapabilityBoundingSet|\
                    coolify-runtime-attestation-ssh-fence.service:AmbientCapabilities|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:AmbientCapabilities)
                        printf 'cap_net_admin\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:NoNewPrivileges|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:NoNewPrivileges|\
                    coolify-runtime-attestation-ssh-fence.service:PrivateTmp|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:PrivateTmp)
                        printf 'yes\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:ProtectHome|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:ProtectHome|\
                    coolify-runtime-attestation-ssh-fence.service:ProtectSystem|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:ProtectSystem)
                        printf 'strict\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:ReadWritePaths|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:ReadWritePaths)
                        printf '/var/lib/coolify-runtime-attestation-ssh-fence\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:RestrictAddressFamilies|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:RestrictAddressFamilies)
                        printf 'AF_UNIX AF_NETLINK\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:Restart)
                        printf 'no\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:Restart)
                        printf 'always\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:RestartUSec)
                        printf '100ms\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:RestartUSec)
                        printf '1s\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:Type)
                        printf 'oneshot\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:Type)
                        printf 'simple\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:RemainAfterExit)
                        printf 'yes\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:RemainAfterExit)
                        printf 'no\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:DefaultDependencies)
                        printf 'no\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence-watchdog.service:DefaultDependencies)
                        printf 'yes\n'
                        ;;
                    coolify-runtime-attestation-ssh-fence.service:*|\
                    coolify-runtime-attestation-ssh-fence-watchdog.service:*)
                        printf '\n'
                        ;;
                        *) exit 1 ;;
                    esac
                done
                ;;
            --version) printf 'systemd 255 (fixture)\n' ;;
            *) exit 1 ;;
        esac
        ;;
    conntrack)
        if [[ ${1:-} == -V ]]; then
            printf 'conntrack v1.4.8\n'
        elif [[ ${1:-} == --delete ]]; then
            printf '%s\n' "$*" >> "$FIXTURE_ROOT/conntrack-delete.log"
            exit 1
        fi
        ;;
    ssh)
        [[ ${1:-} == -V ]] && printf 'OpenSSH_9.6p1 fixture\n' >&2
        ;;
    ssh-keyscan) printf 'fixture ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAfixture\n' ;;
    ip)
        case "$*" in
            '-d -j link show dev tailscale0')
                if [[ -e $FIXTURE_ROOT/management-bridge ]]; then
                    printf '%s\n' '[{"ifname":"tailscale0","linkinfo":{"info_kind":"bridge"}}]'
                elif [[ -e $FIXTURE_ROOT/management-bridge-master ]]; then
                    printf '%s\n' '[{"ifname":"tailscale0","master":"br-management","linkinfo":{"info_kind":"wireguard"}}]'
                else
                    printf '%s\n' '[{"ifname":"tailscale0","linkinfo":{"info_kind":"wireguard"}}]'
                fi
                ;;
            '-d -j link show dev br-management')
                [[ -e $FIXTURE_ROOT/management-bridge-master ]] || exit 1
                printf '%s\n' '[{"ifname":"br-management","linkinfo":{"info_kind":"bridge"}}]'
                ;;
            '-o link show dev br-dddddddddddd')
                printf '42: br-dddddddddddd: <BROADCAST,MULTICAST,UP> mtu 1500 state UP\n'
                ;;
            '-o address show dev tailscale0')
                printf '1: tailscale0    inet 100.64.0.1/32 scope global tailscale0\n'
                ;;
            *) exit 1 ;;
        esac
        ;;
    ipcalc) exit 0 ;;
    nsenter)
        while [[ ${1:-} != -- ]]; do
            shift
        done
        shift
        "$@"
        ;;
    flock) exit 0 ;;
    stat)
        stat_uid=${FIXTURE_STAT_UID:-0}
        stat_gid=${FIXTURE_STAT_GID:-0}
        stat_path=${*: -1}
        if [[ -n ${FIXTURE_REAL_STAT:-} ]]; then
            stat_arguments=("$@")
            if [[ ${1:-} == -Lc && ${2:-} == '%d:%i:%f:%u:%g:%a:%s' \
                && $stat_path == /proc/self/fd/* ]]; then
                cat "$FIXTURE_ROOT/stat-last-identity"
            elif [[ ${1:-} == -c && ${2:-} == '%d:%i:%f:%u:%g:%a:%s' ]]; then
                "$FIXTURE_REAL_STAT" "${stat_arguments[@]}" \
                    | tee "$FIXTURE_ROOT/stat-last-identity"
            else
                "$FIXTURE_REAL_STAT" "${stat_arguments[@]}"
            fi
        elif [[ ${1:-} == -Lc && ${2:-} == '%d:%i' ]]; then
            printf '2049:12345\n'
        elif [[ ${1:-} == -Lc && ${2:-} == '%d:%i:%f:%u:%g:%a:%s' ]]; then
            printf '2049:12345:8180:%s:%s:600:0\n' "$stat_uid" "$stat_gid"
        elif [[ ${1:-} == -c && ${2:-} == '%d:%i:%f:%u:%g:%a:%s' ]]; then
            printf '2049:12345:8180:%s:%s:600:0\n' "$stat_uid" "$stat_gid"
        elif [[ ${1:-} == -c && ${2:-} == '%d:%i:%u:%g:%a:%s' ]]; then
            printf '2049:12345:%s:%s:600:%s\n' "$stat_uid" "$stat_gid" \
                "$(wc -c < "$stat_path" | tr -d '[:space:]')"
        elif [[ ${1:-} == -c && ${2:-} == '%u:%g:%a' ]]; then
            printf '%s:%s:600\n' "$stat_uid" "$stat_gid"
        elif [[ ${1:-} == -c && ${2:-} == '%u:%g:%a:%s' ]]; then
            printf '%s:%s:600:%s\n' "$stat_uid" "$stat_gid" \
                "$(wc -c < "$stat_path" | tr -d '[:space:]')"
        else
            exit 1
        fi
        ;;
    curl)
        body_output=
        header_output=
        unix_socket=
        url=${*: -1}
        previous=
        for argument in "$@"; do
            if [[ $previous == --output ]]; then
                body_output=$argument
            fi
            if [[ $previous == --dump-header ]]; then
                header_output=$argument
            fi
            if [[ $previous == --unix-socket ]]; then
                unix_socket=$argument
            fi
            previous=$argument
        done
        if [[ -n $unix_socket ]]; then
            docker_api_path=${url#http://localhost/}
            docker_api_scenario=healthy
            [[ ! -f $FIXTURE_ROOT/docker-api-scenario ]] \
                || docker_api_scenario=$(<"$FIXTURE_ROOT/docker-api-scenario")
            docker_api_effective_scenario=$docker_api_scenario
            if [[ $docker_api_scenario == absence-* ]]; then
                docker_api_effective_scenario=healthy
                if [[ $docker_api_path == containers/*/json ]]; then
                    docker_api_reference=${docker_api_path#containers/}
                    docker_api_reference=${docker_api_reference%/json}
                    if [[ ($docker_api_reference == candidate \
                            || $docker_api_reference == "$candidate_id") \
                            && -e $FIXTURE_ROOT/candidate-absent ]] \
                        || [[ ($docker_api_reference == legacy \
                            || $docker_api_reference == "$legacy_id") \
                            && -e $FIXTURE_ROOT/legacy-absent ]]; then
                        docker_api_effective_scenario=${docker_api_scenario#absence-}
                    fi
                fi
            fi
            case "$docker_api_effective_scenario" in
                daemon-unavailable)
                    printf '%s\n' 'Cannot connect to the Docker daemon' >&2
                    exit 7
                    ;;
                permission-denied)
                    printf '%s\n' 'Permission denied opening Docker socket' >&2
                    exit 7
                    ;;
                timeout)
                    printf '%s\n' 'Docker Engine request timed out' >&2
                    exit 28
                    ;;
                api-500)
                    printf '%s\n' '{"message":"fixture Engine failure"}' > "$body_output"
                    printf '500'
                    exit 0
                    ;;
                malformed-success)
                    printf '%s\n' 'not-json' > "$body_output"
                    printf '200'
                    exit 0
                    ;;
                malformed-not-found)
                    printf '%s\n' '{"message":"unrelated missing object"}' > "$body_output"
                    printf '404'
                    exit 0
                    ;;
            esac
            case "$docker_api_path" in
                containers/json\?*)
                    case "$docker_api_effective_scenario" in
                        list-failure)
                            printf '%s\n' '{"message":"fixture container list failure"}' \
                                > "$body_output"
                            printf '500'
                            ;;
                        malformed-list)
                            printf '%s\n' '{}' > "$body_output"
                            printf '200'
                            ;;
                        *)
                            jq -S -c -n \
                                --arg proxy_id "$proxy_id" \
                                --arg legacy_id "$legacy_id" \
                                --arg replacement_legacy_id "$replacement_legacy_id" \
                                --arg candidate_id "$candidate_id" \
                                --arg candidate_b_id "$candidate_b_id" \
                                --arg pool_label_value "${FIXTURE_POOL_LABEL_VALUE:-runtime-fence-test}" \
                                --argjson legacy_absent "$([[ -e $FIXTURE_ROOT/legacy-absent ]] && printf true || printf false)" \
                                --argjson legacy_replacement "$([[ -e $FIXTURE_ROOT/legacy-replacement ]] && printf true || printf false)" \
                                --argjson candidate_absent "$([[ -e $FIXTURE_ROOT/candidate-absent ]] && printf true || printf false)" '
                                [{Id: $proxy_id, Names: ["/proxy"], Labels: {}}]
                                + (if $legacy_absent then []
                                    elif $legacy_replacement then
                                        [{Id: $replacement_legacy_id, Names: ["/legacy"], Labels: {}}]
                                    else [{Id: $legacy_id, Names: ["/legacy"], Labels: {}}] end)
                                + (if $candidate_absent then []
                                    else [
                                        {Id: $candidate_id, Names: ["/candidate"],
                                            Labels: {"coolify.control-plane.pool": $pool_label_value}},
                                        {Id: $candidate_b_id, Names: ["/candidate-b"],
                                            Labels: {"coolify.control-plane.pool": $pool_label_value}}
                                    ] end)
                            ' > "$body_output"
                            printf '200'
                            ;;
                    esac
                    ;;
                containers/*/json)
                    docker_api_reference=${docker_api_path#containers/}
                    docker_api_reference=${docker_api_reference%/json}
                    if [[ $docker_api_effective_scenario == active-malformed ]]; then
                        if [[ $docker_api_reference == candidate ]]; then
                            printf '%s\n' '{"Id":"short","Name":"/candidate"}' > "$body_output"
                            printf '200'
                        else
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        fi
                    elif [[ $docker_api_effective_scenario == legacy-id-present \
                        && $docker_api_reference == "$legacy_id" ]]; then
                        container_json "$legacy_id" \
                            2222222222222222222222222222222222222222222222222222222222222222 \
                            2026-07-14T01:01:00Z \
                            | jq -c '.[0] + {Name: "/legacy"}' > "$body_output"
                        printf '200'
                    elif [[ $docker_api_reference == proxy \
                        || $docker_api_reference == "$proxy_id" ]]; then
                        container_json "$proxy_id" \
                            1111111111111111111111111111111111111111111111111111111111111111 \
                            2026-07-14T01:00:00Z \
                            | jq -c '.[0] + {Name: "/proxy"}' > "$body_output"
                        printf '200'
                    elif [[ $docker_api_reference == legacy ]]; then
                        if [[ -e $FIXTURE_ROOT/legacy-absent \
                            || $docker_api_effective_scenario == legacy-id-present ]]; then
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        elif [[ -e $FIXTURE_ROOT/legacy-replacement ]]; then
                            container_json "$replacement_legacy_id" \
                                2222222222222222222222222222222222222222222222222222222222222222 \
                                2026-07-14T02:01:00Z \
                                | jq -c '.[0] + {Name: "/legacy"}' > "$body_output"
                            printf '200'
                        else
                            container_json "$legacy_id" \
                                2222222222222222222222222222222222222222222222222222222222222222 \
                                2026-07-14T01:01:00Z \
                                | jq -c '.[0] + {Name: "/legacy"}' > "$body_output"
                            printf '200'
                        fi
                    elif [[ $docker_api_reference == "$legacy_id" ]]; then
                        if [[ -e $FIXTURE_ROOT/legacy-absent \
                            || -e $FIXTURE_ROOT/legacy-replacement ]]; then
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        else
                            container_json "$legacy_id" \
                                2222222222222222222222222222222222222222222222222222222222222222 \
                                2026-07-14T01:01:00Z \
                                | jq -c '.[0] + {Name: "/legacy"}' > "$body_output"
                            printf '200'
                        fi
                    elif [[ $docker_api_reference == "$replacement_legacy_id" ]]; then
                        if [[ -e $FIXTURE_ROOT/legacy-replacement ]]; then
                            container_json "$replacement_legacy_id" \
                                2222222222222222222222222222222222222222222222222222222222222222 \
                                2026-07-14T02:01:00Z \
                                | jq -c '.[0] + {Name: "/legacy"}' > "$body_output"
                            printf '200'
                        else
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        fi
                    elif [[ $docker_api_reference == candidate \
                        || $docker_api_reference == "$candidate_id" ]]; then
                        if [[ -e $FIXTURE_ROOT/candidate-absent ]]; then
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        else
                            container_json "$candidate_id" \
                                3333333333333333333333333333333333333333333333333333333333333333 \
                                2026-07-14T01:02:00Z \
                                | jq -c '.[0] + {Name: "/candidate"}' > "$body_output"
                            printf '200'
                        fi
                    elif [[ $docker_api_reference == candidate-b \
                        || $docker_api_reference == "$candidate_b_id" ]]; then
                        if [[ -e $FIXTURE_ROOT/candidate-absent ]]; then
                            printf '{"message":"No such container: %s"}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '404'
                        else
                            container_json "$candidate_b_id" \
                                7777777777777777777777777777777777777777777777777777777777777777 \
                                2026-07-14T01:03:00Z \
                                | jq -c '.[0] + {Name: "/candidate-b"}' > "$body_output"
                            printf '200'
                        fi
                    else
                        printf '{"message":"No such container: %s"}\n' \
                            "$docker_api_reference" > "$body_output"
                        printf '404'
                    fi
                    ;;
                info)
                    case "$docker_api_effective_scenario" in
                        info-failure)
                            printf '%s\n' '{"message":"fixture info failure"}' > "$body_output"
                            printf '500'
                            ;;
                        malformed-info)
                            printf '%s\n' '{"ID":""}' > "$body_output"
                            printf '200'
                            ;;
                        *)
                            printf '%s\n' '{"ID":"fixture-daemon-id"}' > "$body_output"
                            printf '200'
                            ;;
                    esac
                    ;;
                networks/*)
                    docker_api_reference=${docker_api_path#networks/}
                    case "$docker_api_effective_scenario" in
                        network-failure)
                            printf '%s\n' '{"message":"fixture network failure"}' \
                                > "$body_output"
                            printf '500'
                            ;;
                        malformed-network)
                            printf '%s\n' '{"Id":"short"}' > "$body_output"
                            printf '200'
                            ;;
                        *)
                            printf '{"Id":"%s","Name":"coolify","Driver":"bridge","Options":{},"IPAM":{"Config":[{"Subnet":"172.18.0.0/16","Gateway":"172.18.0.1"},{"Subnet":"fd00:18::/64","Gateway":"fd00:18::1"}]}}\n' \
                                "$docker_api_reference" > "$body_output"
                            printf '200'
                            ;;
                    esac
                    ;;
                *) exit 64 ;;
            esac
        elif [[ $url == *api/rawdata* ]]; then
            if [[ ${CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION:-} == absent ]]; then
                printf '%s\n' '{"routers":{},"services":{},"errors":[]}'
            elif [[ -e $FIXTURE_ROOT/provider-wrong-backend ]]; then
                printf '%s\n' '{"routers":{"coolify@docker":{"provider":"docker","status":"enabled","service":"coolify"}},"services":{"coolify@docker":{"provider":"docker","status":"enabled","usedBy":["coolify@docker"],"loadBalancer":{"servers":[{"url":"http://172.18.0.99:8080"}]},"serverStatus":{"http://172.18.0.99:8080":"UP"}}},"errors":[]}'
            elif [[ -e $FIXTURE_ROOT/provider-a-dual-stack-missing-b ]]; then
                printf '%s\n' '{"routers":{"coolify@docker":{"provider":"docker","status":"enabled","service":"coolify"}},"services":{"coolify@docker":{"provider":"docker","status":"enabled","usedBy":["coolify@docker"],"loadBalancer":{"servers":[{"url":"http://172.18.0.3:8080"},{"url":"http://[fd00:18::3]:8080"}]},"serverStatus":{"http://172.18.0.3:8080":"UP","http://[fd00:18::3]:8080":"UP"}}},"errors":[]}'
            elif [[ -e $FIXTURE_ROOT/provider-dual-stack-chosen-members ]]; then
                printf '%s\n' '{"routers":{"coolify@docker":{"provider":"docker","status":"enabled","service":"coolify"}},"services":{"coolify@docker":{"provider":"docker","status":"enabled","usedBy":["coolify@docker"],"loadBalancer":{"servers":[{"url":"http://[fd00:18::3]:8080"},{"url":"http://172.18.0.5:8080"}]},"serverStatus":{"http://[fd00:18::3]:8080":"UP","http://172.18.0.5:8080":"UP"}}},"errors":[]}'
            elif [[ ${CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT:-1} == 2 ]]; then
                printf '%s\n' '{"routers":{"coolify@docker":{"provider":"docker","status":"enabled","service":"coolify"}},"services":{"coolify@docker":{"provider":"docker","status":"enabled","usedBy":["coolify@docker"],"loadBalancer":{"servers":[{"url":"http://172.18.0.3:8080"},{"url":"http://172.18.0.5:8080"}]},"serverStatus":{"http://172.18.0.3:8080":"UP","http://172.18.0.5:8080":"UP"}}},"errors":[]}'
            else
                printf '%s\n' '{"routers":{"coolify@docker":{"provider":"docker","status":"enabled","service":"coolify"}},"services":{"coolify@docker":{"provider":"docker","status":"enabled","usedBy":["coolify@docker"],"loadBalancer":{"servers":[{"url":"http://172.18.0.3:8080"}]},"serverStatus":{"http://172.18.0.3:8080":"UP"}}},"errors":[]}'
            fi
        elif [[ -n $header_output ]]; then
            printf 'HTTP/1.1 200 OK\r\nX-Control-Plane-Pool-Ack: %s\r\n\r\n' \
                "${FIXTURE_POOL_ACK:-pool-ack-token-0001}" > "$header_output"
        fi
        ;;
    *) exit 1 ;;
esac
