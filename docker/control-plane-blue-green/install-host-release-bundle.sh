#!/bin/sh

set -eu
umask 077

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly ASSET_CONTRACT="$SCRIPT_DIRECTORY/release-assets.contract"
readonly ASSET_CONTRACT_SHA256=34652d5a3227360cb826784b1ba7e8c757166b7b65e3cda45646fc0232113d1a
readonly DISPATCHER_SOURCE="$SCRIPT_DIRECTORY/release-dispatch.sh"
readonly GLOBAL_TRANSACTION_LOCK=/run/lock/coolify-control-plane-blue-green.lock
readonly PRODUCTION_DISPATCH_SNAPSHOT_DIRECTORY=/run/coolify-control-plane-release-dispatch
readonly BACKUP_STATE_DIRECTORY=/var/lib/coolify/control-plane-backup-quiesce
readonly RUNTIME_CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_STATE_DIRECTORY=/var/lib/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_PROVISION_LOCK=/run/lock/coolify-runtime-attestation-ssh-fence-provision.lock
readonly PRODUCTION_MANIFEST=/etc/coolify-control-plane/release.manifest
readonly PRODUCTION_RELEASES_ROOT=/usr/local/lib/coolify-control-plane/releases
readonly PRODUCTION_DISPATCHER=/usr/local/libexec/coolify-control-plane-release-dispatch
readonly PRODUCTION_LAUNCHER=/usr/local/sbin/control-plane-blue-green
readonly BACKUP_QUIESCE_TIMER_UNIT=control-plane-backup-quiesce-watchdog.timer
readonly STABLE_ASSET_KEYS='release-dispatcher release-launcher backup-quiesce-service-unit backup-quiesce-timer-unit runtime-fence-service-unit runtime-fence-watchdog-unit'
readonly SYSTEMD_ENABLEMENT_UNITS='control-plane-backup-quiesce-watchdog.timer coolify-runtime-attestation-ssh-fence.service coolify-runtime-attestation-ssh-fence-watchdog.service'

fail()
{
    printf 'CONTROL_PLANE_RELEASE_BUNDLE_INSTALL_FAILURE %s\n' "$1" >&2
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

canonical_file_path()
{
    canonical_parent=$(CDPATH='' cd -- "${1%/*}" 2>/dev/null && pwd -P) \
        || fail "file parent is unavailable: ${1%/*}"
    printf '%s/%s\n' "${canonical_parent%/}" "${1##*/}"
}

sync_path()
{
    sync "$1" 2>/dev/null || sync
}

host_path()
{
    if [ "$bundle_test_mode" = 1 ]; then
        printf '%s%s\n' "$test_host_root" "$1"
    else
        printf '%s\n' "$1"
    fi
}

install_directory()
{
    directory_path=$1
    directory_mode=$2
    [ ! -L "$directory_path" ] \
        || fail "installation directory is a symlink: $directory_path"
    if [ "$bundle_test_mode" = 0 ]; then
        install -d -m "$directory_mode" -o root -g root "$directory_path"
    else
        install -d -m "$directory_mode" "$directory_path"
    fi
    [ -d "$directory_path" ] && [ ! -L "$directory_path" ] \
        && [ "$(file_uid "$directory_path"):$(file_gid "$directory_path"):$(file_mode "$directory_path")" \
            = "$immutable_uid:$immutable_gid:$directory_mode" ] \
        || fail "installation directory metadata is unsafe: $directory_path"
}

install_owned_file()
{
    source_path=$1
    destination_path=$2
    destination_mode=$3
    if [ "$bundle_test_mode" = 0 ]; then
        install -m "$destination_mode" -o root -g root "$source_path" "$destination_path"
    else
        install -m "$destination_mode" "$source_path" "$destination_path"
    fi
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
        if [ "$bundle_test_mode" = 0 ]; then
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

validate_asset_contract()
{
    assert_regular_single_link "$ASSET_CONTRACT" 'release asset contract'
    assert_safe_parent_chain "$ASSET_CONTRACT"
    [ "$(sha256_file "$ASSET_CONTRACT")" = "$ASSET_CONTRACT_SHA256" ] \
        || fail 'release asset contract differs from the canonical inventory'
    if [ "$bundle_test_mode" = 0 ]; then
        [ "$(file_uid "$ASSET_CONTRACT"):$(file_gid "$ASSET_CONTRACT"):$(file_mode "$ASSET_CONTRACT")" \
            = 0:0:644 ] \
            || fail 'production release asset contract must be root-owned with mode 0644'
    fi
    awk -F'|' '
        NF != 5 || $1 !~ /^[a-z][a-z0-9-]*$/ || seen_role[$1]++ ||
        $2 !~ /^[A-Za-z0-9_.@+\/-]+$/ || $2 ~ /(^|\/)\.\.?($|\/)/ || seen_source[$2]++ ||
        $3 !~ /^[A-Za-z0-9_.@+\/-]+$/ || $3 ~ /(^|\/)\.\.?($|\/)/ || seen_destination[$3]++ ||
        $4 !~ /^(600|644|700|755)$/ || $5 !~ /^(yes|no)$/ { invalid = 1 }
        END { exit invalid || NR == 0 ? 1 : 0 }
    ' "$ASSET_CONTRACT" \
        || fail 'release asset contract is malformed or ambiguous'
    while IFS='|' read -r contract_role _contract_source _contract_destination contract_mode contract_dispatchable; do
        [ "$(release_asset_expected_mode "$contract_role")" = "$contract_mode" ] \
            || fail "release asset contract mode differs from installer policy: $contract_role"
        case "$contract_mode:$contract_dispatchable" in
            600:no|644:no|700:yes|755:yes) ;;
            *) fail "release asset dispatch policy is unsafe: $contract_role" ;;
        esac
    done < "$ASSET_CONTRACT"
}

