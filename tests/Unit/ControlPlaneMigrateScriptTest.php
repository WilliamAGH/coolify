<?php

function controlPlaneMigrateScript(): string
{
    $script = file_get_contents(getcwd().'/scripts/control-plane-migrate.sh');

    expect($script)->toBeString();

    return $script;
}

it('ships the control-plane migration bridge with valid bash syntax', function () {
    $process = proc_open(
        ['bash', '-n', getcwd().'/scripts/control-plane-migrate.sh'],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        getcwd()
    );

    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    expect($exitCode, trim($stdout."\n".$stderr))->toBe(0);
});

it('exposes the capture, verify, env-merge, inventory, and restore subcommands', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('cmd_capture()')
        ->toContain('cmd_verify()')
        ->toContain('cmd_env_merge()')
        ->toContain('cmd_restore()')
        ->toContain('capture) cmd_capture')
        ->toContain('verify) cmd_verify')
        ->toContain('env-merge) cmd_env_merge')
        ->toContain('restore) cmd_restore')
        ->toContain('MANIFEST_SCHEMA="coolify-control-plane-migration/1"');
});

it('fails closed on an undecided control-plane tree census', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('DEFAULT_CAPTURE_TREES="ssh applications databases services backups source proxy"')
        ->toContain('PROTECTED_TREES="fork-deploy"')
        ->toContain('census fail-closed: undecided top-level directory')
        ->toContain('--include $name or --exclude $name');
});

it('requires an explicit quiescence mode for capture', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('--attest-quiesced')
        ->toContain('--require-source-stopped')
        ->toContain('capture is fail-closed: pass --attest-quiesced')
        ->toContain('is still running; stop it before the final capture');
});

it('preserves APP_KEY and refuses archives without it', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('REQUIRED_SOURCE_KEYS="APP_KEY"')
        ->toContain('APP_KEY APP_PREVIOUS_KEYS')
        ->toContain('source .env has no usable APP_KEY; encrypted credentials would be unrecoverable')
        ->toContain('source .env is missing required key $key; refusing to merge')
        ->toContain('merged .env lost APP_KEY');
});

it('merges env files key by key and never copies the source wholesale', function () {
    $script = controlPlaneMigrateScript();

    expect($script)
        ->toContain('SOURCE_PRESERVED_KEYS="APP_KEY APP_PREVIOUS_KEYS APP_ID APP_NAME APP_URL')
        ->toContain('PUSHER_APP_ID PUSHER_APP_KEY PUSHER_APP_SECRET PUSHER_HOST')
        ->toContain('REGISTRY_URL DOCKER_ADDRESS_POOL_BASE DOCKER_ADDRESS_POOL_SIZE')
        ->toContain('TARGET_RETAINED_KEYS="APP_ENV APP_DEBUG')
        ->toContain('DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD')
        ->toContain('REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_DB')
        ->toContain('COOLIFY_FORK_VERSION VERSIONS_URL UPGRADE_SCRIPT_URL RELEASES_URL AUTOUPDATE')
        ->toContain('APP_PORT PUSHER_BACKEND_HOST PUSHER_BACKEND_PORT')
        ->toContain('DROPPED_KEYS="PUSHER_PORT SOKETI_PORT"')
        ->toContain('dropped(browser-port-pin)')
        ->toContain('merged .env lost target DB_PASSWORD')
        ->toContain('merged .env lost target REDIS_PASSWORD')
        ->toContain('source(unclassified-review)')
        ->toContain('target(retained-absent)');

    expect($script)->not->toContain('cp -p "$archive/env.source" "$target_env"');
});

it('keeps database and redis credentials out of process arguments and container metadata', function () {
    $script = controlPlaneMigrateScript();

    expect($script)
        ->toContain('printf \'AUTH %s\\nBGSAVE\\n\' "$redis_password" | docker exec -i "$REDIS_CONTAINER" redis-cli')
        ->toContain('docker exec "$DB_CONTAINER" pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc');

    expect($script)
        ->not->toContain('PGPASSWORD')
        ->not->toContain('REDISCLI_AUTH')
        ->not->toContain('docker exec -e')
        ->not->toContain('redis-cli -a');
});

