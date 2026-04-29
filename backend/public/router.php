<?php declare(strict_types=1);

/**
 * Router for `php -S host:port -t public router.php` style usage.
 * Replicates what the .htaccess file does for Apache.
 *
 * Usage from the project root:
 *   php -S localhost:8000 -t backend/public backend/public/router.php
 *
 * Or shorter (run from anywhere):
 *   php -S localhost:8000 -t backend/public  (no router; only serves /api/* via index.php
 *                                             if you visit it directly — fine for API-only dev)
 *
 * This router exists primarily so that loading `/` returns the SPA's
 * index.html rather than 404'ing.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Real static file? Let PHP serve it.
if ($path !== '/' && is_file($file)) {
    return false;
}

// API request? Front controller.
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

// Anything else — fall back to the SPA index.
$spa = __DIR__ . '/index.html';
if (is_file($spa)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($spa);
    return true;
}

// Frontend hasn't been built yet.
http_response_code(503);
header('Content-Type: text/plain');
echo "Frontend not built yet. Run `cd frontend && npm run build` first,\n";
echo "or use Vite dev mode: `cd frontend && npm run dev` (proxies /api here).\n";
return true;
