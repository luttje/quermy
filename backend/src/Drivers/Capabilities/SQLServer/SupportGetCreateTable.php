<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportGetCreateTable
{
    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($table);

        // Reconstruct a CREATE TABLE statement from INFORMATION_SCHEMA.
        $cstmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE,
                    IS_NULLABLE, COLUMN_DEFAULT,
                    COLUMNPROPERTY(OBJECT_ID(:tbl), COLUMN_NAME, 'IsIdentity') AS is_identity
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = :tbl2
             ORDER BY ORDINAL_POSITION"
        );
        $cstmt->execute([':tbl' => $table, ':tbl2' => $table]);
        $rows = $cstmt->fetchAll();
        if ($rows === []) {
            throw new RuntimeException("Table not found: $table");
        }

        $lines = [];
        foreach ($rows as $r) {
            $type = strtoupper($r['DATA_TYPE']);
            if ($r['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $len  = $r['CHARACTER_MAXIMUM_LENGTH'] == -1 ? 'MAX' : $r['CHARACTER_MAXIMUM_LENGTH'];
                $type = "$type($len)";
            } elseif ($r['NUMERIC_PRECISION'] !== null && in_array($type, ['DECIMAL', 'NUMERIC'], true)) {
                $type = "$type({$r['NUMERIC_PRECISION']},{$r['NUMERIC_SCALE']})";
            }
            $null   = $r['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $id     = (int)$r['is_identity'] ? ' IDENTITY(1,1)' : '';
            $def    = $r['COLUMN_DEFAULT'] !== null ? " DEFAULT {$r['COLUMN_DEFAULT']}" : '';
            $lines[] = "    [{$r['COLUMN_NAME']}] $type$id $null$def";
        }

        return "CREATE TABLE [dbo].[$table] (\n" . implode(",\n", $lines) . "\n)";
    }
}
