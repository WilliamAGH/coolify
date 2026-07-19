<?php

use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use App\Support\ProxyMutationQueue;

it('pins queued proxy starts to the canonical mutation lane', function () {
    $job = StartProxy::makeJob(Mockery::mock(Server::class));

    expect(ProxyMutationQueue::isMarked($job))->toBeTrue()
        ->and($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);
});

it('executes admitted proxy start jobs synchronously inside their queue worker', function () {
    $server = Mockery::mock(Server::class);
    $action = new class extends StartProxy
    {
        /** @var array{async: bool, force: bool, restarting: bool}|null */
        public ?array $received = null;

        public function handle(Server $server, bool $async = true, bool $force = false, bool $restarting = false): string
        {
            $this->received = compact('async', 'force', 'restarting');

            return 'OK';
        }
    };

    expect($action->asJob($server, async: true, force: true, restarting: true))->toBe('OK')
        ->and($action->received)->toBe([
            'async' => false,
            'force' => true,
            'restarting' => true,
        ]);
});

it('rejects a queued proxy start retargeted outside the canonical lane', function () {
    $job = StartProxy::makeJob(Mockery::mock(Server::class));
    $job->onQueue('high');

    expect(fn () => ProxyMutationQueue::assertUntamperedDispatchTarget($job))
        ->toThrow(LogicException::class, 'cannot target queue');
});
