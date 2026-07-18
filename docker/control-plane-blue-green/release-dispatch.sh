#!/bin/sh

set -eu
umask 077

readonly RELEASE_MANIFEST_VERSION=1
readonly PRODUCTION_RELEASE_MANIFEST=/etc/coolify-control-plane/release.manifest
readonly PRODUCTION_RELEASES_ROOT=/usr/local/lib/coolify-control-plane/releases
readonly PRODUCTION_GLOBAL_TRANSACTION_LOCK=/run/lock/coolify-control-plane-blue-green.lock
readonly PRODUCTION_SNAPSHOT_DIRECTORY=/run/coolify-control-plane-release-dispatch
readonly BACKUP_QUIESCE_TIMER_UNIT=control-plane-backup-quiesce-watchdog.timer
readonly RELEASE_REQUIRED_ASSET_ROLES='operator operator-compose rehearsal-compose ingress-controller backup-attestation-verifier backup-quiesce-controller backup-quiesce-service-unit backup-quiesce-timer-unit runtime-fence-provisioner runtime-fence-controller runtime-fence-controlmaster-reaper runtime-fence-provider-probe runtime-fence-queue-probe runtime-fence-terminal-probe runtime-fence-service-unit runtime-fence-watchdog-unit'
readonly LEGACY_RELEASE_REQUIRED_ASSET_ROLES='operator operator-compose rehearsal-compose ingress-controller backup-attestation-verifier backup-quiesce-controller runtime-fence-provisioner runtime-fence-controller runtime-fence-controlmaster-reaper runtime-fence-provider-probe runtime-fence-queue-probe runtime-fence-terminal-probe'
readonly DISPATCHABLE_RELEASE_ASSET_ROLES='operator ingress-controller backup-attestation-verifier backup-quiesce-controller runtime-fence-provisioner runtime-fence-controller runtime-fence-controlmaster-reaper runtime-fence-provider-probe runtime-fence-queue-probe runtime-fence-terminal-probe'
readonly STABLE_ASSET_KEYS='release-dispatcher release-launcher backup-quiesce-service-unit backup-quiesce-timer-unit runtime-fence-service-unit runtime-fence-watchdog-unit'
readonly SYSTEMD_ENABLEMENT_UNITS='control-plane-backup-quiesce-watchdog.timer coolify-runtime-attestation-ssh-fence.service coolify-runtime-attestation-ssh-fence-watchdog.service'

