<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function releaseWorkflowRepositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function releaseWorkflowTestDigest(string $character): string
{
    return 'sha256:'.str_repeat($character, 64);
}

/**
 * @return array{curl_log: string, environment: array<string, string>, log: string, state: string}
 */
function releaseWorkflowPrepareForkPromotionRegistryDouble(
    string $fixture,
    ?string $mainSemanticDigest,
): array {
    $filesystem = new Filesystem;
    $bin = $fixture.'/bin';
    $curlLog = $fixture.'/curl.log';
    $log = $fixture.'/regctl.log';
    $state = $fixture.'/state';
    $filesystem->mkdir([$bin, $state]);
    file_put_contents($curlLog, '');
    file_put_contents($log, '');
    file_put_contents($state.'/main', ($mainSemanticDigest ?? 'absent')."\n");
    file_put_contents($bin.'/regctl', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${REGCTL_LOG:?}"
main=${MAIN_TARGET:?}
semantic=${SEMANTIC_VERSION:?}
state=${REGCTL_STATE:?}

semantic_digest() {
    if [ ! -f "$1" ]; then
        printf '%s\n' 'manifest unknown' >&2
        exit 1
    fi
    value="$(cat "$1")"
    if [ "$value" = absent ]; then
        printf '%s\n' 'manifest unknown' >&2
        exit 1
    fi
    printf '%s\n' "$value"
}

case "$1:$2" in
    image:digest)
        reference=$3
        case " $* " in
            *' --platform linux/amd64 '*)
                case "$reference" in
                    "$main"@*) printf '%s\n' "$MAIN_AMD64_DIGEST" ;;
                    *) exit 64 ;;
                esac
                ;;
            *' --platform linux/arm64 '*)
                case "$reference" in
                    "$main"@*) printf '%s\n' "$MAIN_ARM64_DIGEST" ;;
                    *) exit 64 ;;
                esac
                ;;
            *)
                case "$reference" in
                    "$main:$semantic") semantic_digest "$state/main" ;;
                    "$main:"*) semantic_digest "$state/${reference#"$main:"}" ;;
                    "$main"@*) printf '%s\n' "$MAIN_INDEX_DIGEST" ;;
                    *) exit 64 ;;
                esac
                ;;
        esac
        ;;
    image:config)
        printf '{"config":{"Labels":{"org.opencontainers.image.source":"%s","org.opencontainers.image.revision":"%s","org.opencontainers.image.version":"%s"}}}\n' "$SOURCE_URL" "$SOURCE_REVISION" "$SEMANTIC_VERSION"
        ;;
    manifest:get)
        version=$SEMANTIC_VERSION
        if [ "$3" = "$main:fork-latest" ] && [ -n "${FORK_LATEST_VERSION:-}" ]; then
            version=$FORK_LATEST_VERSION
        fi
        printf '{"mediaType":"application/vnd.oci.image.index.v1+json","annotations":{"org.opencontainers.image.source":"%s","org.opencontainers.image.revision":"%s","org.opencontainers.image.version":"%s"}}\n' "$SOURCE_URL" "$SOURCE_REVISION" "$version"
        ;;
    image:copy)
        source=$3
        destination=$4
        case "$destination" in
            "$main:$semantic") printf '%s\n' "$MAIN_INDEX_DIGEST" > "$state/main" ;;
            "$main:"*) printf '%s\n' "$MAIN_INDEX_DIGEST" > "$state/${destination#"$main:"}" ;;
            *) exit 64 ;;
        esac
        ;;
    *) exit 64 ;;
esac
SH);
    chmod($bin.'/regctl', 0755);
    file_put_contents($bin.'/curl', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${CURL_LOG:?}"
api="https://api.github.com/repos/${GITHUB_REPOSITORY:?}"
semantic=${SEMANTIC_VERSION:?}
source_revision=${GITHUB_SHA:?}
tag_one=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
tag_two=cccccccccccccccccccccccccccccccccccccccc
url=''
for argument in "$@"; do
    case "$argument" in
        https://api.github.com/*) url=$argument ;;
    esac
done

case "$url" in
    "$api/git/ref/tags/$semantic")
        printf '{"ref":"refs/tags/%s","object":{"type":"tag","sha":"%s"}}\n' "$semantic" "$tag_one"
        ;;
    "$api/git/tags/$tag_one")
        printf '{"object":{"type":"tag","sha":"%s"}}\n' "$tag_two"
        ;;
    "$api/git/tags/$tag_two")
        if [ "${FORK_LIVE_TAG_STATE:-matching}" = mismatch ]; then
            source_revision=dddddddddddddddddddddddddddddddddddddddd
        fi
        printf '{"object":{"type":"commit","sha":"%s"}}\n' "$source_revision"
        ;;
    "$api/rulesets?targets=tag&includes_parents=true&per_page=100")
        printf '[{"id":42,"name":"Protect Coolify fork release tags","enforcement":"active"}]\n'
        ;;
    "$api/rulesets/42")
        if [ "${FORK_RULESET_STATE:-matching}" = missing-deletion ]; then
            printf '{"id":42,"name":"Protect Coolify fork release tags","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/*.*.*-fork*"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"}]}\n'
        else
            printf '{"id":42,"name":"Protect Coolify fork release tags","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/*.*.*-fork*"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"},{"type":"deletion"}]}\n'
        fi
        ;;
    *)
        printf 'unexpected curl URL: %s\n' "$url" >&2
        exit 64
        ;;
esac
SH);
    chmod($bin.'/curl', 0755);

    return [
        'curl_log' => $curlLog,
        'environment' => [
            'CURL_LOG' => $curlLog,
            'GITHUB_EVENT_NAME' => 'push',
            'GITHUB_REF' => 'refs/tags/4.13.1-fork',
            'GITHUB_REF_TYPE' => 'tag',
            'GITHUB_REPOSITORY' => 'williamacallahan/coolify',
            'GITHUB_SHA' => str_repeat('a', 40),
            'MAIN_AMD64_DIGEST' => releaseWorkflowTestDigest('1'),
            'MAIN_ARM64_DIGEST' => releaseWorkflowTestDigest('2'),
            'MAIN_INDEX_DIGEST' => releaseWorkflowTestDigest('3'),
            'MAIN_TARGET' => 'docker.iocloudhost.net/williamagh/coolify',
            'FORK_LATEST_TAG' => 'docker.iocloudhost.net/williamagh/coolify:fork-latest',
            'FORK_VERSION_TAG' => 'docker.iocloudhost.net/williamagh/coolify:fork-4.13.1-fork',
            'FORK_VERSION_SHA_TAG' => 'docker.iocloudhost.net/williamagh/coolify:fork-4.13.1-fork-aaaaaaa',
            'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
            'REGCTL_LOG' => $log,
            'REGCTL_STATE' => $state,
            'RUNNER_TEMP' => $fixture,
            'SEMANTIC_VERSION' => '4.13.1-fork',
            'SOURCE_REVISION' => str_repeat('a', 40),
            'SOURCE_IS_TAG' => 'true',
            'SOURCE_URL' => 'https://github.com/williamacallahan/coolify',
        ],
        'log' => $log,
        'state' => $state,
    ];
}

/**
 * @param  list<string>  $draftAssets
 * @param  array<string, string>  $downloadContents
 * @return array{environment: array<string, string>, gh_log: string, metadata: string}
 */
function releaseWorkflowPrepareForkDraftRecoveryDouble(
    string $fixture,
    array $draftAssets,
    array $downloadContents = [],
    bool $isDraft = true,
    ?string $tagTargetCommit = null,
): array {
    $filesystem = new Filesystem;
    $bin = $fixture.'/bin';
    $bundle = $fixture.'/fork-release-bundles';
    $downloads = $fixture.'/release-downloads';
    $metadata = $fixture.'/release-metadata.json';
    $ghLog = $fixture.'/gh.log';
    $bundleAssets = [
        'release-linux-amd64.env' => "PLATFORM=linux/amd64\n",
        'release-linux-arm64.env' => "PLATFORM=linux/arm64\n",
    ];
    $checksumLines = [];

    $filesystem->mkdir([$bin, $bundle, $downloads]);
    foreach ($bundleAssets as $assetName => $contents) {
        file_put_contents($bundle.'/'.$assetName, $contents);
        file_put_contents($downloads.'/'.$assetName, $downloadContents[$assetName] ?? $contents);
        $checksumLines[] = hash('sha256', $contents).'  ./'.$assetName;
    }
    file_put_contents($bundle.'/SHA256SUMS', implode("\n", $checksumLines)."\n");
    file_put_contents($downloads.'/SHA256SUMS', $downloadContents['SHA256SUMS'] ?? (string) file_get_contents($bundle.'/SHA256SUMS'));
    file_put_contents($metadata, json_encode([
        'tagName' => '4.13.1-fork',
        'name' => 'Coolify fork 4.13.1-fork',
        'isDraft' => $isDraft,
        'isPrerelease' => true,
        'assets' => array_map(static fn (string $assetName): array => ['name' => $assetName], $draftAssets),
    ], JSON_THROW_ON_ERROR));
    file_put_contents($ghLog, '');
    file_put_contents($bin.'/gh', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${GH_LOG:?}"

case "$1:$2" in
    release:view)
        cat "${DRAFT_RELEASE_METADATA:?}"
        ;;
    release:download)
        shift 2
        pattern=''
        directory=''
        while [ "$#" -gt 0 ]; do
            case "$1" in
                --pattern) pattern=$2; shift 2 ;;
                --dir) directory=$2; shift 2 ;;
                *) shift ;;
            esac
        done
        [ -n "$pattern" ]
        [ -n "$directory" ]
        cp "${DRAFT_RELEASE_DOWNLOADS:?}/$pattern" "$directory/$pattern"
        ;;
    release:create)
        printf 'unexpected release creation\n' >&2
        exit 64
        ;;
    api:*)
        [ "$2" = "repos/${GITHUB_REPOSITORY:?}/commits/${SEMANTIC_VERSION:?}" ]
        printf '%s\n' "${DRAFT_RELEASE_TAG_TARGET:?}"
        ;;
    *)
        printf 'unexpected gh invocation: %s %s\n' "$1" "$2" >&2
        exit 64
        ;;
esac
SH);
    chmod($bin.'/gh', 0755);

    return [
        'environment' => [
            'BUNDLE_ARTIFACT_ID' => 'fixture-artifact',
            'DRAFT_RELEASE_DOWNLOADS' => $downloads,
            'DRAFT_RELEASE_METADATA' => $metadata,
            'DRAFT_RELEASE_TAG_TARGET' => $tagTargetCommit ?? str_repeat('a', 40),
            'GH_CLI' => $bin.'/gh',
            'GH_LOG' => $ghLog,
            'GH_TOKEN' => 'fixture-token',
            'GITHUB_REF' => 'refs/tags/4.13.1-fork',
            'GITHUB_REPOSITORY' => 'williamacallahan/coolify',
            'GITHUB_SHA' => str_repeat('a', 40),
            'RUNNER_TEMP' => $fixture,
            'SEMANTIC_VERSION' => '4.13.1-fork',
            'SOURCE_REVISION' => str_repeat('a', 40),
        ],
        'gh_log' => $ghLog,
        'metadata' => $metadata,
    ];
}

/**
 * @return array{environment: array<string, string>, gh_log: string, state: string}
 */
function releaseWorkflowPrepareForkPublicationDouble(
    string $fixture,
    bool $isDraft,
    string $editMode = 'success',
    ?string $tagTargetCommit = null,
): array {
    $filesystem = new Filesystem;
    $bin = $fixture.'/bin';
    $bundle = $fixture.'/fork-release-bundles';
    $downloads = $fixture.'/release-downloads';
    $ghLog = $fixture.'/gh.log';
    $state = $fixture.'/release-state';
    $bundleAssets = [
        'release-linux-amd64.env' => "PLATFORM=linux/amd64\n",
        'release-linux-arm64.env' => "PLATFORM=linux/arm64\n",
    ];

    $filesystem->mkdir([$bin, $bundle, $downloads]);
    foreach ($bundleAssets as $assetName => $contents) {
        file_put_contents($bundle.'/'.$assetName, $contents);
    }
    $privateKey = $fixture.'/release-signing-ed25519.pem';
    (new Process(['openssl', 'genpkey', '-algorithm', 'Ed25519', '-out', $privateKey]))->mustRun();
    (new Process([
        'openssl', 'pkey', '-in', $privateKey, '-pubout', '-out', $bundle.'/release-signing-ed25519.pub',
    ]))->mustRun();
    foreach (array_keys($bundleAssets) as $manifest) {
        (new Process([
            'openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', $privateKey,
            '-in', $bundle.'/'.$manifest, '-out', $bundle.'/'.$manifest.'.sig',
        ]))->mustRun();
    }
    $assetNames = [
        'release-linux-amd64.env',
        'release-linux-amd64.env.sig',
        'release-linux-arm64.env',
        'release-linux-arm64.env.sig',
        'release-signing-ed25519.pub',
    ];
    $checksumLines = [];
    foreach ($assetNames as $assetName) {
        copy($bundle.'/'.$assetName, $downloads.'/'.$assetName);
        $checksumLines[] = hash_file('sha256', $bundle.'/'.$assetName).'  ./'.$assetName;
    }
    file_put_contents($bundle.'/SHA256SUMS', implode("\n", $checksumLines)."\n");
    copy($bundle.'/SHA256SUMS', $downloads.'/SHA256SUMS');
    file_put_contents($ghLog, '');
    file_put_contents($state, $isDraft ? "true\n" : "false\n");
    file_put_contents($bin.'/gh', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${GH_LOG:?}"

case "$1:$2" in
    release:view)
        printf '{"tagName":"%s","name":"Coolify fork %s","isDraft":%s,"isPrerelease":true,"assets":%s}\n' \
            "${SEMANTIC_VERSION:?}" "$SEMANTIC_VERSION" "$(cat "${RELEASE_STATE:?}")" "${RELEASE_ASSETS_JSON:?}"
        ;;
    release:download)
        shift 2
        pattern=''
        directory=''
        while [ "$#" -gt 0 ]; do
            case "$1" in
                --pattern) pattern=$2; shift 2 ;;
                --dir) directory=$2; shift 2 ;;
                *) shift ;;
            esac
        done
        [ -n "$pattern" ]
        [ -n "$directory" ]
        cp "${RELEASE_DOWNLOADS:?}/$pattern" "$directory/$pattern"
        ;;
    release:edit)
        printf 'false\n' > "${RELEASE_STATE:?}"
        if [ "${RELEASE_EDIT_MODE:?}" = ambiguous-success ]; then
            exit 1
        fi
        ;;
    release:upload)
        printf 'unexpected release upload\n' >&2
        exit 64
        ;;
    api:*)
        [ "$2" = "repos/${GITHUB_REPOSITORY:?}/commits/${SEMANTIC_VERSION:?}" ]
        printf '%s\n' "${RELEASE_TAG_TARGET:?}"
        ;;
    *)
        printf 'unexpected gh invocation: %s %s\n' "$1" "$2" >&2
        exit 64
        ;;
esac
SH);
    chmod($bin.'/gh', 0755);

    $assetNames[] = 'SHA256SUMS';

    return [
        'environment' => [
            'BUNDLE_ARTIFACT_ID' => 'fixture-artifact',
            'GH_CLI' => $bin.'/gh',
            'GH_LOG' => $ghLog,
            'GH_TOKEN' => 'fixture-token',
            'GITHUB_REF' => 'refs/tags/4.13.1-fork',
            'GITHUB_REPOSITORY' => 'williamacallahan/coolify',
            'GITHUB_SHA' => str_repeat('a', 40),
            'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
            'RELEASE_ASSETS_JSON' => json_encode(
                array_map(static fn (string $assetName): array => ['name' => $assetName], $assetNames),
                JSON_THROW_ON_ERROR,
            ),
            'RELEASE_DOWNLOADS' => $downloads,
            'RELEASE_EDIT_MODE' => $editMode,
            'RELEASE_STATE' => $state,
            'RELEASE_TAG_TARGET' => $tagTargetCommit ?? str_repeat('a', 40),
            'RUNNER_TEMP' => $fixture,
            'SEMANTIC_VERSION' => '4.13.1-fork',
            'SOURCE_REVISION' => str_repeat('a', 40),
        ],
        'gh_log' => $ghLog,
        'state' => $state,
    ];
}

function releaseWorkflowRegistryReferencePath(string $stateDirectory, string $reference): string
{
    return $stateDirectory.'/refs/'.hash('sha256', $reference);
}

function releaseWorkflowWriteRegistryReference(string $stateDirectory, string $reference, string $digest): void
{
    file_put_contents(releaseWorkflowRegistryReferencePath($stateDirectory, $reference), $digest."\n");
}

