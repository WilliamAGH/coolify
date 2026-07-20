<?php

namespace App\Notifications\Channels;

use App\Exceptions\NonReportableException;
use App\Models\Team;
use Closure;
use Exception;
use Illuminate\Notifications\Notification;
use Resend;
use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailChannel
{
    public function __construct(
        private readonly ?Closure $resendClientFactory = null,
        private readonly ?Team $teamModel = null,
    ) {}

    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        try {
            // Get team and validate membership before proceeding
            $teamId = data_get($notifiable, 'id');
            $members = ($this->teamModel ?? new Team)->find($teamId)->members;

            $useInstanceEmailSettings = $notifiable->emailNotificationSettings->use_instance_email_settings;
            $isTransactionalEmail = data_get($notification, 'isTransactionalEmail', false);
            $customEmails = data_get($notification, 'emails', null);

            if ($useInstanceEmailSettings || $isTransactionalEmail) {
                $settings = instanceSettings();
            } else {
                $settings = $notifiable->emailNotificationSettings;
            }

            $isResendEnabled = $settings->resend_enabled;
            $isSmtpEnabled = $settings->smtp_enabled;

            if ($customEmails) {
                $recipients = [$customEmails];
            } else {
                $recipients = $notifiable->getRecipients();
            }

            // Validate team membership for all recipients
            if (count($recipients) === 0) {
                throw new Exception('No email recipients found');
            }

            // Skip team membership validation for test notifications
            $isTestNotification = data_get($notification, 'isTestNotification', false);

            if (! $isTestNotification) {
                foreach ($recipients as $recipient) {
                    // Check if the recipient is part of the team
                    if (! $members->contains('email', $recipient)) {
                        $emailSettings = $notifiable->emailNotificationSettings;
                        data_set($emailSettings, 'smtp_password', '********');
                        data_set($emailSettings, 'resend_api_key', '********');
                        send_internal_notification(sprintf(
                            "Recipient is not part of the team: %s\nTeam: %s\nNotification: %s\nNotifiable: %s\nEmail Settings:\n%s",
                            $recipient,
                            $teamId,
                            get_class($notification),
                            get_class($notifiable),
                            json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        ));
                        throw new Exception('Recipient is not part of the team');
                    }
                }
            }

            $mailMessage = $notification->toMail($notifiable);

            if ($isResendEnabled) {
                $resend = $this->resendClientFactory
                    ? ($this->resendClientFactory)($settings->resend_api_key)
                    : Resend::client($settings->resend_api_key);
                $from = "{$settings->smtp_from_name} <{$settings->smtp_from_address}>";
                $resend->emails->send([
                    'from' => $from,
                    'to' => $recipients,
                    'subject' => $mailMessage->subject,
                    'html' => (string) $mailMessage->render(),
                ]);
            } elseif ($isSmtpEnabled) {
                $encryption = match (strtolower($settings->smtp_encryption)) {
                    'starttls' => null,
                    'tls' => 'tls',
                    'none' => null,
                    default => null,
                };

                $transport = new EsmtpTransport(
                    $settings->smtp_host,
                    $settings->smtp_port,
                    $encryption
                );
                $transport->setUsername($settings->smtp_username ?? '');
                $transport->setPassword($settings->smtp_password ?? '');

                $mailer = new Mailer($transport);

                $fromEmail = $settings->smtp_from_address ?? 'noreply@localhost';
                $fromName = $settings->smtp_from_name ?? 'System';
                $from = new Address($fromEmail, $fromName);
                $email = (new Email)
                    ->from($from)
                    ->to(...$recipients)
                    ->subject($mailMessage->subject)
                    ->html((string) $mailMessage->render());

                $mailer->send($email);
            }
        } catch (ErrorException $e) {
            [$statusCode, $errorType] = $this->resendErrorMetadata($e);

            $errorMessage = $e->getErrorMessage();
            if (filled($settings->resend_api_key)) {
                $errorMessage = str_replace($settings->resend_api_key, '********', $errorMessage);
            }

            // Map HTTP status codes to user-friendly messages
            $userMessage = match (true) {
                $statusCode === 401 && $errorType === 'missing_api_key' => 'Resend API key is missing. Add an API key in email notification settings.',
                $statusCode === 401 && $errorType === 'restricted_api_key' => 'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.',
                $statusCode === 401 => 'Resend rejected the API key. Verify that it is present and has Full Access permissions.',
                $statusCode === 403 => 'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.',
                $statusCode === 429 => 'Resend rate limit exceeded. Please try again in a few minutes.',
                $statusCode === 400 => 'Email validation failed: '.$errorMessage,
                default => 'Failed to send email via Resend: '.$errorMessage,
            };

            // Log detailed error for admin debugging (redact sensitive data)
            $emailSettings = $notifiable->emailNotificationSettings ?? instanceSettings();
            data_set($emailSettings, 'smtp_password', '********');
            data_set($emailSettings, 'resend_api_key', '********');

            send_internal_notification(sprintf(
                "Resend Error\nStatus Code: %s\nType: %s\nMessage: %s\nNotification: %s\nEmail Settings:\n%s",
                $statusCode ?? 'unknown',
                $errorType ?? 'unknown',
                $errorMessage,
                get_class($notification),
                json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ));

            // Don't report expected errors (invalid keys, validation) to Sentry
            if (in_array($statusCode, [403, 401, 400], true)) {
                throw new NonReportableException($userMessage, $statusCode, $e);
            }

            throw new Exception($userMessage, $statusCode ?? 0, $e);
        } catch (TransporterException $e) {
            send_internal_notification("Resend Transport Error: {$e->getMessage()}");
            throw new Exception('Unable to connect to Resend API. Please check your internet connection and try again.', 0, $e);
        } catch (\Throwable $e) {
            // Check if this is a Resend domain verification error on cloud instances
            if (isCloud() && str_contains($e->getMessage(), 'domain is not verified')) {
                // Throw as NonReportableException so it won't go to Sentry
                throw NonReportableException::fromException($e);
            }
            throw $e;
        }
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function resendErrorMetadata(ErrorException $exception): array
    {
        set_error_handler(
            static fn (int $level, string $message): bool => $level === E_WARNING && str_starts_with($message, 'Undefined array key'),
            E_WARNING
        );

        try {
            try {
                $statusCode = $exception->getErrorCode();
            } catch (\Throwable) {
                $statusCode = null;
            }

            try {
                $errorType = $exception->getErrorType();
            } catch (\Throwable) {
                $errorType = null;
            }
        } finally {
            restore_error_handler();
        }

        return [$statusCode, $errorType];
    }
}