fail()
{
    printf 'CONTROL_PLANE_RELEASE_DISPATCH_FAILURE %s\n' "$1" >&2
    exit 1
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

file_link_count()
{
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

sync_path()
{
    sync "$1" 2>/dev/null || sync
}

host_path()
{
    if [ "$dispatch_test_mode" = 1 ]; then
        printf '%s%s\n' "$dispatch_test_root" "$1"
    else
        printf '%s\n' "$1"
    fi
}

canonical_file_path()
{
    canonical_parent=$(CDPATH='' cd -- "${1%/*}" 2>/dev/null && pwd -P) \
        || fail "file parent is unavailable: ${1%/*}"
    printf '%s/%s\n' "${canonical_parent%/}" "${1##*/}"
}

assert_safe_parent_chain()
{
    checked_path=$1
    checked_parent=${checked_path%/*}
    [ -n "$checked_parent" ] || checked_parent=/
    resolved_parent=$(CDPATH='' cd -- "$checked_parent" 2>/dev/null && pwd -P) \
        || fail "file parent is unavailable: $checked_parent"
    [ "$resolved_parent" = "$checked_parent" ] \
        || fail "file parent path contains a symlink: $checked_parent"
    while [ "$checked_parent" != / ]; do
        [ -d "$checked_parent" ] && [ ! -L "$checked_parent" ] \
            || fail "file parent is unsafe: $checked_parent"
        if [ "$dispatch_test_mode" = 0 ]; then
            [ "$(file_uid "$checked_parent"):$(file_gid "$checked_parent")" = 0:0 ] \
                || fail "production file parent is not root-owned: $checked_parent"
            checked_parent_mode=$(file_mode "$checked_parent")
            [ $((0$checked_parent_mode & 0022)) -eq 0 ] \
                || fail "production file parent is group/world writable: $checked_parent"
        fi
        checked_parent=${checked_parent%/*}
        [ -n "$checked_parent" ] || checked_parent=/
    done
}

assert_regular_single_link()
{
    asserted_path=$1
    asserted_label=$2
    [ -f "$asserted_path" ] && [ ! -L "$asserted_path" ] \
        || fail "$asserted_label must be a regular non-symlink file"
    [ "$(canonical_file_path "$asserted_path")" = "$asserted_path" ] \
        || fail "$asserted_label path contains a symlink"
    [ "$(file_link_count "$asserted_path")" = 1 ] \
        || fail "$asserted_label link count must be exactly one"
}

stable_asset_destination()
{
    case "$1" in
        release-dispatcher) host_path /usr/local/libexec/coolify-control-plane-release-dispatch ;;
        release-launcher) host_path /usr/local/sbin/control-plane-blue-green ;;
        backup-quiesce-service-unit) host_path /etc/systemd/system/control-plane-backup-quiesce-watchdog.service ;;
        backup-quiesce-timer-unit) host_path /etc/systemd/system/control-plane-backup-quiesce-watchdog.timer ;;
        runtime-fence-service-unit) host_path /etc/systemd/system/coolify-runtime-attestation-ssh-fence.service ;;
        runtime-fence-watchdog-unit) host_path /etc/systemd/system/coolify-runtime-attestation-ssh-fence-watchdog.service ;;
        *) fail "unknown stable release asset: $1" ;;
    esac
}

stable_asset_mode()
{
    case "$1" in
        release-dispatcher) printf '%s\n' 700 ;;
        release-launcher) printf '%s\n' 755 ;;
        *) printf '%s\n' 644 ;;
    esac
}

systemd_target_enablement_state()
{
    case "$1" in
        control-plane-backup-quiesce-watchdog.timer)
            printf '%s\n' enabled
            ;;
        coolify-runtime-attestation-ssh-fence.service|coolify-runtime-attestation-ssh-fence-watchdog.service)
            printf '%s\n' disabled
            ;;
        *)
            fail "systemd unit has no release enablement policy: $1"
            ;;
    esac
}

systemd_unit_definition_path()
{
    case "$1" in
        control-plane-backup-quiesce-watchdog.timer)
            stable_asset_destination backup-quiesce-timer-unit
            ;;
        coolify-runtime-attestation-ssh-fence.service)
            stable_asset_destination runtime-fence-service-unit
            ;;
        coolify-runtime-attestation-ssh-fence-watchdog.service)
            stable_asset_destination runtime-fence-watchdog-unit
            ;;
        *)
            fail "systemd unit has no stable definition path: $1"
            ;;
    esac
}

systemd_enablement_state()
{
    enablement_unit=$1
    if enablement_output=$(systemctl is-enabled "$enablement_unit" 2>&1); then
        enablement_status=0
    else
        enablement_status=$?
    fi
    case "$enablement_output:$enablement_status" in
        enabled:0)
            printf '%s\n' enabled
            ;;
        disabled:1)
            printf '%s\n' disabled
            ;;
        not-found:4)
            enablement_definition=$(systemd_unit_definition_path "$enablement_unit")
            [ ! -e "$enablement_definition" ] && [ ! -L "$enablement_definition" ] \
                || fail "systemd unit is not found despite an installed definition: $enablement_unit"
            printf '%s\n' disabled
            ;;
        *)
            fail "systemd unit has an unsupported enablement state: $enablement_unit"
            ;;
    esac
}

assert_systemd_enablement_state()
{
    enablement_unit=$1
    expected_enablement_state=$2
    observed_enablement_state=$(systemd_enablement_state "$enablement_unit")
    [ "$observed_enablement_state" = "$expected_enablement_state" ] \
        || fail "systemd unit enablement differs from the activation journal: $enablement_unit"
}

set_systemd_enablement_state()
{
    enablement_unit=$1
    requested_enablement_state=$2
    case "$requested_enablement_state" in
        enabled) systemctl enable "$enablement_unit" >/dev/null ;;
        disabled) systemctl disable "$enablement_unit" >/dev/null ;;
        *) fail "systemd activation requested an unsupported state: $enablement_unit" ;;
    esac
    assert_systemd_enablement_state "$enablement_unit" "$requested_enablement_state"
}

journal_enablement_record()
{
    awk -F'|' -v unit="$1" \
        '$1 == "unit" && $2 == unit { print; matches++ } END { exit matches == 1 ? 0 : 1 }' \
        "$activation_path/enablements" \
        || fail "release activation journal does not select exactly one systemd unit: $1"
}

validate_activation_enablements()
{
    if [ ! -e "$activation_path/enablements" ] \
        && [ ! -L "$activation_path/enablements" ]; then
        activation_enablements_generation=legacy
        return
    fi
    assert_regular_single_link "$activation_path/enablements" \
        'release activation enablements'
    [ "$(file_uid "$activation_path/enablements"):$(file_gid "$activation_path/enablements"):$(file_mode "$activation_path/enablements")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'release activation enablements identity is unsafe'
    expected_enablement_lines=$(printf '%s\n' "$SYSTEMD_ENABLEMENT_UNITS" \
        | wc -w | tr -d ' ')
    expected_enablement_lines=$((expected_enablement_lines + 1))
    awk -F'|' -v expected_lines="$expected_enablement_lines" '
        NR == 1 { if ($0 != "version|1") invalid = 1; next }
        NR > 1 {
            if ($1 != "unit" || NF != 4 ||
                $2 !~ /^[A-Za-z0-9@_.-]+$/ || seen[$2]++ ||
                $3 !~ /^(enabled|disabled)$/ ||
                $4 !~ /^(enabled|disabled)$/) invalid = 1
        }
        END { exit invalid || NR != expected_lines ? 1 : 0 }
    ' "$activation_path/enablements" \
        || fail 'release activation enablements are malformed'
    observed_enablement_units=$(sed -n 's/^unit|\([^|]*\)|.*/\1/p' \
        "$activation_path/enablements" | LC_ALL=C sort | paste -sd' ' -)
    expected_enablement_units=$(printf '%s\n' "$SYSTEMD_ENABLEMENT_UNITS" \
        | tr ' ' '\n' | LC_ALL=C sort | paste -sd' ' -)
    [ "$observed_enablement_units" = "$expected_enablement_units" ] \
        || fail 'release activation systemd unit inventory is not canonical'
    for enablement_unit in $SYSTEMD_ENABLEMENT_UNITS; do
        enablement_record=$(journal_enablement_record "$enablement_unit")
        recorded_target_state=$(printf '%s\n' "$enablement_record" | awk -F'|' '{print $4}')
        [ "$recorded_target_state" = "$(systemd_target_enablement_state "$enablement_unit")" ] \
            || fail "release activation systemd target is unauthorized: $enablement_unit"
    done
    activation_enablements_generation=current
}

restore_previous_systemd_enablements()
{
    [ "$activation_enablements_generation" = current ] || return 0
    systemctl daemon-reload
    for enablement_unit in $SYSTEMD_ENABLEMENT_UNITS; do
        enablement_record=$(journal_enablement_record "$enablement_unit")
        previous_enablement_state=$(printf '%s\n' "$enablement_record" | awk -F'|' '{print $3}')
        enablement_definition=$(systemd_unit_definition_path "$enablement_unit")
        if [ "$previous_enablement_state" = disabled ] \
            && [ ! -e "$enablement_definition" ] \
            && [ ! -L "$enablement_definition" ]; then
            assert_systemd_enablement_state "$enablement_unit" disabled
        else
            set_systemd_enablement_state "$enablement_unit" "$previous_enablement_state"
        fi
    done
}

assert_target_systemd_enablements()
{
    [ "$activation_enablements_generation" = current ] || return 0
    systemctl daemon-reload
    for enablement_unit in $SYSTEMD_ENABLEMENT_UNITS; do
        enablement_record=$(journal_enablement_record "$enablement_unit")
        target_enablement_state=$(printf '%s\n' "$enablement_record" | awk -F'|' '{print $4}')
        assert_systemd_enablement_state "$enablement_unit" "$target_enablement_state"
    done
}

activate_backup_quiesce_timer()
{
    [ "$activation_enablements_generation" = current ] || return 0
    systemctl start "$BACKUP_QUIESCE_TIMER_UNIT"
    systemctl is-active --quiet "$BACKUP_QUIESCE_TIMER_UNIT" \
        || fail 'backup-quiesce watchdog timer is not active after release activation'
}

assert_stable_asset()
{
    stable_key=$1
    stable_path=$2
    stable_mode=$3
    stable_sha256=$4
    assert_regular_single_link "$stable_path" "stable release asset $stable_key"
    assert_safe_parent_chain "$stable_path"
    [ "$(file_uid "$stable_path"):$(file_gid "$stable_path"):$(file_mode "$stable_path")" \
        = "$immutable_uid:$immutable_gid:$stable_mode" ] \
        && [ "$(sha256_file "$stable_path")" = "$stable_sha256" ] \
        || fail "stable release asset bytes or metadata changed: $stable_key"
}

journal_asset_record()
{
    awk -F'|' -v key="$1" \
        '$1 == "asset" && $2 == key { print; matches++ } END { exit matches == 1 ? 0 : 1 }' \
        "$activation_path/metadata" \
        || fail "release activation journal does not select exactly one stable asset: $1"
}

release_asset_expected_mode()
{
    case "$1" in
        operator-compose|rehearsal-compose)
            printf '%s\n' 600
            ;;
        backup-quiesce-service-unit|backup-quiesce-timer-unit|runtime-fence-service-unit|runtime-fence-watchdog-unit)
            printf '%s\n' 644
            ;;
        operator|backup-attestation-verifier|backup-quiesce-controller)
            printf '%s\n' 755
            ;;
        ingress-controller|runtime-fence-provisioner|runtime-fence-controller|runtime-fence-controlmaster-reaper|runtime-fence-provider-probe|runtime-fence-queue-probe|runtime-fence-terminal-probe)
            printf '%s\n' 700
            ;;
        *)
            fail "release asset role has no mode contract: $1"
            ;;
    esac
}

