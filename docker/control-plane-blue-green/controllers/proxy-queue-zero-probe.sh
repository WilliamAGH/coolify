#!/usr/bin/env bash

set -Eeuo pipefail

fail()
{
    printf 'CONTROL_PLANE_PROXY_QUEUE_PROBE_FAILURE %s\n' "$1" >&2
    exit 1
}

container=${CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER:-}
operation_id=${CONTROL_PLANE_RUNTIME_OPERATION_ID:-}
[[ $container =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
    && $operation_id =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
    || fail 'queue probe configuration is malformed'

counts=$(docker exec "$container" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (App\Support\ProxyMutationQueue::NAME !== "proxy-mutations") {
        throw new UnexpectedValueException("The proxy-mutation queue name drifted");
    }
    $snapshot = App\Support\ProxyMutationQueueState::snapshot();
    printf(
        "%d %d %d %d\n",
        $snapshot["pending"],
        $snapshot["reserved"],
        $snapshot["delayed"],
        $snapshot["running"],
    );
') || fail 'Laravel Redis proxy-queue inspection failed'

[[ $counts =~ ^[0-9]+\ [0-9]+\ [0-9]+\ [0-9]+$ ]] || fail 'proxy-queue counts are malformed'
read -r pending reserved delayed running <<< "$counts"
printf 'version=2\npending=%s\nreserved=%s\ndelayed=%s\nrunning=%s\nobserved_at_epoch=%s\noperation_id=%s\n' \
    "$pending" "$reserved" "$delayed" "$running" "$(date -u +%s)" "$operation_id"