acquire_lock_file()
{
    lock_path=$1
    lock_fd=$2
    lock_label=$3
    lock_parent=${lock_path%/*}
    [ -d "$lock_parent" ] && [ ! -L "$lock_parent" ] \
        || fail "$lock_label parent is unsafe"
    [ ! -e "$lock_path" ] \
        || { [ -f "$lock_path" ] && [ ! -L "$lock_path" ]; } \
        || fail "$lock_label path is unsafe"
    eval "exec $lock_fd>\"\$lock_path\""
    chmod 0600 "$lock_path"
    [ "$(file_uid "$lock_path"):$(file_gid "$lock_path"):$(file_mode "$lock_path"):$(file_link_count "$lock_path")" \
        = "$immutable_uid:$immutable_gid:600:1" ] \
        || fail "$lock_label metadata is unsafe"
    flock -n "$lock_fd" \
        || fail "$lock_label is held by another transaction"
}

release_test_crash()
{
    [ "$bundle_test_mode" = 1 ] \
        && [ "$bundle_test_crash_point" = "$1" ] \
        || return 0
    trap - 0 HUP INT TERM
    exit 137
}

recover_stale_release_candidates()
{
    for stale_candidate in "$releases_root"/.new.*; do
        [ -e "$stale_candidate" ] || [ -L "$stale_candidate" ] || continue
        [ -d "$stale_candidate" ] && [ ! -L "$stale_candidate" ] \
            || fail 'stale release-directory candidate is unsafe'
        [ "$(file_uid "$stale_candidate"):$(file_gid "$stale_candidate")" \
            = "$immutable_uid:$immutable_gid" ] \
            || fail 'stale release-directory candidate owner is unsafe'
        rm -rf -- "$stale_candidate"
        [ ! -e "$stale_candidate" ] && [ ! -L "$stale_candidate" ] \
            || fail 'stale release-directory candidate cleanup failed'
    done
}

assert_source_asset()
{
    source_role=$1
    source_path=$2
    assert_regular_single_link "$source_path" "release source asset $source_role"
    assert_safe_parent_chain "$source_path"
    [ -r "$source_path" ] || fail "release source asset is unreadable: $source_role"
    source_mode=$(file_mode "$source_path")
    [ "$(file_uid "$source_path"):$(file_gid "$source_path")" \
        = "$immutable_uid:$immutable_gid" ] \
        || fail "release source asset is not owned by the immutable release owner: $source_role"
    [ $((0$source_mode & 0022)) -eq 0 ] \
        || fail "release source asset is group/world writable: $source_role"
}

assert_installed_asset()
{
    installed_role=$1
    installed_path=$2
    installed_mode=$3
    installed_sha256=$4
    assert_regular_single_link "$installed_path" "installed release asset $installed_role"
    [ "$(file_uid "$installed_path"):$(file_gid "$installed_path"):$(file_mode "$installed_path")" \
        = "$immutable_uid:$immutable_gid:$installed_mode" ] \
        && [ "$(sha256_file "$installed_path")" = "$installed_sha256" ] \
        || fail "installed release asset bytes or metadata changed: $installed_role"
}

validate_release_directory()
{
    validated_release_directory=$1
    [ -d "$validated_release_directory" ] && [ ! -L "$validated_release_directory" ] \
        && [ "$(file_uid "$validated_release_directory"):$(file_gid "$validated_release_directory"):$(file_mode "$validated_release_directory")" \
            = "$immutable_uid:$immutable_gid:755" ] \
        || fail 'published release directory metadata is unsafe'
    expected_file_count=0
    while IFS='|' read -r asset_role asset_source_relative asset_destination_relative asset_mode _asset_dispatchable; do
        source_path="$source_root/$asset_source_relative"
        installed_path="$validated_release_directory/$asset_destination_relative"
        assert_source_asset "$asset_role" "$source_path"
        assert_installed_asset "$asset_role" "$installed_path" "$asset_mode" \
            "$(sha256_file "$source_path")"
        expected_file_count=$((expected_file_count + 1))
    done < "$ASSET_CONTRACT"
    observed_file_count=$(find "$validated_release_directory" -type f | wc -l | tr -d ' ')
    [ "$observed_file_count" = "$expected_file_count" ] \
        || fail 'published release directory contains an unexpected file inventory'
    [ -z "$(find "$validated_release_directory" -type l -print -quit)" ] \
        || fail 'published release directory contains a symlink'
}

stage_release_directory()
{
    release_candidate="$releases_root/.new.$release_id.$$"
    [ ! -e "$release_candidate" ] && [ ! -L "$release_candidate" ] \
        || fail 'release-directory candidate already exists'
    install_directory "$release_candidate" 755
    while IFS='|' read -r asset_role asset_source_relative asset_destination_relative asset_mode _asset_dispatchable; do
        source_path="$source_root/$asset_source_relative"
        destination_path="$release_candidate/$asset_destination_relative"
        assert_source_asset "$asset_role" "$source_path"
        install_directory "${destination_path%/*}" 755
        source_sha256=$(sha256_file "$source_path")
        install_owned_file "$source_path" "$destination_path" "$asset_mode"
        assert_installed_asset "$asset_role" "$destination_path" "$asset_mode" "$source_sha256"
    done < "$ASSET_CONTRACT"
    sync_path "$release_candidate"
    release_test_crash after-release-directory-fsync
    mv -- "$release_candidate" "$release_directory"
    release_candidate=
    sync_path "$releases_root"
    release_test_crash after-release-directory-published
    validate_release_directory "$release_directory"
}

install_file_atomically()
{
    stable_role=$1
    stable_source=$2
    stable_destination=$3
    stable_mode=$4
    stable_parent=${stable_destination%/*}
    install_directory "$stable_parent" 755
    stable_candidate="$stable_parent/.${stable_destination##*/}.new.$$"
    [ ! -e "$stable_candidate" ] && [ ! -L "$stable_candidate" ] \
        || fail "stable asset candidate already exists: $stable_role"
    stable_sha256=$(sha256_file "$stable_source")
    install_owned_file "$stable_source" "$stable_candidate" "$stable_mode"
    assert_installed_asset "$stable_role" "$stable_candidate" "$stable_mode" "$stable_sha256"
    sync_path "$stable_candidate"
    mv -f -- "$stable_candidate" "$stable_destination"
    sync_path "$stable_parent"
    assert_installed_asset "$stable_role" "$stable_destination" "$stable_mode" "$stable_sha256"
}

