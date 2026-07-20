<?php

use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ExtractControlPlaneDynamicFragments;
use Symfony\Component\Yaml\Yaml;

/**
 * @param  array<string, mixed>  $document
 */
function controlPlaneDynamicSnapshot(array $document): string
{
    return Yaml::dump($document, 20, 2);
}

it('extracts deterministic realtime and terminal fragments with their local dependencies', function (): void {
    $snapshot = controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'terminal-ws' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                    'entryPoints' => ['https'],
                    'service' => 'terminal-route',
                    'middlewares' => ['terminal-security'],
                    'tls' => ['certResolver' => 'letsencrypt'],
                ],
                'realtime-ws' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                    'entryPoints' => ['http'],
                    'service' => 'realtime-route',
                    'middlewares' => ['realtime-chain'],
                ],
            ],
            'services' => [
                'terminal-route' => [
                    'weighted' => [
                        'services' => [
                            ['name' => 'terminal-backend', 'weight' => 1],
                        ],
                    ],
                ],
                'terminal-backend' => [
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://coolify:6002']],
                    ],
                ],
                'realtime-route' => [
                    'middlewares' => ['service-header'],
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://coolify:6001']],
                    ],
                ],
                'error-pages' => [
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://coolify:8080/errors']],
                    ],
                ],
            ],
            'middlewares' => [
                'realtime-chain' => [
                    'chain' => [
                        'middlewares' => ['gzip', 'error-pages'],
                    ],
                ],
                'gzip' => ['compress' => true],
                'error-pages' => [
                    'errors' => [
                        'service' => 'error-pages',
                        'status' => ['500-599'],
                    ],
                ],
                'service-header' => [
                    'headers' => ['customRequestHeaders' => ['X-Service-Route' => 'realtime']],
                ],
                'terminal-security' => [
                    'headers' => ['customRequestHeaders' => ['X-Terminal-Route' => 'true']],
                ],
            ],
        ],
    ]);

    $fragments = ExtractControlPlaneDynamicFragments::run($snapshot);
    $compiled = CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'coolify',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        healthCheckProof: str_repeat('b', 64),
        realtimeRouterFragments: $fragments['realtimeRouterFragments'],
        terminalRouterFragments: $fragments['terminalRouterFragments'],
        preservedServices: $fragments['preservedServices'],
        preservedMiddlewares: $fragments['preservedMiddlewares'],
    );
    $compiledDocument = Yaml::parse($compiled->yaml);

    expect($fragments)->toBe(ExtractControlPlaneDynamicFragments::run($snapshot))
        ->and($fragments['realtimeRouterFragments'])->toBe([
            'realtime-ws' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                'entryPoints' => ['http'],
                'service' => 'realtime-route',
                'middlewares' => ['realtime-chain'],
            ],
        ])
        ->and($fragments['terminalRouterFragments'])->toBe([
            'terminal-ws' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                'entryPoints' => ['https'],
                'service' => 'terminal-route',
                'middlewares' => ['terminal-security'],
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ])
        ->and(array_keys($fragments['preservedServices']))->toBe([
            'error-pages',
            'realtime-route',
            'terminal-backend',
            'terminal-route',
        ])
        ->and(array_keys($fragments['preservedMiddlewares']))->toBe([
            'error-pages',
            'gzip',
            'realtime-chain',
            'service-header',
            'terminal-security',
        ])
        ->and($fragments['preservedServices']['terminal-route']['weighted']['services'])
        ->toBe([['name' => 'terminal-backend', 'weight' => 1]])
        ->and($fragments['preservedMiddlewares']['realtime-chain']['chain']['middlewares'])
        ->toBe(['gzip', 'error-pages'])
        ->and($compiledDocument['http']['routers']['realtime-ws'])
        ->toBe($fragments['realtimeRouterFragments']['realtime-ws'])
        ->and($compiledDocument['http']['routers']['terminal-ws'])
        ->toBe($fragments['terminalRouterFragments']['terminal-ws'])
        ->and($compiledDocument['http']['services']['terminal-route'])
        ->toBe($fragments['preservedServices']['terminal-route'])
        ->and($compiledDocument['http']['middlewares']['realtime-chain'])
        ->toBe($fragments['preservedMiddlewares']['realtime-chain']);
});

it('returns empty maps when the optional websocket routes are absent', function (): void {
    $fragments = ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'dashboard' => [
                    'rule' => 'Host(`dashboard.example.test`)',
                    'entryPoints' => ['https'],
                    'service' => 'dashboard',
                ],
            ],
        ],
    ]));

    expect($fragments)->toBe([
        'realtimeRouterFragments' => [],
        'terminalRouterFragments' => [],
        'preservedServices' => [],
        'preservedMiddlewares' => [],
    ]);
});