function releaseWorkflowReadRegistryReference(string $stateDirectory, string $reference): ?string
{
    $path = releaseWorkflowRegistryReferencePath($stateDirectory, $reference);

    return is_file($path) ? trim((string) file_get_contents($path)) : null;
}

/** @return list<string> */
function releaseWorkflowNeeds(array $job): array
{
    $needs = $job['needs'] ?? [];

    return is_array($needs) ? array_values($needs) : [$needs];
}

/** @return list<array<string, mixed>> */
function releaseWorkflowSteps(array $job): array
{
    return array_values(array_filter($job['steps'] ?? [], is_array(...)));
}

/** @return array<string, mixed> */
function releaseWorkflowStep(array $job, string $name): array
{
    foreach (releaseWorkflowSteps($job) as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    return [];
}

/** @return array<string, mixed> */
function releaseWorkflowStepById(array $job, string $id): array
{
    foreach (releaseWorkflowSteps($job) as $step) {
        if (($step['id'] ?? null) === $id) {
            return $step;
        }
    }

    return [];
}

function releaseWorkflowSelectionCaseBody(string $selectionScript, string $sourcePath): string
{
    $matched = preg_match(
        '/^[\\t ]*[^\\r\\n]*'.preg_quote($sourcePath, '/').'[^\\r\\n]*\\)[\\t ]*\\R(?<body>.*?)(?=^[\\t ]*;;)/ms',
        $selectionScript,
        $matches,
    );

    return $matched === 1 ? $matches['body'] : '';
}

/** @return list<string> */
function releaseWorkflowActionReferences(array $workflow): array
{
    $references = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        if (! is_array($job)) {
            continue;
        }

        if (is_string($job['uses'] ?? null)) {
            $references[] = $job['uses'];
        }

        foreach (releaseWorkflowSteps($job) as $step) {
            if (is_string($step['uses'] ?? null)) {
                $references[] = $step['uses'];
            }
        }
    }

    return $references;
}

/** @return list<string> */
function releaseWorkflowContainerReferences(array $workflow): array
{
    $references = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        if (! is_array($job)) {
            continue;
        }

        $container = $job['container'] ?? null;
        if (is_string($container)) {
            $references[] = $container;
        } elseif (is_string($container['image'] ?? null)) {
            $references[] = $container['image'];
        }

        foreach ($job['services'] ?? [] as $service) {
            if (is_string($service['image'] ?? null)) {
                $references[] = $service['image'];
            }
        }
    }

    return $references;
}

/** @return list<string> */
function releaseWorkflowScalarValues(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    $values = [];
    foreach ($value as $nestedValue) {
        $values = [...$values, ...releaseWorkflowScalarValues($nestedValue)];
    }

    return $values;
}

/** @return list<array{mutation: string, expected: string}> */
function releaseWorkflowNegativeCases(): array
{
    $fixture = Yaml::parseFile(
        releaseWorkflowRepositoryRoot().'/tests/Fixtures/supply-chain-workflow-negative-cases.yml',
    );
    $cases = $fixture['cases'] ?? [];

    return array_map(
        static fn (array $case): array => [
            'mutation' => (string) $case['mutation'],
            'expected' => (string) $case['expected'],
        ],
        $cases,
    );
}

