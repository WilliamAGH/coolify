<?php

use App\Support\ControlPlaneMode;

return [
    'mode' => ControlPlaneMode::fromEnvironment()->value,
    'startup_mode' => env('CONTROL_PLANE_STARTUP_MODE', 'full'),
    'writer_epoch' => env('CONTROL_PLANE_WRITER_EPOCH'),
    'writer_marker_path' => env('CONTROL_PLANE_WRITER_MARKER_PATH'),
    'web_epoch' => env('CONTROL_PLANE_WEB_EPOCH'),
    'web_marker_path' => env('CONTROL_PLANE_WEB_MARKER_PATH'),
    'route_drain_epoch' => env('CONTROL_PLANE_ROUTE_DRAIN_EPOCH'),
    'route_drain_marker_path' => env('CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH'),
    'mutation_freeze_epoch' => env('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'mutation_freeze_marker_path' => env('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
    'proxy_mutation_operation_lock_seconds' => 43200,
    'proxy_mutation_operation_lock_wait_seconds' => 36000,
    'trusted_proxy_addresses' => env('CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES'),
    'direct_probe_token_path' => '/run/secrets/control-plane-direct-probe-token',
    'route_health_token_path' => '/run/secrets/control-plane-route-health-token',
    'applied_ack_path' => '/run/secrets/control-plane-applied-ack',
    'pool_ack_path' => env('CONTROL_PLANE_POOL_ACK_PATH'),
    'member_id' => env('CONTROL_PLANE_MEMBER_ID'),
];
