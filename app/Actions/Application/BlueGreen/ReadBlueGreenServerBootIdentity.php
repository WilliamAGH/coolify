<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReadBlueGreenServerBootIdentity
{
    use AsAction;

    private const BOOT_ID_PATH = '/proc/sys/kernel/random/boot_id';

    public function handle(Server $server, ?string $expectedBootId = null): string
    {
        return $this->fromRemoteOutput((string) instant_remote_process([
            $this->commandFor(),
        ], $server), $expectedBootId);
    }

    public function commandFor(): string
    {
        return 'test -r '.self::BOOT_ID_PATH.'; tr -d \'\\n\' < '.self::BOOT_ID_PATH;
    }

    public function assertionCommandFor(string $expectedBootId): string
    {
        $this->fromRemoteOutput($expectedBootId);

        return 'test -r '.self::BOOT_ID_PATH
            .'; test "$(tr -d \'\\n\' < '.self::BOOT_ID_PATH.')" = '.escapeshellarg($expectedBootId);
    }

    public function fromRemoteOutput(string $output, ?string $expectedBootId = null): string
    {
        $bootId = rtrim($output, "\r\n");
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $bootId) !== 1) {
            throw new BlueGreenOperationFenceLostException('The destination server did not return a canonical boot identity.');
        }
        if ($expectedBootId !== null && ! hash_equals($expectedBootId, $bootId)) {
            throw new BlueGreenOperationFenceLostException('The destination server rebooted after the blue-green operation was claimed.');
        }

        return $bootId;
    }
}