stable_unit_destination()
{
    case "$1" in
        backup-quiesce-service-unit) host_path /etc/systemd/system/control-plane-backup-quiesce-watchdog.service ;;
        backup-quiesce-timer-unit) host_path /etc/systemd/system/control-plane-backup-quiesce-watchdog.timer ;;
        runtime-fence-service-unit) host_path /etc/systemd/system/coolify-runtime-attestation-ssh-fence.service ;;
        runtime-fence-watchdog-unit) host_path /etc/systemd/system/coolify-runtime-attestation-ssh-fence-watchdog.service ;;
        *) fail "release asset role is not a stable systemd unit: $1" ;;
    esac
}

stable_asset_destination()
{
    case "$1" in
        release-dispatcher) printf '%s\n' "$dispatcher_path" ;;
        release-launcher) printf '%s\n' "$launcher_path" ;;
        *) stable_unit_destination "$1" ;;
    esac
}

stable_asset_source()
{
    case "$1" in
        release-dispatcher|release-launcher)
            printf '%s\n' "$DISPATCHER_SOURCE"
            ;;
        *)
            asset_destination_relative=$(awk -F'|' -v role="$1" \
                '$1 == role { print $3; matches++ } END { exit matches == 1 ? 0 : 1 }' \
                "$ASSET_CONTRACT") \
                || fail "stable asset is absent from the release contract: $1"
            printf '%s/%s\n' "$release_directory" "$asset_destination_relative"
            ;;
    esac
}