it('verifies archives fail-closed before restore', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('sha256sum -c SHA256SUMS')
        ->toContain('archive checksum verification failed')
        ->toContain('verify_dump_readable')
        ->toContain('pg_dump archive is not readable')
        ->toContain('tree archive is corrupt')
        ->toContain('cmd_verify --archive "$archive"');
});

it('fails closed when the app container runs a creation-time APP_KEY', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('verify_effective_app_key')
        ->toContain('effective APP_KEY mismatch')
        ->toContain('--force-recreate');
});

it('distinguishes a missing postgres client image from an unreadable dump', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('docker image inspect "$client_image"')
        ->toContain('is not present locally')
        ->toContain('this check never pulls images');
});

it('requires contextual overwrite authorization and a managed target for restore', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('--authorize-overwrite must equal this host\'s hostname')
        ->toContain('overwrite authorization mismatch')
        ->toContain('require_file "$root/fork-deploy/current" "fork-deploy current-release marker"');
});

it('proves the exact signed fork release and image digest on the target', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('prove_release_digest()')
        ->toContain('--expect-fork-version')
        ->toContain('--expect-image-digest')
        ->toContain('target fork version mismatch')
        ->toContain('release proof failed: app image does not resolve to expected digest');
});

it('refuses postgresql major downgrades on restore', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('pg_server_major()')
        ->toContain('SHOW server_version_num')
        ->toContain('PostgreSQL major downgrade refused: source $source_pg_major, target $target_pg_major');
});

it('disables horizon and the scheduler by default on the restored target', function () {
    $script = controlPlaneMigrateScript();

    expect($script)
        ->toContain('set_env_var HORIZON_ENABLED false "$merged_env"')
        ->toContain('set_env_var SCHEDULER_ENABLED false "$merged_env"')
        ->toContain('WARNING: --enable-workers given')
        ->toContain('There must never be two mutable production control planes');
});

it('never restores the source tree or protected state onto the managed target', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('NEVER_RESTORE_TREES="source"')
        ->toContain('OPT_IN_RESTORE_TREES="proxy"')
        ->toContain('source) continue ;;')
        ->toContain('manifest names a protected tree; refusing to restore')
        ->toContain('skipping opt-in tree (pass --restore-proxy to restore): proxy');
});

it('backs up the target installation before overwriting it', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('control-plane-migrate-target-backup-')
        ->toContain('cp -p "$target_env" "$target_backup_dir/env.pre-restore"')
        ->toContain('target backup failed for tree')
        ->toContain('postgres.pre-restore.dump')
        ->toContain('target database backup failed')
        ->toContain('.pre-migration-');
});

it('applies ownership contracts to restored state', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('chown 9999:root "$target_env"')
        ->toContain('chmod 0600 "$target_env"')
        ->toContain('find "$root/ssh" -type d -exec chmod 0700')
        ->toContain('find "$root/ssh" -type f -exec chmod 0600')
        ->toContain('chown -R root:9999 "$root/proxy"');
});

it('proves database migrations against the restored database', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('docker exec "$APP_CONTAINER" php artisan start:migration')
        ->toContain('database migrations failed against the restored database')
        ->toContain('COOLIFY_MIGRATE_MIGRATION_ATTEMPTS');
});

it('records inventory and quiescence evidence in the manifest', function () {
    expect(controlPlaneMigrateScript())
        ->toContain('write_inventory_json()')
        ->toContain('"quiescence"')
        ->toContain('SOURCE_PG_MAJOR=')
        ->toContain('.inventory-post-restore.json');
});

it('has a functional integration suite wired for CI', function () {
    $workflow = file_get_contents(getcwd().'/.github/workflows/application-validation.yml');

    expect($workflow)->toContain('tests/Integration/ControlPlaneMigrateTest.sh');
});
