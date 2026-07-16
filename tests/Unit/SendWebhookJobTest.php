<?php

use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('sends webhook to valid URLs', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $job = new SendWebhookJob(
        payload: ['event' => 'test'],
        webhookUrl: 'https://example.com/webhook'
    );

    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook';
    });
});

it('blocks webhook to loopback address', function () {
    Http::fake();
    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(function ($message) {
            return in_array($message, [
                'Webhook URL points to blocked IP range',
                'SendWebhookJob: blocked unsafe webhook URL',
            ], true);
        });

    $job = new SendWebhookJob(
        payload: ['event' => 'test'],
        webhookUrl: 'http://127.0.0.1/admin'
    );

    $job->handle();

    Http::assertNothingSent();
});

it('blocks webhook to cloud metadata endpoint', function () {
    Http::fake();
    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(function ($message) {
            return in_array($message, [
                'Webhook URL points to blocked IP range',
                'SendWebhookJob: blocked unsafe webhook URL',
            ], true);
        });

    $job = new SendWebhookJob(
        payload: ['event' => 'test'],
        webhookUrl: 'http://169.254.169.254/latest/meta-data/'
    );

    $job->handle();

    Http::assertNothingSent();
});

it('blocks webhook to localhost', function () {
    Http::fake();
    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(function ($message) {
            return in_array($message, [
                'Webhook URL points to blocked host',
                'SendWebhookJob: blocked unsafe webhook URL',
            ], true);
        });

    $job = new SendWebhookJob(
        payload: ['event' => 'test'],
        webhookUrl: 'http://localhost/internal-api'
    );

    $job->handle();

    Http::assertNothingSent();
});