recover_stale_manifest_candidates()
{
    for stale_manifest in "${release_manifest}".new.*; do
        [ -e "$stale_manifest" ] || [ -L "$stale_manifest" ] || continue
        stale_suffix=${stale_manifest#"${release_manifest}.new."}
        case "$stale_suffix" in
            ''|*[!0-9]*) fail 'stale release manifest candidate name is unsafe' ;;
        esac
        assert_regular_single_link "$stale_manifest" 'stale release manifest candidate'
        [ "$(file_uid "$stale_manifest"):$(file_gid "$stale_manifest"):$(file_mode "$stale_manifest")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            || fail 'stale release manifest candidate metadata is unsafe'
        rm -f -- "$stale_manifest"
    done
    sync_path "${release_manifest%/*}"
}

validate_activation_journal()
{
    [ -d "$activation_path" ] && [ ! -L "$activation_path" ] \
        && [ "$(file_uid "$activation_path"):$(file_gid "$activation_path"):$(file_mode "$activation_path")" \
            = "$immutable_uid:$immutable_gid:700" ] \
        || fail 'release activation journal directory is unsafe'
    assert_safe_parent_chain "$activation_path/placeholder"
    assert_regular_single_link "$activation_path/metadata" 'release activation metadata'
    [ "$(file_uid "$activation_path/metadata"):$(file_gid "$activation_path/metadata"):$(file_mode "$activation_path/metadata")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'release activation metadata identity is unsafe'
    expected_stable_count=$(printf '%s\n' "$STABLE_ASSET_KEYS" | wc -w | tr -d ' ')
    expected_journal_lines=$((expected_stable_count + 4))
    awk -F'|' -v expected_lines="$expected_journal_lines" '
        NR == 1 { if ($0 != "version|1") invalid = 1; next }
        NR == 2 {
            if ($1 != "release" || NF != 2 ||
                $2 !~ /^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/) invalid = 1
            next
        }
        NR == 3 {
            if ($1 != "previous-manifest" || NF != 3 ||
                $2 !~ /^(present|absent)$/ ||
                ($2 == "present" && $3 !~ /^[a-f0-9]{64}$/) ||
                ($2 == "absent" && $3 != "-")) invalid = 1
            next
        }
        NR == 4 {
            if ($1 != "target-manifest" || NF != 2 || $2 !~ /^[a-f0-9]{64}$/) invalid = 1
            next
        }
        NR > 4 {
            if ($1 != "asset" || NF != 7 || $2 !~ /^[a-z][a-z0-9-]*$/ || seen[$2]++ ||
                $3 !~ /^(present|absent)$/ ||
                ($3 == "present" && $4 !~ /^[a-f0-9]{64}$/) ||
                ($3 == "absent" && $4 != "-") ||
                $5 !~ /^[a-f0-9]{64}$/ || $6 !~ /^(600|644|700|755)$/ ||
                $7 !~ /^(yes|no)$/) invalid = 1
        }
        END { exit invalid || NR != expected_lines ? 1 : 0 }
    ' "$activation_path/metadata" \
        || fail 'release activation metadata is malformed'
    observed_stable_keys=$(sed -n 's/^asset|\([^|]*\)|.*/\1/p' \
        "$activation_path/metadata" | LC_ALL=C sort | paste -sd' ' -)
    expected_stable_keys=$(printf '%s\n' "$STABLE_ASSET_KEYS" | tr ' ' '\n' \
        | LC_ALL=C sort | paste -sd' ' -)
    [ "$observed_stable_keys" = "$expected_stable_keys" ] \
        || fail 'release activation stable asset inventory is not canonical'

    journal_previous_state=$(sed -n '3s/^previous-manifest|\([^|]*\)|.*/\1/p' \
        "$activation_path/metadata")
    journal_previous_sha256=$(sed -n '3s/^previous-manifest|[^|]*|//p' \
        "$activation_path/metadata")
    journal_target_sha256=$(sed -n '4s/^target-manifest|//p' "$activation_path/metadata")
    assert_regular_single_link "$activation_path/target.manifest" \
        'release activation target manifest'
    [ "$(file_uid "$activation_path/target.manifest"):$(file_gid "$activation_path/target.manifest"):$(file_mode "$activation_path/target.manifest")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        && [ "$(sha256_file "$activation_path/target.manifest")" = "$journal_target_sha256" ] \
        || fail 'release activation target manifest identity is unsafe'
    if [ "$journal_previous_state" = present ]; then
        assert_regular_single_link "$activation_path/previous.manifest" \
            'release activation previous manifest'
        [ "$(file_uid "$activation_path/previous.manifest"):$(file_gid "$activation_path/previous.manifest"):$(file_mode "$activation_path/previous.manifest")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            && [ "$(sha256_file "$activation_path/previous.manifest")" \
                = "$journal_previous_sha256" ] \
            || fail 'release activation previous manifest identity is unsafe'
    else
        [ ! -e "$activation_path/previous.manifest" ] \
            && [ ! -L "$activation_path/previous.manifest" ] \
            || fail 'absent previous manifest has an unexpected activation backup'
    fi

    for stable_key in $STABLE_ASSET_KEYS; do
        stable_record=$(journal_asset_record "$stable_key")
        stable_previous_state=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $3}')
        stable_previous_sha256=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $4}')
        stable_record_mode=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $6}')
        [ "$stable_record_mode" = "$(stable_asset_mode "$stable_key")" ] \
            || fail "release activation stable asset mode is unauthorized: $stable_key"
        stable_backup="$activation_path/previous.$stable_key"
        if [ "$stable_previous_state" = present ]; then
            assert_stable_asset "$stable_key activation backup" "$stable_backup" \
                "$stable_record_mode" "$stable_previous_sha256"
        else
            [ ! -e "$stable_backup" ] && [ ! -L "$stable_backup" ] \
                || fail "absent stable asset has an unexpected activation backup: $stable_key"
        fi
    done
    validate_activation_enablements
    legacy_port8000_deprovision_required=0
    if [ -e "$activation_path/legacy-port8000-deprovision" ] \
        || [ -L "$activation_path/legacy-port8000-deprovision" ]; then
        assert_regular_single_link "$activation_path/legacy-port8000-deprovision" \
            'legacy port8000 activation migration marker'
        [ "$(file_uid "$activation_path/legacy-port8000-deprovision"):$(file_gid "$activation_path/legacy-port8000-deprovision"):$(file_mode "$activation_path/legacy-port8000-deprovision")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            && [ "$(sed -n '1p' "$activation_path/legacy-port8000-deprovision")" = version=1 ] \
            && [ "$(sed -n '2p' "$activation_path/legacy-port8000-deprovision")" = migration=legacy-port8000-deprovision ] \
            && [ "$(wc -l < "$activation_path/legacy-port8000-deprovision" | tr -d ' ')" = 2 ] \
            || fail 'legacy port8000 activation migration marker is unsafe'
        legacy_port8000_deprovision_required=1
    fi
}

install_recovery_file()
{
    recovery_source=$1
    recovery_destination=$2
    recovery_mode=$3
    recovery_parent=${recovery_destination%/*}
    recovery_candidate="$recovery_parent/.${recovery_destination##*/}.recover.$$"
    [ -d "$recovery_parent" ] && [ ! -L "$recovery_parent" ] \
        && [ ! -e "$recovery_candidate" ] && [ ! -L "$recovery_candidate" ] \
        || fail 'release activation recovery destination is unsafe'
    if [ "$dispatch_test_mode" = 0 ]; then
        install -m "$recovery_mode" -o root -g root "$recovery_source" "$recovery_candidate"
    else
        install -m "$recovery_mode" "$recovery_source" "$recovery_candidate"
    fi
    sync_path "$recovery_candidate"
    mv -f -- "$recovery_candidate" "$recovery_destination"
    sync_path "$recovery_parent"
}

restore_previous_stable_assets()
{
    for stable_key in $STABLE_ASSET_KEYS; do
        stable_record=$(journal_asset_record "$stable_key")
        stable_previous_state=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $3}')
        stable_previous_sha256=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $4}')
        stable_record_mode=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $6}')
        stable_destination=$(stable_asset_destination "$stable_key")
        if [ "$stable_previous_state" = present ]; then
            install_recovery_file "$activation_path/previous.$stable_key" \
                "$stable_destination" "$stable_record_mode"
            assert_stable_asset "$stable_key" "$stable_destination" \
                "$stable_record_mode" "$stable_previous_sha256"
        elif [ -e "$stable_destination" ] || [ -L "$stable_destination" ]; then
            assert_regular_single_link "$stable_destination" \
                "rollback-only stable release asset $stable_key"
            rm -f -- "$stable_destination"
            sync_path "${stable_destination%/*}"
        fi
    done
}