stable_asset_mode()
{
    case "$1" in
        release-dispatcher) printf '%s\n' 700 ;;
        release-launcher) printf '%s\n' 755 ;;
        *) release_asset_expected_mode "$1" ;;
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
            stable_unit_destination backup-quiesce-timer-unit
            ;;
        coolify-runtime-attestation-ssh-fence.service)
            stable_unit_destination runtime-fence-service-unit
            ;;
        coolify-runtime-attestation-ssh-fence-watchdog.service)
            stable_unit_destination runtime-fence-watchdog-unit
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

converge_target_systemd_enablements()
{
    [ "$activation_enablements_generation" = current ] \
        || fail 'new release activation omitted its systemd enablement journal'
    systemctl daemon-reload
    enablement_index=0
    for enablement_unit in $SYSTEMD_ENABLEMENT_UNITS; do
        enablement_record=$(journal_enablement_record "$enablement_unit")
        target_enablement_state=$(printf '%s\n' "$enablement_record" | awk -F'|' '{print $4}')
        set_systemd_enablement_state "$enablement_unit" "$target_enablement_state"
        enablement_index=$((enablement_index + 1))
        if [ "$enablement_index" -eq 1 ]; then
            release_test_crash after-first-systemd-enablement
        fi
    done
    assert_target_systemd_enablements
    release_test_crash after-systemd-enablements-converged
}

activate_backup_quiesce_timer()
{
    [ "$activation_enablements_generation" = current ] || return 0
    systemctl start "$BACKUP_QUIESCE_TIMER_UNIT"
    release_test_crash after-backup-quiesce-timer-start
    systemctl is-active --quiet "$BACKUP_QUIESCE_TIMER_UNIT" \
        || fail 'backup-quiesce watchdog timer is not active after release activation'
}

