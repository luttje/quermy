<?php declare(strict_types=1);

// This endpoint runs before anything, so we can check if the system is functional. Right now this
// only checks if the PHP version is sufficient to run the app, but in the future it could be expanded to check for
// required PHP extensions, permissions, or other environment factors.
// Without this check, users on unsupported PHP versions would see an unhelpful error.
if (isset($_SERVER['REQUEST_URI']) && str_ends_with($_SERVER['REQUEST_URI'], '/api/system-check')) {
    if (version_compare(PHP_VERSION, '8.3.0', '<')) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'error' => 'PHP 8.3 or higher is required to run Quermy. Current version: ' . PHP_VERSION,
        ]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Quermy\Http\Json;
use Quermy\Http\Router;
use Quermy\Http\ConnectionSession;
use Quermy\Controllers\BaseController;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Kernel\Container;

// Session cookies are HTTPOnly to prevent JavaScript access, mitigating XSS risks.
// SameSite=Lax to prevent CSRF on state-changing endpoints while allowing normal navigation.
// Secure flag is set if we're on HTTPS, ensuring cookies are only sent over secure connections.
session_name('quermy_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

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
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . ltrim($path, '/');

/*
 * CORS — development only.
 *
 * In production the compiled frontend is served from the same origin as the
 * PHP backend, so the browser never sends an Origin header and these headers
 * are never emitted.
 *
 * During local development the Vite dev server runs on a different port, so
 * we allow it — but only for localhost/127.0.0.1 origins to prevent a
 * production mis-deployment from accidentally opening the API to all origins.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isLocalOrigin = (bool) preg_match(
    '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    $origin,
);

if ($isLocalOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
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
