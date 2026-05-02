<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
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
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->pdo->exec('SET SHOWPLAN_ALL ON');
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll();
        } finally {
            $this->pdo->exec('SET SHOWPLAN_ALL OFF');
        }
    }
}