assert_target_stable_assets()
{
    for stable_key in $STABLE_ASSET_KEYS; do
        stable_record=$(journal_asset_record "$stable_key")
        stable_target_sha256=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $5}')
        stable_record_mode=$(printf '%s\n' "$stable_record" | awk -F'|' '{print $6}')
        assert_stable_asset "$stable_key" "$(stable_asset_destination "$stable_key")" \
            "$stable_record_mode" "$stable_target_sha256"
    done
}

recover_interrupted_activation()
{
    if [ ! -e "$activation_path" ] && [ ! -L "$activation_path" ]; then
        recover_stale_manifest_candidates
        return
    fi
    validate_activation_journal
    if [ -f "$release_manifest" ] && [ ! -L "$release_manifest" ]; then
        active_manifest_sha256=$(sha256_file "$release_manifest")
    elif [ ! -e "$release_manifest" ] && [ ! -L "$release_manifest" ]; then
        active_manifest_sha256=absent
    else
        fail 'active release manifest identity is unsafe during activation recovery'
    fi
    if [ "$active_manifest_sha256" = "$journal_target_sha256" ]; then
        assert_target_stable_assets
        assert_target_systemd_enablements
        activate_backup_quiesce_timer
        [ "$legacy_port8000_deprovision_required" = 0 ] \
            || fail 'target release requires installer-owned legacy port8000 deprovision recovery'
    elif [ "$journal_previous_state" = present ] \
        && { [ "$active_manifest_sha256" = "$journal_previous_sha256" ] \
            || [ "$active_manifest_sha256" = absent ]; }; then
        restore_previous_systemd_enablements
        restore_previous_stable_assets
        [ "$activation_enablements_generation" = legacy ] || systemctl daemon-reload
        if [ "$active_manifest_sha256" = absent ]; then
            install_recovery_file "$activation_path/previous.manifest" "$release_manifest" 600
            [ "$(sha256_file "$release_manifest")" = "$journal_previous_sha256" ] \
                || fail 'restored release manifest differs from the activation backup'
        fi
    elif [ "$journal_previous_state" = absent ] \
        && [ "$active_manifest_sha256" = absent ]; then
        restore_previous_systemd_enablements
        restore_previous_stable_assets
        [ "$activation_enablements_generation" = legacy ] || systemctl daemon-reload
    else
        fail 'active release manifest does not match either side of the activation journal'
    fi
    recover_stale_manifest_candidates
    rm -rf -- "$activation_path"
    [ ! -e "$activation_path" ] && [ ! -L "$activation_path" ] \
        || fail 'release activation journal cleanup failed'
    sync_path "${activation_path%/*}"
}

