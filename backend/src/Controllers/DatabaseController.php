<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\Capabilities\SupportsAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SupportsAlterTableEngine;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsEventManagement;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsViewManagement;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;
use RuntimeException;

final class DatabaseController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('GET', '/api/databases')]
    public function listDatabases(): void
    {
        $driver = $this->session->open();
        try {
            Json::send(['databases' => $driver->listDatabases()]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables')]
    public function listTables(string $db): void
    {
        $driver = $this->session->open();
        try {
            Json::send(['tables' => $driver->listTables($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables/{table}')]
    public function browseTable(string $db, string $table): void
    {
        $limit  = (int)($_GET['limit']  ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $driver = $this->session->open();
        try {
            Json::send($driver->browseTable($db, $table, $limit, $offset));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/views')]
    public function listViews(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsViewManagement) {
                throw new RuntimeException('This engine does not support view management.');
            }
            Json::send(['views' => $driver->listViews($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/views/{view}')]
    public function getViewDefinition(string $db, string $view): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsViewManagement) {
                throw new RuntimeException('This engine does not support view management.');
            }
            Json::send([
                'name'       => $view,
                'definition' => $driver->getViewDefinition($db, $view),
            ]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/views/{view}')]
    public function upsertView(string $db, string $view): void
    {
        $body       = Json::readBody();
        $definition = trim((string)($body['definition'] ?? ''));
        if ($definition === '') Json::error('definition is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsViewManagement) {
                throw new RuntimeException('This engine does not support view management.');
            }
            $driver->upsertView($db, $view, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/views/{view}')]
    public function dropView(string $db, string $view): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsViewManagement) {
                throw new RuntimeException('This engine does not support view management.');
            }
            $driver->dropView($db, $view);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/procedures')]
    public function listProcedures(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsProcedureManagement) {
                throw new RuntimeException('This engine does not support procedure management.');
            }
            Json::send(['procedures' => $driver->listProcedures($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/procedures/{procedure}')]
    public function getProcedureDefinition(string $db, string $procedure): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsProcedureManagement) {
                throw new RuntimeException('This engine does not support procedure management.');
            }
            Json::send([
                'name'       => $procedure,
                'definition' => $driver->getProcedureDefinition($db, $procedure),
            ]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/procedures/{procedure}')]
    public function upsertProcedure(string $db, string $procedure): void
    {
        $body       = Json::readBody();
        $definition = trim((string)($body['definition'] ?? ''));
        if ($definition === '') Json::error('definition is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsProcedureManagement) {
                throw new RuntimeException('This engine does not support procedure management.');
            }
            $driver->upsertProcedure($db, $procedure, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/procedures/{procedure}')]
    public function dropProcedure(string $db, string $procedure): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsProcedureManagement) {
                throw new RuntimeException('This engine does not support procedure management.');
            }
            $driver->dropProcedure($db, $procedure);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/functions')]
    public function listFunctions(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsFunctionManagement) {
                throw new RuntimeException('This engine does not support function management.');
            }
            Json::send(['functions' => $driver->listFunctions($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/functions/{function}')]
    public function getFunctionDefinition(string $db, string $function): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsFunctionManagement) {
                throw new RuntimeException('This engine does not support function management.');
            }
            Json::send([
                'name'       => $function,
                'definition' => $driver->getFunctionDefinition($db, $function),
            ]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/functions/{function}')]
    public function upsertFunction(string $db, string $function): void
    {
        $body       = Json::readBody();
        $definition = trim((string)($body['definition'] ?? ''));
        if ($definition === '') Json::error('definition is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsFunctionManagement) {
                throw new RuntimeException('This engine does not support function management.');
            }
            $driver->upsertFunction($db, $function, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/functions/{function}')]
    public function dropFunction(string $db, string $function): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsFunctionManagement) {
                throw new RuntimeException('This engine does not support function management.');
            }
            $driver->dropFunction($db, $function);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/events')]
    public function listEvents(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsEventManagement) {
                throw new RuntimeException('This engine does not support event management.');
            }
            Json::send(['events' => $driver->listEvents($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/events/{event}')]
    public function getEventDefinition(string $db, string $event): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsEventManagement) {
                throw new RuntimeException('This engine does not support event management.');
            }
            Json::send([
                'name'       => $event,
                'definition' => $driver->getEventDefinition($db, $event),
            ]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/events/{event}')]
    public function upsertEvent(string $db, string $event): void
    {
        $body       = Json::readBody();
        $definition = trim((string)($body['definition'] ?? ''));
        if ($definition === '') Json::error('definition is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsEventManagement) {
                throw new RuntimeException('This engine does not support event management.');
            }
            $driver->upsertEvent($db, $event, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/events/{event}')]
    public function dropEvent(string $db, string $event): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsEventManagement) {
                throw new RuntimeException('This engine does not support event management.');
            }
            $driver->dropEvent($db, $event);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/engines')]
    public function listEngines(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterTableEngine) {
                throw new RuntimeException('This engine does not support listing storage engines.');
            }
            Json::send(['engines' => $driver->listTableEngines()]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/collations')]
    public function listDatabaseCollations(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterDatabaseCollation) {
                throw new RuntimeException('This engine does not support database collation management.');
            }
            Json::send(['collations' => $driver->listDatabaseCollations($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/info')]
    public function getDatabaseInfo(string $db): void
    {
        $driver = $this->session->open();
        try {
            Json::send($driver->getDatabaseInfo($db));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/databases/{db}/rename')]
    public function renameDatabase(string $db): void
    {
        $body    = Json::readBody();
        $newName = trim((string)($body['newName'] ?? ''));
        if ($newName === '') Json::error('newName is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsRenameDatabase) {
                throw new RuntimeException('This engine does not support renaming databases.');
            }
            $driver->renameDatabase($db, $newName);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/collation')]
    public function alterDatabaseCollation(string $db): void
    {
        $body      = Json::readBody();
        $collation = trim((string)($body['collation'] ?? ''));
        if ($collation === '') Json::error('collation is required', 400);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsAlterDatabaseCollation) {
                throw new RuntimeException('This engine does not support altering database collation.');
            }
            $driver->alterDatabaseCollation($db, $collation);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}')]
    public function dropDatabase(string $db): void
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsDropDatabase) {
                throw new RuntimeException('This engine does not support dropping databases.');
            }
            $driver->dropDatabase($db);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/query')]
    public function runQuery(): void
    {
        $body = Json::readBody();
        $sql  = trim((string)($body['sql']      ?? ''));
        $db   = (string)       ($body['database'] ?? '');
        if ($sql === '') Json::error('SQL is empty', 400);

        $driver = $this->session->open();
        try {
            Json::send($driver->runQuery($db, $sql));
        } finally {
            $driver->disconnect();
        }
    }
}
