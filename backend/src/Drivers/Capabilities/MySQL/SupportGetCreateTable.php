<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportGetCreateTable
{
    public function getCreateTable(string $database, string $table): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot get create table for $database");
        }

        $this->ensureConnected();
        $database = $this->validateIdent($database);
        $table    = $this->validateIdent($table);

        $stmt = $this->pdo->query("SHOW CREATE TABLE `$database`.`$table`");
        $row  = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Table not found: $database.$table");
        }
        // SHOW CREATE TABLE returns either ['Table' => ..., 'Create Table' => DDL]
        // or for views ['View' => ..., 'Create View' => DDL]. Pick whichever
        // "Create *" key is present.
        foreach ($row as $key => $val) {
            if (str_starts_with((string)$key, 'Create ')) {
                return (string)$val;
            }
        }
        throw new RuntimeException('Unexpected SHOW CREATE TABLE output.');
    }
}
