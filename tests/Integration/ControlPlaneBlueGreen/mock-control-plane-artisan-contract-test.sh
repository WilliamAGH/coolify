#!/bin/sh

# Simulation-only contract test for the mock control-plane lab image.
set -eu

TEST_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
MOCK_DIRECTORY=$TEST_DIRECTORY/mock-control-plane
TEMPORARY_DIRECTORY=$(mktemp -d)
IMAGE_TAG=${CONTROL_PLANE_BLUE_GREEN_SIMULATION_IMAGE:-}
OWNED_IMAGE=0

cleanup()
{
    if [ "$OWNED_IMAGE" -eq 1 ]; then
        docker image rm "$IMAGE_TAG" >/dev/null 2>&1 || true
    fi
    rm -rf "$TEMPORARY_DIRECTORY"
}

trap cleanup EXIT HUP INT TERM

fail()
{
    printf 'control-plane simulation migration inventory contract failed: %s\n' "$1" >&2
    exit 1
}

write_control_plane_migration_inventory()
{
    CONTROL_PLANE_MIGRATION_NAMES_FILE="$TEMPORARY_DIRECTORY/control-plane-migrations.txt"
    if ! (
        cd "$REPOSITORY_ROOT"
        php artisan start:migration --print-control-plane-migration-inventory --no-interaction
    ) > "$CONTROL_PLANE_MIGRATION_NAMES_FILE" 2>&1; then
        fail 'production control-plane migration inventory command failed'
    fi

    [ -s "$CONTROL_PLANE_MIGRATION_NAMES_FILE" ] \
        || fail 'production control-plane migration inventory command returned no migrations'
}

write_control_plane_migration_inventory
if [ -n "$IMAGE_TAG" ]; then
    docker image inspect "$IMAGE_TAG" >/dev/null 2>&1 \
        || fail "supplied simulation image is unavailable: $IMAGE_TAG"
else
    IMAGE_TAG="control-plane-blue-green-mock-artisan-contract:$(printf '%s' "$TEMPORARY_DIRECTORY" \
        | sha256sum | awk '{print substr($1, 1, 12)}')"
    OWNED_IMAGE=1
    build_context=$TEMPORARY_DIRECTORY/context
    mkdir -p "$build_context/vendor/composer" \
        "$build_context/vendor/doctrine/inflector" \
        "$build_context/vendor/laravel/framework" \
        "$build_context/vendor/psr/container" \
        "$build_context/vendor/symfony/polyfill-php85" \
        "$build_context/app/Support" \
        "$build_context/config"
    cp -R "$MOCK_DIRECTORY/." "$build_context/"
    cp "$REPOSITORY_ROOT/config/database.php" "$build_context/config/database.php"
    cp "$REPOSITORY_ROOT/app/Support/ControlPlaneMigrationInventory.php" \
        "$build_context/app/Support/ControlPlaneMigrationInventory.php"
    while IFS= read -r migration_name; do
        migration_file="$REPOSITORY_ROOT/database/migrations/${migration_name}.php"
        [ -f "$migration_file" ] && [ ! -L "$migration_file" ] && [ -r "$migration_file" ] \
            || fail "control-plane migration source is unsafe or unavailable: $migration_name"
        cp "$migration_file" \
            "$build_context/migrations/${migration_name}.php"
    done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
    cp "$REPOSITORY_ROOT/database/migrations/control-plane-immutable-baseline-surplus.list" \
        "$build_context/migrations/control-plane-immutable-baseline-surplus.list"
    cp "$REPOSITORY_ROOT/database/migrations/control-plane-migration-inventory.fingerprint" \
        "$build_context/migrations/control-plane-migration-inventory.fingerprint"
    cp "$REPOSITORY_ROOT/vendor/composer/ClassLoader.php" \
        "$build_context/vendor/composer/ClassLoader.php"
    cp "$REPOSITORY_ROOT/vendor/composer/autoload_psr4.php" \
        "$build_context/vendor/composer/autoload_psr4.php"
    cp -R "$REPOSITORY_ROOT/vendor/doctrine/inflector" "$build_context/vendor/doctrine/"
    cp -R "$REPOSITORY_ROOT/vendor/laravel/framework" "$build_context/vendor/laravel/"
    cp -R "$REPOSITORY_ROOT/vendor/psr/container" "$build_context/vendor/psr/"
    cp -R "$REPOSITORY_ROOT/vendor/symfony/polyfill-php85" \
        "$build_context/vendor/symfony/"

    docker build --tag "$IMAGE_TAG" "$build_context" >/dev/null
