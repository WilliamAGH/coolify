<?php

declare(strict_types=1);

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$database = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
if (! is_string($host)
    || ! is_string($port)
    || ! is_string($database)
    || ! is_string($username)
    || ! is_string($password)) {
    fwrite(STDERR, "Database attestation environment is incomplete.\n");
    exit(64);
}

try {
    $connection = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $identity = $connection->query(<<<'SQL'
        SELECT
            (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
            current_database() AS database_name,
            host(inet_server_addr()) AS server_address,
            inet_server_port() AS server_port,
            (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS database_oid
        SQL)->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Database attestation lookup failed: {$throwable->getMessage()}\n");
    exit(1);
}

if (! is_array($identity)
    || ! is_string($identity['system_identifier'] ?? null)
    || ! is_string($identity['database_name'] ?? null)
    || ! is_string($identity['server_address'] ?? null)
    || ! is_int($identity['server_port'] ?? null)
    || ! is_string($identity['database_oid'] ?? null)) {
    fwrite(STDERR, "PostgreSQL returned an incomplete database attestation.\n");
    exit(65);
}

$instanceMarker = hash('sha256', "{$identity['system_identifier']}:{$identity['database_oid']}");
$identityPayload = implode(';', [
    "system_identifier={$identity['system_identifier']}",
    "database={$identity['database_name']}",
    "server_address={$identity['server_address']}",
    "server_port={$identity['server_port']}",
    "instance_marker={$instanceMarker}",
]);

fwrite(STDOUT, "system_identifier={$identity['system_identifier']}\n");
fwrite(STDOUT, 'identity_sha256='.hash('sha256', $identityPayload)."\n");
fwrite(STDOUT, "identity_payload={$identityPayload}\n");
