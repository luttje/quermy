# 🌐 Hosting Quermy on a server

Quermy is built first as a tool for your own development machine. It can also run on a server for other people to use, but doing that safely takes some setup. Read [Before you host](#before-you-host) first.

> [!WARNING]
> Quermy is a prototype. It gives whoever can reach it a way to connect to database servers from your machine. Do not put it on the open internet without the precautions on this page.

## Before you host

### Pick a mode

**Hosted mode (recommended).** You fix the database engine, host, and port in `config.php`. Visitors only enter a username and password, and can only reach that one database server. This is the same model phpMyAdmin uses. See [Hosted mode](#hosted-mode-recommended).

**Open mode (the default, not recommended on a server).** Without a `config.php`, every visitor chooses the engine, host, and port themselves. On a server that means anyone who can open the page can make *your server* connect to any database server it can reach, including:

- databases on the server itself (`127.0.0.1`), which often accept local connections with weak or no passwords;
- databases on your internal network that are not reachable from the internet;
- other people's database servers, for password guessing or port probing that then appears to come from your IP address.

Quermy has no login of its own and does not limit connection attempts. If you run open mode on a server, put authentication in front of it (see [Restricting who can reach Quermy](#restricting-who-can-reach-quermy)) and only give access to people you trust.

### Checklist

- [ ] Use hosted mode, or put authentication in front of Quermy. Preferably both.
- [ ] Serve it over HTTPS only. Database passwords and AI keys travel from the browser to the server on every connect and chat request.
- [ ] Set [`allowed_hosts`](#configphp) to the domain name(s) you serve Quermy on.
- [ ] Leave `allow_sqlite` off. See [SQLite](#sqlite).
- [ ] Give visitors database accounts with only the privileges they need. Quermy runs whatever SQL the account is allowed to run.
- [ ] Set `display_errors = Off` and `log_errors = On` in `php.ini` (or a `.user.ini` inside `public/`).
- [ ] Keep Quermy up to date, and read [SECURITY.md](../SECURITY.md) for how to report a problem.

## What is stored where

| What | Where it lives |
| ---- | -------------- |
| Saved connections, including passwords | The visitor's browser (IndexedDB). Encrypted with AES-256-GCM if the visitor sets a master password, otherwise stored as plain text. |
| Saved AI provider keys | The same browser vault. |
| Credentials of the active connection | The server, in the PHP session, as plain text. By default PHP keeps sessions as files on disk. They stay there until the visitor disconnects or PHP cleans up the expired session. |
| Settings (system prompt, etc.) | The visitor's browser (`localStorage`). |

Quermy keeps no database of its own and one visitor cannot see another visitor's connection. But the server is not blind to what passes through it:

- The database password is sent to the server when a visitor connects, and is held in their session while they are connected.
- The AI provider key is sent to the server with every chat message. The server uses it to call the AI provider and does not store it.
- Query results pass through the server on their way to the browser. When the AI assistant is used, schema details and sample rows are sent to the chosen AI provider.

Anyone who uses your instance is therefore trusting you, and anyone with access to the server (or to its PHP session storage) can read the credentials of connected visitors. On shared hosting, check that PHP's session directory is not readable by other customers.

## Requirements

- PHP 8.3 or newer
- One or more PDO extensions matching the database engines you want to support:
  `pdo_mysql` (MySQL/MariaDB), `pdo_pgsql` (PostgreSQL), `pdo_sqlsrv` (SQL Server)
- Apache with `mod_rewrite` (and `mod_headers` for the security headers), or Nginx with PHP-FPM

## Apache

Point a `VirtualHost` or `Alias` at the `public/` directory. Only `public/` should be reachable from the web; `src/`, `vendor/`, and `config.php` sit next to it and must not be served. The bundled `.htaccess` handles routing and sets the security headers:

```apache
Alias /quermy /var/www/quermy/public
<Directory /var/www/quermy/public>
    AllowOverride All
    Require all granted
</Directory>
```

## Nginx + PHP-FPM

Nginx does not read `.htaccess`, so the security headers are set here instead:

```nginx
location /quermy/ {
    root   /var/www;
    index  index.html index.php;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;

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

## Restricting who can reach Quermy

Quermy has no user accounts. To limit who can open it at all, use your web server. Any of these works; pick what fits your setup:

- **A VPN or private network.** Only serve Quermy on an address that is not reachable from the internet.
- **An IP allowlist.** In Apache: `Require ip 203.0.113.0/24` instead of `Require all granted`.
- **HTTP Basic authentication** (always over HTTPS). In Apache:

    ```apache
    <Directory /var/www/quermy/public>
        AllowOverride All
        AuthType Basic
        AuthName "Quermy"
        AuthUserFile /etc/apache2/quermy.htpasswd
        Require valid-user
    </Directory>
    ```

    Create the password file with `htpasswd -c /etc/apache2/quermy.htpasswd yourname`.

## `config.php`

Server-side settings live in a file named `config.php` that sits next to the `public/` directory (in a source checkout that is `backend/config.php`). It does not exist by default. Create it from the example that ships alongside it:

```bash
cp config.example.php config.php
```

| Setting | What it does |
| ------- | ------------ |
| `server_connection` | Turns on [hosted mode](#hosted-mode-recommended). |
| `allowed_hosts` | The hostnames Quermy may be reached by, without port, for example `['db.example.com']`. Requests for any other hostname are refused. Always set this on a server. It stops a malicious website from pointing its own domain at your server and using Quermy through a visitor's browser. `*.example.com` matches any subdomain. |
| `allow_sqlite` | Enables SQLite. Off by default; leave it off on a server. See [SQLite](#sqlite). |
| `dev_cors_origins` | For Quermy development only. Leave it out. |

If you run Quermy behind a reverse proxy, the proxy must pass the original `Host` header through unchanged. Quermy refuses requests whose `Origin` header does not match the `Host` it was addressed by, and refuses hostnames that are not in `allowed_hosts`.

## Hosted mode (recommended)

To lock the instance to one database server, so visitors only enter a username and password, add a `server_connection` block to `config.php`:

```php
return [
    'allowed_hosts' => ['db.example.com'],

    'server_connection' => [
        'engine'   => 'mysql',      // mysql | mariadb | postgresql | sqlserver
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => null,         // null = visitor picks a database; or set a fixed name
    ],
];
```

The login screen then becomes a simple username and password form. The engine, host, and port are hidden from visitors and cannot be overridden: the backend ignores any values the browser sends for those fields and always uses the ones from `config.php`.

Setting `database` to a fixed name pre-selects that database in the interface. It is a convenience, not a security boundary: which databases a visitor can actually read or change is decided by the privileges of the database account they log in with.

To return to open mode, remove the `server_connection` block.

## SQLite

SQLite is disabled unless `config.php` contains `'allow_sqlite' => true`.

A SQLite "connection" opens a file on the server. Anyone who can connect with SQLite can open any SQLite file PHP is able to read, and can create files anywhere PHP is able to write, which on most servers is enough to take the server over. Pinning a database path in hosted mode does not prevent this.

Only enable SQLite on a machine where every person who can reach Quermy is fully trusted, such as your own development machine. Do not enable it on a server other people use.

## Known limitations

These matter if people you do not fully trust can reach your instance:

- There is no limit on connection attempts, so Quermy can be used to guess database passwords.
- Exports are built in memory. Exporting a very large table can exhaust PHP's memory limit.
- A request to the AI assistant can run for up to five minutes.
