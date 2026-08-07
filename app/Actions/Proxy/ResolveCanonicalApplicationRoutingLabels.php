<?php

namespace App\Actions\Proxy;

use App\Models\Application;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveCanonicalApplicationRoutingLabels
{
    use AsAction;

    /** @return list<string> */
    public function handle(Application $application, StandaloneDocker $destination): array
    {
        if ($application->build_pack === 'dockercompose') {
            return $application->blueGreenRoutingLabels();
        }

        $applicationForLabels = clone $application;
        $applicationForLabels->setRelation('destination', $destination);
        $customLabels = data_get($applicationForLabels, 'custom_labels');
        if ($customLabels === null || $customLabels === '') {
            return generateLabelsApplication($applicationForLabels);
        }
        if (! is_string($customLabels)) {
            throw new InvalidArgumentException('The persisted canonical application routing labels are malformed.');
        }

        // custom_labels carries two persisted encodings by design: the Livewire
        // editors base64-encode it, while the API's readonly-label regeneration
        // in ApplicationsController stores newline-joined plain text. Accept
        // both, as Application::parseContainerLabels and
        // ApplicationDeploymentJob::customLogOwnerLabel already do — a
        // plain-text row is ordinary state, not corruption, and must not fail a
        // blue-green rollout. Only a value that round-trips through base64
        // exactly is treated as encoded; anything else is the literal label text.
        $decoded = base64_decode($customLabels, true);
        $resolved = $decoded !== false && base64_encode($decoded) === $customLabels
            ? $decoded
            : $customLabels;
        if (mb_detect_encoding($resolved, 'UTF-8', true) === false) {
            throw new InvalidArgumentException('The persisted canonical application routing labels are malformed.');
        }
        $labels = preg_split("/\r\n|\n|\r/", $resolved);
        if ($labels === false || $labels === []) {
            throw new InvalidArgumentException('The persisted canonical application routing labels are malformed.');
        }

        return $labels;
    }
}
