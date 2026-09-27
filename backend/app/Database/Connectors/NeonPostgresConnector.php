<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\PostgresConnector;

class NeonPostgresConnector extends PostgresConnector
{
    /**
     * Add Neon's endpoint identifier for PostgreSQL clients without SNI support.
     */
    protected function getDsn(array $config)
    {
        $dsn = parent::getDsn($config);
        $endpoint = $config['neon_endpoint'] ?? null;

        if (is_string($endpoint) && preg_match('/^ep-[a-z0-9-]+$/', $endpoint)) {
            $dsn .= ";options='endpoint={$endpoint}'";
        }

        return $dsn;
    }
}
