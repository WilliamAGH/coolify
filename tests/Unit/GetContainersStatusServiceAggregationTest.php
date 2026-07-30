<?php

/**
 * Unit tests for GetContainersStatus service aggregation logic (SSH path).
 *
 * These tests verify that the SSH-based status updates (GetContainersStatus)
 * correctly aggregates container statuses for services with multiple containers,
 * using the same logic as PushServerUpdateJob (Sentinel path).
 *
 * This ensures consistency across both status update paths and prevents
 * race conditions where the last container processed wins.
 */
it('implements service multi-container aggregation in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service container collection property exists
    expect($actionFile)
        ->toContain('protected ?Collection $serviceContainerStatuses;');

    // Verify aggregateServiceContainerStatuses method exists
    expect($actionFile)
        ->toContain('private function aggregateServiceContainerStatuses($services)')
        ->toContain('$this->aggregateServiceContainerStatuses($services);');

    // Verify service aggregation delegates to the canonical aggregator
    expect($actionFile)
        ->toContain('$aggregator = new ContainerStatusAggregator;');
});

it('services use same priority as applications in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Application and service aggregation must both delegate to ContainerStatusAggregator,
    // which owns the shared priority state machine.
    expect(substr_count($actionFile, '$aggregator = new ContainerStatusAggregator;'))->toBeGreaterThanOrEqual(2);
    expect(substr_count($actionFile, '$aggregator->aggregateFromStrings($relevantStatuses'))->toBeGreaterThanOrEqual(2);
});

it('collects service containers before aggregating in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service containers are collected, not immediately updated
    expect($actionFile)
        ->toContain('$key = $serviceLabelId.\':\'.$subType.\':\'.$subId;')
        ->toContain('$this->serviceContainerStatuses->get($key)->put($containerName, $containerStatus);');

    // Verify aggregation happens before ServiceChecked dispatch
    expect($actionFile)
        ->toContain('$this->aggregateServiceContainerStatuses($services);')
        ->toContain('ServiceChecked::dispatch($this->server->team->id);');
});

it('SSH and Sentinel paths use identical service aggregation logic', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both must delegate the priority state machine to the canonical aggregator
    expect($jobFile)->toContain('$aggregator = new ContainerStatusAggregator;');
    expect($actionFile)->toContain('$aggregator = new ContainerStatusAggregator;');

    // Both must aggregate the filtered (non-excluded) container statuses
    expect($jobFile)->toContain('$aggregator->aggregateFromStrings($relevantStatuses');
    expect($actionFile)->toContain('$aggregator->aggregateFromStrings($relevantStatuses');

    // Both must preserve "Restarting" for individual sub-resources
    expect($jobFile)->toContain('preserveRestarting: true');
    expect($actionFile)->toContain('preserveRestarting: true');
});

it('handles service status updates consistently', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should parse service key with same format
    expect($jobFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');
    expect($actionFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');

    // Both should handle excluded containers through the shared compose-derived exclusion list
    expect($jobFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
    expect($actionFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});
