<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use PDO;
use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportRenameDatabase
{
    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $db  = $this->validateIdent($database);
        $ndb = $this->validateIdent($newName);

        // Capture charset/collation from the source schema
        $info    = $this->getDatabaseInfo($db);
        $charset = $this->validateIdent($info['charset'] ?? 'utf8mb4');
        $coll    = $this->sanitizeCollationName($info['collation'] ?? 'utf8mb4_unicode_ci');

        // Create the target database
        $this->pdo->exec("CREATE DATABASE `$ndb` CHARACTER SET `$charset` COLLATE `$coll`");

        // Move every base table (MySQL has no single-step RENAME DATABASE)
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([':db' => $db]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $t = $this->validateIdent($table);
            $this->pdo->exec("RENAME TABLE `$db`.`$t` TO `$ndb`.`$t`");
        }

        // Drop the (now-empty) source database
        $this->pdo->exec("DROP DATABASE `$db`");
    }
}
