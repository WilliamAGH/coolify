<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Laravel\Horizon\Connectors\RedisConnector;

class ProxyMutationRedisConnector extends RedisConnector
{
    #[\Override]
    public function connect(array $config)
    {
        return new ProxyMutationRedisQueue(
            $this->redis,
            $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null),
        );
    }
}
