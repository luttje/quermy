<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Quermy\Http\Json;
use Quermy\Http\Router;
use Quermy\Http\ConnectionSession;
use Quermy\Controllers\BaseController;

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
 * Dependencies
 */
$session = new ConnectionSession();

/*
 * Controller resolver
 *
 * Hand-rolled mini-container. Each controller declares its dependencies via
 * its constructor; we map the two we have ($vault, $session) by parameter
 * type. Anything else throws — better to fail loudly than silently inject
 * nulls.
 */
$resolve = function (string $class) use ($session): object {
    $rc = new ReflectionClass($class);
    $ctor = $rc->getConstructor();
    if ($ctor === null) {
        return new $class();
    }

    $args = [];
    foreach ($ctor->getParameters() as $p) {
        $type = $p->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $args[] = match ($name) {
            ConnectionSession::class => $session,
            default => throw new RuntimeException(
                "Cannot resolve parameter \${$p->getName()} of type "
                . ($name ?? 'mixed') . " for $class"
            ),
        };
    }
    return $rc->newInstanceArgs($args);
};

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
 * CORS (local dev only — same-origin in production)
 */
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== '') {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
}
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router->dispatch($method, $path, $resolve);
} catch (\Throwable $e) {
    Json::error($e->getMessage(), 500);
}
