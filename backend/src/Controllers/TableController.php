<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\Capabilities\ProvidesTableInfo;
use Quermy\Drivers\Capabilities\SupportsAlterTableAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsAlterTableCollation;
use Quermy\Drivers\Capabilities\SupportsAlterTableEngine;
use Quermy\Drivers\Capabilities\SupportsDropTable;
use Quermy\Drivers\Capabilities\SupportsTriggerManagement;
use Quermy\Drivers\Capabilities\SupportsTruncateTable;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;
use RuntimeException;

final class TableController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('GET', '/api/databases/{db}/tables/{table}/info')]
    public function getTableInfo(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof ProvidesTableInfo) {
                throw new RuntimeException('This engine does not provide table metadata.');
            }
            Json::send($driver->getTableInfo($db, $table));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables/{table}/collations')]
    public function listTableCollations(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableCollation) {
                throw new RuntimeException('This engine does not support table collation management.');
            }
            Json::send(['collations' => $driver->listTableCollations($db, $table)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/tables/{table}/collation')]
    public function alterTableCollation(string $db, string $table): void
    {
        $body      = Json::readBody();
        $collation = trim((string)($body['collation'] ?? ''));
        if ($collation === '') Json::error('collation is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableCollation) {
                throw new RuntimeException('This engine does not support altering table collation.');
            }
            $driver->alterTableCollation($db, $table, $collation);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables/{table}/engines')]
    public function listTableEngines(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableEngine) {
                throw new RuntimeException('This engine does not support table engine management.');
            }
            Json::send(['engines' => $driver->listTableEngines()]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/tables/{table}/engine')]
    public function alterTableEngine(string $db, string $table): void
    {
        $body   = Json::readBody();
        $engine = trim((string)($body['engine'] ?? ''));
        if ($engine === '') Json::error('engine is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableEngine) {
                throw new RuntimeException('This engine does not support altering table engine.');
            }
            $driver->alterTableEngine($db, $table, $engine);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/tables/{table}')]
    public function dropTable(string $db, string $table): void
    {
        $body  = Json::readBody();
        $force = (bool)($body['force'] ?? false);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsDropTable) {
                throw new RuntimeException('This engine does not support dropping tables.');
            }
            $driver->dropTable($db, $table, $force);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/databases/{db}/tables/{table}/truncate')]
    public function truncateTable(string $db, string $table): void
    {
        $body  = Json::readBody();
        $force = (bool)($body['force'] ?? false);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsTruncateTable) {
                throw new RuntimeException('This engine does not support truncating tables.');
            }
            $driver->truncateTable($db, $table, $force);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/tables/{table}/auto-increment')]
    public function alterTableAutoIncrement(string $db, string $table): void
    {
        $body  = Json::readBody();
        $value = $body['value'] ?? null;
        if ($value === null || !is_numeric($value) || (int)$value < 1) {
            Json::error('value must be a positive integer', 400);
        }

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableAutoIncrement) {
                throw new RuntimeException('This engine does not support altering auto-increment.');
            }
            $driver->alterTableAutoIncrement($db, $table, (int)$value);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables/{table}/triggers')]
    public function listTriggers(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsTriggerManagement) {
                throw new RuntimeException('This engine does not support trigger management.');
            }
            Json::send(['triggers' => $driver->listTriggers($db, $table)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/triggers/{trigger}')]
    public function getTriggerDefinition(string $db, string $trigger): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsTriggerManagement) {
                throw new RuntimeException('This engine does not support trigger management.');
            }
            Json::send([
                'name'       => $trigger,
                'definition' => $driver->getTriggerDefinition($db, $trigger),
            ]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/tables/{table}/triggers/{trigger}')]
    public function upsertTrigger(string $db, string $table, string $trigger): void
    {
        $body       = Json::readBody();
        $definition = trim((string)($body['definition'] ?? ''));
        if ($definition === '') Json::error('definition is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsTriggerManagement) {
                throw new RuntimeException('This engine does not support trigger management.');
            }
            $driver->upsertTrigger($db, $table, $trigger, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/triggers/{trigger}')]
    public function dropTrigger(string $db, string $trigger): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsTriggerManagement) {
                throw new RuntimeException('This engine does not support trigger management.');
            }
            $driver->dropTrigger($db, $trigger);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }
}