assert_manifest_identity()
{
    [ -f "$release_manifest" ] && [ ! -L "$release_manifest" ] \
        || fail 'release manifest must be a regular non-symlink file'
    [ "$(canonical_file_path "$release_manifest")" = "$release_manifest" ] \
        || fail 'release manifest path contains a symlink'
    [ "$(file_link_count "$release_manifest")" = 1 ] \
        || fail 'release manifest link count must be exactly one'
    assert_safe_parent_chain "$release_manifest"
    [ "$(file_uid "$release_manifest"):$(file_gid "$release_manifest"):$(file_mode "$release_manifest")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'release manifest ownership or mode is unsafe'
}

validate_manifest_snapshot()
{
    manifest_asset_count=$(awk 'END { print NR - 2 }' "$manifest_snapshot")
    current_asset_count=$(printf '%s\n' "$RELEASE_REQUIRED_ASSET_ROLES" | wc -w | tr -d ' ')
    legacy_asset_count=$(printf '%s\n' "$LEGACY_RELEASE_REQUIRED_ASSET_ROLES" | wc -w | tr -d ' ')
    awk -F'|' -v expected_version="$RELEASE_MANIFEST_VERSION" '
        NR == 1 { if ($0 != "version|" expected_version) invalid = 1; next }
        NR == 2 {
            if ($1 != "release" || NF != 2 ||
                $2 !~ /^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/) invalid = 1
            next
        }
        NR > 2 {
            if (NF != 7 || $1 != "asset" || $2 !~ /^[a-z][a-z0-9-]*$/ || seen[$2]++ ||
                $3 !~ /^\/[A-Za-z0-9_.\/:@+-]+$/ || $4 !~ /^[a-f0-9]{64}$/ ||
                $5 !~ /^[0-9]+$/ || $6 !~ /^[0-9]+$/ || $7 !~ /^[0-7]{3,4}$/) invalid = 1
        }
        END { exit invalid ? 1 : 0 }
    ' "$manifest_snapshot" \
        || fail 'release manifest structure is malformed'
    observed_roles=$(sed -n 's/^asset|\([^|]*\)|.*/\1/p' "$manifest_snapshot" \
        | LC_ALL=C sort | paste -sd' ' -)
    current_roles=$(printf '%s\n' "$RELEASE_REQUIRED_ASSET_ROLES" | tr ' ' '\n' \
        | LC_ALL=C sort | paste -sd' ' -)
    legacy_roles=$(printf '%s\n' "$LEGACY_RELEASE_REQUIRED_ASSET_ROLES" | tr ' ' '\n' \
        | LC_ALL=C sort | paste -sd' ' -)
    if [ "$manifest_asset_count" = "$current_asset_count" ] \
        && [ "$observed_roles" = "$current_roles" ]; then
        manifest_generation=current
    elif [ "$manifest_asset_count" = "$legacy_asset_count" ] \
        && [ "$observed_roles" = "$legacy_roles" ]; then
        manifest_generation=legacy
    else
        fail 'release manifest role inventory is not canonical'
    fi
    manifest_release_id=$(sed -n '2s/^release|//p' "$manifest_snapshot")
}

assert_dispatch_asset()
{
    dispatch_line=$(awk -F'|' -v role="$dispatch_role" \
        '$1 == "asset" && $2 == role { print; matches++ } END { exit matches == 1 ? 0 : 1 }' \
        "$manifest_snapshot") \
        || fail "release manifest does not select exactly one asset: $dispatch_role"
    dispatch_path=$(printf '%s\n' "$dispatch_line" | awk -F'|' '{print $3}')
    dispatch_sha256=$(printf '%s\n' "$dispatch_line" | awk -F'|' '{print $4}')
    dispatch_uid=$(printf '%s\n' "$dispatch_line" | awk -F'|' '{print $5}')
    dispatch_gid=$(printf '%s\n' "$dispatch_line" | awk -F'|' '{print $6}')
    dispatch_mode=$(printf '%s\n' "$dispatch_line" | awk -F'|' '{print $7}')
    expected_mode=$(release_asset_expected_mode "$dispatch_role")
    if [ "$manifest_generation" = current ]; then
        case "$dispatch_path" in
            "$releases_root/$manifest_release_id"/*) ;;
            *) fail "release executable is outside the selected versioned release: $dispatch_role" ;;
        esac
    fi
    [ "$dispatch_uid:$dispatch_gid:$dispatch_mode" = \
        "$immutable_uid:$immutable_gid:$expected_mode" ] \
        || fail "release asset manifest metadata is unauthorized: $dispatch_role"
    [ -f "$dispatch_path" ] && [ ! -L "$dispatch_path" ] && [ -x "$dispatch_path" ] \
        || fail "release executable is absent or unsafe: $dispatch_role"
    [ "$(canonical_file_path "$dispatch_path")" = "$dispatch_path" ] \
        && [ "$(file_link_count "$dispatch_path")" = 1 ] \
        || fail "release executable path or link count is unsafe: $dispatch_role"
    assert_safe_parent_chain "$dispatch_path"
    [ "$(file_uid "$dispatch_path"):$(file_gid "$dispatch_path"):$(file_mode "$dispatch_path")" \
        = "$dispatch_uid:$dispatch_gid:$dispatch_mode" ] \
        && [ "$(sha256_file "$dispatch_path")" = "$dispatch_sha256" ] \
        || fail "release executable bytes or metadata changed: $dispatch_role"
}

dispatch_test_mode=${CONTROL_PLANE_RELEASE_DISPATCH_TEST_MODE:-0}
[ "$dispatch_test_mode" = 0 ] || [ "$dispatch_test_mode" = 1 ] \
    || fail 'release dispatcher test mode must be exactly 0 or 1'
if [ "$dispatch_test_mode" = 0 ]; then
    [ "$(id -u)" = 0 ] || fail 'production release dispatch requires root'
    dispatch_test_root=
    release_manifest=$PRODUCTION_RELEASE_MANIFEST
    releases_root=$PRODUCTION_RELEASES_ROOT
    global_transaction_lock=$PRODUCTION_GLOBAL_TRANSACTION_LOCK
    snapshot_directory=$PRODUCTION_SNAPSHOT_DIRECTORY
    immutable_uid=0
    immutable_gid=0
    [ -z "${CONTROL_PLANE_RELEASE_MANIFEST_FILE:-}" ] \
        || [ "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" = "$release_manifest" ] \
        || fail 'production release manifest path is fixed'
    [ -z "${CONTROL_PLANE_RELEASES_ROOT:-}" ] \
        || [ "$CONTROL_PLANE_RELEASES_ROOT" = "$releases_root" ] \
        || fail 'production releases root is fixed'
    [ -z "${CONTROL_PLANE_RELEASE_DISPATCH_TEST_ROOT:-}" ] \
        && [ -z "${CONTROL_PLANE_RELEASE_DISPATCH_GLOBAL_LOCK_FILE:-}" ] \
        && [ -z "${CONTROL_PLANE_RELEASE_DISPATCH_SNAPSHOT_DIRECTORY:-}" ] \
        || fail 'production release dispatch rejects lab path overrides'
else
    dispatch_test_root=${CONTROL_PLANE_RELEASE_DISPATCH_TEST_ROOT:-}
    release_manifest=${CONTROL_PLANE_RELEASE_MANIFEST_FILE:-}
    releases_root=${CONTROL_PLANE_RELEASES_ROOT:-}
    [ -n "$dispatch_test_root" ] && [ "${dispatch_test_root#/}" != "$dispatch_test_root" ] \
        || fail 'lab dispatch requires an explicit absolute test host root'
    [ -d "$dispatch_test_root" ] && [ ! -L "$dispatch_test_root" ] \
        && [ "$(CDPATH='' cd -- "$dispatch_test_root" && pwd -P)" = "$dispatch_test_root" ] \
        || fail 'lab dispatch test host root is unsafe'
    global_transaction_lock=$(host_path "$PRODUCTION_GLOBAL_TRANSACTION_LOCK")
    snapshot_directory=$(host_path "$PRODUCTION_SNAPSHOT_DIRECTORY")
    immutable_uid=${CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_UID:-$(id -u)}
    immutable_gid=${CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_GID:-$(id -g)}
    [ -n "$release_manifest" ] && [ -n "$releases_root" ] \
        || fail 'lab dispatch requires explicit manifest and releases-root paths'
    [ "$release_manifest" = "$(host_path "$PRODUCTION_RELEASE_MANIFEST")" ] \
        && [ "$releases_root" = "$(host_path "$PRODUCTION_RELEASES_ROOT")" ] \
        || fail 'lab dispatch manifest and releases-root paths must be inside the test host root'
    [ -z "${CONTROL_PLANE_RELEASE_DISPATCH_GLOBAL_LOCK_FILE:-}" ] \
        || [ "$CONTROL_PLANE_RELEASE_DISPATCH_GLOBAL_LOCK_FILE" = "$global_transaction_lock" ] \
        || fail 'lab dispatch global lock path is fixed inside the test host root'
    [ -z "${CONTROL_PLANE_RELEASE_DISPATCH_SNAPSHOT_DIRECTORY:-}" ] \
        || [ "$CONTROL_PLANE_RELEASE_DISPATCH_SNAPSHOT_DIRECTORY" = "$snapshot_directory" ] \
        || fail 'lab dispatch snapshot directory is fixed inside the test host root'
fi
case "$release_manifest:$releases_root" in
    /*:/*) ;;
    *) fail 'release manifest and releases root must be absolute paths' ;;
esac
activation_path="${release_manifest%/*}/release.activation"

lock_parent=${global_transaction_lock%/*}
if [ "$dispatch_test_mode" = 0 ]; then
    install -d -m 0755 -o root -g root "$lock_parent"
    install -d -m 0700 -o root -g root "$snapshot_directory"
else
    install -d -m 0755 "$lock_parent"
    install -d -m 0700 "$snapshot_directory"
fi
[ -d "$lock_parent" ] && [ ! -L "$lock_parent" ] \
    && [ -d "$snapshot_directory" ] && [ ! -L "$snapshot_directory" ] \
    || fail 'release dispatch lock or snapshot directory is unsafe'
[ "$(file_uid "$snapshot_directory"):$(file_gid "$snapshot_directory"):$(file_mode "$snapshot_directory")" \
    = "$immutable_uid:$immutable_gid:700" ] \
    || fail 'release dispatch snapshot directory must have immutable-owner mode 0700'
[ ! -e "$global_transaction_lock" ] \
    || { [ -f "$global_transaction_lock" ] && [ ! -L "$global_transaction_lock" ]; } \
    || fail 'release dispatch global transaction lock is unsafe'
exec 8> "$global_transaction_lock"
chmod 0600 "$global_transaction_lock"
[ "$(file_uid "$global_transaction_lock"):$(file_gid "$global_transaction_lock"):$(file_mode "$global_transaction_lock")" \
    = "$immutable_uid:$immutable_gid:600" ] \
    || fail 'release dispatch global transaction lock metadata is unsafe'
flock -x 8 || fail 'release dispatch could not acquire the global transaction lock'
recover_interrupted_activation

invocation_name=${0##*/}
if [ "$invocation_name" = control-plane-blue-green ]; then
    dispatch_role=operator
else
    dispatch_role=${1:-}
    [ "$#" -gt 0 ] && shift
fi
case " $DISPATCHABLE_RELEASE_ASSET_ROLES " in
    *" $dispatch_role "*) ;;
    *) fail "release asset role is not dispatchable: $dispatch_role" ;;