journal_asset_record()
{
    awk -F'|' -v key="$1" \
        '$1 == "asset" && $2 == key { print; matches++ } END { exit matches == 1 ? 0 : 1 }' \
        "$activation_path/metadata" \
        || fail "release activation journal does not select exactly one stable asset: $1"
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

recover_stale_manifest_candidates()
{
    for stale_manifest in "${manifest_path}".new.*; do
        [ -e "$stale_manifest" ] || [ -L "$stale_manifest" ] || continue
        stale_suffix=${stale_manifest#"${manifest_path}.new."}
        case "$stale_suffix" in
            ''|*[!0-9]*) fail 'stale release manifest candidate name is unsafe' ;;
        esac
        assert_regular_single_link "$stale_manifest" 'stale release manifest candidate'
        [ "$(file_uid "$stale_manifest"):$(file_gid "$stale_manifest"):$(file_mode "$stale_manifest")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            || fail 'stale release manifest candidate metadata is unsafe'
        rm -f -- "$stale_manifest"
    done
    sync_path "$manifest_parent"
}

recover_stale_activation_candidates()
{
    for stale_activation in "${activation_path}".new.*; do
        [ -e "$stale_activation" ] || [ -L "$stale_activation" ] || continue
        stale_suffix=${stale_activation#"${activation_path}.new."}
        case "$stale_suffix" in
            ''|*[!0-9]*) fail 'stale release activation candidate name is unsafe' ;;
        esac
        [ -d "$stale_activation" ] && [ ! -L "$stale_activation" ] \
            && [ "$(file_uid "$stale_activation"):$(file_gid "$stale_activation"):$(file_mode "$stale_activation")" \
                = "$immutable_uid:$immutable_gid:700" ] \
            && [ -z "$(find "$stale_activation" -type l -print -quit)" ] \
            || fail 'stale release activation candidate is unsafe'
        rm -rf -- "$stale_activation"
    done
    sync_path "$manifest_parent"
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

    journal_release_id=$(sed -n '2s/^release|//p' "$activation_path/metadata")
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
        && [ "$(sed -n '2s/^release|//p' "$activation_path/target.manifest")" \
            = "$journal_release_id" ] \
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
}

remove_activation_journal()
{
    rm -rf -- "$activation_path"
    [ ! -e "$activation_path" ] && [ ! -L "$activation_path" ] \
        || fail 'release activation journal cleanup failed'
    sync_path "$manifest_parent"
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
            install_file_atomically "$stable_key recovery" \
                "$activation_path/previous.$stable_key" "$stable_destination" \
                "$stable_record_mode"
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

restore_previous_manifest()
{
    manifest_recovery_candidate="${manifest_path}.recover.$$"
    [ ! -e "$manifest_recovery_candidate" ] && [ ! -L "$manifest_recovery_candidate" ] \
        || fail 'release manifest recovery candidate already exists'
    install_owned_file "$activation_path/previous.manifest" \
        "$manifest_recovery_candidate" 600
    sync_path "$manifest_recovery_candidate"
    mv -f -- "$manifest_recovery_candidate" "$manifest_path"
    sync_path "$manifest_parent"
    [ "$(sha256_file "$manifest_path")" = "$journal_previous_sha256" ] \
        || fail 'restored release manifest differs from the activation backup'
}

recover_interrupted_activation()
{
    recover_stale_activation_candidates
    if [ ! -e "$activation_path" ] && [ ! -L "$activation_path" ]; then
        recover_stale_manifest_candidates
        return
    fi
    validate_activation_journal
    if [ -f "$manifest_path" ] && [ ! -L "$manifest_path" ]; then
        active_manifest_sha256=$(sha256_file "$manifest_path")
    elif [ ! -e "$manifest_path" ] && [ ! -L "$manifest_path" ]; then
        active_manifest_sha256=absent
    else
        fail 'active release manifest identity is unsafe during activation recovery'
    fi
    if [ "$active_manifest_sha256" = "$journal_target_sha256" ]; then
        assert_target_stable_assets
        assert_target_systemd_enablements
        activate_backup_quiesce_timer
    elif [ "$journal_previous_state" = present ] \
        && { [ "$active_manifest_sha256" = "$journal_previous_sha256" ] \
            || [ "$active_manifest_sha256" = absent ]; }; then
        restore_previous_systemd_enablements
        restore_previous_stable_assets
        [ "$activation_enablements_generation" = legacy ] || systemctl daemon-reload
        if [ "$active_manifest_sha256" = absent ]; then
            restore_previous_manifest
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
    remove_activation_journal
}

prepare_activation_journal()
{
    activation_candidate="${activation_path}.new.$$"
    [ ! -e "$activation_candidate" ] && [ ! -L "$activation_candidate" ] \
        || fail 'release activation candidate already exists'
    install_directory "$activation_candidate" 700

    manifest_inventory="$activation_candidate/manifest.inventory"
    : > "$manifest_inventory"
    while IFS='|' read -r asset_role asset_source_relative asset_destination_relative asset_mode _asset_dispatchable; do
        installed_path="$release_directory/$asset_destination_relative"
        assert_installed_asset "$asset_role" "$installed_path" "$asset_mode" \
            "$(sha256_file "$source_root/$asset_source_relative")"
        printf 'asset|%s|%s|%s|%s|%s|%s\n' \
            "$asset_role" "$installed_path" "$(sha256_file "$installed_path")" \
            "$immutable_uid" "$immutable_gid" "$asset_mode" >> "$manifest_inventory"
    done < "$ASSET_CONTRACT"
    {
        printf 'version|1\nrelease|%s\n' "$release_id"
        LC_ALL=C sort "$manifest_inventory"
    } > "$activation_candidate/target.manifest"
    rm -f -- "$manifest_inventory"
    manifest_inventory=
    if [ "$bundle_test_mode" = 0 ]; then
        chown root:root "$activation_candidate/target.manifest"
    fi
    chmod 0600 "$activation_candidate/target.manifest"
    sync_path "$activation_candidate/target.manifest"
    target_manifest_sha256=$(sha256_file "$activation_candidate/target.manifest")

    if [ -e "$manifest_path" ] || [ -L "$manifest_path" ]; then
        assert_regular_single_link "$manifest_path" 'existing release manifest'
        [ "$(file_uid "$manifest_path"):$(file_gid "$manifest_path"):$(file_mode "$manifest_path")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            || fail 'existing release manifest metadata is unsafe'
        install_owned_file "$manifest_path" "$activation_candidate/previous.manifest" 600
        previous_manifest_state=present
        previous_manifest_sha256=$(sha256_file "$manifest_path")
        sync_path "$activation_candidate/previous.manifest"
    else
        previous_manifest_state=absent
        previous_manifest_sha256=-
    fi

    {
        printf 'version|1\nrelease|%s\n' "$release_id"
        printf 'previous-manifest|%s|%s\n' \
            "$previous_manifest_state" "$previous_manifest_sha256"
        printf 'target-manifest|%s\n' "$target_manifest_sha256"
    } > "$activation_candidate/metadata"
    for stable_key in $STABLE_ASSET_KEYS; do
        stable_source=$(stable_asset_source "$stable_key")
        stable_destination=$(stable_asset_destination "$stable_key")
        stable_mode=$(stable_asset_mode "$stable_key")
        stable_target_sha256=$(sha256_file "$stable_source")
        if [ -e "$stable_destination" ] || [ -L "$stable_destination" ]; then
            assert_regular_single_link "$stable_destination" \
                "existing stable release asset $stable_key"
            [ "$(file_uid "$stable_destination"):$(file_gid "$stable_destination"):$(file_mode "$stable_destination")" \
                = "$immutable_uid:$immutable_gid:$stable_mode" ] \
                || fail "existing stable release asset metadata is unsafe: $stable_key"
            stable_previous_state=present
            stable_previous_sha256=$(sha256_file "$stable_destination")
            install_owned_file "$stable_destination" \
                "$activation_candidate/previous.$stable_key" "$stable_mode"
            sync_path "$activation_candidate/previous.$stable_key"
        else
            stable_previous_state=absent
            stable_previous_sha256=-
        fi
        printf 'asset|%s|%s|%s|%s|%s|yes\n' \
            "$stable_key" "$stable_previous_state" "$stable_previous_sha256" \
            "$stable_target_sha256" "$stable_mode" >> "$activation_candidate/metadata"
    done
    if [ "$bundle_manage_systemd" = 1 ]; then
        printf 'version|1\n' > "$activation_candidate/enablements"
        for enablement_unit in $SYSTEMD_ENABLEMENT_UNITS; do
            previous_enablement_state=$(systemd_enablement_state "$enablement_unit")
            target_enablement_state=$(systemd_target_enablement_state "$enablement_unit")
            printf 'unit|%s|%s|%s\n' "$enablement_unit" \
                "$previous_enablement_state" "$target_enablement_state" \
                >> "$activation_candidate/enablements"
        done
        if [ "$bundle_test_mode" = 0 ]; then
            chown root:root "$activation_candidate/enablements"
        fi
        chmod 0600 "$activation_candidate/enablements"
        sync_path "$activation_candidate/enablements"
    fi
    if [ "$bundle_test_mode" = 0 ]; then
        chown root:root "$activation_candidate/metadata"
    fi
    chmod 0600 "$activation_candidate/metadata"
    sync_path "$activation_candidate/metadata"
    sync_path "$activation_candidate"
    mv -- "$activation_candidate" "$activation_path"
    activation_candidate=
    sync_path "$manifest_parent"
    validate_activation_journal
    release_test_crash after-activation-journal-published
}

publish_stable_assets()
{
    stable_unit_count=0
    for stable_key in $STABLE_ASSET_KEYS; do
        install_file_atomically "$stable_key" "$(stable_asset_source "$stable_key")" \
            "$(stable_asset_destination "$stable_key")" "$(stable_asset_mode "$stable_key")"
        case "$stable_key" in
            release-dispatcher|release-launcher) ;;
            *)
                stable_unit_count=$((stable_unit_count + 1))
                if [ "$stable_unit_count" -eq 1 ]; then
                    release_test_crash after-first-stable-unit-published
                fi
                ;;
        esac
    done
    release_test_crash after-stable-assets-published
}

publish_release_manifest()
{
    assert_target_systemd_enablements
    manifest_candidate="${manifest_path}.new.$$"
    [ ! -e "$manifest_candidate" ] && [ ! -L "$manifest_candidate" ] \
        || fail 'release manifest candidate already exists'
    install_owned_file "$activation_path/target.manifest" "$manifest_candidate" 600
    sync_path "$manifest_candidate"
    assert_regular_single_link "$manifest_candidate" 'release manifest candidate'
    [ "$(sha256_file "$manifest_candidate")" = "$journal_target_sha256" ] \
        || fail 'release manifest candidate differs from the activation target'
    release_test_crash after-release-manifest-candidate-fsync
    mv -f -- "$manifest_candidate" "$manifest_path"
    manifest_candidate=
    release_test_crash after-release-manifest-replaced
    sync_path "$manifest_parent"
    release_test_crash after-release-manifest-parent-fsync
    assert_regular_single_link "$manifest_path" 'published release manifest'
    [ "$(file_uid "$manifest_path"):$(file_gid "$manifest_path"):$(file_mode "$manifest_path")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'published release manifest metadata is unsafe'
    [ "$(sha256_file "$manifest_path")" = "$journal_target_sha256" ] \
        || fail 'published release manifest differs from the activation target'
    assert_target_stable_assets
    recover_stale_manifest_candidates
}

bundle_test_mode=${CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE:-0}
[ "$bundle_test_mode" = 0 ] || [ "$bundle_test_mode" = 1 ] \
    || fail 'release bundle test mode must be exactly 0 or 1'
bundle_test_crash_point=${CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT:-}
case "$bundle_test_crash_point" in
    ''|after-release-directory-fsync|after-release-directory-published|after-activation-journal-published|after-first-stable-unit-published|after-stable-assets-published|after-first-systemd-enablement|after-systemd-enablements-converged|after-release-manifest-candidate-fsync|after-release-manifest-replaced|after-release-manifest-parent-fsync|after-backup-quiesce-timer-start) ;;
    *) fail 'release bundle crash injection point is unsupported' ;;
esac
requested_systemd_management=${CONTROL_PLANE_RELEASE_BUNDLE_TEST_SYSTEMD:-}
if [ "$bundle_test_mode" = 0 ]; then
    [ "$(id -u)" = 0 ] || fail 'production release bundle installation requires root'
    [ -z "$bundle_test_crash_point" ] \
        || fail 'production release bundle installation rejects crash injection'
    [ -z "$requested_systemd_management" ] || [ "$requested_systemd_management" = 1 ] \
        || fail 'production release bundle installation cannot disable systemd management'
    bundle_manage_systemd=1
    test_host_root=
    immutable_uid=0
    immutable_gid=0
    source_root=$SCRIPT_DIRECTORY
else
    [ -z "$requested_systemd_management" ] \
        || [ "$requested_systemd_management" = 0 ] \
        || [ "$requested_systemd_management" = 1 ] \
        || fail 'lab release bundle systemd management must be exactly 0 or 1'
    bundle_manage_systemd=${requested_systemd_management:-0}
    test_host_root=${CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT:-}
    source_root=${CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT:-$SCRIPT_DIRECTORY}
    [ -n "$test_host_root" ] && [ "${test_host_root#/}" != "$test_host_root" ] \
        || fail 'lab release bundle installation requires an absolute test host root'
    [ -d "$test_host_root" ] && [ ! -L "$test_host_root" ] \
        || fail 'lab release bundle test host root is unsafe'
    test_host_root=$(CDPATH='' cd -- "$test_host_root" && pwd -P)
    immutable_uid=$(file_uid "$test_host_root")
    immutable_gid=$(file_gid "$test_host_root")
fi
[ -d "$source_root" ] && [ ! -L "$source_root" ] \
    && [ "$(CDPATH='' cd -- "$source_root" && pwd -P)" = "$source_root" ] \
    || fail 'release bundle source root is unsafe'

release_id=${1:-}
[ "$#" -eq 1 ] || fail 'usage: install-host-release-bundle.sh RELEASE_ID'
printf '%s' "$release_id" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$' \
    || fail 'release ID must contain 16 to 128 filesystem-safe token characters'
case "$release_id" in
    .*) fail 'release ID must not use a hidden or reserved .new name' ;;
