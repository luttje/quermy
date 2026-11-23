# 🤯 Troubleshooting

If you run into any issues while using Quermy, here are some common problems and their solutions.

## All `/api/` requests fail with 404

If all requests to `/api/` endpoints are returning 404 Not Found, it's likely that the backend server is not running or not properly configured.

**Make sure:**

- `mod_rewrite` is enabled in your Apache configuration:

    ```bash
    a2enmod rewrite
    ```

    Or check that `httpd.conf` has the line:

    ```apache
    # Note that there is NOT a # in front of this line:
    LoadModule rewrite_module modules/mod_rewrite.so
    ```

- The `.htaccess` file is present in the `backend/public/` directory and has the correct rewrite rules (as shown in the [`/backend/public/.htaccess`](../backend/public/.htaccess) file).

- The Alias or VirtualHost configuration for the backend:

  - Is correctly pointing to the `backend/public/` directory.
  - Has `AllowOverride All` enabled for that directory to allow `.htaccess` to work.

- Apache has been restarted after making any configuration changes:

    ```bash
    sudo systemctl restart apache2
    ```

## SQLite is missing from the engine list

SQLite is disabled by default. A SQLite connection opens a file on the machine that runs Quermy's PHP backend, and anyone who can connect can read any SQLite file PHP can read and create files anywhere PHP can write. That is fine on your own development machine, but dangerous on a server other people can reach.

To enable it, create a `config.php` next to the `public/` directory (in a source checkout that is `backend/config.php`) with:

```php
<?php
return [
    'allow_sqlite' => true,
];
```

Only do this where every user of the Quermy instance is trusted. Never enable it on a publicly hosted instance.
