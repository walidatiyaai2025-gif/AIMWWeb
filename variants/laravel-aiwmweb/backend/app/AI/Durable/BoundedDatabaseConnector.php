<?php

namespace App\AI\Durable;

use Illuminate\Queue\Connectors\DatabaseConnector;

final class BoundedDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new BoundedDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null,
        );
    }
}
