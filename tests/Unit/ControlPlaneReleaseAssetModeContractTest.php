<?php

test('the release installer and production preflight agree on backup verifier mode', function () {
    $repositoryRoot = dirname(__DIR__, 2);
    $installer = file_get_contents($repositoryRoot.'/docker/control-plane-blue-green/install-host-release.sh');
    $operator = file_get_contents($repositoryRoot.'/docker/control-plane-blue-green/control-plane-blue-green.sh');

    expect($installer)
        ->not->toBeFalse()
        ->toMatch(
            '/backup-attestation-verifier\)\s+'.
            '.*?\[ "\$release_test_mode" = 1 \] \|\| \[ "\$asset_mode" = 755 \]/s',
        );

    expect($operator)
        ->not->toBeFalse()
        ->toMatch(
            '/backup-attestation-verifier\)\s+'.
            '.*?\[ "\$test_mode" = 1 \] \|\| \[ "\$release_identity_mode" = 755 \]/s',
        )
        ->toContain(
            '[ "$(file_mode "$backup_attestation_verifier")" = 755 ]',
        );
});

test('other production release executables remain private to root', function () {
    $repositoryRoot = dirname(__DIR__, 2);
    $installer = file_get_contents($repositoryRoot.'/docker/control-plane-blue-green/install-host-release.sh');
    $operator = file_get_contents($repositoryRoot.'/docker/control-plane-blue-green/control-plane-blue-green.sh');

    expect($installer)
        ->not->toBeFalse()
        ->toContain(
            '[ "$release_test_mode" = 1 ] || [ "$asset_mode" = 700 ]',
        );

    expect($operator)
        ->not->toBeFalse()
        ->toContain(
            '[ "$test_mode" = 1 ] || [ "$release_identity_mode" = 700 ]',
        );
});
