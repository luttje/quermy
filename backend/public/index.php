<?php
declare(strict_types=1);

/**
 * Quermy API — front controller.
 *
 * Drop-in deploy: point Apache/Nginx at this directory or rewrite /api/* to it.
 * For Apache the included .htaccess does the rewriting.
 */

require __DIR__ . '/../vendor/autoload.php';

use Quermy\Ai\ChatService;
use Quermy\Http\Json;
use Quermy\Http\ConnectionSession;
use Quermy\Drivers\DriverFactory;
use Quermy\Storage\CredentialVault;

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

// Dependencies
$storageDir = realpath(__DIR__ . '/../storage') ?: __DIR__ . '/../storage';
$vault   = new CredentialVault(
    vaultPath: $storageDir . '/connections.json',
    keyPath:   $storageDir . '/quermy.key',
);
$session = new ConnectionSession($vault);

// Routing
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Strip the script's directory so deployments under /quermy/ still work.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . ltrim($path, '/');

// CORS only for local dev — same-origin in production.
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== '') {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
}
if ($method === 'OPTIONS') { http_response_code(204); exit; }

try {
    switch (true) {

        /*
         * Meta
         */
        case $method === 'GET' && $path === '/api/engines':
            Json::send(['engines' => DriverFactory::supportedEngines()]);
            break;

        case $method === 'GET' && $path === '/api/session':
            Json::send(['active' => $session->describe()]);
            break;

        case $method === 'POST' && $path === '/api/session/disconnect':
            $session->clear();
            Json::send(['ok' => true]);
            break;

        /*
         * Saved connections
         */
        case $method === 'GET' && $path === '/api/connections':
            Json::send(['connections' => $vault->listPublic()]);
            break;

        case $method === 'POST' && $path === '/api/connections':
            $body = Json::readBody();
            requireFields($body, ['engine','host','port','username']);
            $saved = $vault->save($body);
            Json::send(['connection' => $saved], 201);
            break;

        case $method === 'DELETE' && preg_match('#^/api/connections/([a-f0-9]+)$#', $path, $m):
            $ok = $vault->delete($m[1]);
            Json::send(['ok' => $ok], $ok ? 200 : 404);
            break;

        /*
         * Connect (and optionally save)
         */
        case $method === 'POST' && $path === '/api/connect':
            $body = Json::readBody();
            requireFields($body, ['engine','host','port','username']);

            // First test the connection by actually opening it.
            $driver = DriverFactory::make($body['engine']);
            $driver->connect([
                'host'     => $body['host'],
                'port'     => (int)$body['port'],
                'username' => $body['username'],
                'password' => (string)($body['password'] ?? ''),
                'database' => $body['database'] ?? null,
            ]);
            $driver->disconnect();

            // Optionally persist.
            $savedRecord = null;
            if (!empty($body['save'])) {
                $savedRecord = $vault->save($body);
                $session->bindSaved($savedRecord['id']);
            } else {
                $session->bindAdhoc($body);
            }

            Json::send(['ok' => true, 'saved' => $savedRecord]);
            break;

        case $method === 'POST' && preg_match('#^/api/connect/saved/([a-f0-9]+)$#', $path, $m):
            // Open a previously saved connection.
            $creds = $vault->loadCredentials($m[1]);
            if (!$creds) Json::error('Connection not found', 404);
            $driver = DriverFactory::make($creds['engine']);
            $driver->connect($creds);
            $driver->disconnect();
            $session->bindSaved($m[1]);
            Json::send(['ok' => true]);
            break;

        /*
         * Data operations (require active session)
         */
        case $method === 'GET' && $path === '/api/databases':
            $driver = $session->open();
            try { Json::send(['databases' => $driver->listDatabases()]); }
            finally { $driver->disconnect(); }
            break;

        case $method === 'GET' && preg_match('#^/api/databases/([^/]+)/tables$#', $path, $m):
            $driver = $session->open();
            try { Json::send(['tables' => $driver->listTables(rawurldecode($m[1]))]); }
            finally { $driver->disconnect(); }
            break;

        case $method === 'GET' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)$#', $path, $m):
            $limit  = (int)($_GET['limit'] ?? 100);
            $offset = (int)($_GET['offset'] ?? 0);
            $driver = $session->open();
            try {
                $data = $driver->browseTable(rawurldecode($m[1]), rawurldecode($m[2]), $limit, $offset);
                Json::send($data);
            } finally { $driver->disconnect(); }
            break;

        case $method === 'POST' && $path === '/api/query':
            $body = Json::readBody();
            $sql = trim((string)($body['sql'] ?? ''));
            $db  = (string)($body['database'] ?? '');
            if ($sql === '') Json::error('SQL is empty', 400);

            $driver = $session->open();
            try {
                Json::send($driver->runQuery($db, $sql));
            } finally { $driver->disconnect(); }
            break;

        /*
         * Row mutations (require active session + table context)
         */
        case $method === 'POST' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/rows$#', $path, $m):
            $body   = Json::readBody();
            $values = $body['values'] ?? [];
            if (empty($values)) Json::error('No values provided', 422);
            $driver = $session->open();
            try { Json::send($driver->insertRow(rawurldecode($m[1]), rawurldecode($m[2]), $values)); }
            finally { $driver->disconnect(); }
            break;

        case $method === 'PUT' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/rows$#', $path, $m):
            $body   = Json::readBody();
            $where  = $body['where']  ?? [];
            $values = $body['values'] ?? [];
            if (empty($where))  Json::error('No WHERE conditions provided', 422);
            if (empty($values)) Json::error('No values to update', 422);
            $driver = $session->open();
            try { Json::send($driver->updateRow(rawurldecode($m[1]), rawurldecode($m[2]), $where, $values)); }
            finally { $driver->disconnect(); }
            break;

        case $method === 'DELETE' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/rows$#', $path, $m):
            $body  = Json::readBody();
            $where = $body['where'] ?? [];
            if (empty($where)) Json::error('No WHERE conditions provided', 422);
            $driver = $session->open();
            try { Json::send($driver->deleteRow(rawurldecode($m[1]), rawurldecode($m[2]), $where)); }
            finally { $driver->disconnect(); }
            break;

        /*
         * Column mutations
         */
        case $method === 'POST' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/columns$#', $path, $m):
            $body = Json::readBody();
            requireFields($body, ['name', 'type']);
            $driver = $session->open();
            try {
                $driver->addColumn(rawurldecode($m[1]), rawurldecode($m[2]), $body);
                Json::send(['ok' => true]);
            } finally { $driver->disconnect(); }
            break;

        case $method === 'PUT' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/columns/([^/]+)$#', $path, $m):
            $body = Json::readBody();
            requireFields($body, ['name', 'type']);
            $driver = $session->open();
            try {
                $driver->modifyColumn(rawurldecode($m[1]), rawurldecode($m[2]), rawurldecode($m[3]), $body);
                Json::send(['ok' => true]);
            } finally { $driver->disconnect(); }
            break;

        case $method === 'DELETE' && preg_match('#^/api/databases/([^/]+)/tables/([^/]+)/columns/([^/]+)$#', $path, $m):
            $driver = $session->open();
            try {
                $driver->dropColumn(rawurldecode($m[1]), rawurldecode($m[2]), rawurldecode($m[3]));
                Json::send(['ok' => true]);
            } finally { $driver->disconnect(); }
            break;

        /*
         * AI configuration (stored encrypted in vault)
         */
        case $method === 'GET' && $path === '/api/ai/config':
            $cfg = $vault->getAiConfig('openai');
            Json::send($cfg ?? ['configured' => false, 'model' => 'gpt-4o-mini']);
            break;

        case $method === 'POST' && $path === '/api/ai/config':
            $body   = Json::readBody();
            $apiKey = trim((string)($body['apiKey'] ?? ''));
            $model  = trim((string)($body['model'] ?? 'gpt-4o-mini'));
            if ($apiKey === '') { Json::error('apiKey is required', 422); break; }
            $vault->saveAiConfig('openai', $apiKey, $model);
            Json::send(['configured' => true, 'model' => $model]);
            break;

        case $method === 'DELETE' && $path === '/api/ai/config':
            $vault->deleteAiConfig('openai');
            Json::send(['ok' => true]);
            break;

        /*
         * AI chat (key read from vault, never sent by the client)
         */
        case $method === 'POST' && $path === '/api/ai/chat':
            $body     = Json::readBody();
            $messages = $body['messages'] ?? [];
            if (!is_array($messages) || $messages === []) {
                Json::error('messages must be a non-empty array', 422);
                break;
            }

            $apiKey = $vault->getAiKey('openai');
            if ($apiKey === null) {
                Json::error('No API key configured. Add one via the AI settings.', 422);
                break;
            }

            $cfg   = $vault->getAiConfig('openai');
            $model = $cfg['model'] ?? 'gpt-4o-mini';

            $chat  = new ChatService();
            $reply = $chat->chat($apiKey, $messages, $model);
            Json::send(['reply' => $reply]);
            break;

        /*
         * AI chat — streaming (Server-Sent Events)
         * Returns: text/event-stream with data: {"chunk":"..."} lines, ending with data: [DONE]
         */
        case $method === 'POST' && $path === '/api/ai/chat/stream':
            $body     = Json::readBody();
            $messages = $body['messages'] ?? [];
            if (!is_array($messages) || $messages === []) {
                Json::error('messages must be a non-empty array', 422);
                break;
            }

            $apiKey = $vault->getAiKey('openai');
            if ($apiKey === null) {
                Json::error('No API key configured. Add one via the AI settings.', 422);
                break;
            }

            $cfg   = $vault->getAiConfig('openai');
            $model = $cfg['model'] ?? 'gpt-4o-mini';

            // Flush any existing output buffer so SSE frames are not held back.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // disable nginx proxy buffering

            try {
                $chat = new ChatService();
                foreach ($chat->stream($apiKey, $messages, $model) as $delta) {
                    echo 'data: ' . json_encode(['chunk' => (string) $delta]) . "\n\n";
                    flush();
                }
                echo "data: [DONE]\n\n";
                flush();
            } catch (\Throwable $e) {
                echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
                flush();
            }
            exit;

        default:
            Json::error('Not found: ' . $method . ' ' . $path, 404);
    }
} catch (\Throwable $e) {
    Json::error($e->getMessage(), 500);
}

function requireFields(array $body, array $fields): void {
    foreach ($fields as $f) {
        if (!array_key_exists($f, $body) || $body[$f] === '') {
            Json::error("Missing field: $f", 422);
        }
    }
}
