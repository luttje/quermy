<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Quermy\Http\Json;
use Quermy\Http\Router;
use Quermy\Http\ConnectionSession;
use Quermy\Controllers\BaseController;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\RequestGuard;
use Quermy\Http\ServerConfig;
use Quermy\Kernel\Container;

// The URL directory Quermy is served from: '' at the web root, '/quermy' under
// an alias. Used to scope the session cookie and to strip the prefix from
// request paths further down.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

// HTTPS as seen by PHP, or as reported by a TLS-terminating proxy in front.
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

/*
 * Session
 *
 * The session holds the database credentials, so its cookie is locked down:
 * - HttpOnly: page scripts cannot read it.
 * - SameSite=Strict: never sent on requests started by another site. Only the
 *   API uses this cookie and the API is only called by Quermy's own page, so
 *   nothing legitimate needs a looser setting.
 * - Secure on HTTPS: never sent over plain HTTP.
 * - Path limited to Quermy's own directory, so other applications on the
 *   same host are not sent it.
 * - Strict mode: PHP refuses session IDs it did not issue itself, so an
 *   attacker cannot plant a known ID in a victim's browser beforehand.
 */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('quermy_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $base . '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => $isHttps,
]);
session_start();

/*
 * Security headers for API responses. The .htaccess sets the same ones for
 * Apache; sending them here too covers Nginx and the PHP built-in server.
 * API responses are data, never documents, so they get a CSP that forbids
 * everything in case a browser is ever tricked into rendering one.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

/*
 * Container
 *
 * Register every dependency the controllers can ask for. Adding a new
 * service means one bind() call here — controllers themselves stay ignorant
 * of how their dependencies are constructed.
 */
$container = new Container();
$container->instance(ConnectionSessionInterface::class, new ConnectionSession());

/*
 * Controller discovery
 *
 * Scan src/Controllers/ for *Controller.php files, build the FQCN from the
 * filename, and register any class that extends BaseController. Skipping the
 * base itself avoids registering an abstract class.
 */
$router = new Router();

$controllerDir = __DIR__ . '/../src/Controllers';
foreach (glob($controllerDir . '/*Controller.php') ?: [] as $file) {
    $class = 'Quermy\\Controllers\\' . basename($file, '.php');
    if (!class_exists($class)) continue;

    $rc = new ReflectionClass($class);
    if ($rc->isAbstract()) continue;
    if (!$rc->isSubclassOf(BaseController::class)) continue;

    $router->registerControllers([$class]);
}

/*
 * Request normalization
 */
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Strip the script's directory so deployments under /quermy/ still work.
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . ltrim($path, '/');

/*
 * Request origin checks — see RequestGuard for what each one defends against.
 */
$hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
$origin     = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : null;
$devOrigins = ServerConfig::devCorsOrigins();

if (!RequestGuard::hostAllowed($hostHeader, (string)($_SERVER['REMOTE_ADDR'] ?? ''), ServerConfig::allowedHosts())) {
    Json::error(
        "This Quermy instance does not accept requests for this hostname. "
        . "If the hostname is legitimate, add it to 'allowed_hosts' in config.php.",
        403,
    );
}

if (!RequestGuard::originAllowed($origin, $hostHeader, $devOrigins)) {
    Json::error('Cross-origin requests are not allowed.', 403);
}

/*
 * CORS — opt-in, for development only.
 *
 * The compiled frontend is served from the same origin as this backend, and
 * `npm run dev` proxies /api through Vite, so neither needs CORS. It is only
 * enabled for origins a developer lists under 'dev_cors_origins' in
 * config.php, and never for anything else.
 */
if (RequestGuard::isDevOrigin($origin, $devOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
    header('Vary: Origin');
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router->dispatch($method, $path, $container);
} catch (\Throwable $e) {
    Json::error($e->getMessage(), 500);
}
