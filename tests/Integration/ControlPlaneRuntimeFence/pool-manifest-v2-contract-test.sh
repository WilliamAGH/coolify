#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly TEST_DIRECTORY REPOSITORY_ROOT
readonly PROBE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh
readonly CONTROLLER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh
readonly POOL_FIXTURE=$TEST_DIRECTORY/pool-manifest-fixture.bash

fail()
{
    printf 'POOL_MANIFEST_V2_CONTRACT_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

fixture=$(readlink -f -- "$(mktemp -d /tmp/control-plane-pool-manifest-v2.XXXXXX)")
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
fixture_uid=$(id -u)
fixture_gid=$(id -g)
chgrp "$fixture_gid" "$fixture"
mkdir -p "$fixture/bin"
if [[ $(uname -s) == Darwin ]]; then
    ln -s "$(command -v gstat)" "$fixture/bin/stat"
fi
printf '%s' candidate-direct-placeholder > "$fixture/candidate-direct-token"
printf '%s' candidate-b-direct-placeholder > "$fixture/candidate-b-direct-token"
printf '%s' candidate-applied-placeholder > "$fixture/candidate-applied-ack"
printf '%s' candidate-b-applied-placeholder > "$fixture/candidate-b-applied-ack"

# shellcheck disable=SC1090 # The runtime fixture path is resolved beside this test.
source "$POOL_FIXTURE"
prepare_control_plane_pool_plan_fixture "$fixture" pool-manifest-contract "$fixture_uid" "$fixture_gid"

run_validation()
{
    local plan=$1 output=$2 hook=${3:-}
    local -a environment=(
        PATH="$fixture/bin:$PATH"
        CONTROL_PLANE_TERMINAL_TEST_MODE=1
        CONTROL_PLANE_RUNTIME_OPERATION_ID=pool-manifest-contract
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST="$plan"
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256="$(pool_fixture_sha256 "$plan")"
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA="$(pool_fixture_metadata "$plan")"
        CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=
    )
    if [[ -n $hook ]]; then
        environment+=(
            CONTROL_PLANE_TERMINAL_TEST_SAFE_READ_HOOK="$hook"
            SWAP_LABEL="${SWAP_LABEL:?}"
            SWAP_REPLACEMENT="${SWAP_REPLACEMENT:?}"
            SWAP_MODE="${SWAP_MODE:?}"
        )
    fi
    env "${environment[@]}" "$PROBE" validate-pool-plan > "$output" 2>&1
}

mutated_plan()
{
    local name=$1
    local destination=$fixture/$name.manifest
    cp "$POOL_FIXTURE_POOL_PLAN_MANIFEST" "$destination"
    chmod 0600 "$destination"
    printf '%s\n' "$destination"
}

plan_value()
{
    local plan=$1 key=$2
    sed -n "s/^${key}=//p" "$plan"
}

plan_set_bytes()
{
    local plan=$1 member key
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(plan_value "$plan" "member_${member}_${key}")"
        done < <(pool_fixture_plan_member_keys)
    done
    printf '%s\0' "$(plan_value "$plan" writer_member)"
}

refresh_plan_set_sha256()
{
    local plan=$1 checksum temporary
    temporary=$plan.plan-set
    checksum=$(plan_set_bytes "$plan" | sha256sum | awk '{ print $1 }')
    awk -F= -v checksum="$checksum" \
        '$1 == "pool_plan_set_sha256" { print $1 "=" checksum; next } { print }' \
        "$plan" > "$temporary"
    chmod 0600 "$temporary"
    mv -f -- "$temporary" "$plan"
}

replace_plan_value()
{
    local plan=$1 key=$2 value=$3 temporary
    temporary=$plan.new
    awk -F= -v key="$key" -v value="$value" \
        '$1 == key { print key "=" value; next } { print }' "$plan" > "$temporary"
    chmod 0600 "$temporary"
    mv -f -- "$temporary" "$plan"
    refresh_plan_set_sha256 "$plan"
}

expect_validation_failure()
{
    local name=$1 plan=$2 expected=$3
    if run_validation "$plan" "$fixture/$name.out"; then
        fail "invalid plan passed: $name"
    fi
    grep -F -q "$expected" "$fixture/$name.out" || {
        sed "s/^/$name: /" "$fixture/$name.out" >&2
        fail "invalid plan emitted an unexpected failure: $name"
    }
}

if ! run_validation "$POOL_FIXTURE_POOL_PLAN_MANIFEST" "$fixture/valid.out"; then
    sed 's/^/valid: /' "$fixture/valid.out" >&2
    fail 'canonical plan did not validate'
fi
grep -F -x -q pool_plan=validated "$fixture/valid.out" \
    || fail 'canonical plan validation result is absent'
grep -E -x -q 'release_files_sha256=[a-f0-9]{64}' "$fixture/valid.out" \
    || fail 'canonical release-file identity is absent'
[[ $(pool_fixture_plan_member_keys | wc -l | tr -d '[:space:]') == 27 ]] \
    || fail 'fixture member tuple is not the canonical 27-field contract'
tuple_value_count=$((2 * $(pool_fixture_plan_member_keys | wc -l | tr -d '[:space:]') + 1))
[[ $tuple_value_count == 55 ]] || fail 'fixture plan-set tuple is not 55 NUL-delimited values'

extract_shell_function()
{
    local source_file=$1 function_name=$2
    awk -v signature="${function_name}()" '
        $0 == signature { capture=1 }
        capture { print }
        capture && $0 == "}" { exit }
    ' "$source_file"
}

production_key_inventory()
{
    local source_file=$1 retired_count=$2 script=$fixture/key-inventory.$$.sh
    {
        printf '#!/usr/bin/env bash\nset -Eeuo pipefail\n'
        printf 'pool_plan_manifest=unused\n'
        # shellcheck disable=SC2016
        printf 'document_value() { [[ $2 == retired_member_count ]] && printf "%s\\n"; }\n' \
            "$retired_count"
        # shellcheck disable=SC2016
        printf 'manifest_value() { [[ $1 == retired_member_count ]] && printf "%s\\n"; }\n' \
            "$retired_count"
        extract_shell_function "$source_file" plan_member_keys
        extract_shell_function "$source_file" pool_plan_keys
        extract_shell_function "$source_file" ingress_member_keys
        extract_shell_function "$source_file" ingress_pool_keys
        printf 'pool_plan_keys\nprintf "%%s\\n" "--ingress--"\ningress_pool_keys\n'
    } > "$script"
    bash "$script"
    rm -f -- "$script"
}

for retired_count in 1 2; do
    controller_inventory=$(production_key_inventory "$CONTROLLER" "$retired_count")
    terminal_inventory=$(production_key_inventory "$PROBE" "$retired_count")
    [[ $controller_inventory == "$terminal_inventory" ]] \
        || fail "production controller/terminal key inventory drifted: retired_count=$retired_count"
done
production_inventory_sha256=$(printf '%s' "$controller_inventory" | sha256sum | awk '{print $1}')

plan=$(mutated_plan version-one)
replace_plan_value "$plan" version 1
expect_validation_failure version-one "$plan" 'pool plan does not belong to this exact two-member operation'

plan=$(mutated_plan route-identity-reuse)
replace_plan_value "$plan" member_b_route_identity route-identity-a-01
expect_validation_failure route-identity-reuse "$plan" 'pool route identity reuses an authority value'

plan=$(mutated_plan port-reuse)
replace_plan_value "$plan" member_b_expected_loopback_port \
    "$(pool_fixture_plan_value member_a_expected_loopback_port)"
expect_validation_failure port-reuse "$plan" 'pool loopback port reuses an authority value'

plan=$(mutated_plan volume-reuse)
replace_plan_value "$plan" member_b_private_volume runtime-fence-coordination
expect_validation_failure volume-reuse "$plan" 'pool volume authority reuses an authority value'

plan=$(mutated_plan retired-name-reuse)
replace_plan_value "$plan" member_b_name legacy
expect_validation_failure retired-name-reuse "$plan" 'planned member name collides with retired member A'

plan=$(mutated_plan path-reuse)
replace_plan_value "$plan" member_b_repin_intent_file \
    "$(pool_fixture_plan_value member_a_repin_intent_file)"
expect_validation_failure path-reuse "$plan" 'pool authority path reuses an authority value'

plan=$(mutated_plan marker-tuple-reuse)
replace_plan_value "$plan" member_a_route_drain_marker_path \
    "$(pool_fixture_plan_value member_a_web_marker_path)"
expect_validation_failure marker-tuple-reuse "$plan" \
    'pool private marker authority tuple reuses an authority value'

plan=$(mutated_plan unsorted-networks)
replace_plan_value "$plan" member_a_network_ids \
    eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee,dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
expect_validation_failure unsorted-networks "$plan" \
    'member-a network plan must be sorted and contain no duplicate network IDs'

plan=$(mutated_plan short-epoch)
replace_plan_value "$plan" member_a_web_epoch 1
expect_validation_failure short-epoch "$plan" 'member-a web epoch is not a safe authority token'

plan=$(mutated_plan route-health-path)
replace_plan_value "$plan" route_health_path /api/control-plane/route-health-alias
expect_validation_failure route-health-path "$plan" \
    'pool plan route-health path is not the canonical endpoint'

plan=$(mutated_plan reordered)
awk '
    /^member_a_name=/ { held=$0; next }
    /^member_a_route_identity=/ { print; print held; next }
    { print }
' "$plan" > "$plan.new"
chmod 0600 "$plan.new"
mv -f -- "$plan.new" "$plan"
expect_validation_failure reordered "$plan" 'pool-plan-manifest keys are reordered, duplicated, absent, or unknown'

cat > "$fixture/safe-read-hook" <<'HOOK'
#!/usr/bin/env bash
set -Eeuo pipefail
stage=$1
label=$2
path=$3
if [[ $stage == after-copy && $label == "$SWAP_LABEL" ]]; then
    mv -f -- "$path" "$path.before-swap"
    cp "$SWAP_REPLACEMENT" "$path"
    chmod "$SWAP_MODE" "$path"
fi
HOOK
chmod 0700 "$fixture/safe-read-hook"

plan=$(mutated_plan plan-race)
replacement=$(mutated_plan plan-race-replacement)
replace_plan_value "$replacement" color blue
if SWAP_LABEL=pool-plan-manifest SWAP_REPLACEMENT="$replacement" SWAP_MODE=0600 \
    run_validation "$plan" "$fixture/plan-race.out" "$fixture/safe-read-hook"; then
    fail 'plan path swap passed descriptor-safe read'
fi
grep -F -q 'pool-plan-manifest changed while its descriptor was being read' \
    "$fixture/plan-race.out" || fail 'plan path swap did not reach the post-read inode check'

mv -f -- "$plan.before-swap" "$plan"
chmod 0600 "$plan"
replacement=$fixture/pool-ack-race.replacement
printf '%s' pool-ack-token-9999 > "$replacement"
chmod 0600 "$replacement"
if SWAP_LABEL=pool-ack SWAP_REPLACEMENT="$replacement" SWAP_MODE=0600 \
    run_validation "$plan" "$fixture/artifact-race.out" "$fixture/safe-read-hook"; then
    fail 'artifact path swap passed descriptor-safe read'
fi
grep -F -q 'pool-ack changed while its descriptor was being read' \
    "$fixture/artifact-race.out" || fail 'artifact path swap did not reach the post-read inode check'

printf 'POOL_MANIFEST_V2_CONTRACT_TEST PASS fields_per_member=27 tuple_values=55 races=2 inventory_sha256=%s\n' \
    "$production_inventory_sha256"
