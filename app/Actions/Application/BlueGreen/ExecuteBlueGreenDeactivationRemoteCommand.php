<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class ExecuteBlueGreenDeactivationRemoteCommand
{
    use AsAction;

    private const OUTPUT_PREFIX = 'coolify-blue-green-deactivation-remote-v1';

    public function handle(Server $server, string $command): string
    {
        $result = $this->decode((string) instant_remote_process([
            $this->commandFor($command),
        ], $server));
        if ($result->outcome === BlueGreenDeactivationRemoteOutcome::InvariantViolation) {
            $detail = trim($result->output);
            throw new BlueGreenDeactivationException(
                'The destination proved a blue-green deactivation invariant failure with exit status '
                .$result->exitStatus.($detail === '' ? '.' : ": {$detail}"),
            );
        }

        return $result->output;
    }

    public function commandFor(string $command): string
    {
        if ($command === '') {
            throw new InvalidArgumentException('A blue-green deactivation remote command is required.');
        }

        return implode("\n", [
            'set -eu',
            'umask 077',
            'command_file=$(mktemp)',
            'stdout_file=$(mktemp)',
            'stderr_file=$(mktemp)',
            'trap \'rm -f -- "$command_file" "$stdout_file" "$stderr_file"\' 0 HUP INT TERM',
            'printf %s '.escapeshellarg(base64_encode($command)).' | base64 -d >"$command_file"',
            'set +e',
            'sh "$command_file" >"$stdout_file" 2>"$stderr_file"',
            'remote_status=$?',
            'set -e',
            'if [ "$remote_status" -eq 0 ]; then',
            '  remote_outcome='.escapeshellarg(BlueGreenDeactivationRemoteOutcome::Success->value),
            '  remote_output=$stdout_file',
            'else',
            '  remote_outcome='.escapeshellarg(BlueGreenDeactivationRemoteOutcome::InvariantViolation->value),
            '  remote_output=$stderr_file',
            'fi',
            'printf \'%s|%s|%s|\' '.escapeshellarg(self::OUTPUT_PREFIX).' "$remote_outcome" "$remote_status"',
            'base64 <"$remote_output" | tr -d \'\n\'',
        ]);
    }

    public function decode(string $encoded): BlueGreenDeactivationRemoteResult
    {
        $fields = explode('|', trim($encoded), 4);
        if (count($fields) !== 4 || $fields[0] !== self::OUTPUT_PREFIX) {
            throw new BlueGreenDeactivationTransportException(
                'Blue-green deactivation transport returned no valid remote outcome; the durable operation remains resumable.',
            );
        }
        $outcome = BlueGreenDeactivationRemoteOutcome::tryFrom($fields[1]);
        $exitStatus = filter_var($fields[2], FILTER_VALIDATE_INT);
        $output = base64_decode($fields[3], true);
        if ($outcome === null || ! is_int($exitStatus) || $exitStatus < 0 || $exitStatus > 255 || ! is_string($output)
            || ($outcome === BlueGreenDeactivationRemoteOutcome::Success) !== ($exitStatus === 0)) {
            throw new BlueGreenDeactivationTransportException(
                'Blue-green deactivation transport returned an inconsistent remote outcome; the durable operation remains resumable.',
            );
        }

        return new BlueGreenDeactivationRemoteResult($outcome, $exitStatus, $output);
    }

    public function encode(BlueGreenDeactivationRemoteResult $result): string
    {
        if (($result->outcome === BlueGreenDeactivationRemoteOutcome::Success) !== ($result->exitStatus === 0)
            || $result->exitStatus < 0 || $result->exitStatus > 255) {
            throw new InvalidArgumentException('A blue-green deactivation remote result has an inconsistent exit status.');
        }

        return implode('|', [
            self::OUTPUT_PREFIX,
            $result->outcome->value,
            (string) $result->exitStatus,
            base64_encode($result->output),
        ]);
    }
}
