#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly TEST_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly PROBE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/proxy-queue-zero-probe.sh
readonly FIXTURE_APP=$REPOSITORY_ROOT/docker/control-plane-blue-green/fixtures/proxy-queue-runtime-probe
readonly QUEUE_PREFIX='runtime-fence:'
readonly REDIS_IMAGE='redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99'

fail()
{
    printf 'CONTROL_PLANE_PROXY_QUEUE_PROBE_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

REAL_DOCKER=$(command -v docker) || fail 'Docker CLI is required for the proxy queue probe test'
readonly REAL_DOCKER
"$REAL_DOCKER" image inspect "$REDIS_IMAGE" >/dev/null \
    || fail 'the pinned Redis fixture image is not present'
php -m | grep -Fx redis >/dev/null \
    || fail 'the PHP Redis extension is required for the proxy queue probe test'

fixture=$(mktemp -d "${TMPDIR:-/tmp}/control-plane-proxy-queue.XXXXXX")
redis_container=
redis_port=

cleanup()
{
    if [[ -n $redis_container ]]; then
        "$REAL_DOCKER" rm --force "$redis_container" >/dev/null 2>&1 || true
    fi
    rm -rf "$fixture"
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$fixture/bin"
ln -s "$FIXTURE_APP/docker" "$fixture/bin/docker"
export PATH=$fixture/bin:$PATH
export CONTROL_PLANE_QUEUE_FIXTURE_REPOSITORY_ROOT=$REPOSITORY_ROOT
export CONTROL_PLANE_QUEUE_FIXTURE_QUEUE_PREFIX=$QUEUE_PREFIX
export APP_CONFIG_CACHE=$fixture/config.php
export APP_ENV=testing
export CONTROL_PLANE_MODE=active
export QUEUE_CONNECTION=redis
export REDIS_CLIENT=phpredis
export REDIS_HOST=127.0.0.1
export REDIS_DB=0
export REDIS_PREFIX=$QUEUE_PREFIX
export HORIZON_PREFIX=$QUEUE_PREFIX

start_real_redis()
{
    redis_container="control-plane-proxy-queue-redis-${PPID}-${BASHPID}"
    "$REAL_DOCKER" run --detach --pull never --name "$redis_container" \
        --publish 127.0.0.1::6379 --tmpfs /data:rw,mode=0700 \
        "$REDIS_IMAGE" redis-server --save '' --appendonly no >/dev/null \
        || fail 'the pinned Redis fixture could not start'
    redis_port=$("$REAL_DOCKER" port "$redis_container" 6379/tcp | awk -F: 'NR == 1 { print $NF }')
    [[ $redis_port =~ ^[1-9][0-9]{0,4}$ ]] || fail 'the pinned Redis fixture did not publish a valid port'

    for _ in {1..50}; do
        if "$REAL_DOCKER" exec "$redis_container" redis-cli --raw ping 2>/dev/null | grep -Fxq PONG; then
            export CONTROL_PLANE_QUEUE_FIXTURE_REDIS_PORT=$redis_port
            export REDIS_PORT=$redis_port
            return
        fi
        sleep 0.05
    done

    fail 'the pinned Redis fixture did not become ready'
}

redis()
{
    "$REAL_DOCKER" exec "$redis_container" redis-cli --raw "$@"
}

run_probe()
{
    CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER=candidate \
        CONTROL_PLANE_RUNTIME_OPERATION_ID=proxy-queue-test \
        "$PROBE"
}

reset_queues()
{
    redis flushdb >/dev/null
}

assert_counts()
{
    local expected_pending=$1 expected_reserved=$2 expected_delayed=$3 expected_running=$4 description=$5 output
    output=$(run_probe) || fail "$description did not complete"
    grep -F -x -q 'version=2' <<< "$output" || fail "$description returned the wrong probe version"
    grep -F -x -q "pending=$expected_pending" <<< "$output" || fail "$description returned the wrong pending count"
    grep -F -x -q "reserved=$expected_reserved" <<< "$output" || fail "$description returned the wrong reserved count"
    grep -F -x -q "delayed=$expected_delayed" <<< "$output" || fail "$description returned the wrong delayed count"
    grep -F -x -q "running=$expected_running" <<< "$output" || fail "$description returned the wrong running count"
}

assert_marker_only_queue_contract()
{
    local output
    output=$(docker exec candidate php -r '
        require "/var/www/html/vendor/autoload.php";
        $app = require "/var/www/html/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $queue = app(Illuminate\Queue\QueueManager::class)->connection(App\Support\ProxyMutationQueue::CONNECTION);
        if (! $queue instanceof App\Support\ProxyMutationRedisQueue) {
            throw new LogicException("The canonical queue did not resolve through the marker gate.");
        }

        $queueKey = $queue->getQueue(App\Support\ProxyMutationQueue::NAME);
        $ordinary = (new ReflectionClass(App\Jobs\CoolifyTask::class))->newInstanceWithoutConstructor();
        try {
            $queue->push($ordinary, "", App\Support\ProxyMutationQueue::NAME);
            throw new LogicException("Unmarked work reached the canonical queue.");
        } catch (LogicException $exception) {
            if (! str_contains($exception->getMessage(), "only accepts marker-backed work")) {
                throw $exception;
            }
        }
        if ($queue->getConnection()->llen($queueKey) !== 0) {
            throw new LogicException("Rejected unmarked work changed physical Redis state.");
        }

        $marked = new App\Jobs\CleanupStuckedResourcesJob;
        $queue->push($marked, "", App\Support\ProxyMutationQueue::NAME);
        $raw = $queue->getConnection()->lindex($queueKey, 0);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $mutationMetadata = array_values(array_filter(
            array_keys($decoded),
            static fn (string $key): bool => str_starts_with($key, "coolifyProxyMutation"),
        ));
        if (($decoded[App\Support\ProxyMutationQueue::PAYLOAD_MARKER] ?? null) !== true
            || $mutationMetadata !== [App\Support\ProxyMutationQueue::PAYLOAD_MARKER]) {
            throw new LogicException("The canonical payload is not marker-only.");
        }

        try {
            $queue->pushRaw($raw, App\Support\ProxyMutationQueue::NAME);
            throw new LogicException("A fabricated public raw write was accepted.");
        } catch (LogicException $exception) {
            if (! str_contains($exception->getMessage(), "failed Horizon origin")) {
                throw $exception;
            }
        }

        $reserved = $queue->pop(App\Support\ProxyMutationQueue::NAME);
        if (! $reserved instanceof Illuminate\Queue\Jobs\RedisJob) {
            throw new LogicException("The canonical marker payload was not reserved.");
        }
        $marked->setJob($reserved);
        $handled = false;
        App\Support\ControlPlaneMode::withMutationDrainLease(
            $reserved,
            static function () use (&$handled): void {
                $handled = true;
            },
            $marked,
        );
        if (! $handled) {
            throw new LogicException("A genuine marker reservation was not admitted.");
        }
        $reserved->delete();

        $tamperCommand = new App\Jobs\CleanupStuckedResourcesJob;
        $queue->push($tamperCommand, "", App\Support\ProxyMutationQueue::NAME);
        $tamperedJob = $queue->pop(App\Support\ProxyMutationQueue::NAME);
        if (! $tamperedJob instanceof Illuminate\Queue\Jobs\RedisJob) {
            throw new LogicException("The tamper fixture was not reserved.");
        }
        $tamperCommand->setJob($tamperedJob);
        $tamperedPayload = json_decode($tamperedJob->getReservedJob(), true, flags: JSON_THROW_ON_ERROR);
        $tamperedPayload["id"] = "tampered-id";
        $tamperedPayload = json_encode($tamperedPayload, JSON_THROW_ON_ERROR);
        $reservedQueue = $queueKey.":reserved";
        $queue->getConnection()->zrem($reservedQueue, $tamperedJob->getReservedJob());
        $queue->getConnection()->zadd($reservedQueue, time() + 60, $tamperedPayload);
        (new ReflectionProperty(Illuminate\Queue\Jobs\RedisJob::class, "reserved"))
            ->setValue($tamperedJob, $tamperedPayload);

        try {
            App\Support\ControlPlaneMode::withMutationDrainLease(
                $tamperedJob,
                static fn (): null => null,
                $tamperCommand,
            );
            throw new LogicException("A changed marker reservation was admitted.");
        } catch (App\Exceptions\ControlPlaneMutationLockedException) {
        }

        $queue->clear(App\Support\ProxyMutationQueue::NAME);
        printf("%s%c", "PROXY_MARKER_ONLY", 10);
    ') || fail 'marker-only real Redis fixture failed'

    grep -F -x -q 'PROXY_MARKER_ONLY' <<< "$output" \
        || fail 'marker-only real Redis fixture did not prove its contract'
}

start_real_redis
reset_queues
assert_counts 0 0 0 0 'empty authoritative queue'
assert_marker_only_queue_contract
assert_counts 0 0 0 0 'remediated marker queue'

redis rpush "${QUEUE_PREFIX}queues:proxy-mutations" '{"id":"unmarked","uuid":"unmarked","attempts":0}' >/dev/null
if run_probe > "$fixture/unmarked.out" 2>&1; then
    fail 'queue-zero accepted an unmarked physical payload'
fi
grep -F -q 'not marker-backed' "$fixture/unmarked.out" \
    || fail 'unmarked physical payload did not fail for the marker boundary'
reset_queues

docker exec candidate php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $queue = app(Illuminate\Queue\QueueManager::class)->connection("redis");
    $queue->push(new App\Jobs\CleanupStuckedResourcesJob, "", App\Support\ProxyMutationQueue::NAME);
    $queue->push(new App\Jobs\CleanupStuckedResourcesJob, "", App\Support\ProxyMutationQueue::NAME);
    $queue->later(3600, new App\Jobs\CleanupStuckedResourcesJob, "", App\Support\ProxyMutationQueue::NAME);
    $queue->pop(App\Support\ProxyMutationQueue::NAME);
' || fail 'marker physical-state fixture failed'
assert_counts 1 1 1 1 'all authoritative marker physical states'

redis zadd "${QUEUE_PREFIX}pending_jobs" 1 horizon-orphan >/dev/null
redis hset "${QUEUE_PREFIX}horizon-orphan" status failed >/dev/null
assert_counts 1 1 1 1 'stale Horizon metadata is observational only'

reset_queues
assert_counts 0 0 0 0 'queue-zero ignores absent Horizon metadata'

if CONTROL_PLANE_RUNTIME_OPERATION_ID='invalid operation!' \
    CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER=candidate "$PROBE" > "$fixture/invalid.out" 2>&1; then
    fail 'malformed queue probe operation was accepted'
fi
grep -F -q 'queue probe configuration is malformed' "$fixture/invalid.out" \
    || fail 'malformed queue probe operation did not fail closed'

printf 'CONTROL_PLANE_PROXY_QUEUE_PROBE_TEST PASS\n'
