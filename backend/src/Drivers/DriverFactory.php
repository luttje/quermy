<?php declare(strict_types=1);

namespace Quermy\Drivers;

use RuntimeException;

class DriverFactory
{
    /** @var array<string,class-string<DriverInterface>> */
    private static array $drivers = [
        'mysql' => MySQLDriver::class,
        // 'postgres' => PostgresDriver::class,  // future
        // 'sqlite'   => SQLiteDriver::class,    // future
    ];

    public static function make(string $engine): DriverInterface
    {
        if (!isset(self::$drivers[$engine])) {
            throw new RuntimeException("Unsupported engine: $engine");
        }
        $class = self::$drivers[$engine];
        return new $class();
    }

    /** @return string[] */
    public static function supportedEngines(): array
    {
        return array_keys(self::$drivers);
    }
}