esac

validate_asset_contract
assert_source_asset release-dispatcher "$DISPATCHER_SOURCE"

global_lock=$(host_path "$GLOBAL_TRANSACTION_LOCK")
dispatch_snapshot_directory=$(host_path "$PRODUCTION_DISPATCH_SNAPSHOT_DIRECTORY")
backup_state_directory=$(host_path "$BACKUP_STATE_DIRECTORY")
runtime_config_directory=$(host_path "$RUNTIME_CONFIG_DIRECTORY")
runtime_state_directory=$(host_path "$RUNTIME_STATE_DIRECTORY")
runtime_provision_lock=$(host_path "$RUNTIME_PROVISION_LOCK")
manifest_path=$(host_path "$PRODUCTION_MANIFEST")
releases_root=$(host_path "$PRODUCTION_RELEASES_ROOT")
dispatcher_path=$(host_path "$PRODUCTION_DISPATCHER")
launcher_path=$(host_path "$PRODUCTION_LAUNCHER")
release_directory="$releases_root/$release_id"
manifest_parent=${manifest_path%/*}
activation_path="$manifest_parent/release.activation"

install_directory "${global_lock%/*}" 755
install_directory "$dispatch_snapshot_directory" 700
install_directory "$backup_state_directory" 700
install_directory "$runtime_config_directory" 700
install_directory "$runtime_state_directory" 700
install_directory "$manifest_parent" 700
install_directory "$releases_root" 755
acquire_lock_file "$global_lock" 9 'global control-plane transaction lock'
acquire_lock_file "$backup_state_directory/.lock" 8 'backup-quiesce transaction lock'
acquire_lock_file "$runtime_provision_lock" 7 'runtime-fence provision transaction lock'

release_candidate=
activation_candidate=
manifest_candidate=
manifest_inventory=
trap 'rm -rf -- "${release_candidate:-}" "${activation_candidate:-}" 2>/dev/null || true; rm -f -- "${manifest_candidate:-}" "${manifest_inventory:-}" 2>/dev/null || true' 0 HUP INT TERM
recover_interrupted_activation

if [ "$bundle_test_mode" = 1 ] && [ -n "${CONTROL_PLANE_RELEASE_BUNDLE_TEST_LOCK_MARKER:-}" ]; then
    printf 'locked\n' > "$CONTROL_PLANE_RELEASE_BUNDLE_TEST_LOCK_MARKER"
fi
bundle_lock_hold=${CONTROL_PLANE_RELEASE_BUNDLE_TEST_HOLD_LOCK_SECONDS:-0}
printf '%s' "$bundle_lock_hold" | grep -Eq '^[0-9]+$' \
    || fail 'lab release bundle lock hold must be a nonnegative integer'
[ "$bundle_test_mode" = 1 ] || [ "$bundle_lock_hold" = 0 ] \
    || fail 'production release bundle installation rejects a lab lock hold'
[ "$bundle_lock_hold" = 0 ] || sleep "$bundle_lock_hold"

active_backup_path="$backup_state_directory/active"
[ ! -e "$active_backup_path" ] && [ ! -L "$active_backup_path" ] \
    || fail 'an active backup-quiesce lease forbids release activation'
runtime_environment="$runtime_config_directory/runtime.env"
if [ -e "$runtime_environment" ] || [ -L "$runtime_environment" ]; then
    assert_regular_single_link "$runtime_environment" 'runtime-fence environment'
    [ "$(file_uid "$runtime_environment"):$(file_gid "$runtime_environment"):$(file_mode "$runtime_environment")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'runtime-fence environment metadata is unsafe'
    ! grep -F -x -q 'CONTROL_PLANE_RUNTIME_ARMED=1' "$runtime_environment" \
        || fail 'an armed runtime fence forbids release activation'
else
    runtime_environment_candidate="$runtime_config_directory/.runtime.env.$$"
    printf 'CONTROL_PLANE_RUNTIME_ARMED=0\nCONTROL_PLANE_RUNTIME_STATE_DIR=%s\n' \
        "$runtime_state_directory" > "$runtime_environment_candidate"
    chmod 0600 "$runtime_environment_candidate"
    sync_path "$runtime_environment_candidate"
    mv -- "$runtime_environment_candidate" "$runtime_environment"
    sync_path "$runtime_config_directory"
fi

if [ -e "$manifest_path" ] || [ -L "$manifest_path" ]; then
    assert_regular_single_link "$manifest_path" 'existing release manifest'
    [ "$(file_uid "$manifest_path"):$(file_gid "$manifest_path"):$(file_mode "$manifest_path")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'existing release manifest metadata is unsafe'
fi

recover_stale_release_candidates
if [ -e "$release_directory" ] || [ -L "$release_directory" ]; then
    validate_release_directory "$release_directory"
else
    stage_release_directory
fi
prepare_activation_journal
publish_stable_assets
if [ "$bundle_manage_systemd" = 1 ]; then
    converge_target_systemd_enablements
fi
publish_release_manifest

if [ "$bundle_manage_systemd" = 1 ]; then
    activate_backup_quiesce_timer
fi
remove_activation_journal
validate_release_directory "$release_directory"
trap - 0 HUP INT TERM

printf 'CONTROL_PLANE_RELEASE_BUNDLE_INSTALL complete=true release_id=%s manifest=%s manifest_sha256=%s release_directory=%s\n' \
    "$release_id" "$manifest_path" "$(sha256_file "$manifest_path")" "$release_directory"