esac

assert_manifest_identity
manifest_snapshot=$(mktemp "$snapshot_directory/release.manifest.XXXXXX")
trap 'rm -f -- "$manifest_snapshot"' 0 HUP INT TERM
cp -- "$release_manifest" "$manifest_snapshot"
chmod 0600 "$manifest_snapshot"
validate_manifest_snapshot
manifest_sha256=$(sha256_file "$manifest_snapshot")

if [ -n "${CONTROL_PLANE_RELEASE_MANIFEST_SHA256:-}" ] \
    && [ "$CONTROL_PLANE_RELEASE_MANIFEST_SHA256" != "$manifest_sha256" ]; then
    fail 'caller-pinned release manifest SHA-256 differs from the active manifest'
fi
if [ -n "${CONTROL_PLANE_RELEASE_ID:-}" ] \
    && [ "$CONTROL_PLANE_RELEASE_ID" != "$manifest_release_id" ]; then
    fail 'caller-pinned release ID differs from the active manifest'
fi

assert_dispatch_asset

export CONTROL_PLANE_RELEASE_MANIFEST_FILE="$release_manifest"
export CONTROL_PLANE_RELEASE_MANIFEST_SHA256="$manifest_sha256"
export CONTROL_PLANE_RELEASE_ID="$manifest_release_id"
trap - 0 HUP INT TERM
rm -f -- "$manifest_snapshot"
exec 8>&-
exec "$dispatch_path" "$@"
