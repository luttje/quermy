<?php declare(strict_types=1);

/**
 * Server-managed connection configuration.
 *
 * Copy this file to config.php (same directory) and fill in the details.
 *
 * When config.php exists and contains a 'server_connection' entry, Quermy
 * switches to "hosted mode": visitors only need to enter a username and
 * password to connect — they cannot change the engine, host, or port.
 * This mirrors the phpMyAdmin model where the admin controls which server
 * is accessible.
 *
 * Leave config.php absent (or remove server_connection) to keep the default
 * behaviour where each visitor supplies all connection details themselves.
 */
return [
    'server_connection' => [
        // Required: the database engine to use.
        // Supported values: mysql | mariadb | postgresql | sqlite | sqlserver
        'engine' => 'mysql',

        // Required for network engines (everything except sqlite).
        'host' => '127.0.0.1',
        'port' => 3306,

        // Optional: pre-select a specific database.
        // Leave as null (or remove this key) to let users choose from all
        // databases their credentials grant access to.
        'database' => null,
    ],
];
