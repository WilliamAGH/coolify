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

        $decoded = base64_decode($customLabels, true);
        if ($decoded === false || mb_detect_encoding($decoded, 'UTF-8', true) === false) {
            throw new InvalidArgumentException('The persisted canonical application routing labels are malformed.');
        }
        $labels = preg_split("/\r\n|\n|\r/", $decoded);
        if ($labels === false || $labels === []) {
            throw new InvalidArgumentException('The persisted canonical application routing labels are malformed.');
        }

        return $labels;
    }
}
