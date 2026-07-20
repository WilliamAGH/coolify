<?php

use App\Exceptions\NonReportableException;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SendsEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;

function emailChannelWithResendFailure(Throwable $exception, Team $teamModel): EmailChannel
{
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($exception);
    $resendClient->emails = $emailsService;

    return new EmailChannel(fn () => $resendClient, $teamModel);
}

function emailChannelWithResendSuccess(Team $teamModel, array &$apiKeys, array &$messages): EmailChannel
{
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')
        ->once()
        ->andReturnUsing(function (array $message) use (&$messages): object {
            $messages[] = $message;

            return (object) ['id' => 'email-123'];
        });
    $resendClient->emails = $emailsService;

    return new EmailChannel(function (string $apiKey) use ($resendClient, &$apiKeys): object {
        $apiKeys[] = $apiKey;

        return $resendClient;
    }, $teamModel);
}

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    $team = (object) [
        'members' => collect([
            (object) ['email' => 'test@example.com'],
            (object) ['email' => 'admin@example.com'],
        ]),
    ];

    $this->teamModel = Mockery::mock(Team::class);
    $this->teamModel->shouldReceive('find')->with(1)->andReturn($team);

    $this->settings = (object) [
        'resend_enabled' => true,
        'smtp_enabled' => false,
        'use_instance_email_settings' => false,
        'smtp_from_name' => 'Test Sender',
        'smtp_from_address' => 'sender@example.com',
        'resend_api_key' => 'test_api_key',
        'smtp_password' => 'password',
    ];

    $this->notifiable = new class($this->settings) implements SendsEmail
    {
        public int $id = 1;

        public function __construct(public object $emailNotificationSettings) {}

        public function getRecipients(): array
        {
            return ['test@example.com'];
        }
    };

    $mailMessage = Mockery::mock(MailMessage::class);
    $mailMessage->subject = 'Test Email';
    $mailMessage->shouldReceive('render')->andReturn('<html>Test</html>');

    $this->notification = new class($mailMessage) extends Notification
    {
        public bool $isTransactionalEmail = false;

        public ?string $emails = null;

        public function __construct(private MailMessage $mailMessage) {}

        public function toMail($notifiable): MailMessage
        {
            return $this->mailMessage;
        }
    };
});

it('throws user-friendly error for invalid Resend API key (403)', function () {
    $resendError = new ErrorException([
        'message' => 'API key is invalid.',
        'statusCode' => 403,
    ]);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);
    $exception = null;

    try {
        $channel->send($this->notifiable, $this->notification);
    } catch (Throwable $caughtException) {
        $exception = $caughtException;
    }

    expect($exception)
        ->toBeInstanceOf(NonReportableException::class)
        ->and($exception->getMessage())
        ->toBe('Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.')
        ->and($exception->getPrevious())
        ->toBe($resendError);
});

it('creates a team-scoped Resend client and sends the rendered message', function () {
    $apiKeys = [];
    $messages = [];
    $channel = emailChannelWithResendSuccess($this->teamModel, $apiKeys, $messages);

    $channel->send($this->notifiable, $this->notification);

    expect($apiKeys)->toBe(['test_api_key'])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toMatchArray([
            'from' => 'Test Sender <sender@example.com>',
            'to' => ['test@example.com'],
            'subject' => 'Test Email',
            'html' => '<html>Test</html>',
        ]);
});

it('distinguishes a missing Resend API key from a restricted key', function () {
    $resendError = new ErrorException([
        'message' => 'Missing API key.',
        'statusCode' => 401,
        'type' => 'missing_api_key',
    ]);
    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Resend API key is missing. Add an API key in email notification settings.',
        );
});

it('throws user-friendly error for restricted Resend API key (401)', function () {
    // Create mock ErrorException for restricted key
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(401);
    $resendError->shouldReceive('getErrorType')->andReturn('restricted_api_key');
    $resendError->shouldReceive('getErrorMessage')->andReturn('This API key is restricted to only send emails.');
    $resendError->shouldReceive('getCode')->andReturn(401);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.'
        );
});

it('throws user-friendly error for rate limiting (429)', function () {
    // Create mock ErrorException for rate limit
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(429);
    $resendError->shouldReceive('getErrorMessage')->andReturn('Too many requests.');
    $resendError->shouldReceive('getCode')->andReturn(429);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);
    $exception = null;

    try {
        $channel->send($this->notifiable, $this->notification);
    } catch (Throwable $caughtException) {
        $exception = $caughtException;
    }

    expect($exception)
        ->toBeInstanceOf(Exception::class)
        ->not->toBeInstanceOf(NonReportableException::class)
        ->and($exception->getMessage())
        ->toBe('Resend rate limit exceeded. Please try again in a few minutes.')
        ->and($exception->getPrevious())
        ->toBe($resendError);
});

it('throws user-friendly error for validation errors (400)', function () {
    // Create mock ErrorException for validation error
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(400);
    $resendError->shouldReceive('getErrorMessage')->andReturn('Invalid email format.');
    $resendError->shouldReceive('getCode')->andReturn(400);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(NonReportableException::class, 'Email validation failed: Invalid email format.');
});

it('throws user-friendly error for network/transport errors', function () {
    // Create mock TransporterException
    $transportError = Mockery::mock(TransporterException::class);
    $transportError->shouldReceive('getMessage')->andReturn('Network error');

    $channel = emailChannelWithResendFailure($transportError, $this->teamModel);

    $exception = null;

    try {
        $channel->send($this->notifiable, $this->notification);
    } catch (Throwable $caughtException) {
        $exception = $caughtException;
    }

    expect($exception)
        ->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())
        ->toBe('Unable to connect to Resend API. Please check your internet connection and try again.')
        ->and($exception->getPrevious())
        ->toBe($transportError);
});

it('uses legacy Resend code for unknown errors', function () {
    $resendError = new ErrorException([
        'code' => 500,
        'message' => 'Internal server error.',
    ]);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);
    $exception = null;

    try {
        $channel->send($this->notifiable, $this->notification);
    } catch (Throwable $caughtException) {
        $exception = $caughtException;
    }

    expect($exception)
        ->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())
        ->toBe('Failed to send email via Resend: Internal server error.')
        ->and($exception->getPrevious())
        ->toBe($resendError);
});

it('keeps malformed Resend errors chained without exposing the API key', function () {
    $resendError = new ErrorException([
        'message' => 'Malformed response for test_api_key.',
    ]);

    $channel = emailChannelWithResendFailure($resendError, $this->teamModel);
    $exception = null;

    try {
        $channel->send($this->notifiable, $this->notification);
    } catch (Throwable $caughtException) {
        $exception = $caughtException;
    }

    expect($exception)
        ->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())
        ->toBe('Failed to send email via Resend: Malformed response for ********.')
        ->not->toContain('test_api_key')
        ->and($exception->getPrevious())
        ->toBe($resendError);
});