/** @return list<string> */
/** @return list<string> */
function releaseFoundationWorkflowViolations(array $sharedWorkflow, array $applicationValidationWorkflow, array $callers): array
{
    $violations = [];
    $workflowCall = $sharedWorkflow['on']['workflow_call'] ?? null;
    $jobs = $sharedWorkflow['jobs'] ?? [];
    $applicationJobs = $applicationValidationWorkflow['jobs'] ?? [];

    if (! is_array($workflowCall)) {
        $violations[] = 'publish workflow must be callable';
    }

    foreach ([
        'release_kind',
        'dockerfile',
        'artifact_name',
        'candidate_repository',
        'target_repository',
        'semantic_version',
        'staging_alias',
        'publish_latest',
        'validate_only',
    ] as $input) {
        if (! isset($workflowCall['inputs'][$input])) {
            $violations[] = "missing reusable input: {$input}";
        }
    }

    if (($sharedWorkflow['permissions'] ?? null) !== []) {
        $violations[] = 'shared publication must not grant workflow-wide permissions';
    }
    if (($sharedWorkflow['concurrency']['group'] ?? null) !== 'linux-image-${{ inputs.target_repository }}' ||
        ($sharedWorkflow['concurrency']['cancel-in-progress'] ?? null) !== false) {
        $violations[] = 'shared publication must serialize each target without cancellation';
    }

    foreach (['release', 'repair-latest'] as $jobName) {
        if (($jobs[$jobName]['concurrency']['group'] ?? null) !== 'linux-image-alias-mutation-${{ inputs.target_repository }}' ||
            ($jobs[$jobName]['concurrency']['cancel-in-progress'] ?? null) !== false) {
            $violations[] = 'alias mutation jobs must serialize per target at the job level because workflow_call ignores workflow-level concurrency';
        }
    }

    $targetStep = releaseWorkflowStepById($jobs['validate-inputs'] ?? [], 'target');
    $targetScript = (string) ($targetStep['run'] ?? '');
    if (! str_contains($targetScript, 'run_tag="sha-$GITHUB_SHA-run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"')) {
        $violations[] = 'immutable run tags must begin with the source commit and include the run provenance';
    }

    foreach ([
        'Stage candidates by verified digest' => 'stage-candidates',
        'Repair latest aliases atomically' => 'repair-latest',
        'Create or verify immutable run tags' => 'release',
        'Publish semantic and latest aliases atomically' => 'release',
        'Delete candidate and quarantine tags only' => 'cleanup',
    ] as $stepName => $jobName) {
        if (releaseWorkflowStep($jobs[$jobName] ?? [], $stepName) === []) {
            $violations[] = "missing lifecycle step: {$stepName}";
        }
    }

    $aliasScript = (string) (releaseWorkflowStep(
        $jobs['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    )['run'] ?? '');
    if (! str_contains($aliasScript, 'ensure-pair')) {
        $violations[] = 'semantic tags must use a preflighted cross-registry publisher';
    }

    $repairJob = $jobs['repair-latest'] ?? [];
    $repairScript = (string) (releaseWorkflowStep(
        $repairJob,
        'Repair latest aliases atomically',
    )['run'] ?? '');
    $forwardRepairInvocation = 'scripts/publish-linux-image.sh repair-alias-forward "$GHCR_TARGET" "$DOCKER_TARGET" latest "$INDEX_DIGEST"';
    if (substr_count($repairScript, $forwardRepairInvocation) !== 1 ||
        str_contains($repairScript, 'jq ') ||
        str_contains($repairScript, 'regctl image') ||
        str_contains($repairScript, 'verify-index') ||
        str_contains($repairScript, 'promote-latest')) {
        $violations[] = 'automatic latest repair must delegate provenance validation and forward mutation to the helper';
    }
    if (! str_contains((string) ($repairJob['if'] ?? ''), "inputs.release_kind == 'production'")) {
        $violations[] = 'automatic latest repair must run only for a validated production repair';
    }

    $attestationScript = (string) (releaseWorkflowStep(
        $jobs['attest-and-verify'] ?? [],
        'Verify both registry attestations',
    )['run'] ?? '');
    if (! str_contains(
        $attestationScript,
        '--signer-workflow "${GITHUB_REPOSITORY}/.github/workflows/publish-linux-image.yml"',
    )) {
        $violations[] = 'attestation verification must require the reusable workflow signer';
    }

    foreach ($jobs as $job) {
        foreach (releaseWorkflowSteps($job) as $step) {
            $uses = (string) ($step['uses'] ?? '');
            if ($uses !== '' &&
                ! str_starts_with($uses, './') &&
                preg_match('/@[0-9a-f]{40}\z/', $uses) !== 1) {
                $violations[] = 'every external action must use a full commit SHA';
            }
        }
    }

    $validationEvents = $applicationValidationWorkflow['on'] ?? null;
    if (! is_array($validationEvents) || ! array_key_exists('workflow_call', $validationEvents)) {
        $violations[] = 'application validation workflow must be callable';
    }
    if (($applicationValidationWorkflow['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'application validation must use read-only repository permissions';
    }
    if (($applicationValidationWorkflow['concurrency']['group'] ?? null) !== 'application-validation-${{ inputs.source_sha || github.event.pull_request.number || github.ref }}' ||
        ($applicationValidationWorkflow['concurrency']['cancel-in-progress'] ?? null) !== '${{ github.event_name == \'pull_request\' }}') {
        $violations[] = 'application validation must serialize pushes without cancellation while superseding stale pull requests';
    }

    $genericJobs = ['php', 'blue-green-lifecycle', 'browser', 'formatting', 'node', 'workflow-and-shell'];
    foreach ($genericJobs as $jobName) {
        if (! isset($applicationJobs[$jobName])) {
            $violations[] = "missing generic application validation job: {$jobName}";
        }
    }
    $phpTestScript = (string) (releaseWorkflowStep(
        $applicationJobs['php'] ?? [],
        'Run release and version-consumer tests',
    )['run'] ?? '');
    foreach ([
        'tests/Unit/CheckHelperImageJobTest.php',
        'tests/Unit/SentinelVersionTest.php',
        'tests/Unit/V4xCandidateWorkflowTest.php',
        'tests/Feature/UpgradeComponentTest.php',
    ] as $requiredPhpTest) {
        if (! str_contains($phpTestScript, $requiredPhpTest)) {
            $violations[] = "required PHP validation must execute regression test: {$requiredPhpTest}";
        }
    }
    $browserJob = $applicationJobs['browser'] ?? [];
    $browserRedis = $browserJob['services']['redis'] ?? [];
    if (($browserRedis['image'] ?? null) !== 'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99' ||
        ($browserJob['env']['REDIS_HOST'] ?? null) !== '127.0.0.1' ||
        ($browserJob['env']['REDIS_PORT'] ?? null) !== 6379) {
        $violations[] = 'browser validation must provide its Redis runtime dependency';
    }
    if (($browserJob['env']['DB_DATABASE'] ?? null) !== 'database/browser-testing.sqlite' ||
        ! collect(releaseWorkflowSteps($browserJob))->contains(
            fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'touch database/browser-testing.sqlite'),
        )) {
        $violations[] = 'browser validation must use a file-backed SQLite database';
    }
    $blueGreenLifecycleJob = $applicationJobs['blue-green-lifecycle'] ?? [];
    if (($blueGreenLifecycleJob['env']['COOLIFY_EXTERNAL_TEST_SERVICES'] ?? null) !== true ||
        ($blueGreenLifecycleJob['env']['DB_HOST'] ?? null) !== '127.0.0.1' ||
        ! str_ends_with((string) ($blueGreenLifecycleJob['env']['DB_DATABASE'] ?? ''), '_testing')) {
        $violations[] = 'PostgreSQL lifecycle validation must explicitly confirm isolated loopback test services';
    }
    $requiredJobs = [...$genericJobs, 'fork-deploy', 'testing-host-runtime'];
    $requiredNeeds = releaseWorkflowNeeds($applicationJobs['required'] ?? []);
    sort($requiredJobs);
    sort($requiredNeeds);
    if (($applicationJobs['required']['name'] ?? null) !== 'Application validation required') {
        $violations[] = 'application validation must preserve the protected branch status context';
    }
    if ($requiredNeeds !== $requiredJobs || ($applicationJobs['required']['if'] ?? null) !== 'always()') {
        $violations[] = 'release foundation validation must require every owned job';
    }
    $requiredStep = releaseWorkflowStep(
        $applicationJobs['required'] ?? [],
        'Require every generic validation job',
    );
    if (($requiredStep['env']['TESTING_HOST_RUNTIME_RESULT'] ?? null) !== '${{ needs.testing-host-runtime.result }}' ||
        ($requiredStep['env']['EVENT_NAME'] ?? null) !== '${{ github.event_name }}' ||
        ($requiredStep['env']['VALIDATION_SOURCE_SHA'] ?? null) !== '${{ inputs.source_sha }}' ||
        ! str_contains((string) ($requiredStep['run'] ?? ''), '[[ "$EVENT_NAME" == pull_request || -n "$VALIDATION_SOURCE_SHA" ]]') ||
        ! str_contains((string) ($requiredStep['run'] ?? ''), '[[ "$TESTING_HOST_RUNTIME_RESULT" == success ]]') ||
        ! str_contains((string) ($requiredStep['run'] ?? ''), '[[ "$TESTING_HOST_RUNTIME_RESULT" == skipped ]]')) {
        $violations[] = 'required status must fail when testing-host runtime validation does not succeed';
    }

    $forkDeployJob = $applicationJobs['fork-deploy'] ?? [];
    if (($forkDeployJob['timeout-minutes'] ?? null) !== 30 ||
        ! collect(releaseWorkflowSteps($forkDeployJob))->contains(
            fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'tests/Integration/ForkDeploy/run.sh'),
        )) {
        $violations[] = 'fork deployment validation must be bounded and execute its canonical integration owner';
    }

    $testingHostRuntimeJob = $applicationJobs['testing-host-runtime'] ?? [];
    $testingHostBuildStep = releaseWorkflowStep(
        $testingHostRuntimeJob,
        'Build exact testing-host source image without publication',
    );
    $testingHostBuildScript = (string) ($testingHostBuildStep['run'] ?? '');
    $productionBuildStep = releaseWorkflowStep(
        $testingHostRuntimeJob,
        'Build exact production source image without publication',
    );
    $productionBuildScript = (string) ($productionBuildStep['run'] ?? '');
    $testingHostRuntimeStep = releaseWorkflowStep(
        $testingHostRuntimeJob,
        'Run exact testing-host runtime contract',
    );
    $testingHostRuntimeScript = (string) ($testingHostRuntimeStep['run'] ?? '');
    $bundledRuntimeStep = releaseWorkflowStep(
        $testingHostRuntimeJob,
        'Run exact bundled Reverb and terminal runtime contract',
    );
    $testingHostImage = 'coolify-testing-host:application-validation-${{ inputs.source_sha || github.sha }}';
    $productionImage = 'coolify:application-validation-${{ inputs.source_sha || github.sha }}';
    if (($testingHostRuntimeJob['timeout-minutes'] ?? null) !== 75 ||
        ($testingHostRuntimeJob['if'] ?? null) !== '${{ github.event_name == \'pull_request\' || inputs.source_sha != \'\' }}' ||
        ! str_contains($testingHostBuildScript, 'docker buildx build --load --pull') ||
        ! str_contains($testingHostBuildScript, '--file docker/testing-host/Dockerfile') ||
        ! str_contains($testingHostBuildScript, '--tag "$TESTING_HOST_IMAGE"') ||
        ! str_contains($productionBuildScript, 'docker buildx build --load --pull') ||
        ! str_contains($productionBuildScript, '--file docker/production/Dockerfile') ||
        ! str_contains($productionBuildScript, '--tag "$PRODUCTION_IMAGE"') ||
        ($testingHostBuildStep['env']['TESTING_HOST_IMAGE'] ?? null) !== $testingHostImage ||
        ($productionBuildStep['env']['PRODUCTION_IMAGE'] ?? null) !== $productionImage ||
        ($testingHostRuntimeStep['env']['TESTING_HOST_IMAGE'] ?? null) !== $testingHostImage ||
        ($testingHostRuntimeStep['env']['PRODUCTION_IMAGE'] ?? null) !== $productionImage ||
        $testingHostRuntimeScript !== 'tests/Integration/TestingHostImageTest.sh' ||
        ($bundledRuntimeStep['env']['PRODUCTION_IMAGE'] ?? null) !== $productionImage ||
        ($bundledRuntimeStep['run'] ?? null) !== 'tests/Integration/RealtimeImageTest.sh') {
        $violations[] = 'production runtime validation must bridge the testing host and execute bundled Reverb and terminal acceptance on pull requests';
    }
    $testingHostJobDefinition = json_encode($testingHostRuntimeJob, JSON_THROW_ON_ERROR);
    foreach (['secrets.', 'docker login', 'docker push', '--push', 'publish-linux-image'] as $publicationContract) {
        if (str_contains($testingHostJobDefinition, $publicationContract)) {
            $violations[] = 'testing-host pull-request validation must not require credentials or publish images';

            break;
        }
    }

    $workflowAndShell = $applicationJobs['workflow-and-shell'] ?? [];
    foreach (['Validate workflows', 'Verify pinned source provenance', 'Require immutable external action and container references', 'ShellCheck changed shell scripts'] as $stepName) {
        if (releaseWorkflowStep($workflowAndShell, $stepName) === []) {
            $violations[] = "workflow validation is missing required step: {$stepName}";
        }
    }
    $ownedWorkflows = (string) ($workflowAndShell['env']['OWNED_WORKFLOWS'] ?? '');
    foreach ([
        '.github/workflows/application-validation.yml',
        '.github/workflows/coolify-production-build.yml',
        '.github/workflows/coolify-staging-build.yml',
        '.github/workflows/coolify-testing-host.yml',
        '.github/workflows/gate-v4x-candidate.yml',
        '.github/workflows/publish-fork.yml',
        '.github/workflows/publish-linux-image.yml',
        '.github/workflows/release-operational-acceptance.yml',
    ] as $workflowPath) {
        if (! str_contains($ownedWorkflows, $workflowPath)) {
            $violations[] = "workflow validation must lint owned workflow: {$workflowPath}";
        }
    }
    $provenanceScript = (string) (releaseWorkflowStep(
        $workflowAndShell,
        'Verify pinned source provenance',
    )['run'] ?? '');
    foreach (['docker/production/Dockerfile', 'docker/testing-host/Dockerfile'] as $dockerfile) {
        if (! str_contains($provenanceScript, "docker/verify-source-provenance.sh {$dockerfile}")) {
            $violations[] = "workflow validation must verify pinned source provenance for {$dockerfile}";
        }
    }
    $shellcheckScript = (string) (releaseWorkflowStep($workflowAndShell, 'ShellCheck changed shell scripts')['run'] ?? '');
    foreach (['strict_scripts', 'legacy_scripts', '--diff-filter=A', '--severity=error'] as $requiredShellcheckContract) {
        if (! str_contains($shellcheckScript, $requiredShellcheckContract)) {
            $violations[] = 'workflow validation must apply strict checks to new scripts and error checks to legacy scripts';

            break;
        }
    }

    $expectedCallers = [
        'production' => [
            'target' => 'coollabsio/coolify',
            'publish_latest' => true,
            'needs' => ['application-validation'],
        ],
        'staging' => [
            'target' => 'coollabsio/coolify-staging',
            'publish_latest' => false,
            'needs' => ['application-validation', 'resolve-staging-alias'],
        ],
        'testing-host' => [
            'target' => 'coollabsio/coolify-testing-host',
            'publish_latest' => true,
            'needs' => ['application-validation'],
        ],
    ];
    foreach ($expectedCallers as $name => $expected) {
        $caller = $callers[$name] ?? [];
        $publish = $caller['jobs']['publish'] ?? [];
        if (($publish['with']['target_repository'] ?? null) !== $expected['target']) {
            $violations[] = "{$name} has an invalid target_repository";
        }
        if (($publish['with']['publish_latest'] ?? null) !== $expected['publish_latest']) {
            $violations[] = "{$name} has an invalid publish_latest";
        }
        if (($publish['concurrency']['group'] ?? null) !== "linux-image-release-{$expected['target']}" ||
            ($publish['concurrency']['cancel-in-progress'] ?? null) !== false) {
            $violations[] = "{$name} must serialize the complete publication invocation by target";
        }
        $actualNeeds = releaseWorkflowNeeds($publish);
        sort($actualNeeds);
        $expectedNeeds = $expected['needs'];
        sort($expectedNeeds);
        if ($actualNeeds !== $expectedNeeds && $name !== 'production') {
            $violations[] = "{$name} publication has invalid validation dependencies";
        }
    }

    $testingHostAuthorization = $callers['testing-host']['jobs']['authorize'] ?? [];
    if (($testingHostAuthorization['if'] ?? null) !== "\${{ github.repository == 'coollabsio/coolify' && github.ref == 'refs/heads/next' }}") {
        $violations[] = 'testing-host authorization must skip noncanonical repository and ref';
    }

    $productionNeeds = releaseWorkflowNeeds($callers['production']['jobs']['resolve-version'] ?? []);
    if (! in_array('validation-required', $productionNeeds, true)) {
        $violations[] = 'production publication must wait for a trusted preflight or hosted validation';
    }

    $archiveGate = releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Verify the exact production or staging OCI archive and runtime',
    );
    if (($archiveGate['if'] ?? null) !== "inputs.release_kind == 'production' || inputs.release_kind == 'staging'" ||
        ! str_contains((string) ($archiveGate['run'] ?? ''), 'tests/Integration/VerifyOciArchiveImage.sh')) {
        $violations[] = 'production and staging must verify the exact OCI archive on each native architecture';
    }

    $forkContentGate = releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Verify exact fork control-plane OCI runtime and content',
    );
    $forkContentGateRun = (string) ($forkContentGate['run'] ?? '');
    if (isset($forkContentGate['if']) ||
        ($forkContentGate['continue-on-error'] ?? false) !== false ||
        ($forkContentGate['env']['CONTENT_POLICY'] ?? null) !== "\${{ matrix.product == 'main' && 'control-plane-main' || 'none' }}" ||
        ! str_contains($forkContentGateRun, 'OCI_CONTENT_POLICY="$CONTENT_POLICY"') ||
        ! str_contains($forkContentGateRun, 'tests/Integration/VerifyOciArchiveImage.sh')) {
        $violations[] = 'both native fork control-plane OCI archives must pass the fail-closed runtime content census';
    }

    $forkBundledRuntimeGate = releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Verify exact fork bundled Reverb and terminal runtime',
    );
    if (isset($forkBundledRuntimeGate['if']) ||
        ($forkBundledRuntimeGate['continue-on-error'] ?? false) !== false ||
        ($forkBundledRuntimeGate['env']['PRODUCTION_IMAGE'] ?? null) !== 'local/${{ matrix.artifact_name }}:${{ github.sha }}-${{ matrix.arch }}-fork-runtime' ||
        ($forkBundledRuntimeGate['run'] ?? null) !== 'tests/Integration/RealtimeImageTest.sh') {
        $violations[] = 'both native fork production images must execute bundled Reverb and terminal acceptance before publication';
    }

    $forkMainPlatforms = collect($jobs['fork-build']['strategy']['matrix']['include'] ?? [])
        ->filter(fn (array $entry): bool => ($entry['product'] ?? null) === 'main')
        ->map(fn (array $entry): array => [
            $entry['arch'] ?? null,
            $entry['platform'] ?? null,
        ])
        ->values()
        ->all();
    $trustedForkRunner = [
        'group' => 'coolify-trusted',
        'labels' => ['self-hosted', 'Linux', 'X64', 'williamacallahan'],
    ];
    $forkQemuSetup = releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Set up QEMU for fork cross-platform builds',
    );
    $forkBuildxVerification = (string) (releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Verify fork Buildx platform support',
    )['run'] ?? '');
    if ($forkMainPlatforms !== [
        ['amd64', 'linux/amd64'],
        ['arm64', 'linux/arm64'],
    ] ||
        ($jobs['fork-build']['runs-on'] ?? null) !== $trustedForkRunner ||
        ($jobs['fork-stage']['runs-on'] ?? null) !== $trustedForkRunner ||
        ($forkQemuSetup['uses'] ?? null) !== 'docker/setup-qemu-action@c7c53464625b32c7a7e944ae62b3e17d2b600130' ||
        ($forkQemuSetup['with']['platforms'] ?? null) !== 'arm64' ||
        ! str_contains($forkBuildxVerification, 'docker buildx inspect --bootstrap | grep -F "$PLATFORM"')) {
        $violations[] = 'fork control-plane publication must retain self-hosted amd64 and arm64 archive acceptance';
    }

    $forkSbomCensus = releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Enforce fork control-plane SBOM content census',
    );
    $forkSbomCensusRun = (string) ($forkSbomCensus['run'] ?? '');
    if (($forkSbomCensus['if'] ?? null) !== "\${{ matrix.product == 'main' && steps.fork-sbom.outcome == 'success' }}" ||
        ($forkSbomCensus['continue-on-error'] ?? false) !== false ||
        ! str_contains($forkSbomCensusRun, 'contains("haproxy")') ||
        ! str_contains($forkSbomCensusRun, '.sbom.spdx.json')) {
        $violations[] = 'both native fork control-plane SBOMs must reject HAProxy package residue';
    }

    return array_values(array_unique($violations));
}
function mutateReleaseWorkflow(array $sharedWorkflow, array $callers, string $mutation): array
{
    return match ($mutation) {
        'remove-production-validation' => (function () use ($sharedWorkflow, $callers): array {
            $callers['production']['jobs']['resolve-version']['needs'] = [];

            return [$sharedWorkflow, $callers];
        })(),
        'testing-host-targets-production' => (function () use ($sharedWorkflow, $callers): array {
            $callers['testing-host']['jobs']['publish']['with']['target_repository'] = 'coollabsio/coolify';

            return [$sharedWorkflow, $callers];
        })(),
        'staging-publishes-latest' => (function () use ($sharedWorkflow, $callers): array {
            $callers['staging']['jobs']['publish']['with']['publish_latest'] = true;

            return [$sharedWorkflow, $callers];
        })(),
        'staging-targets-production' => (function () use ($sharedWorkflow, $callers): array {
            $callers['staging']['jobs']['publish']['with']['target_repository'] = 'coollabsio/coolify';

            return [$sharedWorkflow, $callers];
        })(),
        'cancel-target-publication' => (function () use ($sharedWorkflow, $callers): array {
            $sharedWorkflow['concurrency']['cancel-in-progress'] = true;

            return [$sharedWorkflow, $callers];
        })(),
        'drop-caller-target-concurrency' => (function () use ($sharedWorkflow, $callers): array {
            unset($callers['production']['jobs']['publish']['concurrency']);

            return [$sharedWorkflow, $callers];
        })(),
        'drop-alias-mutation-serialization' => (function () use ($sharedWorkflow, $callers): array {
            unset($sharedWorkflow['jobs']['release']['concurrency']);

            return [$sharedWorkflow, $callers];
        })(),
        'drop-repair-alias-mutation-serialization' => (function () use ($sharedWorkflow, $callers): array {
            unset($sharedWorkflow['jobs']['repair-latest']['concurrency']);

            return [$sharedWorkflow, $callers];
        })(),
        'repair-latest-bypasses-forward-helper' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['repair-latest']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Repair latest aliases atomically') {
                    $sharedWorkflow['jobs']['repair-latest']['steps'][$index]['run'] = str_replace(
                        'repair-alias-forward',
                        'promote-latest',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'repair-latest-allows-non-production' => (function () use ($sharedWorkflow, $callers): array {
            $sharedWorkflow['jobs']['repair-latest']['if'] = str_replace(
                "inputs.release_kind == 'production' && ",
                '',
                (string) ($sharedWorkflow['jobs']['repair-latest']['if'] ?? ''),
            );

            return [$sharedWorkflow, $callers];
        })(),
        'remove-immutable-run-tags' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['release']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Create or verify immutable run tags') {
                    unset($sharedWorkflow['jobs']['release']['steps'][$index]);
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'unprovenanced-run-tags' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['validate-inputs']['steps'] as $index => $step) {
                if (($step['id'] ?? null) === 'target') {
                    $sharedWorkflow['jobs']['validate-inputs']['steps'][$index]['run'] = str_replace(
                        'run_tag="sha-$GITHUB_SHA-run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"',
                        'run_tag="run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'caller-workflow-signer' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['attest-and-verify']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Verify both registry attestations') {
                    $sharedWorkflow['jobs']['attest-and-verify']['steps'][$index]['run'] = str_replace(
                        '--signer-workflow "${GITHUB_REPOSITORY}/.github/workflows/publish-linux-image.yml"',
                        '--signer-workflow "$GITHUB_WORKFLOW_REF"',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'semantic-tags-sequential' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['release']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Publish semantic and latest aliases atomically') {
                    $sharedWorkflow['jobs']['release']['steps'][$index]['run'] = str_replace(
                        'ensure-pair',
                        'ensure-tag',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'make-checkout-mutable' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['build-and-scan']['steps'] as &$step) {
                if (($step['uses'] ?? null) === 'actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd') {
                    $step['uses'] = 'actions/checkout@v5';
                    break;
                }
            }
            unset($step);

            return [$sharedWorkflow, $callers];
        })(),
        'remove-fork-control-plane-census' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['fork-build']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Verify exact fork control-plane OCI runtime and content') {
                    unset($sharedWorkflow['jobs']['fork-build']['steps'][$index]);
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'misroute-fork-control-plane-census' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['fork-build']['steps'] as &$step) {
                if (($step['name'] ?? null) === 'Verify exact fork control-plane OCI runtime and content') {
                    $step['if'] = "\${{ matrix.product == 'non-main' }}";
                    break;
                }
            }
            unset($step);

            return [$sharedWorkflow, $callers];
        })(),
        'allow-fork-control-plane-census-failure' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['fork-build']['steps'] as &$step) {
                if (($step['name'] ?? null) === 'Verify exact fork control-plane OCI runtime and content') {
                    $step['continue-on-error'] = true;
                    break;
                }
            }
            unset($step);

            return [$sharedWorkflow, $callers];
        })(),
        'remove-fork-main-arm64-acceptance' => (function () use ($sharedWorkflow, $callers): array {
            $sharedWorkflow['jobs']['fork-build']['strategy']['matrix']['include'] = array_values(array_filter(
                $sharedWorkflow['jobs']['fork-build']['strategy']['matrix']['include'],
                fn (array $entry): bool => ! (
                    ($entry['product'] ?? null) === 'main'
                    && ($entry['arch'] ?? null) === 'arm64'
                ),
            ));

            return [$sharedWorkflow, $callers];
        })(),
        'remove-fork-control-plane-sbom-census' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['fork-build']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Enforce fork control-plane SBOM content census') {
                    unset($sharedWorkflow['jobs']['fork-build']['steps'][$index]);
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'remove-fork-bundled-runtime' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['fork-build']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Verify exact fork bundled Reverb and terminal runtime') {
                    unset($sharedWorkflow['jobs']['fork-build']['steps'][$index]);
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        default => throw new InvalidArgumentException("Unknown release workflow mutation: {$mutation}"),
    };
}

it('enforces the shared Linux publication graph and caller boundaries', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))->toBe([]);
});

it('keeps the hardened publisher as the sole realtime release owner', function () {
    $root = releaseWorkflowRepositoryRoot();

    expect(file_exists($root.'/.github/workflows/coolify-realtime.yml'))->toBeFalse()
        ->and(file_exists($root.'/.github/workflows/coolify-realtime-next.yml'))->toBeFalse();
});

it('uses the trusted fork promotion runner while keeping pull-request validation GitHub-hosted', function (): void {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $pullRequestQualityWorkflow = Yaml::parseFile($root.'/.github/workflows/pr-quality.yaml');

    expect($sharedWorkflow['jobs']['fork-release']['runs-on'] ?? null)->toBe([
        'group' => 'coolify-trusted',
        'labels' => ['self-hosted', 'Linux', 'X64', 'williamacallahan'],
    ]);

    foreach ($applicationValidationWorkflow['jobs'] ?? [] as $jobName => $job) {
        expect($job)->toBeArray()
            ->and($job['runs-on'] ?? null)->toBe(
                'ubuntu-24.04',
                "pull-request validation job {$jobName} must stay GitHub-hosted",
            );
    }

    expect($pullRequestQualityWorkflow['jobs']['pr-quality']['runs-on'] ?? null)
        ->toBe('ubuntu-latest', 'pull-request quality job must stay GitHub-hosted');
});

it('rejects fork publication graphs that bypass the exact control-plane image census', function (string $mutation) {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    [$mutatedWorkflow, $mutatedCallers] = mutateReleaseWorkflow($sharedWorkflow, $callers, $mutation);

    expect(releaseFoundationWorkflowViolations(
        $mutatedWorkflow,
        $applicationValidationWorkflow,
        $mutatedCallers,
    ))->toContain('both native fork control-plane OCI archives must pass the fail-closed runtime content census');
})->with([
    'missing census' => 'remove-fork-control-plane-census',
    'census applied to the wrong image' => 'misroute-fork-control-plane-census',
    'census allowed to fail' => 'allow-fork-control-plane-census-failure',
]);

it('rejects fork publication graphs that drop native architecture or SBOM residue acceptance', function (string $mutation, string $expectedViolation) {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    [$mutatedWorkflow, $mutatedCallers] = mutateReleaseWorkflow($sharedWorkflow, $callers, $mutation);

    expect(releaseFoundationWorkflowViolations(
        $mutatedWorkflow,
        $applicationValidationWorkflow,
        $mutatedCallers,
    ))->toContain($expectedViolation);
})->with([
    'missing native arm64 acceptance' => [
        'remove-fork-main-arm64-acceptance',
        'fork control-plane publication must retain self-hosted amd64 and arm64 archive acceptance',
    ],
    'missing signed SBOM census' => [
        'remove-fork-control-plane-sbom-census',
        'both native fork control-plane SBOMs must reject HAProxy package residue',
    ],
    'missing native bundled runtime' => [
        'remove-fork-bundled-runtime',
        'both native fork production images must execute bundled Reverb and terminal acceptance before publication',
    ],
]);

