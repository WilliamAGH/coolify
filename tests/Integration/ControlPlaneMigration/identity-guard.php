<?php

declare(strict_types=1);

/**
 * @return array{system_identifier: string, database: string, address: string, port: int}
 */
function databaseIdentity(string $dsn, string $username, string $password): array
{
    $connection = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $connection->query(<<<'SQL'
        SELECT system_identifier::text,
               current_database(),
               inet_server_addr()::text,
               inet_server_port()
        FROM pg_control_system()
        SQL)->fetch(PDO::FETCH_ASSOC);

    if (! is_array($identity)
        || ! is_string($identity['system_identifier'] ?? null)
        || ! is_string($identity['current_database'] ?? null)
        || ! is_string($identity['inet_server_addr'] ?? null)
        || ! is_int($identity['inet_server_port'] ?? null)) {
        throw new RuntimeException('PostgreSQL returned an incomplete database identity.');
    }

    return [
        'system_identifier' => $identity['system_identifier'],
        'database' => $identity['current_database'],
        'address' => $identity['inet_server_addr'],
        'port' => $identity['inet_server_port'],
    ];
}

$sourceDsn = getenv('CONTROL_PLANE_SOURCE_DSN');
$rehearsalDsn = getenv('CONTROL_PLANE_REHEARSAL_DSN');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
if (! is_string($sourceDsn) || ! is_string($rehearsalDsn) || ! is_string($username) || ! is_string($password)) {
    fwrite(STDERR, "Database identity environment is incomplete.\n");
    exit(64);
}

try {
    $sourceIdentity = databaseIdentity($sourceDsn, $username, $password);
    $rehearsalIdentity = databaseIdentity($rehearsalDsn, $username, $password);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Database identity lookup failed: {$throwable->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, 'SOURCE_IDENTITY '.json_encode($sourceIdentity, JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, 'REHEARSAL_IDENTITY '.json_encode($rehearsalIdentity, JSON_THROW_ON_ERROR)."\n");

if ($sourceIdentity === $rehearsalIdentity) {
    fwrite(STDERR, "Rehearsal database identity equals the source database identity.\n");
    exit(2);
}

fwrite(STDOUT, "DATABASE_IDENTITIES_DISTINCT\n");
