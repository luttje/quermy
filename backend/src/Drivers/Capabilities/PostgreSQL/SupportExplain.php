<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportExplain
{
    public function explainQuery(string $database, string $sql): array
    {
        $this->ensureConnected();
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('explainQuery only accepts SELECT statements.');
        }
        if (preg_match('/;\s*\S/', rtrim($sql, "; \t\n\r"))) {
            throw new RuntimeException('explainQuery accepts only a single statement.');
        }
        $stmt = $this->pdo->query('EXPLAIN (FORMAT JSON) ' . $sql);
        $rows = $stmt->fetchAll();
        // PostgreSQL EXPLAIN (FORMAT JSON) returns one row with a JSON string.
        if (isset($rows[0]['QUERY PLAN'])) {
            $decoded = json_decode($rows[0]['QUERY PLAN'], true);
            return is_array($decoded) ? $decoded : $rows;
        }
        return $rows;
    }
}
