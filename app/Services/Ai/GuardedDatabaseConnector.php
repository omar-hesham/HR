<?php

namespace App\Services\Ai;

use Illuminate\Database\Connection;
use Illuminate\Queue\Connectors\DatabaseConnector;

class GuardedDatabaseConnector extends DatabaseConnector
{
    /**
     * @param array<string,mixed> $config */
    public function connect(array $config)
    {
        $connection = $this->connections->connection($config['connection'] ?? null);
        if (! ($connection instanceof Connection)) {
            throw new \RuntimeException('ai_database_connection_required');
        }

        return new GuardedDatabaseQueue($connection, $config['table'], $config['queue'], $config['retry_after'], $config['after_commit'] ?? false);
    }
}