it('fails closed across canonical publication and fork validation modes', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $semanticVersionPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        ['coollabsio/coolify', 'false', 'fixture-user', 'fixture-token', true],
        ['coollabsio/coolify', 'false', '', '', false],
        ['coollabsio/coolify', 'true', 'fixture-user', 'fixture-token', false],
        ['example/coolify-fork', 'true', '', '', true],
        ['example/coolify-fork', 'false', '', '', false],
    ];

    foreach ($cases as [$repository, $validateOnly, $dockerhubUsername, $dockerhubToken, $shouldSucceed]) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-release-target-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify',
                'CANDIDATE_REPOSITORY' => 'coollabsio/coolify-production-staging',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'DOCKERHUB_TOKEN' => $dockerhubToken,
                'DOCKERHUB_USERNAME' => $dockerhubUsername,
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'PUBLISH_LATEST' => 'true',
                'RELEASE_KIND' => 'production',
                'REPOSITORY' => $repository,
                'SEMANTIC_VERSION' => '4.0.0',
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
                'TARGET_REPOSITORY' => 'coollabsio/coolify',
                'VALIDATE_ONLY' => $validateOnly,
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe(
                $shouldSucceed,
                "repository={$repository} validate_only={$validateOnly}",
            );
        } finally {
            unlink($githubOutput);
        }
    }
});

it('distinguishes absent semantic tags from registry disagreement and transport failures', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $publicationStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'publication');
    $script = (string) ($publicationStep['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-registry-state-'.bin2hex(random_bytes(8));
    $mockBin = $fixture.'/bin';
    $filesystem->mkdir([$mockBin, $fixture.'/runner']);
    $curl = <<<'SH'
#!/bin/sh
set -eu
output=
headers=
url=
arguments=" $* "
for contract in '--connect-timeout 10' '--max-time 30' '--retry 3' '--retry-all-errors' '--retry-delay 1'; do
    case "$arguments" in
        *" $contract "*) ;;
        *) printf 'bounded retry contract missing: %s\n' "$contract" >&2; exit 64 ;;
    esac
done
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift 2 ;;
        --dump-header) headers=$2; shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
