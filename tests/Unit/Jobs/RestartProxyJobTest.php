<?php

namespace Tests\Unit\Jobs;

use App\Contracts\ProxyMutation;
use App\Enums\ProcessStatus;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Sleep;
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

        $this->assertEquals(1, $job->tries);
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

    public function test_job_holds_the_canonical_worker_until_the_remote_activity_finishes()
    {
        Sleep::fake();
        $job = new RestartProxyJob(Mockery::mock(Server::class));
        $activity = Mockery::mock(Activity::class);
        $activity->shouldReceive('refresh')->twice()->andReturnSelf();
        $activity->shouldReceive('getExtraProperty')
            ->with('status')
            ->twice()
            ->andReturn(ProcessStatus::IN_PROGRESS->value, ProcessStatus::FINISHED->value);

        $waitForActivity = new ReflectionMethod($job, 'waitForActivity');
        $waitForActivity->invoke($job, $activity);

        Sleep::assertSleptTimes(1);
    }
}
