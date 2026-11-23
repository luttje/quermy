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
    // SQLite is disabled by default. A SQLite connection opens a file on THIS
    // server, and anyone who can connect can read any SQLite file PHP can
    // read and create files anywhere PHP can write (via ATTACH DATABASE).
    // Only enable this on a machine where every user of Quermy is trusted,
    // such as your own development machine. Never enable it on a public host.
    'allow_sqlite' => false,

    'server_connection' => [
        // Required: the database engine to use.
        // Supported values: mysql | mariadb | postgresql | sqlite | sqlserver
        // (sqlite additionally requires 'allow_sqlite' => true above)
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
