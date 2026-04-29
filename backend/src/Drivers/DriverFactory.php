<?php declare(strict_types=1);

namespace Quermy\Drivers;

use RuntimeException;

class DriverFactory
{
    /** @var array<string,class-string<DriverInterface>> */
    private static array $drivers = [
        'mysql'      => MySQLDriver::class,
        'mariadb'    => MariaDBDriver::class,
        'postgresql' => PostgreSQLDriver::class,
        'sqlite'     => SQLiteDriver::class,
        'sqlserver'  => SQLServerDriver::class,
    ];

    public static function make(string $engine): DriverInterface
    {
        if (!isset(self::$drivers[$engine])) {
            throw new RuntimeException("Unsupported engine: $engine");
        }
        $class = self::$drivers[$engine];
        return new $class();
    }

    /**
     * Returns the engineMeta() array for every registered driver.
     *
     * @return list<array{id:string,label:string,defaultPort:int,defaultUsername:string,connectionType:string,identifierOpen:string,identifierClose:string}>
     */
    public static function supportedEnginesMeta(): array
    {
        $meta = [];
        foreach (self::$drivers as $class) {
            $meta[] = $class::engineMeta();
        }
        return $meta;
    }

    /**
     * Returns engineMeta() for a single engine id, or throws for unknown engines.
     *
     * @return array{id:string,label:string,defaultPort:int,defaultUsername:string,connectionType:string,identifierOpen:string,identifierClose:string}
     */
    public static function engineMetaFor(string $engine): array
    {
        if (!isset(self::$drivers[$engine])) {
            throw new RuntimeException("Unsupported engine: $engine");
        }
        return self::$drivers[$engine]::engineMeta();
    }
}
