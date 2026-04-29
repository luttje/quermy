
# 🌐 Hosting publicly

Quermy can be hosted on a public server. Each visitor brings their own database credentials and API keys — nothing is stored on the server.

## How it works

| What | Where it lives |
| ---- | -------------- |
| Saved connections (passwords) | Visitor's browser (IndexedDB, optionally AES-256-GCM encrypted with a master password they choose) |
| AI provider keys | Same browser vault |
| Active session credentials | Server RAM only (`$_SESSION`, cleared when the browser tab closes) |
| Settings (system prompt, etc.) | Visitor's browser (`localStorage`) |

The server stores **nothing** between sessions. Two visitors hitting the same Quermy instance cannot see each other's data.

## Requirements

- PHP 8.3 or newer
- One or more PDO extensions matching the database engines you want to support:
  `pdo_mysql` (MySQL/MariaDB), `pdo_pgsql` (PostgreSQL), `pdo_sqlite` (SQLite), `pdo_sqlsrv` (SQL Server)
- Apache with `mod_rewrite`, or Nginx with a `try_files` rewrite rule pointing to `index.php` (see below)

## Apache

Point a `VirtualHost` or `Alias` at the `public/` directory. The bundled `.htaccess` handles routing:

```apache
Alias /quermy /var/www/quermy/public
<Directory /var/www/quermy/public>
    AllowOverride All
    Require all granted
</Directory>
```

## Nginx + PHP-FPM

```nginx
location /quermy/ {
    root   /var/www;
    index  index.html index.php;

    # SPA routes → index.html; API routes → PHP
    location ~ ^/quermy/api/ {
        try_files $uri /quermy/index.php$is_args$args;
    }
    location /quermy/ {
        try_files $uri /quermy/index.html;
    }
}

location ~ ^/quermy/index\.php$ {
    fastcgi_pass   unix:/run/php/php8.3-fpm.sock;
    fastcgi_index  index.php;
    include        fastcgi_params;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Recommended production settings

- **HTTPS is strongly recommended.** Without it, the session cookie `Secure` flag is not set and database passwords are transmitted in plaintext over the active session.
- Set `display_errors = Off` and `log_errors = On` in `php.ini` (or in a `.user.ini` inside `public/`) to prevent PHP error details from reaching visitors.
- No additional authentication layer is needed — every visitor must connect to their own database and optionally protect their saved credentials with a master password.

## Hosted mode (phpMyAdmin-style)

By default every visitor supplies their own engine, host, and port. If you want to lock the instance to a specific database server — so visitors only enter a username and password — create `backend/config.php` from the provided example:

```bash
cp backend/config.example.php backend/config.php
```

Then edit `backend/config.php`:

```php
return [
    'server_connection' => [
        'engine'   => 'mysql',      // mysql | mariadb | postgresql | sqlserver
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => null,         // null = visitor picks a database; or set a fixed name
    ],
];
```

When `config.php` is present the login screen changes to a simple username / password form. The engine, host, and port fields are hidden from visitors and **cannot be overridden** — the backend ignores any values the client sends for those fields and always uses the values from `config.php`.

To return to the default behaviour, delete or rename `config.php`.
