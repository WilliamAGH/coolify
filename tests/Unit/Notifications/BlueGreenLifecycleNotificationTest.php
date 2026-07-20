<?php

use App\Jobs\SendMessageToTelegramJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Notifications\Application\BlueGreenDeploymentRolledBack;
use App\Notifications\Application\BlueGreenInterventionRequired;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'fqdn' => 'https://coolify.example.test',
    ]));
    $project = new Project([
        'name' => 'Blue/green project',
        'uuid' => 'blue-green-project',
    ]);
    $environment = new Environment([
        'name' => 'production',
        'uuid' => 'blue-green-production',
    ]);
    $environment->setRelation('project', $project);
    $this->application = new Application([
        'name' => 'Example application',
        'uuid' => 'example-application',
        'fqdn' => 'https://app.example.test,https://second.example.test',
    ]);
    $this->application->setRelation('environment', $environment);
});

it('builds intervention payloads for every deployment failure channel', function () {
    $notification = new BlueGreenInterventionRequired($this->application);
    $notifiable = Mockery::mock();
    $notifiable->shouldReceive('getEnabledChannels')
        ->once()
        ->with('deployment_failure')
        ->andReturn(['mail']);

    expect($notification->via($notifiable))->toBe(['mail'])
        ->and($notification->queue)->toBe('high')
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->toMail()->subject)->toContain('Action required')
        ->and($notification->toMail()->view)->toBe('emails.application-blue-green-intervention-required')
        ->and($notification->toDiscord()->title)->toBe('Blue/green intervention required')
        ->and($notification->toTelegram()['message'])->toContain('operator intervention')
        ->and($notification->toPushover()->level)->toBe('error')
        ->and($notification->toSlack()->description)->toContain('Open Application')
        ->and($notification->toWebhook())->toMatchArray([
            'success' => false,
            'event' => 'deployment_failed',
            'blue_green_event' => 'intervention_required',
            'application_uuid' => 'example-application',
            'deployment_uuid' => null,
        ]);
});

it('builds rollback payloads with deployment evidence', function () {
    $notification = new BlueGreenDeploymentRolledBack($this->application, 'rolled-back-deployment');
    $notifiable = Mockery::mock();
    $notifiable->shouldReceive('getEnabledChannels')
        ->once()
        ->with('deployment_failure')
        ->andReturn(['mail', 'slack']);

    expect($notification->via($notifiable))->toBe(['mail', 'slack'])
        ->and(unserialize(serialize($notification)))->toEqual($notification)
        ->and($notification->toMail()->subject)->toContain('rolled back')
        ->and($notification->toMail()->view)->toBe('emails.application-blue-green-deployment-rolled-back')
        ->and($notification->toDiscord()->description)->toContain('previous healthy version')
        ->and($notification->toTelegram()['buttons'][0]['url'])->toEndWith('/deployment/rolled-back-deployment')
        ->and($notification->toPushover()->message)->toContain('previous healthy version')
        ->and($notification->toSlack()->title)->toBe('Blue/green deployment rolled back')
        ->and($notification->toWebhook())->toMatchArray([
            'success' => false,
            'event' => 'deployment_failed',
            'blue_green_event' => 'reconciler_rollback',
            'application_uuid' => 'example-application',
            'deployment_uuid' => 'rolled-back-deployment',
        ]);
});

it('uses the configured deployment failure Telegram thread', function () {
    Bus::fake([SendMessageToTelegramJob::class]);
    $settings = (object) [
        'telegram_token' => 'telegram-token',
        'telegram_chat_id' => 'telegram-chat',
        'telegram_notifications_deployment_failure_thread_id' => 'failure-thread',
    ];
    $notifiable = new class($settings)
    {
        public function __construct(public object $telegramNotificationSettings) {}
    };

    (new TelegramChannel)->send(
        $notifiable,
        new BlueGreenInterventionRequired($this->application),
    );
    (new TelegramChannel)->send(
        $notifiable,
        new BlueGreenDeploymentRolledBack($this->application, 'rolled-back-deployment'),
    );

    Bus::assertDispatched(
        SendMessageToTelegramJob::class,
        fn (SendMessageToTelegramJob $job): bool => $job->threadId === 'failure-thread',
    );
    Bus::assertDispatchedTimes(SendMessageToTelegramJob::class, 2);
});