case "$url" in
    *'/token?'*) printf '{"token":"fixture-token"}\n' ;;
    *ghcr.io/v2/*/manifests/latest)
        [ "${GHCR_LATEST_STATUS:?}" != network ] || exit 7
        if [ "$GHCR_LATEST_STATUS" = 404 ]; then printf '%s\n' "${GHCR_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${GHCR_LATEST_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$GHCR_LATEST_STATUS"
        ;;
    *registry-1.docker.io/v2/*/manifests/latest)
        [ "${DOCKER_LATEST_STATUS:?}" != network ] || exit 7
        if [ "$DOCKER_LATEST_STATUS" = 404 ]; then printf '%s\n' "${DOCKER_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${DOCKER_LATEST_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$DOCKER_LATEST_STATUS"
        ;;
    *ghcr.io/v2/*)
        [ "${GHCR_STATUS:?}" != network ] || exit 7
        [ "$GHCR_STATUS" != timeout ] || exit 28
        if [ "$GHCR_STATUS" = 404 ]; then printf '%s\n' "${GHCR_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${GHCR_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$GHCR_STATUS"
        ;;
    *registry-1.docker.io/v2/*)
        [ "${DOCKER_STATUS:?}" != network ] || exit 7
        [ "$DOCKER_STATUS" != timeout ] || exit 28
        if [ "$DOCKER_STATUS" = 404 ]; then printf '%s\n' "${DOCKER_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${DOCKER_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$DOCKER_STATUS"
        ;;
    *) exit 64 ;;
esac
SH;
    file_put_contents($mockBin.'/curl', $curl);
    chmod($mockBin.'/curl', 0755);

    try {
        foreach ([
            'both semantic tags absent require publication' => ['404', '404', 'a', 'a', '404', '404', 'a', 'a', true, 'true', 'false'],
            'semantic and latest all match skip publication' => ['200', '200', 'a', 'a', '200', '200', 'a', 'a', true, 'false', 'false'],
            'missing latest alias requires repair' => ['200', '200', 'a', 'a', '404', '200', 'a', 'a', true, 'false', 'true'],
            'stale latest aliases require repair' => ['200', '200', 'a', 'a', '200', '200', 'b', 'b', true, 'false', 'true'],
            'cross-registry semantic presence disagreement fails' => ['200', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'cross-registry semantic digest disagreement fails' => ['200', '200', 'a', 'b', '200', '200', 'a', 'a', false, null, null],
            'registry server failure fails' => ['500', '500', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'registry transport failure fails' => ['network', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'registry timeout fails' => ['timeout', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
        ] as $description => [$ghcrStatus, $dockerStatus, $ghcrDigest, $dockerDigest, $ghcrLatestStatus, $dockerLatestStatus, $ghcrLatestDigest, $dockerLatestDigest, $successful, $publishRequired, $repairRequired]) {
            $output = tempnam($fixture.'/runner', 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_STATUS' => $dockerStatus,
                'DEFAULT_404_BODY' => '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}',
                'DOCKER_DIGEST' => 'sha256:'.str_repeat($dockerDigest, 64),
                'DOCKER_LATEST_DIGEST' => 'sha256:'.str_repeat($dockerLatestDigest, 64),
                'DOCKER_LATEST_STATUS' => $dockerLatestStatus,
                'GITHUB_OUTPUT' => $output,
                'GHCR_STATUS' => $ghcrStatus,
                'GHCR_DIGEST' => 'sha256:'.str_repeat($ghcrDigest, 64),
                'GHCR_LATEST_DIGEST' => 'sha256:'.str_repeat($ghcrLatestDigest, 64),
                'GHCR_LATEST_STATUS' => $ghcrLatestStatus,
                'PATH' => $mockBin.':'.getenv('PATH'),
                'RELEASE_KIND' => 'production',
                'RUNNER_TEMP' => $fixture.'/runner',
                'SEMANTIC_VERSION' => '4.1.4',
                'TARGET_REPOSITORY' => 'coollabsio/coolify',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description."\n".$process->getErrorOutput());
            if ($publishRequired !== null) {
                $values = [];
                foreach (file($output, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                    $values[$key] = $value;
                }
                expect($values['publish_required'] ?? null)->toBe($publishRequired)
                    ->and($values['repair_required'] ?? null)->toBe($repairRequired);
                if ($publishRequired === 'false' && $repairRequired === 'false') {
                    $digest = 'sha256:'.str_repeat('a', 64);
                    expect($values['index_digest'] ?? null)->toBe($digest)
                        ->and($values['ghcr_image'] ?? null)->toBe("ghcr.io/coollabsio/coolify@{$digest}")
                        ->and($values['docker_image'] ?? null)->toBe("docker.io/coollabsio/coolify@{$digest}");
                } else {
                    expect($values['ghcr_image'] ?? null)->toBe('')
                        ->and($values['docker_image'] ?? null)->toBe('');
                }
            }
        }

        foreach ([
            'unauthorized 404 body' => '{"errors":[{"code":"UNAUTHORIZED","message":"denied"}]}',
            'arbitrary 404 body' => '{"message":"not found"}',
        ] as $description => $body) {
            $output = tempnam($fixture.'/runner', 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_STATUS' => '404',
                'DEFAULT_404_BODY' => '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}',
                'DOCKER_404_BODY' => $body,
                'DOCKER_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'DOCKER_LATEST_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'DOCKER_LATEST_STATUS' => '200',
                'GITHUB_OUTPUT' => $output,
                'GHCR_STATUS' => '404',
                'GHCR_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'GHCR_LATEST_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'GHCR_LATEST_STATUS' => '200',
                'PATH' => $mockBin.':'.getenv('PATH'),
                'RELEASE_KIND' => 'production',
                'RUNNER_TEMP' => $fixture.'/runner',
                'SEMANTIC_VERSION' => '4.1.4',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();
            expect($process->isSuccessful())->toBeFalse($description);
        }
    } finally {
        $filesystem->remove($fixture);
    }
});

it('verifies no-op aliases are multi-platform indexes with release provenance', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $step = releaseWorkflowStep($workflow['jobs']['verify-authoritative-noop'] ?? [], 'Verify index platforms and release provenance');
    $script = (string) ($step['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-noop-index-'.bin2hex(random_bytes(8));
    $mockBin = $fixture.'/bin';
    $filesystem->mkdir($mockBin);
    file_put_contents($mockBin.'/regctl', <<<'SH'
#!/bin/sh
set -eu
case "$1:$2" in
    manifest:get) printf '%s\n' "${REGCTL_MEDIA_TYPE:?}" ;;
    image:digest)
        case "$*" in
            *linux/amd64*) printf 'sha256:%064d\n' 1 ;;
            *linux/arm64*)
                if [ "${REGCTL_ARM64_SUFFIX:-2}" = invalid ]; then printf 'invalid\n'; else printf 'sha256:%064d\n' "$REGCTL_ARM64_SUFFIX"; fi
                ;;
            *) printf '%s\n' "${INDEX_DIGEST:?}" ;;
        esac
        ;;
    image:config)
        case "$*" in
            *linux/arm64*) run_id="${REGCTL_ARM64_RUN_ID:-42}" ;;
            *) run_id=42 ;;
        esac
        printf '{"config":{"Labels":{"io.coolify.release-run-id":"%s","io.coolify.release-run-attempt":"1","org.opencontainers.image.revision":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","org.opencontainers.image.version":"4.1.4"}}}\n' "$run_id"
        ;;
    *) exit 64 ;;
esac
SH);
    chmod($mockBin.'/regctl', 0755);
    $digest = 'sha256:'.str_repeat('a', 64);

    try {
        foreach ([
            'valid OCI index' => ['application/vnd.oci.image.index.v1+json', '2', '42', true],
            'single image manifest' => ['application/vnd.oci.image.manifest.v1+json', '2', '42', false],
            'single architecture masquerading as an index' => ['application/vnd.oci.image.index.v1+json', '1', '42', false],
            'invalid arm64 digest' => ['application/vnd.oci.image.index.v1+json', 'invalid', '42', false],
            'mismatched arm64 provenance' => ['application/vnd.oci.image.index.v1+json', '2', '43', false],
        ] as $description => [$mediaType, $arm64Suffix, $arm64RunId, $successful]) {
            $output = tempnam($fixture, 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_TARGET' => 'docker.io/coollabsio/coolify',
                'GITHUB_OUTPUT' => $output,
                'GHCR_TARGET' => 'ghcr.io/coollabsio/coolify',
                'INDEX_DIGEST' => $digest,
                'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                'REGCTL_ARM64_SUFFIX' => $arm64Suffix,
                'REGCTL_ARM64_RUN_ID' => $arm64RunId,
                'REGCTL_MEDIA_TYPE' => $mediaType,
                'SEMANTIC_VERSION' => '4.1.4',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description);
            if ($successful) {
                expect((string) file_get_contents($output))->toContain("index_digest={$digest}")
                    ->toContain("ghcr_image=ghcr.io/coollabsio/coolify@{$digest}")
                    ->toContain("docker_image=docker.io/coollabsio/coolify@{$digest}");
            }
        }
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects OCI tags longer than 128 characters in input validation', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $semanticVersionPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        '128-character semantic tag is accepted' => ['1.0.0-'.str_repeat('a', 122), '1', true],
        '129-character semantic tag is rejected' => ['1.0.0-'.str_repeat('a', 123), '1', false],
        'oversized derived quarantine tag is rejected' => ['4.0.0', str_repeat('9', 70), false],
    ];

    foreach ($cases as $description => [$semanticVersion, $runId, $shouldSucceed]) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-release-tag-limit-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify',
                'CANDIDATE_REPOSITORY' => 'coollabsio/coolify-production-staging',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'DOCKERHUB_TOKEN' => 'fixture-token',
                'DOCKERHUB_USERNAME' => 'fixture-user',
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => $runId,
                'GITHUB_SHA' => str_repeat('a', 40),
                'PUBLISH_LATEST' => 'true',
                'RELEASE_KIND' => 'production',
                'REPOSITORY' => 'coollabsio/coolify',
                'SEMANTIC_VERSION' => $semanticVersion,
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
                'TARGET_REPOSITORY' => 'coollabsio/coolify',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($shouldSucceed, $description);
        } finally {
            unlink($githubOutput);
        }
    }
});

it('rejects fork versions whose derived canonical tag exceeds the OCI limit', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $forkSemanticVersionPattern = (string) ($targetStep['env']['FORK_SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        '115-character fork version is accepted' => ['1.0.1'.str_repeat('0', 105).'-fork', true],
        '116-character fork version is rejected' => ['1.0.1'.str_repeat('0', 106).'-fork', false],
    ];

    foreach ($cases as $description => [$semanticVersion, $shouldSucceed]) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-fork-tag-limit-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify-fork',
                'CANDIDATE_REPOSITORY' => 'williamagh/coolify-fork-candidates',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'FORK_RELEASE_SIGNING_ED25519_PRIVATE_KEY' => 'fixture-key',
                'FORK_SEMANTIC_VERSION_PATTERN' => $forkSemanticVersionPattern,
                'FORK_SOURCE_IS_TAG' => 'true',
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_EVENT_NAME' => 'push',
                'GITHUB_REF' => "refs/tags/{$semanticVersion}",
                'GITHUB_REF_PROTECTED' => 'false',
                'GITHUB_REF_TYPE' => 'tag',
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'NEXUS_PASSWORD' => 'fixture-password',
                'NEXUS_USERNAME' => 'fixture-user',
                'PUBLISH_LATEST' => 'false',
                'RELEASE_KIND' => 'fork',
                'REPOSITORY' => 'williamacallahan/coolify',
                'SEMANTIC_VERSION' => $semanticVersion,
                'TARGET_REPOSITORY' => 'williamagh/coolify',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($shouldSucceed, $description);
        } finally {
            unlink($githubOutput);
        }
    }
});

it('uses isolated database and cache stores in release validation', function () {
    $applicationValidationWorkflow = Yaml::parseFile(releaseWorkflowRepositoryRoot().'/.github/workflows/application-validation.yml');
    $environment = $applicationValidationWorkflow['jobs']['php']['env'] ?? [];

    expect($environment['DB_CONNECTION'] ?? null)->toBe('testing')
        ->and($environment['CACHE_STORE'] ?? null)->toBe('array');
});

it('rejects renaming the protected branch application validation status context', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $applicationValidationWorkflow['jobs']['required']['name'] = 'Renamed application validation';
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain('application validation must preserve the protected branch status context');
});

it('rejects a testing-host authorization job without a canonical source gate', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    unset($callers['testing-host']['jobs']['authorize']['if']);

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain('testing-host authorization must skip noncanonical repository and ref');
});

it('runs the testing-host image canary when a runtime Compose consumer changes', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml');
    $paths = $workflow['on']['push']['paths'] ?? [];

    expect($paths)->toContain(
        'docker-compose.dev.yml',
        'docker-compose-maxio.dev.yml',
        'docker-compose.windows.yml',
        'other/nightly/docker-compose.windows.yml',
    );
});

it('keeps helper Sentinel and upgrade regressions in the required PHP lane', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    $phpStepIndex = collect($applicationValidationWorkflow['jobs']['php']['steps'] ?? [])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run release and version-consumer tests');
    expect($phpStepIndex)->not->toBeFalse();

    foreach ([
        'tests/Unit/CheckHelperImageJobTest.php',
        'tests/Unit/SentinelVersionTest.php',
        'tests/Unit/V4xCandidateWorkflowTest.php',
        'tests/Feature/UpgradeComponentTest.php',
    ] as $requiredPhpTest) {
        $mutatedWorkflow = $applicationValidationWorkflow;
        $mutatedWorkflow['jobs']['php']['steps'][$phpStepIndex]['run'] = str_replace(
            $requiredPhpTest,
            '',
            (string) ($mutatedWorkflow['jobs']['php']['steps'][$phpStepIndex]['run'] ?? ''),
        );

        expect(releaseFoundationWorkflowViolations($sharedWorkflow, $mutatedWorkflow, $callers))
            ->toContain("required PHP validation must execute regression test: {$requiredPhpTest}");
    }
});

it('requires fork-runnable production runtime validation without publication', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    $withoutRuntimeJob = $applicationValidationWorkflow;
    unset($withoutRuntimeJob['jobs']['testing-host-runtime']);
    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $withoutRuntimeJob, $callers))
        ->toContain('production runtime validation must bridge the testing host and execute bundled Reverb and terminal acceptance on pull requests');

    $canonicalOnlyRuntimeJob = $applicationValidationWorkflow;
    $canonicalOnlyRuntimeJob['jobs']['testing-host-runtime']['if'] = "\${{ github.repository == 'coollabsio/coolify' }}";
    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $canonicalOnlyRuntimeJob, $callers))
        ->toContain('production runtime validation must bridge the testing host and execute bundled Reverb and terminal acceptance on pull requests');

    $publishingRuntimeJob = $applicationValidationWorkflow;
    foreach ($publishingRuntimeJob['jobs']['testing-host-runtime']['steps'] as &$step) {
        if (($step['name'] ?? null) === 'Build exact testing-host source image without publication') {
            $step['run'] .= "\ndocker push \"\$TESTING_HOST_IMAGE\"";
        }
    }
    unset($step);
    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $publishingRuntimeJob, $callers))
        ->toContain('testing-host pull-request validation must not require credentials or publish images');

    $withoutRequiredResult = $applicationValidationWorkflow;
    foreach ($withoutRequiredResult['jobs']['required']['steps'] as &$step) {
        if (($step['name'] ?? null) === 'Require every generic validation job') {
            unset($step['env']['TESTING_HOST_RUNTIME_RESULT']);
        }
    }
    unset($step);
    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $withoutRequiredResult, $callers))
        ->toContain('required status must fail when testing-host runtime validation does not succeed');

    $withoutBundledRuntime = $applicationValidationWorkflow;
    foreach ($withoutBundledRuntime['jobs']['testing-host-runtime']['steps'] as $index => $step) {
        if (($step['name'] ?? null) === 'Run exact bundled Reverb and terminal runtime contract') {
            unset($withoutBundledRuntime['jobs']['testing-host-runtime']['steps'][$index]);
        }
    }
    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $withoutBundledRuntime, $callers))
        ->toContain('production runtime validation must bridge the testing host and execute bundled Reverb and terminal acceptance on pull requests');
});

it('preloads pinned testing-host service images before pull-never runtime use', function () {
    $script = file_get_contents(
        releaseWorkflowRepositoryRoot().'/tests/Integration/TestingHostImageTest.sh',
    );

    expect($script)->toBeString();

    foreach (['database', 'redis'] as $service) {
        $pull = 'docker pull "$'.$service.'_image"';
        $run = '"$'.$service.'_image" >/dev/null';

        expect($script)
            ->toContain($pull)
            ->toContain($run)
            ->and(strpos($script, $pull))->toBeLessThan(strpos($script, $run));
    }
});

it('rejects removing the browser Redis runtime dependency', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    unset($applicationValidationWorkflow['jobs']['browser']['services']['redis']);

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain('browser validation must provide its Redis runtime dependency');
});

it('rejects an in-memory browser database', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    $applicationValidationWorkflow['jobs']['browser']['env']['DB_DATABASE'] = ':memory:';

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain('browser validation must use a file-backed SQLite database');
});

it('rejects applying legacy ShellCheck severity to new scripts', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    foreach ($applicationValidationWorkflow['jobs']['workflow-and-shell']['steps'] as $index => $step) {
        if (($step['name'] ?? null) === 'ShellCheck changed shell scripts') {
            $applicationValidationWorkflow['jobs']['workflow-and-shell']['steps'][$index]['run'] = str_replace(
                '--severity=error',
                '',
                (string) ($step['run'] ?? ''),
            );
        }
    }

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain('workflow validation must apply strict checks to new scripts and error checks to legacy scripts');
});

it('rejects omission of either image source-provenance gate', function (string $dockerfile): void {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    foreach ($applicationValidationWorkflow['jobs']['workflow-and-shell']['steps'] as $index => $step) {
        if (($step['name'] ?? null) === 'Verify pinned source provenance') {
            $applicationValidationWorkflow['jobs']['workflow-and-shell']['steps'][$index]['run'] = str_replace(
                "docker/verify-source-provenance.sh {$dockerfile}",
                '',
                (string) ($step['run'] ?? ''),
            );
        }
    }

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain("workflow validation must verify pinned source provenance for {$dockerfile}");
})->with([
    'production image' => 'docker/production/Dockerfile',
    'testing-host image' => 'docker/testing-host/Dockerfile',
]);

it('pins the production Git LFS binary to verified fixed Go source provenance', function (): void {
    $dockerfile = (string) file_get_contents(releaseWorkflowRepositoryRoot().'/docker/production/Dockerfile');
    $databaseSeederCopy = <<<'DOCKERFILE'
COPY --chown=www-data:www-data \
    database/seeders/CaSslCertSeeder.php \
    database/seeders/DatabaseSeeder.php \
    database/seeders/OauthSettingSeeder.php \
    database/seeders/PopulateSshKeysDirectorySeeder.php \
    database/seeders/ProductionSeeder.php \
    database/seeders/RootUserSeeder.php \
    database/seeders/SentinelSeeder.php \
    ./database/seeders/
DOCKERFILE;

    expect($dockerfile)
        ->toContain('ARG GO_BUILD_IMAGE=golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2')
        ->toContain('ARG GIT_LFS_VERSION=3.7.1')
        ->toContain('ARG GIT_LFS_TAG=v3.7.1')
        ->toContain('ARG GIT_LFS_COMMIT=b84b33847fe6458f36ef521534dc0eac953cb379')
        ->toContain('ARG GIT_LFS_SOURCE_SHA256=e1ef5ba4828fa632337be6a2c421a685432faec0405bf187f46a1459e82a9a62')
        ->toContain('ARG GIT_LFS_X_NET_VERSION=v0.56.0')
        ->toContain('ARG GIT_LFS_GO_MOD_SHA256=9b34961df646f48ca832d5201d9a4a78059a06269f50237d682630cf28485460')
        ->toContain('ARG GIT_LFS_GO_SUM_SHA256=f2e6c6da7a7d84ef4ffcad42902798eaa2aa14b795c3639a857d36504f2c6396')
        ->toContain('FROM go-source-builder AS git-lfs-builder')
        ->toContain('go get "golang.org/x/net@${GIT_LFS_X_NET_VERSION}"')
        ->toContain('go mod verify')
        ->toContain('go version -m /out/git-lfs')
        ->toContain('COPY --from=git-lfs-builder --chmod=755 /out/git-lfs /usr/local/bin/git-lfs')
        ->toContain('git lfs version')
        ->toContain("COPY app ./app\nCOPY config ./config\nCOPY lang ./lang\nCOPY resources ./resources\nCOPY routes ./routes\nCOPY templates ./templates\nRUN npm run build")
        ->toContain('COPY --chown=www-data:www-data database/migrations ./database/migrations')
        ->toContain($databaseSeederCopy)
        ->not->toContain('database/seeders/GithubAppSeeder.php')
        ->not->toContain('database/seeders/PersonalAccessTokenSeeder.php')
        ->not->toContain('database/seeders/S3StorageSeeder.php')
        ->not->toContain('database/seeders/StandalonePostgresqlSeeder.php')
        ->not->toContain('database/seeders/StandaloneRedisSeeder.php')
        ->not->toContain('database/schema')
        ->not->toContain('COPY --exclude=database . .')
        ->not->toContain('database ./database')
        ->not->toMatch('/^COPY \\. \\.\s*$/m')
        ->not->toMatch('/^\\s+git-lfs\\s+\\\\$/m');
});

it('rejects omission of an owned workflow from actionlint', function (string $workflowPath): void {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];
    $applicationValidationWorkflow['jobs']['workflow-and-shell']['env']['OWNED_WORKFLOWS'] = str_replace(
        $workflowPath,
        '',
        (string) ($applicationValidationWorkflow['jobs']['workflow-and-shell']['env']['OWNED_WORKFLOWS'] ?? ''),
    );

    expect(releaseFoundationWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))
        ->toContain("workflow validation must lint owned workflow: {$workflowPath}");
})->with([
    'application validation' => '.github/workflows/application-validation.yml',
    'production caller' => '.github/workflows/coolify-production-build.yml',
    'staging caller' => '.github/workflows/coolify-staging-build.yml',
    'testing-host caller' => '.github/workflows/coolify-testing-host.yml',
    'v4.x candidate gate' => '.github/workflows/gate-v4x-candidate.yml',
    'fork publisher' => '.github/workflows/publish-fork.yml',
    'reusable publisher' => '.github/workflows/publish-linux-image.yml',
    'operational acceptance' => '.github/workflows/release-operational-acceptance.yml',
]);

it('rejects parsed workflow policy regressions', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    foreach (releaseWorkflowNegativeCases() as $negativeCase) {
        [$mutatedSharedWorkflow, $mutatedCallers] = mutateReleaseWorkflow(
            $sharedWorkflow,
            $callers,
            $negativeCase['mutation'],
        );

        expect(releaseFoundationWorkflowViolations($mutatedSharedWorkflow, $applicationValidationWorkflow, $mutatedCallers))
            ->toContain($negativeCase['expected']);
    }
});

it('compensates the whole alias transaction when latest publication fails', function (string $failedRegistry, bool $preexistingGhcrSemantic) {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $aliasStep = releaseWorkflowStep(
        $sharedWorkflow['jobs']['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    );
    $script = (string) ($aliasStep['run'] ?? '');
    $filesystem = new Filesystem;
    $stateDirectory = sys_get_temp_dir().'/coolify-release-alias-'.bin2hex(random_bytes(8));
    $mockBin = $stateDirectory.'/bin';
    $log = $stateDirectory.'/regctl.log';
    $ghcrRepository = 'ghcr.io/coollabsio/coolify';
    $dockerRepository = 'docker.io/coollabsio/coolify';
    $semanticVersion = '4.2.0';
    $amd64Digest = releaseWorkflowTestDigest('a');
    $arm64Digest = releaseWorkflowTestDigest('b');
    $oldIndex = releaseWorkflowTestDigest('c');
    $newIndex = releaseWorkflowTestDigest('d');

    $filesystem->mkdir([$mockBin, $stateDirectory.'/refs', $stateDirectory.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($log, '');

    foreach ([$ghcrRepository, $dockerRepository] as $repository) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$oldIndex}", $oldIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$newIndex}", $newIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}:latest", $oldIndex);
    }
    if ($preexistingGhcrSemantic) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}", $newIndex);
    }
    file_put_contents($stateDirectory.'/runs/'.substr($oldIndex, strlen('sha256:')), "41\n");
    file_put_contents($stateDirectory.'/runs/'.substr($newIndex, strlen('sha256:')), "42\n");

    $failedTarget = ($failedRegistry === 'ghcr' ? $ghcrRepository : $dockerRepository).':latest';
    $process = new Process(['bash', '-c', $script], $root, [
        'AMD64_DIGEST' => $amd64Digest,
        'ARM64_DIGEST' => $arm64Digest,
        'DOCKER_TARGET' => $dockerRepository,
        'GHCR_TARGET' => $ghcrRepository,
        'GITHUB_RUN_ID' => '42',
        'INDEX_DIGEST' => $newIndex,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64Digest,
        'REGCTL_ARM64' => $arm64Digest,
        'REGCTL_FAIL_TARGET' => $failedTarget,
        'REGCTL_LOG' => $log,
        'REGCTL_STATE' => $stateDirectory,
        'SEMANTIC_VERSION' => $semanticVersion,
    ]);

    try {
        $process->setTimeout(15);
        $process->run();
        $registryLog = (string) file_get_contents($log);

        expect($process->isSuccessful())->toBeFalse()
            ->and($registryLog)->toContain("copy:{$ghcrRepository}@{$newIndex}:{$dockerRepository}:{$semanticVersion}")
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}"))->toBe($preexistingGhcrSemantic ? $newIndex : null)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:latest"))->toBe($oldIndex)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:latest"))->toBe($oldIndex);

        if ($preexistingGhcrSemantic) {
            expect($registryLog)->not->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:{$semanticVersion}");
        } else {
            expect($registryLog)->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:{$semanticVersion}");
        }

        if ($failedRegistry === 'ghcr') {
            expect($registryLog)->not->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:latest");
        } else {
            expect($registryLog)
                ->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:latest")
                ->toContain("copy:{$ghcrRepository}@{$oldIndex}:{$ghcrRepository}:latest");
        }
    } finally {
        $filesystem->remove($stateDirectory);
    }
})->with([
    'failure immediately after semantic publication' => ['ghcr', false],
    'failure between latest updates preserves a preexisting semantic alias' => ['docker', true],
]);

it('fails closed when alias snapshots, rollback reads, or cross-registry state are unsafe', function (string $failureMode) {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $step = releaseWorkflowStep($workflow['jobs']['release'] ?? [], 'Publish semantic and latest aliases atomically');
    $filesystem = new Filesystem;
    $state = sys_get_temp_dir().'/coolify-alias-read-'.bin2hex(random_bytes(8));
    $mockBin = $state.'/bin';
    $ghcr = 'ghcr.io/coollabsio/coolify';
    $docker = 'docker.io/coollabsio/coolify';
    $old = releaseWorkflowTestDigest('c');
    $new = releaseWorkflowTestDigest('d');
    $amd64 = releaseWorkflowTestDigest('a');
    $arm64 = releaseWorkflowTestDigest('b');
    $semantic = '4.2.0';
    $filesystem->mkdir([$mockBin, $state.'/refs', $state.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($state.'/regctl.log', '');
    foreach ([$ghcr, $docker] as $repository) {
        releaseWorkflowWriteRegistryReference($state, "{$repository}@{$old}", $old);
        releaseWorkflowWriteRegistryReference($state, "{$repository}@{$new}", $new);
        releaseWorkflowWriteRegistryReference($state, "{$repository}:{$semantic}", $old);
        releaseWorkflowWriteRegistryReference($state, "{$repository}:latest", $old);
    }
    file_put_contents($state.'/runs/'.substr($old, 7), "41\n");
    file_put_contents($state.'/runs/'.substr($new, 7), "42\n");

    $environment = [
        'AMD64_DIGEST' => $amd64,
        'ARM64_DIGEST' => $arm64,
        'DOCKER_TARGET' => $docker,
        'GHCR_TARGET' => $ghcr,
        'GITHUB_RUN_ID' => '42',
        'INDEX_DIGEST' => $new,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64,
        'REGCTL_ARM64' => $arm64,
        'REGCTL_LOG' => $state.'/regctl.log',
        'REGCTL_STATE' => $state,
        'SEMANTIC_VERSION' => $semantic,
    ];
    if (str_starts_with($failureMode, 'snapshot')) {
        $environment['REGCTL_FAIL_READ_REFERENCE'] = "{$ghcr}:{$semantic}";
        $environment['REGCTL_FAIL_READ_MESSAGE'] = match ($failureMode) {
            'snapshot-credential' => 'credential helper not found',
            'snapshot-auth404' => 'authentication endpoint returned HTTP 404',
            default => 'registry transport unavailable',
        };
    } else {
        $environment['REGCTL_FAIL_TARGET'] = "{$docker}:latest";
        $environment['REGCTL_FAIL_READ_AFTER_COPY_FAILURE_REFERENCE'] = "{$ghcr}:latest";
        releaseWorkflowWriteRegistryReference($state, "{$ghcr}:{$semantic}", $new);
        releaseWorkflowWriteRegistryReference($state, "{$docker}:{$semantic}", $new);
        if ($failureMode === 'rollback-absent') {
            unlink(releaseWorkflowRegistryReferencePath($state, "{$ghcr}:latest"));
        } elseif ($failureMode === 'rollback-cross-prior') {
            releaseWorkflowWriteRegistryReference($state, "{$docker}:latest", $amd64);
            releaseWorkflowWriteRegistryReference($state, "{$docker}@{$amd64}", $amd64);
            file_put_contents($state.'/runs/'.substr($amd64, 7), "41\n");
            $environment['REGCTL_CONCURRENT_REFERENCE'] = "{$ghcr}:latest";
            $environment['REGCTL_CONCURRENT_DIGEST'] = $amd64;
        }
    }

    try {
        $process = new Process(['bash', '-c', (string) ($step['run'] ?? '')], $root, $environment);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and(releaseWorkflowReadRegistryReference($state, "{$ghcr}:{$semantic}"))->not->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($state, "{$docker}:{$semantic}"))->not->toBeNull();
        if ($failureMode === 'rollback') {
            expect($process->getErrorOutput())->toContain('compensation was incomplete');
        } elseif ($failureMode === 'rollback-absent') {
            expect($process->getErrorOutput())->toContain('alias state is split across registries for latest')
                ->and(releaseWorkflowReadRegistryReference($state, "{$ghcr}:latest"))->toBeNull();
        } elseif ($failureMode === 'rollback-cross-prior') {
            expect($process->getErrorOutput())->toContain('alias state is split across registries for latest')
                ->and(releaseWorkflowReadRegistryReference($state, "{$ghcr}:latest"))->toBe($old);
        }
    } finally {
        $filesystem->remove($state);
    }
})->with(['snapshot', 'snapshot-credential', 'snapshot-auth404', 'rollback', 'rollback-absent', 'rollback-cross-prior']);

it('rejects a superseded candidate before creating its absent semantic aliases', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $aliasStep = releaseWorkflowStep(
        $sharedWorkflow['jobs']['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    );
    $script = (string) ($aliasStep['run'] ?? '');
    $filesystem = new Filesystem;
    $stateDirectory = sys_get_temp_dir().'/coolify-superseded-alias-'.bin2hex(random_bytes(8));
    $mockBin = $stateDirectory.'/bin';
    $log = $stateDirectory.'/regctl.log';
    $ghcrRepository = 'ghcr.io/coollabsio/coolify';
    $dockerRepository = 'docker.io/coollabsio/coolify';
    $semanticVersion = '4.1.0';
    $amd64Digest = releaseWorkflowTestDigest('a');
    $arm64Digest = releaseWorkflowTestDigest('b');
    $candidateIndex = releaseWorkflowTestDigest('c');
    $newerIndex = releaseWorkflowTestDigest('d');

    $filesystem->mkdir([$mockBin, $stateDirectory.'/refs', $stateDirectory.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($log, '');

    foreach ([$ghcrRepository, $dockerRepository] as $repository) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$candidateIndex}", $candidateIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$newerIndex}", $newerIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}:latest", $newerIndex);
    }
    file_put_contents($stateDirectory.'/runs/'.substr($candidateIndex, strlen('sha256:')), "41\n");
    file_put_contents($stateDirectory.'/runs/'.substr($newerIndex, strlen('sha256:')), "42\n");

    $process = new Process(['bash', '-c', $script], $root, [
        'AMD64_DIGEST' => $amd64Digest,
        'ARM64_DIGEST' => $arm64Digest,
        'DOCKER_TARGET' => $dockerRepository,
        'GHCR_TARGET' => $ghcrRepository,
        'GITHUB_RUN_ID' => '41',
        'INDEX_DIGEST' => $candidateIndex,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64Digest,
        'REGCTL_ARM64' => $arm64Digest,
        'REGCTL_LOG' => $log,
        'REGCTL_STATE' => $stateDirectory,
        'SEMANTIC_VERSION' => $semanticVersion,
    ]);

    try {
        $process->setTimeout(15);
        $process->run();
        $registryLog = (string) file_get_contents($log);

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('classification=superseded')
            ->and($registryLog)->not->toContain('copy:')
            ->and($registryLog)->not->toContain('delete:')
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:latest"))->toBe($newerIndex)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:latest"))->toBe($newerIndex);
    } finally {
        $filesystem->remove($stateDirectory);
    }
});

it('executes immutable-tag, compensation, and platform-verification behavior against a registry double', function () {
    $process = new Process([
        'bash',
        releaseWorkflowRepositoryRoot().'/tests/Integration/PublishLinuxImageScriptTest.sh',
    ]);
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('PUBLISH_LINUX_IMAGE_HELPER_PASS');
});

it('loads a native child from a nested attested OCI archive', function () {
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-nested-oci-'.bin2hex(random_bytes(8));
    $layout = $fixture.'/layout';
    $blobDirectory = $layout.'/blobs/sha256';
    $bin = $fixture.'/bin';
    $filesystem->mkdir([$blobDirectory, $bin]);

    $writeBlob = static function (array $document) use ($blobDirectory): array {
        $contents = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $contents);
        file_put_contents($blobDirectory.'/'.$digest, $contents);

        return ['digest' => 'sha256:'.$digest, 'size' => strlen($contents)];
    };

    try {
        $config = $writeBlob([
            'architecture' => 'amd64',
            'config' => ['Labels' => []],
            'os' => 'linux',
            'rootfs' => ['diff_ids' => [], 'type' => 'layers'],
        ]);
        $manifest = $writeBlob([
            'config' => [
                'digest' => $config['digest'],
                'mediaType' => 'application/vnd.oci.image.config.v1+json',
                'size' => $config['size'],
            ],
            'layers' => [],
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'schemaVersion' => 2,
        ]);
        $attestation = $writeBlob([
            'config' => [
                'digest' => $config['digest'],
                'mediaType' => 'application/vnd.oci.image.config.v1+json',
                'size' => $config['size'],
            ],
            'layers' => [],
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'schemaVersion' => 2,
        ]);
        $innerIndex = $writeBlob([
            'manifests' => [
                [
                    'digest' => $manifest['digest'],
                    'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
                    'platform' => ['architecture' => 'amd64', 'os' => 'linux'],
                    'size' => $manifest['size'],
                ],
                [
                    'annotations' => [
                        'vnd.docker.reference.digest' => $manifest['digest'],
                        'vnd.docker.reference.type' => 'attestation-manifest',
                    ],
                    'digest' => $attestation['digest'],
                    'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
                    'platform' => ['architecture' => 'unknown', 'os' => 'unknown'],
                    'size' => $attestation['size'],
                ],
            ],
            'mediaType' => 'application/vnd.oci.image.index.v1+json',
            'schemaVersion' => 2,
        ]);
        file_put_contents($layout.'/oci-layout', json_encode(['imageLayoutVersion' => '1.0.0'], JSON_THROW_ON_ERROR));
        file_put_contents($layout.'/index.json', json_encode([
            'manifests' => [[
                'digest' => $innerIndex['digest'],
                'mediaType' => 'application/vnd.oci.image.index.v1+json',
                'size' => $innerIndex['size'],
            ]],
            'mediaType' => 'application/vnd.oci.image.index.v1+json',
            'schemaVersion' => 2,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $archive = $fixture.'/nested.oci.tar';
        (new Process(['tar', '-C', $layout, '-cf', $archive, 'oci-layout', 'index.json', 'blobs']))->mustRun();
        file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu
case "$1:$2" in
    image:inspect)
        case " $* " in
            *' --format {{.Id}} '*) printf '%s\n' "${CONFIG_DIGEST:?}" ;;
            *' --format {{.Os}}/{{.Architecture}} '*) printf '%s\n' 'linux/amd64' ;;
            *) exit 1 ;;
        esac
        ;;
    load:*) exit 0 ;;
    tag:*) exit 0 ;;
    *) printf 'unexpected docker invocation: %s\n' "$*" >&2; exit 64 ;;
esac
SH);
        chmod($bin.'/docker', 0755);

        $process = new Process(
            ['sh', releaseWorkflowRepositoryRoot().'/tests/Integration/VerifyOciArchiveImage.sh'],
            releaseWorkflowRepositoryRoot(),
            [
                'CONFIG_DIGEST' => $config['digest'],
                'OCI_ARCHIVE' => $archive,
                'OCI_ARCHIVE_SHA256' => hash_file('sha256', $archive),
                'OCI_CHILD_DIGEST' => $manifest['digest'],
                'OCI_CONTENT_POLICY' => 'none',
                'OCI_IMAGE' => 'local/coolify:test-nested',
                'OCI_PLATFORM' => 'linux/amd64',
                'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                'RUNNER_TEMP' => $fixture,
            ],
        );
        $process->mustRun();

        expect($process->getOutput())
            ->toContain('OCI_ARCHIVE_RUNTIME_VERIFIED')
            ->toContain('child_digest='.$manifest['digest']);
    } finally {
        $filesystem->remove($fixture);
    }
});

it('defines one referrerless fork release graph for the main image on both platforms', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $caller = Yaml::parseFile($root.'/.github/workflows/publish-fork.yml');
    $jobs = $workflow['jobs'] ?? [];

    expect(is_executable($root.'/tests/Integration/VerifyOciArchiveImage.sh'))->toBeTrue();
    expect(is_executable($root.'/scripts/ci/deriveImageTags.mjs'))->toBeTrue()
        ->and(is_executable($root.'/scripts/ci/deriveImageTags.test.sh'))->toBeTrue()
        ->and(is_executable($root.'/scripts/ci/verify-nexus-docker-write-policy.sh'))->toBeTrue()
        ->and(is_executable($root.'/tests/Integration/VerifyNexusDockerWritePolicyTest.sh'))->toBeTrue();

    $matrix = $jobs['fork-build']['strategy']['matrix']['include'] ?? [];
    $contracts = array_map(
        static fn (array $entry): array => [
            $entry['product'] ?? null,
            $entry['dockerfile'] ?? null,
            $entry['platform'] ?? null,
        ],
        $matrix,
    );
    expect($contracts)->toBe([
        ['main', 'docker/production/Dockerfile', 'linux/amd64'],
        ['main', 'docker/production/Dockerfile', 'linux/arm64'],
    ]);

    $trustedForkRunner = [
        'group' => 'coolify-trusted',
        'labels' => ['self-hosted', 'Linux', 'X64', 'williamacallahan'],
    ];
    expect($jobs['fork-build']['runs-on'] ?? null)->toBe($trustedForkRunner)
        ->and($jobs['fork-stage']['runs-on'] ?? null)->toBe($trustedForkRunner)
        ->and($jobs['fork-release']['runs-on'] ?? null)->toBe($trustedForkRunner);

    $qemuSetup = releaseWorkflowStep($jobs['fork-build'] ?? [], 'Set up QEMU for fork cross-platform builds');
    $buildxPlatformVerification = (string) (releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Verify fork Buildx platform support',
    )['run'] ?? '');
    expect($qemuSetup['uses'] ?? null)
        ->toBe('docker/setup-qemu-action@c7c53464625b32c7a7e944ae62b3e17d2b600130')
        ->and($qemuSetup['with']['platforms'] ?? null)->toBe('arm64')
        ->and($buildxPlatformVerification)->toContain('docker buildx inspect --bootstrap | grep -F "$PLATFORM"');

    $buildStep = releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Build fork OCI archive with inline provenance and SBOM',
    );
    expect($buildStep['with']['provenance'] ?? null)->toBe('mode=max')
        ->and($buildStep['with']['sbom'] ?? null)->toBeTrue()
        ->and((string) ($buildStep['with']['labels'] ?? ''))
        ->toContain('org.opencontainers.image.source=https://github.com/${{ github.repository }}')
        ->toContain('org.opencontainers.image.revision=${{ github.sha }}')
        ->toContain('org.opencontainers.image.version=${{ inputs.semantic_version }}');

    $archiveVerificationRun = (string) (releaseWorkflowStep(
        $jobs['fork-build'] ?? [],
        'Verify fork OCI labels and inline attestations',
    )['run'] ?? '');
    expect($archiveVerificationRun)
        ->toContain('expected one nested image index')
        ->toContain('nested_index_blob="$layout/blobs/sha256/${nested_index_digest#sha256:}"');

    $runtimeVerifier = (string) file_get_contents($root.'/tests/Integration/VerifyOciArchiveImage.sh');
    expect($runtimeVerifier)
        ->toContain('expected one nested image index')
        ->toContain('indexes = [index]')
        ->toContain("indexes.append(json.load(source.extractfile(f'blobs/sha256/{nested_digest}')))");

    foreach ([
        'Generate standalone fork SPDX SBOM',
        'Scan fork OCI for high and critical vulnerabilities',
        'Scan fork OCI for secrets',
    ] as $scanStepName) {
        $scanStep = releaseWorkflowStep($jobs['fork-build'] ?? [], $scanStepName);
        expect(json_encode($scanStep, JSON_THROW_ON_ERROR))->toContain('-fork-runtime');
    }
    $secretScan = releaseWorkflowStep($jobs['fork-build'] ?? [], 'Scan fork OCI for secrets');
    expect($secretScan['with']['image-ref'] ?? null)
        ->toBe('local/${{ matrix.artifact_name }}:${{ github.sha }}-${{ matrix.arch }}-fork-runtime');

    $forkRegistryPolicy = $jobs['fork-registry-policy'] ?? [];
    $policyCheckout = releaseWorkflowStep($forkRegistryPolicy, 'Check out immutable fork policy source');
    $policyStep = releaseWorkflowStep(
        $forkRegistryPolicy,
        'Validate shared Nexus docker-hosted contract',
    );
    $policyRun = (string) ($policyStep['run'] ?? '');
    $stageRun = (string) (releaseWorkflowStep(
        $jobs['fork-stage'] ?? [],
        'Stage the fork image with inline build attestations',
    )['run'] ?? '');
    $promotionRun = (string) (releaseWorkflowStep(
        $jobs['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $forkRegistryPolicyStepNames = collect(releaseWorkflowSteps($forkRegistryPolicy))
        ->pluck('name')
        ->values()
        ->all();
    expect($forkRegistryPolicy['name'] ?? null)->toBe('Validate shared Nexus docker-hosted contract');
    expect($forkRegistryPolicy['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and($forkRegistryPolicy['env'] ?? [])
        ->not->toHaveKey('NEXUS_PASSWORD')
        ->not->toHaveKey('NEXUS_USERNAME')
        ->and($policyStep['env'] ?? null)->toBe([
            'NEXUS_PASSWORD' => '${{ secrets.NEXUS_PASSWORD }}',
            'NEXUS_USERNAME' => '${{ secrets.NEXUS_USERNAME }}',
        ])
        ->and($policyCheckout['uses'] ?? null)
        ->toBe('actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd')
        ->and($policyCheckout['with']['persist-credentials'] ?? null)->toBeFalse()
        ->and($policyCheckout['with']['ref'] ?? null)->toBe('${{ github.sha }}');
    expect($policyRun)
        ->toBe('scripts/ci/verify-nexus-docker-write-policy.sh')
        ->and($forkRegistryPolicyStepNames)
        ->not->toContain('Reject existing fork semantic tags before build')
        ->and($stageRun)
        ->toContain('stage_product main coolify "$MAIN_TARGET"')
        ->toContain('org.opencontainers.image.source')
        ->toContain('org.opencontainers.image.revision')
        ->toContain('org.opencontainers.image.version')
        ->toContain('manifests: ((.[0].manifests + .[1].manifests) | unique_by(.digest))')
        ->toContain('regctl manifest put')
        ->not->toContain('regctl index create')
        ->and($promotionRun)
        ->toContain('preflight_semantic_tag')
        ->toContain('promote_or_verify_semantic_tag')
        ->toContain('promote_and_verify_canonical_tags')
        ->toContain('fork_version_is_newer')
        ->toContain('preflight_latest_tag')
        ->toContain('Refusing to move fork-latest backward or sideways')
        ->toContain('FORK_LATEST_TAG')
        ->toContain('FORK_VERSION_TAG')
        ->toContain('FORK_VERSION_SHA_TAG')
        ->not->toContain('scripts/ci/deriveImageTags.mjs')
        ->toContain('does not match its expected immutable index digest')
        ->toContain('accepting recovery state')
        ->toContain('assert_live_fork_tag_binding')
        ->toContain('assert_active_fork_tag_protection')
        ->toContain('assert_dispatch_source_binding')
        ->toContain('assert_release_source_binding')
        ->toContain('[[ "$GITHUB_REF" == refs/heads/v4.x ]]')
        ->toContain('Protect Coolify fork release tags')
        ->toContain('refs/tags/*.*.*-fork*')
        ->toContain('index("creation")')
        ->toContain('index("update")')
        ->toContain('index("deletion")')
        ->toContain('human authority remains a residual risk')
        ->toContain('preflight_semantic_tag "$MAIN_TARGET" "$MAIN_INDEX_DIGEST"')
        ->toContain('promote_or_verify_semantic_tag "$MAIN_TARGET" "$MAIN_INDEX_DIGEST"')
        ->toContain('promote_and_verify_canonical_tags "$MAIN_TARGET" "$MAIN_INDEX_DIGEST"')
        ->not->toContain('REALTIME_TARGET')
        ->not->toContain('DOCKER_TARGET');

    $preflightMainPosition = strpos($promotionRun, 'preflight_semantic_tag "$MAIN_TARGET" "$MAIN_INDEX_DIGEST"');
    $preWriteTagVerificationPosition = strpos($promotionRun, 'assert_release_source_binding', $preflightMainPosition ?: 0);
    $mainPromotionPosition = strpos($promotionRun, 'promote_or_verify_semantic_tag "$MAIN_TARGET" "$MAIN_INDEX_DIGEST"');
    $postWriteTagVerificationPosition = strpos($promotionRun, 'assert_release_source_binding', ($mainPromotionPosition ?: 0) + 1);
    expect($preflightMainPosition)->not->toBeFalse()
        ->and($preWriteTagVerificationPosition)->not->toBeFalse()
        ->and($mainPromotionPosition)->not->toBeFalse()
        ->and($postWriteTagVerificationPosition)->not->toBeFalse()
        ->and($preWriteTagVerificationPosition)->toBeGreaterThan($preflightMainPosition)
        ->and($mainPromotionPosition)->toBeGreaterThan($preWriteTagVerificationPosition)
        ->and($postWriteTagVerificationPosition)->toBeGreaterThan($mainPromotionPosition);

    $attestationJob = $jobs['fork-attest'] ?? [];
    expect($attestationJob['if'] ?? null)
        ->toBe('${{ inputs.release_kind == \'fork\' && needs.fork-stage.result == \'success\' }}');
    $forkAttestations = collect(releaseWorkflowSteps($attestationJob))
        ->filter(fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/attest@'));
    expect($forkAttestations)->toHaveCount(6);
    foreach ($forkAttestations as $attestation) {
        expect($attestation['with']['push-to-registry'] ?? null)->toBeFalse()
            ->and($attestation['with']['create-storage-record'] ?? null)->toBeFalse();
    }
    $bundleVerification = (string) (releaseWorkflowStep(
        $attestationJob,
        'Verify every signed fork bundle off-registry',
    )['run'] ?? '');
    expect($bundleVerification)
        ->toContain('--bundle "$bundle"')
        ->toContain('--source-digest "$GITHUB_SHA"')
        ->toContain('--source-ref "$GITHUB_REF"')
        ->toContain('case "$SOURCE_IS_TAG" in')
        ->toContain('false)')
        ->not->toContain('--bundle-from-oci');
    foreach ([
        'Generate and Ed25519-sign strict fork deploy manifests',
        'Sign amd64 deploy manifest off-registry',
        'Sign arm64 deploy manifest off-registry',
        'Collect deploy-manifest attestation bundles and checksums',
    ] as $tagOnlyStepName) {
        expect(releaseWorkflowStep($attestationJob, $tagOnlyStepName)['if'] ?? null)
            ->toBe('${{ inputs.source_is_tag }}');
    }
    $bundleChecksums = releaseWorkflowStep($attestationJob, 'Collect fork bundle checksums');
    expect($bundleChecksums)->not->toHaveKey('if')
        ->and((string) ($bundleChecksums['run'] ?? ''))
        ->toContain('find . -maxdepth 1 -type f -print0')
        ->toContain('install -m 0600 "$checksum_file" fork-signed-bundles/SHA256SUMS');

    $releaseBundleDownload = releaseWorkflowStep(
        $jobs['fork-release'] ?? [],
        'Download verified fork release bundles',
    );
    $releasePublication = (string) (releaseWorkflowStep(
        $jobs['fork-release'] ?? [],
        'Publish immutable signed fork bundles to the tag release',
    )['run'] ?? '');
    $draftRecovery = (string) (releaseWorkflowStep(
        $jobs['fork-release'] ?? [],
        'Create or validate empty draft recovery release before semantic promotion',
    )['run'] ?? '');
    $forkRelease = $jobs['fork-release'] ?? [];
    $releaseEvidence = (string) (releaseWorkflowStep(
        $forkRelease,
        'Record verified direct fork registry evidence',
    )['run'] ?? '');
    $releaseCheckoutSteps = collect(releaseWorkflowSteps($forkRelease))
        ->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'));
    $finalPolicyStep = releaseWorkflowStep(
        $forkRelease,
        'Revalidate shared Nexus docker-hosted contract before final tagging',
    );
    $finalPolicyRun = (string) ($finalPolicyStep['run'] ?? '');
    $finalLoginStep = releaseWorkflowStep(
        $forkRelease,
        'Login to Nexus fork registry for final promotion',
    );
    $forkReleaseNexusSecretSteps = collect(releaseWorkflowSteps($forkRelease))
        ->filter(static fn (array $step): bool => collect(releaseWorkflowScalarValues($step))
            ->contains(static fn (string $value): bool => in_array($value, [
                '${{ secrets.NEXUS_PASSWORD }}',
                '${{ secrets.NEXUS_USERNAME }}',
            ], true)))
        ->pluck('name')
        ->values()
        ->all();
    expect($forkRelease['env'] ?? [])
        ->not->toHaveKey('NEXUS_PASSWORD')
        ->not->toHaveKey('NEXUS_USERNAME')
        ->and($finalPolicyStep['env'] ?? [])
        ->toBe([
            'NEXUS_PASSWORD' => '${{ secrets.NEXUS_PASSWORD }}',
            'NEXUS_USERNAME' => '${{ secrets.NEXUS_USERNAME }}',
        ])
        ->and($finalLoginStep['with']['password'] ?? null)
        ->toBe('${{ secrets.NEXUS_PASSWORD }}')
        ->and($finalLoginStep['with']['username'] ?? null)
        ->toBe('${{ secrets.NEXUS_USERNAME }}')
        ->and($forkReleaseNexusSecretSteps)
        ->toBe([
            'Revalidate shared Nexus docker-hosted contract before final tagging',
            'Login to Nexus fork registry for final promotion',
        ]);
    expect($releaseCheckoutSteps)->toBeEmpty();
    expect($finalPolicyRun)
        ->toContain('.writePolicy == "ALLOW"')
        ->toContain('.format == "docker"')
        ->toContain('.type == "hosted"')
        ->not->toContain('ALLOW_ONCE')
        ->not->toContain('--request');
    expect($forkRelease['permissions']['contents'] ?? null)->toBe('write')
        ->and($releaseBundleDownload['with']['artifact-ids'] ?? null)
        ->toBe('${{ needs.fork-attest.outputs.bundle_artifact_id }}')
        ->and($releaseBundleDownload['if'] ?? null)->toBe('${{ inputs.source_is_tag }}')
        ->and(releaseWorkflowStep($forkRelease, 'Install pinned GitHub CLI for fork release publication')['if'] ?? null)
        ->toBe('${{ inputs.source_is_tag }}')
        ->and(releaseWorkflowStep($forkRelease, 'Create or validate empty draft recovery release before semantic promotion')['if'] ?? null)
        ->toBe('${{ inputs.source_is_tag }}')
        ->and(releaseWorkflowStep($forkRelease, 'Publish immutable signed fork bundles to the tag release')['if'] ?? null)
        ->toBe('${{ inputs.source_is_tag }}')
        ->and($draftRecovery)
        ->toContain('"$GH_CLI" release create')
        ->toContain('--verify-tag')
        ->toContain('--draft '.chr(92)."\n".'    --prerelease')
        ->toContain('--prerelease')
        ->toContain('--latest=false')
        ->toContain('assert_recovery_release_assets_are_exact')
        ->toContain('assert_release_tag_targets_source_revision')
        ->toContain('Published fork release exactly matches the immutable release contract; accepting idempotent retry.')
        ->not->toContain('release upload')
        ->and($releasePublication)
        ->not->toContain('"$GH_CLI" release create')
        ->toContain('Fork recovery draft is missing after semantic promotion; refusing to publish signed bundles.')
        ->toContain('release upload')
        ->toContain('release edit')
        ->toContain('--draft=false')
        ->toContain('if ! "$GH_CLI" release edit "$SEMANTIC_VERSION"')
        ->toContain('Fork release publication returned an ambiguous failure; reconciling the observed release state.')
        ->toContain('assert_release_tag_targets_source_revision')
        ->toContain('if [[ "$(jq -r \'.isDraft\' "$release_metadata_file")" == true ]]; then')
        ->toContain('"$GH_CLI" release upload "$SEMANTIC_VERSION"')
        ->toContain('sha256sum --check --strict SHA256SUMS')
        ->toContain('Fork tag release asset does not match the signed release bundle')
        ->toContain('Published fork tag release assets are incomplete; refusing to modify a published release.')
        ->not->toContain('--clobber')
        ->and($releaseEvidence)
        ->toContain('### Verified fork registry publication')
        ->toContain('"$FORK_LATEST_TAG"')
        ->toContain('"$FORK_VERSION_TAG"')
        ->toContain('"$FORK_VERSION_SHA_TAG"')
        ->toContain('linux/amd64')
        ->toContain('linux/arm64');

    $forkReleaseStepNames = array_map(
        static fn (array $step): string => (string) ($step['name'] ?? ''),
        releaseWorkflowSteps($forkRelease),
    );
    $draftRecoveryStepPosition = array_search('Create or validate empty draft recovery release before semantic promotion', $forkReleaseStepNames, true);
    $promotionStepPosition = array_search('Promote and verify the main fork image', $forkReleaseStepNames, true);
    $publicationStepPosition = array_search('Publish immutable signed fork bundles to the tag release', $forkReleaseStepNames, true);
    expect($draftRecoveryStepPosition)->not->toBeFalse()
        ->and($promotionStepPosition)->not->toBeFalse()
        ->and($publicationStepPosition)->not->toBeFalse()
        ->and($draftRecoveryStepPosition)->toBeLessThan($promotionStepPosition)
        ->and($promotionStepPosition)->toBeLessThan($publicationStepPosition);

    $draftRecoveryPosition = strpos($releasePublication, 'if [[ "$(jq -r \'.isDraft\' "$release_metadata_file")" == true ]]; then');
    $draftInventoryCheckPosition = strpos($releasePublication, 'assert_release_assets_are_exact', $draftRecoveryPosition ?: 0);
    $draftTagTargetCheckPosition = strpos($releasePublication, 'assert_release_tag_targets_source_revision', $draftRecoveryPosition ?: 0);
    $draftPublicationPosition = strpos($releasePublication, '"$GH_CLI" release edit "$SEMANTIC_VERSION"', $draftRecoveryPosition ?: 0);
    expect($draftRecoveryPosition)->not->toBeFalse()
        ->and($draftInventoryCheckPosition)->not->toBeFalse()
        ->and($draftTagTargetCheckPosition)->not->toBeFalse()
        ->and($draftPublicationPosition)->not->toBeFalse()
        ->and($draftInventoryCheckPosition)->toBeLessThan($draftPublicationPosition)
        ->and($draftTagTargetCheckPosition)->toBeLessThan($draftPublicationPosition);

    $publish = $caller['jobs']['publish'] ?? [];
    expect($publish['with'] ?? null)->toBe([
        'artifact_name' => 'coolify-fork',
        'candidate_repository' => 'williamagh/coolify-fork-candidates',
        'dockerfile' => 'docker/production/Dockerfile',
        'publish_latest' => false,
        'release_kind' => 'fork',
        'semantic_version' => '${{ needs.resolve-tag.outputs.version }}',
        'source_is_tag' => '${{ needs.resolve-tag.outputs.source_is_tag == \'true\' }}',
        'target_repository' => 'williamagh/coolify',
        'validate_only' => false,
    ])->and(array_keys($publish['secrets'] ?? []))->toBe([
        'FORK_RELEASE_SIGNING_ED25519_PRIVATE_KEY',
        'NEXUS_PASSWORD',
        'NEXUS_USERNAME',
    ])->and($publish['permissions'] ?? null)->toBe([
        'artifact-metadata' => 'write',
        'attestations' => 'write',
        'contents' => 'write',
        'id-token' => 'write',
        'packages' => 'write',
    ])
        ->and((string) file_get_contents($root.'/.github/workflows/publish-linux-image.yml'))
        ->not->toContain('FORK_RELEASE_SIGNING_KEY_ID')
        ->and((string) file_get_contents($root.'/.github/workflows/publish-fork.yml'))
        ->not->toContain('FORK_RELEASE_SIGNING_KEY_ID');
});

it('permits only exact fork tags or protected v4.x direct fork publication sources', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($workflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $semanticPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $forkPattern = (string) ($targetStep['env']['FORK_SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        'exact current fork tag' => ['4.13.1-fork', 'refs/tags/4.13.1-fork', 'tag', 'push', 'false', 'true', 'false', 'false', true],
        'future fork release' => ['4.14.0-fork', 'refs/tags/4.14.0-fork', 'tag', 'push', 'false', 'true', 'false', 'false', true],
        'protected v4x direct dispatch' => ['4.13.1-fork', 'refs/heads/v4.x', 'branch', 'workflow_dispatch', 'true', 'false', 'false', 'false', true],
        'legacy numbered fork release' => ['4.13.1-fork.1', 'refs/tags/4.13.1-fork.1', 'tag', 'push', 'false', 'true', 'false', 'false', false],
        'zero fork release' => ['4.13.1-fork.0', 'refs/tags/4.13.1-fork.0', 'tag', 'push', 'false', 'true', 'false', 'false', false],
        'v-prefixed alias' => ['4.13.1-fork', 'refs/tags/v4.13.1-fork', 'tag', 'push', 'false', 'true', 'false', 'false', false],
        'unprotected v4x direct dispatch' => ['4.13.1-fork', 'refs/heads/v4.x', 'branch', 'workflow_dispatch', 'false', 'false', 'false', 'false', false],
        'wrong direct branch' => ['4.13.1-fork', 'refs/heads/main', 'branch', 'workflow_dispatch', 'true', 'false', 'false', 'false', false],
        'tag mode with dispatch event' => ['4.13.1-fork', 'refs/tags/4.13.1-fork', 'tag', 'workflow_dispatch', 'false', 'true', 'false', 'false', false],
        'latest publication' => ['4.13.1-fork', 'refs/tags/4.13.1-fork', 'tag', 'push', 'false', 'true', 'true', 'false', false],
        'validate-only publication' => ['4.13.1-fork', 'refs/tags/4.13.1-fork', 'tag', 'push', 'false', 'true', 'false', 'true', false],
    ];

    foreach ($cases as $description => [$version, $ref, $refType, $eventName, $refProtected, $sourceIsTag, $publishLatest, $validateOnly, $successful]) {
        $output = tempnam(sys_get_temp_dir(), 'coolify-fork-target-');
        expect($output)->not->toBeFalse();
        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify-fork',
                'CANDIDATE_REPOSITORY' => 'williamagh/coolify-fork-candidates',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'FORK_RELEASE_SIGNING_ED25519_PRIVATE_KEY' => $sourceIsTag === 'true' ? 'fixture-private-key' : '',
                'FORK_SEMANTIC_VERSION_PATTERN' => $forkPattern,
                'FORK_SOURCE_IS_TAG' => $sourceIsTag,
                'GITHUB_OUTPUT' => $output,
                'GITHUB_EVENT_NAME' => $eventName,
                'GITHUB_REF' => $ref,
                'GITHUB_REF_PROTECTED' => $refProtected,
                'GITHUB_REF_TYPE' => $refType,
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'NEXUS_PASSWORD' => 'fixture-password',
                'NEXUS_USERNAME' => 'fixture-user',
                'PUBLISH_LATEST' => $publishLatest,
                'RELEASE_KIND' => 'fork',
                'REPOSITORY' => 'williamacallahan/coolify',
                'SEMANTIC_VERSION' => $version,
                'SEMANTIC_VERSION_PATTERN' => $semanticPattern,
                'TARGET_REPOSITORY' => 'williamagh/coolify',
                'VALIDATE_ONLY' => $validateOnly,
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description);
        } finally {
            unlink($output);
        }
    }
});

it('requires both fork application version sources to exactly match the immutable source before builds', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $validateInputs = $workflow['jobs']['validate-inputs'] ?? [];
    $checkoutStep = releaseWorkflowStep(
        $validateInputs,
        'Check out exact fork source for version binding',
    );
    $versionStep = releaseWorkflowStep(
        $validateInputs,
        'Require fork application versions match the immutable source',
    );
    $script = (string) ($versionStep['run'] ?? '');
    $validateInputStepNames = array_map(
        static fn (array $step): string => (string) ($step['name'] ?? ''),
        releaseWorkflowSteps($validateInputs),
    );
    $checkoutPosition = array_search('Check out exact fork source for version binding', $validateInputStepNames, true);
    $versionPosition = array_search('Require fork application versions match the immutable source', $validateInputStepNames, true);
    expect($checkoutStep['uses'] ?? null)
        ->toBe('actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd')
        ->and($validateInputs['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and($checkoutStep['if'] ?? null)->toBe('${{ inputs.release_kind == \'fork\' }}')
        ->and($checkoutStep['with'] ?? null)->toBe([
            'ref' => '${{ github.sha }}',
            'persist-credentials' => false,
        ])
        ->and($checkoutPosition)->not->toBeFalse()
        ->and($versionPosition)->not->toBeFalse()
        ->and($checkoutPosition)->toBeLessThan($versionPosition)
        ->and($script)->toContain('source_root="${GITHUB_WORKSPACE:?}"');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-version-binding-'.bin2hex(random_bytes(8));
    $constants = (string) file_get_contents($root.'/config/constants.php');
    $versions = (string) file_get_contents($root.'/versions.json');

    $filesystem->mkdir($fixture.'/config');
    try {
        $cases = [
            'matching version sources' => [$constants, $versions, true, ''],
            'constants version mismatch' => [
                str_replace("'4.13.3-fork'", "'4.13.4-fork'", $constants),
                $versions,
                false,
                'config/constants.php Coolify version must equal the fork publication version',
            ],
            'versions json mismatch' => [
                $constants,
                str_replace('"4.13.3-fork"', '"4.13.4-fork"', $versions),
                false,
                'versions.json Coolify v4 version must equal the fork publication version',
            ],
        ];

        foreach ($cases as $description => [$fixtureConstants, $fixtureVersions, $successful, $error]) {
            file_put_contents($fixture.'/config/constants.php', $fixtureConstants);
            file_put_contents($fixture.'/versions.json', $fixtureVersions);
            $process = new Process(['bash', '-c', $script], $root, [
                'GITHUB_WORKSPACE' => $fixture,
                'SEMANTIC_VERSION' => '4.13.3-fork',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description);
            if (! $successful) {
                expect($process->getErrorOutput())->toContain($error);
            }
        }
    } finally {
        $filesystem->remove($fixture);
    }
});

it('permits only hash-matching known recovery draft assets before semantic promotion', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $draftRecoveryRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Create or validate empty draft recovery release before semantic promotion',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-recovery-draft-prefix-'.bin2hex(random_bytes(8));
    $draft = releaseWorkflowPrepareForkDraftRecoveryDouble(
        $fixture,
        ['release-linux-amd64.env'],
    );

    try {
        $process = new Process(['bash', '-c', $draftRecoveryRun], $fixture, $draft['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and((string) file_get_contents($draft['gh_log']))->toContain('release download 4.13.1-fork')
            ->not->toContain('release create');
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects corrupt, duplicate, and unexpected recovery draft assets before semantic promotion', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $draftRecoveryRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Create or validate empty draft recovery release before semantic promotion',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $cases = [
        'corrupt' => [
            ['release-linux-amd64.env'],
            ['release-linux-amd64.env' => "corrupt\n"],
            'Fork recovery draft asset does not match the signed release bundle',
        ],
        'duplicate' => [
            ['release-linux-amd64.env', 'release-linux-amd64.env'],
            [],
            'Fork recovery draft contains duplicate or unsafe asset names',
        ],
        'unexpected' => [
            ['unexpected.txt'],
            [],
            'Fork recovery draft contains unexpected assets',
        ],
    ];

    foreach ($cases as $kind => [$draftAssets, $downloadContents, $error]) {
        $fixture = sys_get_temp_dir().'/coolify-fork-recovery-draft-'.$kind.'-'.bin2hex(random_bytes(8));
        $draft = releaseWorkflowPrepareForkDraftRecoveryDouble($fixture, $draftAssets, $downloadContents);
        try {
            $process = new Process(['bash', '-c', $draftRecoveryRun], $fixture, $draft['environment']);
            $process->run();

            expect($process->isSuccessful())->toBeFalse($kind)
                ->and($process->getErrorOutput())->toContain($error)
                ->and((string) file_get_contents($draft['gh_log']))->not->toContain('release create');
        } finally {
            $filesystem->remove($fixture);
        }
    }
});

it('accepts an exact published fork release during pre-promotion retry', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $draftRecoveryRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Create or validate empty draft recovery release before semantic promotion',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-published-recovery-'.bin2hex(random_bytes(8));
    $release = releaseWorkflowPrepareForkDraftRecoveryDouble(
        $fixture,
        ['release-linux-amd64.env', 'release-linux-arm64.env', 'SHA256SUMS'],
        isDraft: false,
    );

    try {
        $process = new Process(['bash', '-c', $draftRecoveryRun], $fixture, $release['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('accepting idempotent retry')
            ->and((string) file_get_contents($release['gh_log']))
            ->toContain('api repos/williamacallahan/coolify/commits/4.13.1-fork --jq .sha')
            ->not->toContain('release create');
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects published fork release identity, inventory, digest, and tag target mismatches', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $draftRecoveryRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Create or validate empty draft recovery release before semantic promotion',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $exactAssets = ['release-linux-amd64.env', 'release-linux-arm64.env', 'SHA256SUMS'];
    $cases = [
        'release identity' => [$exactAssets, [], str_repeat('a', 40), true, null],
        'asset inventory' => [array_slice($exactAssets, 0, 2), [], str_repeat('a', 40), false, 'do not exactly match'],
        'asset digest' => [
            $exactAssets,
            ['release-linux-amd64.env' => "corrupt\n"],
            str_repeat('a', 40),
            false,
            'does not match the signed release bundle',
        ],
        'tag target' => [$exactAssets, [], str_repeat('d', 40), false, 'does not target the immutable workflow source revision'],
    ];

    foreach ($cases as $kind => [$assets, $downloads, $tagTarget, $mutateIdentity, $error]) {
        $fixture = sys_get_temp_dir().'/coolify-fork-published-mismatch-'.str_replace(' ', '-', $kind).'-'.bin2hex(random_bytes(8));
        $release = releaseWorkflowPrepareForkDraftRecoveryDouble(
            $fixture,
            $assets,
            $downloads,
            false,
            $tagTarget,
        );
        if ($mutateIdentity) {
            $metadata = json_decode((string) file_get_contents($release['metadata']), true, flags: JSON_THROW_ON_ERROR);
            $metadata['name'] = 'Unexpected release identity';
            file_put_contents($release['metadata'], json_encode($metadata, JSON_THROW_ON_ERROR));
        }

        try {
            $process = new Process(['bash', '-c', $draftRecoveryRun], $fixture, $release['environment']);
            $process->run();

            expect($process->isSuccessful())->toBeFalse($kind)
                ->and($process->getOutput())->not->toContain('accepting idempotent retry')
                ->and((string) file_get_contents($release['gh_log']))->not->toContain('release create');
            if ($error !== null) {
                expect($process->getErrorOutput())->toContain($error);
            }
        } finally {
            $filesystem->remove($fixture);
        }
    }
});

it('reconciles ambiguous fork release publication and accepts exact published reruns', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $publicationRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Publish immutable signed fork bundles to the tag release',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $cases = [
        'ambiguous successful edit' => [true, 'ambiguous-success', 1],
        'exact published rerun' => [false, 'success', 0],
    ];

    foreach ($cases as $kind => [$isDraft, $editMode, $expectedEdits]) {
        $fixture = sys_get_temp_dir().'/coolify-fork-publication-retry-'.str_replace(' ', '-', $kind).'-'.bin2hex(random_bytes(8));
        $release = releaseWorkflowPrepareForkPublicationDouble($fixture, $isDraft, $editMode);
        try {
            $process = new Process(['bash', '-c', $publicationRun], $fixture, $release['environment']);
            $process->run();

            $ghLog = (string) file_get_contents($release['gh_log']);
            expect($process->isSuccessful())->toBeTrue($kind.': '.$process->getErrorOutput()."\n".$ghLog)
                ->and(trim((string) file_get_contents($release['state'])))->toBe('false')
                ->and(substr_count($ghLog, 'release edit 4.13.1-fork'))->toBe($expectedEdits)
                ->and($ghLog)->not->toContain('release upload')
                ->toContain('api repos/williamacallahan/coolify/commits/4.13.1-fork --jq .sha');
        } finally {
            $filesystem->remove($fixture);
        }
    }
});

it('binds tag compatibility and direct dispatch publication to an exact trusted source', function () {
    $caller = Yaml::parseFile(releaseWorkflowRepositoryRoot().'/.github/workflows/publish-fork.yml');
    $resolveTag = $caller['jobs']['resolve-tag'] ?? [];
    $resolveTagRun = (string) (releaseWorkflowStepById($resolveTag, 'version')['run'] ?? '');

    expect($resolveTag['needs'] ?? null)->toBe('application-validation')
        ->and($resolveTag['outputs']['version'] ?? null)->toBe('${{ steps.version.outputs.version }}')
        ->and($resolveTag['outputs']['source_is_tag'] ?? null)->toBe('${{ steps.version.outputs.source_is_tag }}')
        ->and($resolveTagRun)
        ->toContain("git fetch --no-tags origin '+refs/heads/v4.x:refs/remotes/origin/v4.x'")
        ->toContain("git rev-parse --verify 'refs/remotes/origin/v4.x^{commit}'")
        ->toContain('push:tag)')
        ->toContain('workflow_dispatch:branch)')
        ->toContain("[[ \"\$REF\" == 'refs/heads/v4.x' ]]")
        ->toContain('[[ "$REF_PROTECTED" == true ]]')
        ->toContain('[[ "$SOURCE_REVISION" == "$trusted_default_branch" ]]')
        ->toContain('git merge-base --is-ancestor "$SOURCE_REVISION" "$trusted_default_branch"')
        ->toContain('[[ "$constants_version" == "$versions_json_version" ]]')
        ->toContain('source_is_tag=%s');
});

it('emits the strict signed fork deploy manifest schema', function () {
    $workflow = Yaml::parseFile(releaseWorkflowRepositoryRoot().'/.github/workflows/publish-linux-image.yml');
    $manifestRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-attest'] ?? [],
        'Generate and Ed25519-sign strict fork deploy manifests',
    )['run'] ?? '');
    $expectedKeys = [
        'SCHEMA',
        'KEY_ID',
        'VERSION',
        'SOURCE_REVISION',
        'SOURCE_TAG',
        'PLATFORM',
        'MAIN_IMAGE',
        'MAIN_INDEX_DIGEST',
        'MAIN_PLATFORM_DIGEST',
        'POSTGRES_IMAGE',
        'POSTGRES_DIGEST',
        'POSTGRES_MAJOR',
        'REDIS_IMAGE',
        'REDIS_DIGEST',
        'REDIS_MAJOR',
        'MAIN_OCI_LABELS_SHA256',
        'MAIN_SBOM_SHA256',
        'MAIN_PROVENANCE_SHA256',
        'DOCKERFILE_MAIN_SHA256',
        'COMPOSE_SHA256',
        'COMPOSE_PROD_SHA256',
        'COMPOSE_OVERLAY_SHA256',
        'ENV_PRODUCTION_SHA256',
    ];
    $previousPosition = -1;
    foreach ($expectedKeys as $key) {
        $position = strpos($manifestRun, "printf '{$key}=");
        expect($position)->not->toBeFalse("Manifest key is missing: {$key}")
            ->and($position)->toBeGreaterThan($previousPosition, "Manifest key is out of order: {$key}");
        $previousPosition = $position;
    }

    expect($manifestRun)
        ->toContain("printf 'SCHEMA=coolify-fork-release/v2\\n'")
        ->not->toContain('REALTIME_')
        ->toContain('openssl pkeyutl -sign -rawin')
        ->toContain('openssl pkeyutl -verify -rawin -pubin')
        ->toContain('committed_public_key=docker/fork-release-signing-ed25519.pub')
        ->toContain('openssl pkey -pubin -in "$committed_public_key" -pubout -outform DER -out "$committed_public_der"')
        ->toContain('cmp --silent "$derived_public_der" "$committed_public_der"')
        ->toContain('key_id="sha256:$(sha256sum "$committed_public_der"')
        ->toContain("grep -Eq '^sha256:[0-9a-f]{64}$'")
        ->not->toContain('FORK_RELEASE_SIGNING_KEY_ID')
        ->toContain('release-${platform//\//-}.env')
        ->toContain('write_manifest linux/amd64')
        ->toContain('write_manifest linux/arm64')
        ->toContain('docker-compose.linux-amd64.custom.yml')
        ->toContain('docker-compose.linux-arm64.custom.yml')
        ->not->toContain('RELEASE_ASSET_MANIFEST_SHA256');
});

it('accepts an idempotent fork semantic promotion without overwriting its matching image', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-split-recovery-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble(
        $fixture,
        releaseWorkflowTestDigest('3'),
    );

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        $registryLog = (string) file_get_contents($registry['log']);
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('accepting recovery state')
            ->and($registryLog)
            ->not->toContain('image copy docker.iocloudhost.net/williamagh/coolify@'.releaseWorkflowTestDigest('3').' docker.iocloudhost.net/williamagh/coolify:4.13.1-fork')
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-latest')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-4.13.1-fork')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-4.13.1-fork-aaaaaaa')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(substr_count((string) file_get_contents($registry['curl_log']), '/git/ref/tags/4.13.1-fork'))->toBe(2)
            ->and(substr_count((string) file_get_contents($registry['curl_log']), 'rulesets?targets=tag&includes_parents=true&per_page=100'))->toBe(2);
    } finally {
        $filesystem->remove($fixture);
    }
});

it('publishes and verifies every direct protected-v4x fork registry alias without a GitHub tag', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-direct-registry-publication-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    $registry['environment']['GITHUB_EVENT_NAME'] = 'workflow_dispatch';
    $registry['environment']['GITHUB_REF'] = 'refs/heads/v4.x';
    $registry['environment']['GITHUB_REF_PROTECTED'] = 'true';
    $registry['environment']['GITHUB_REF_TYPE'] = 'branch';
    $registry['environment']['SOURCE_IS_TAG'] = 'false';

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and((string) file_get_contents($registry['curl_log']))->toBe('')
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-latest')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-4.13.1-fork')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/fork-4.13.1-fork-aaaaaaa')))->toBe(releaseWorkflowTestDigest('3'));
    } finally {
        $filesystem->remove($fixture);
    }
});

it('fails closed on missing fork tag protection before any semantic registry write', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-ruleset-rejection-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    $registry['environment']['FORK_RULESET_STATE'] = 'missing-deletion';

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('creation, update, and deletion controls')
            ->and((string) file_get_contents($registry['log']))->not->toContain('image copy')
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe('absent');
    } finally {
        $filesystem->remove($fixture);
    }
});

it('fails closed when the live fork tag no longer resolves to the workflow source revision', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-live-tag-rejection-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    $registry['environment']['FORK_LIVE_TAG_STATE'] = 'mismatch';

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('no longer resolves to the immutable workflow source revision')
            ->and((string) file_get_contents($registry['log']))->not->toContain('image copy')
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe('absent');
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects a mismatched fork semantic tag before any registry write', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-mismatch-rejection-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble(
        $fixture,
        releaseWorkflowTestDigest('f'),
    );

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('does not match its expected immutable index digest')
            ->and((string) file_get_contents($registry['log']))->not->toContain('image copy')
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe(releaseWorkflowTestDigest('f'));
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects a mismatched canonical fork identity tag before updating fork latest', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-canonical-mismatch-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    file_put_contents($registry['state'].'/fork-4.13.1-fork', releaseWorkflowTestDigest('f')."\n");

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('refusing release-identity overwrite')
            ->and((string) file_get_contents($registry['log']))->not->toContain('image copy')
            ->and(file_exists($registry['state'].'/fork-latest'))->toBeFalse()
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe('absent')
            ->and(trim((string) file_get_contents($registry['state'].'/fork-4.13.1-fork')))
            ->toBe(releaseWorkflowTestDigest('f'));
    } finally {
        $filesystem->remove($fixture);
    }
});

it('refuses to move fork latest backward after a newer release has won serialization', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-latest-rollback-refusal-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    file_put_contents($registry['state'].'/fork-latest', releaseWorkflowTestDigest('f')."\n");
    $registry['environment']['FORK_LATEST_VERSION'] = '4.13.2-fork';

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Refusing to move fork-latest backward or sideways from 4.13.2-fork to 4.13.1-fork')
            ->and((string) file_get_contents($registry['log']))->not->toContain('image copy')
            ->and(trim((string) file_get_contents($registry['state'].'/fork-latest')))->toBe(releaseWorkflowTestDigest('f'))
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe('absent');
    } finally {
        $filesystem->remove($fixture);
    }
});

it('moves fork latest forward after an older release has won serialization', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $promotionRun = (string) (releaseWorkflowStep(
        $workflow['jobs']['fork-release'] ?? [],
        'Promote and verify the main fork image',
    )['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-fork-latest-forward-'.bin2hex(random_bytes(8));
    $registry = releaseWorkflowPrepareForkPromotionRegistryDouble($fixture, null);
    file_put_contents($registry['state'].'/fork-latest', releaseWorkflowTestDigest('f')."\n");
    $registry['environment']['FORK_LATEST_VERSION'] = '4.13.0-fork';

    try {
        $process = new Process(['bash', '-c', $promotionRun], $root, $registry['environment']);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(trim((string) file_get_contents($registry['state'].'/fork-latest')))->toBe(releaseWorkflowTestDigest('3'))
            ->and(trim((string) file_get_contents($registry['state'].'/main')))->toBe(releaseWorkflowTestDigest('3'));
    } finally {
        $filesystem->remove($fixture);
    }
});
