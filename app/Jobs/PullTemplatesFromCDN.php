<?php

namespace App\Jobs;

use App\Actions\Server\UpdateCoolify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class PullTemplatesFromCDN implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 10;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            if (isDev()) {
                return;
            }
            // A fork ships its own templates/service-templates-latest.json and is
            // the only authority over its own service catalog. Without this, the
            // hourly pull overwrote that shipped file with upstream's copy, so a
            // self-hosted fork still took a third party's ~1MB of JSON every hour
            // and served it as its own. The other upstream fetches — version
            // discovery, changelog, helper image, sentinel version — already fail
            // closed on a fork release the same way; this one was reached through
            // constants.services rather than constants.coolify and was missed.
            if (UpdateCoolify::isGuardedForkRelease(config('constants.coolify.version'))) {
                return;
            }
            $response = Http::retry(3, 1000)->get(config('constants.services.official'));
            if ($response->successful()) {
                $services = $response->json();
                File::put(base_path('templates/'.config('constants.services.file_name')), json_encode($services));
            } else {
                send_internal_notification('PullTemplatesAndVersions failed with: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            send_internal_notification('PullTemplatesAndVersions failed with: '.$e->getMessage());
        }
    }
}