it('migrates exact legacy realtime container backends while preserving enrolled route names', function (): void {
    $fragments = ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'coolify-realtime-wss' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'coolify-realtime',
                ],
                'coolify-reverb-api-https' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/apps`)',
                    'entryPoints' => ['https'],
                    'service' => 'coolify-realtime',
                ],
                'coolify-terminal-wss' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                    'entryPoints' => ['https'],
                    'service' => 'coolify-terminal',
                ],
            ],
            'services' => [
                'coolify-realtime' => [
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://coolify-realtime:6001']],
                    ],
                ],
                'coolify-terminal' => [
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://coolify-realtime:6002']],
                    ],
                ],
            ],
        ],
    ]));
    $compiled = CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'coolify',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        healthCheckProof: str_repeat('b', 64),
        realtimeRouterFragments: $fragments['realtimeRouterFragments'],
        terminalRouterFragments: $fragments['terminalRouterFragments'],
        preservedServices: $fragments['preservedServices'],
        preservedMiddlewares: $fragments['preservedMiddlewares'],
    );
    $compiledDocument = Yaml::parse($compiled->yaml);

    expect($fragments['realtimeRouterFragments'])->toHaveKey('coolify-realtime-wss')
        ->toHaveKey('coolify-reverb-api-https')
        ->and($fragments['terminalRouterFragments'])->toHaveKey('coolify-terminal-wss')
        ->and($fragments['preservedServices']['coolify-realtime']['loadBalancer']['servers'])
        ->toBe([['url' => 'http://coolify:6001']])
        ->and($fragments['preservedServices']['coolify-terminal']['loadBalancer']['servers'])
        ->toBe([['url' => 'http://coolify:6002']])
        ->and($compiledDocument['http']['routers']['coolify-realtime-wss'])
        ->toBe($fragments['realtimeRouterFragments']['coolify-realtime-wss'])
        ->and($compiledDocument['http']['routers']['coolify-reverb-api-https'])
        ->toBe($fragments['realtimeRouterFragments']['coolify-reverb-api-https'])
        ->and($compiledDocument['http']['routers']['coolify-terminal-wss'])
        ->toBe($fragments['terminalRouterFragments']['coolify-terminal-wss']);
});

it('rejects malformed, duplicate, and incomplete source configuration', function (): void {
    expect(fn (): array => ExtractControlPlaneDynamicFragments::run("http:\n  routers: [\n"))
        ->toThrow(InvalidArgumentException::class, 'invalid');

    $duplicateRouterSnapshot = <<<'YAML'
http:
  routers:
    realtime:
      rule: PathPrefix(`/app`)
      entryPoints: [https]
      service: realtime
    realtime:
      rule: PathPrefix(`/app`)
      entryPoints: [https]
      service: realtime
YAML;

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run($duplicateRouterSnapshot))
        ->toThrow(InvalidArgumentException::class, 'invalid');

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                ],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'incomplete');
});

it('rejects missing and unsafe File-provider dependencies while retaining explicit external references', function (): void {
    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'missing',
                ],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'missing');

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime@file',
                ],
            ],
            'services' => [
                'realtime@docker' => [
                    'loadBalancer' => ['servers' => [['url' => 'http://coolify:6001']]],
                ],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'crosses providers');

    $externalFragments = ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime@docker',
                    'middlewares' => ['auth@docker'],
                ],
            ],
        ],
    ]));

    expect($externalFragments['realtimeRouterFragments']['realtime']['service'])->toBe('realtime@docker')
        ->and($externalFragments['realtimeRouterFragments']['realtime']['middlewares'])->toBe(['auth@docker'])
        ->and($externalFragments['preservedServices'])->toBe([])
        ->and($externalFragments['preservedMiddlewares'])->toBe([]);
});

it('rejects ambiguous route matches and managed-name collisions', function (): void {
    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'ambiguous' => [
                    'rule' => 'PathPrefix(`/app`) || PathPrefix(`/terminal/ws`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime',
                ],
            ],
            'services' => [
                'realtime' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify:6001']]]],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'ambiguously');

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'coolify-app-port' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime',
                ],
            ],
            'services' => [
                'realtime' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify:6001']]]],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'managed');

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'coolify-control-plane',
                ],
            ],
            'services' => [
                'coolify-control-plane' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify:8080']]]],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'managed');

    expect(fn (): array => ExtractControlPlaneDynamicFragments::run(controlPlaneDynamicSnapshot([
        'http' => [
            'routers' => [
                'realtime' => [
                    'rule' => 'PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime',
                    'middlewares' => ['coolify-control-plane-identity'],
                ],
            ],
            'services' => [
                'realtime' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify:6001']]]],
            ],
            'middlewares' => [
                'coolify-control-plane-identity' => ['headers' => []],
            ],
        ],
    ])))->toThrow(InvalidArgumentException::class, 'managed');
});
