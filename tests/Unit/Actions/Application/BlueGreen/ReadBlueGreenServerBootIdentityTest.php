<?php

use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\ReadBlueGreenServerBootIdentity;

it('reads one canonical Linux boot identity', function () {
    $action = new ReadBlueGreenServerBootIdentity;
    $bootId = '11111111-2222-3333-4444-555555555555';

    expect($action->commandFor())
        ->toBe("test -r /proc/sys/kernel/random/boot_id; tr -d '\\n' < /proc/sys/kernel/random/boot_id")
        ->and($action->assertionCommandFor($bootId))
        ->toBe("test -r /proc/sys/kernel/random/boot_id; test \"$(tr -d '\\n' < /proc/sys/kernel/random/boot_id)\" = '11111111-2222-3333-4444-555555555555'")
        ->and($action->fromRemoteOutput($bootId."\n", $bootId))->toBe($bootId);
});

it('rejects a changed server boot identity', function () {
    expect(fn () => (new ReadBlueGreenServerBootIdentity)->fromRemoteOutput(
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        '11111111-2222-3333-4444-555555555555',
    ))->toThrow(BlueGreenOperationFenceLostException::class, 'rebooted');
});

it('rejects missing or malformed server boot identities', function (string $output) {
    expect(fn () => (new ReadBlueGreenServerBootIdentity)->fromRemoteOutput($output))
        ->toThrow(BlueGreenOperationFenceLostException::class, 'canonical boot identity');
})->with([
    'missing' => '',
    'uppercase' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
    'surrounding spaces' => ' 11111111-2222-3333-4444-555555555555 ',
    'machine id instead of boot id' => '11111111222233334444555555555555',
]);
