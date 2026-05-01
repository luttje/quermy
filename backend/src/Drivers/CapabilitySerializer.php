<?php declare(strict_types=1);

namespace Quermy\Drivers;

use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesColumnTypesWithLength;
use Quermy\Drivers\Capabilities\ProvidesDefaultColumnType;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesReferentialActions;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesTextColumnTypePatterns;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SupportsAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsColumnAfter;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsEventManagement;
use Quermy\Drivers\Capabilities\SupportsExplain;
use Quermy\Drivers\Capabilities\SupportsForeignKeyManagement;
use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsReorderColumn;
use Quermy\Drivers\Capabilities\SupportsViewManagement;

final class CapabilitySerializer
{
    private const SUPPORTS = [
        SupportsAddColumn::class,
        SupportsModifyColumn::class,
        SupportsDropColumn::class,
        SupportsColumnAfter::class,
        SupportsAutoIncrement::class,
        SupportsReorderColumn::class,
        SupportsIndexManagement::class,
        SupportsForeignKeys::class,
        SupportsForeignKeyManagement::class,
        SupportsGetCreateTable::class,
        SupportsExplain::class,
        SupportsRenameDatabase::class,
        SupportsDropDatabase::class,
        SupportsAlterDatabaseCollation::class,
        SupportsViewManagement::class,
        SupportsProcedureManagement::class,
        SupportsFunctionManagement::class,
        SupportsEventManagement::class,
    ];

    public static function serialize(DriverInterface $driver): array
    {
        $implemented = class_implements($driver);

        $caps = [];

        // Boolean flags derived by convention: SupportsXxx → supportsXxx.
        foreach (self::SUPPORTS as $interface) {
            $key = lcfirst(substr($interface, strrpos($interface, '\\') + 1));
            $caps[$key] = isset($implemented[$interface]);
        }

        // Data provided by Provides* interfaces.
        $caps['columnTypes'] = $driver instanceof ProvidesColumnTypes
            ? $driver->columnTypes()
            : [];
        $caps['columnTypesWithLength'] = $driver instanceof ProvidesColumnTypesWithLength
            ? $driver->columnTypesWithLength()
            : [];
        $caps['defaultColumnType'] = $driver instanceof ProvidesDefaultColumnType
            ? $driver->defaultColumnType()
            : '';
        $caps['referentialActions'] = $driver instanceof ProvidesReferentialActions
            ? $driver->referentialActions()
            : [];
        $caps['textColumnTypePatterns'] = $driver instanceof ProvidesTextColumnTypePatterns
            ? $driver->textColumnTypePatterns()
            : [];
        $caps['welcomeQuery'] = $driver instanceof ProvidesWelcomeQuery
            ? $driver->welcomeQuery()
            : '';
        $caps['structureQueryTemplate'] = $driver instanceof ProvidesStructureQueryTemplate
            ? $driver->structureQueryTemplate()
            : '';
        $caps['listTablesQuery'] = $driver instanceof ProvidesListTablesQuery
            ? $driver->listTablesQuery()
            : '';

        // Identifier quoting characters — always available from static engine metadata.
        $meta = $driver::engineMeta();
        $caps['identifierOpen']  = $meta['identifierOpen'];
        $caps['identifierClose'] = $meta['identifierClose'];

        return $caps;
    }
}
