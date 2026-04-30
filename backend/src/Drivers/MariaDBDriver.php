<?php declare(strict_types=1);

namespace Quermy\Drivers;

/**
 * MariaDB driver.
 *
 * MariaDB is wire-compatible with MySQL (same `mysql:` DSN prefix, same
 * PDO driver, same information_schema layout) so we reuse MySQLDriver
 * wholesale and only override the identification and capabilities that
 * differ (label, a few extra types, UUID support).
 */
class MariaDBDriver extends MySQLDriver
{
    public static function engineId(): string
    {
        return 'mariadb';
    }

    public static function engineMeta(): array
    {
        return [
            'id'              => 'mariadb',
            'label'           => 'MariaDB',
            'defaultPort'     => 3306,
            'defaultUsername' => 'root',
            'connectionType'  => 'tcp',
            'identifierOpen'  => '`',
            'identifierClose' => '`',
        ];
    }

    public function columnTypes(): array
    {
        return array_merge(parent::columnTypes(), [
            'UUID',
            'INET4',
            'INET6',
        ]);
    }
}