fi
validated_migration_names_file=$TEMPORARY_DIRECTORY/validated-control-plane-migrations.txt
docker run --rm --entrypoint php85 "$IMAGE_TAG" \
    /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory \
    > "$validated_migration_names_file"
cmp -s "$CONTROL_PLANE_MIGRATION_NAMES_FILE" "$validated_migration_names_file" \
    || fail 'mock migration runner differs from the canonical migration inventory'
malformed_inventory_output=$TEMPORARY_DIRECTORY/malformed-migration-inventory-output
if docker run --rm --user 0 --entrypoint sh "$IMAGE_TAG" -c \
    'touch /var/www/html/database/migrations/2026_07_12_malformed.php && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$malformed_inventory_output" 2>&1; then
    fail 'mock migration runner accepted a malformed canonical migration filename'
fi
grep -F -q 'Control-plane migration inventory contains an unsafe or unexpected file.' "$malformed_inventory_output" \
    || fail 'mock migration runner did not reject the malformed canonical migration filename'
foreign_inventory_output=$TEMPORARY_DIRECTORY/foreign-migration-inventory-output
if docker run --rm --user 0 --entrypoint sh "$IMAGE_TAG" -c \
    'touch /var/www/html/database/migrations/2026_07_11_000000_foreign.php && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$foreign_inventory_output" 2>&1; then
    fail 'mock migration runner accepted a foreign migration file'
fi
grep -F -q 'Control-plane migration directory contains unexpected migration files.' \
    "$foreign_inventory_output" \
    || fail 'mock migration runner did not reject the foreign migration file'
unreviewed_inventory_output=$TEMPORARY_DIRECTORY/unreviewed-migration-inventory-output
if docker run --rm --user 0 --entrypoint sh "$IMAGE_TAG" -c \
    'touch /var/www/html/database/migrations/2026_07_12_999997_unreviewed.php && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$unreviewed_inventory_output" 2>&1; then
    fail 'mock migration runner accepted a well-formed unreviewed migration file'
fi
grep -F -q 'Control-plane migration inventory does not match the reviewed fingerprint.' \
    "$unreviewed_inventory_output" \
    || fail 'mock migration runner did not enforce the reviewed migration fingerprint on addition'
removed_migration_name=$(sed -n '1p' "$CONTROL_PLANE_MIGRATION_NAMES_FILE")
removed_inventory_output=$TEMPORARY_DIRECTORY/removed-migration-inventory-output
if docker run --rm --user 0 --env "CONTROL_PLANE_TEST_REMOVED_MIGRATION=$removed_migration_name" \
    --entrypoint sh "$IMAGE_TAG" -c \
    'rm "/var/www/html/database/migrations/${CONTROL_PLANE_TEST_REMOVED_MIGRATION}.php" && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$removed_inventory_output" 2>&1; then
    fail 'mock migration runner accepted a reviewed migration removal'
fi
grep -F -q 'Control-plane migration inventory does not match the reviewed fingerprint.' \
    "$removed_inventory_output" \
    || fail 'mock migration runner did not enforce the reviewed migration fingerprint on removal'
regular_symlink_output=$TEMPORARY_DIRECTORY/regular-migration-symlink-output
if docker run --rm --user 0 --env "CONTROL_PLANE_TEST_SYMLINK_TARGET=$removed_migration_name" \
    --entrypoint sh "$IMAGE_TAG" -c \
    'ln -s "${CONTROL_PLANE_TEST_SYMLINK_TARGET}.php" /var/www/html/database/migrations/2026_07_12_999998_regular_symlink.php && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$regular_symlink_output" 2>&1; then
    fail 'mock migration runner accepted a matching regular migration symlink'
fi
grep -F -q 'Control-plane migration inventory contains an unsafe or unexpected file.' "$regular_symlink_output" \
    || fail 'mock migration runner did not reject the matching regular migration symlink'
dangling_symlink_output=$TEMPORARY_DIRECTORY/dangling-migration-symlink-output
if docker run --rm --user 0 --entrypoint sh "$IMAGE_TAG" -c \
    'ln -s missing-control-plane-migration.php /var/www/html/database/migrations/2026_07_12_999999_dangling_symlink.php && exec php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory' \
    > "$dangling_symlink_output" 2>&1; then
    fail 'mock migration runner accepted a matching dangling migration symlink'
fi
grep -F -q 'Control-plane migration inventory contains an unsafe or unexpected file.' "$dangling_symlink_output" \
    || fail 'mock migration runner did not reject the matching dangling migration symlink'

printf '%s\n' 'CONTROL_PLANE_SIMULATION_MIGRATION_INVENTORY_CONTRACT_PASS'
