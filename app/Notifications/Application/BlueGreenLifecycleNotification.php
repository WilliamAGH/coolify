<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

abstract class BlueGreenLifecycleNotification extends CustomEmailNotification
{
    public string $applicationName;

    public string $applicationUuid;

    public string $projectName;

    public string $projectUuid;

    public string $environmentName;

    public string $environmentUuid;

    public string $applicationUrl;

    public ?string $deploymentUrl;

    public ?string $fqdn;

    public function __construct(
        Application $application,
        public ?string $deploymentUuid,
    ) {
        $this->onQueue('high');
        $this->afterCommit();
        $this->applicationName = data_get($application, 'name');
        $this->applicationUuid = data_get($application, 'uuid');
        $this->projectName = data_get($application, 'environment.project.name');
        $this->projectUuid = data_get($application, 'environment.project.uuid');
        $this->environmentName = data_get($application, 'environment.name');
        $this->environmentUuid = data_get($application, 'environment.uuid');
        $this->fqdn = str(data_get($application, 'fqdn'))->explode(',')->first();
        $this->applicationUrl = base_url()."/project/{$this->projectUuid}/environment/{$this->environmentUuid}/application/{$application->uuid}";
        $this->deploymentUrl = $deploymentUuid === null
            ? null
            : "{$this->applicationUrl}/deployment/{$deploymentUuid}";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('deployment_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject($this->mailSubject());
        $mail->view($this->mailView(), [
            'name' => $this->applicationName,
            'fqdn' => $this->fqdn,
            'application_url' => $this->applicationUrl,
            'deployment_url' => $this->deploymentUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: $this->title(),
            description: $this->description(),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
        $message->addField('Project', $this->projectName, true);
        $message->addField('Environment', $this->environmentName, true);
        $message->addField('Name', $this->applicationName, true);
        $message->addField($this->actionLabel(), '[Link]('.$this->actionUrl().')');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->description()}",
            'buttons' => [[
                'text' => $this->actionLabel(),
                'url' => $this->actionUrl(),
            ]],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->title(),
            message: $this->description(),
            buttons: [[
                'text' => $this->actionLabel(),
                'url' => $this->actionUrl(),
            ]],
            level: 'error',
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = $this->description();
        $description .= "\n\n*Project:* {$this->projectName}";
        $description .= "\n*Environment:* {$this->environmentName}";
        $description .= "\n*{$this->actionLabel()}:* {$this->actionUrl()}";

        return new SlackMessage(
            title: $this->title(),
            description: $description,
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => $this->title(),
            'event' => 'deployment_failed',
            'blue_green_event' => $this->blueGreenEvent(),
            'application_name' => $this->applicationName,
            'application_uuid' => $this->applicationUuid,
            'deployment_uuid' => $this->deploymentUuid,
            'deployment_url' => $this->deploymentUrl,
            'application_url' => $this->applicationUrl,
            'project' => $this->projectName,
            'environment' => $this->environmentName,
            'fqdn' => $this->fqdn,
        ];
    }

    private function actionLabel(): string
    {
        return $this->deploymentUrl === null ? 'Open Application' : 'Deployment Logs';
    }

    private function actionUrl(): string
    {
        return $this->deploymentUrl ?? $this->applicationUrl;
    }

    abstract protected function title(): string;

    abstract protected function description(): string;

    abstract protected function blueGreenEvent(): string;

    abstract protected function mailSubject(): string;

    abstract protected function mailView(): string;
}
