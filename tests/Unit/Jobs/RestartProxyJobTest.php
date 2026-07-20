<?php

namespace Tests\Unit\Jobs;

use App\Contracts\ProxyMutation;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Mockery;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Unit tests for RestartProxyJob.
 *
 * These tests focus on testing the job's middleware configuration and constructor.
 * Full integration tests for the job's handle() method are in tests/Feature/Proxy/
 * because they require database and complex mocking of SchemalessAttributes.
 */
class RestartProxyJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_has_without_overlapping_middleware()
    {
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
        $server->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');

        $job = new RestartProxyJob($server);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_has_correct_configuration()
    {
        $server = Mockery::mock(Server::class);

        $job = new RestartProxyJob($server);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3, $job->maxExceptions);
        $this->assertSame([30, 90, 180], $job->backoff());
        $this->assertEquals(660, $job->timeout);
        $this->assertNull($job->activity_id);
        $this->assertInstanceOf(ProxyMutation::class, $job);
        $this->assertSame(ProxyMutationQueue::CONNECTION, $job->connection);
        $this->assertSame(ProxyMutationQueue::NAME, $job->queue);
    }

    public function test_job_stores_server()
    {
        $server = Mockery::mock(Server::class);

        $job = new RestartProxyJob($server);

        $this->assertSame($server, $job->server);
    }

    public function test_job_timeout_covers_the_inline_remote_process_budget()
    {
        $job = new RestartProxyJob(Mockery::mock(Server::class));

        $this->assertGreaterThan(RestartProxyJob::REMOTE_TIMEOUT_SECONDS, $job->timeout);
    }

    public function test_job_announces_the_activity_before_inline_execution()
    {
        Event::fake([ProxyStatusChangedUI::class]);
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
        $server->shouldReceive('getAttribute')->with('team_id')->andReturn(19);
        $activity = new Activity;
        $activity->id = 314;
        $job = new RestartProxyJob($server);

        $announceActivity = new ReflectionMethod($job, 'announceActivity');
        $announceActivity->invoke($job, $activity);

        $this->assertSame(314, $job->activity_id);
        Event::assertDispatched(
            ProxyStatusChangedUI::class,
            fn (ProxyStatusChangedUI $event): bool => $event->teamId === 19 && $event->activityId === 314,
        );
    }
}
