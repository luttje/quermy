<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Ai\ChatService;
use Quermy\Ai\Tools\GetDatabases;
use Quermy\Ai\Tools\ListTables;
use Quermy\Ai\Tools\RunSelectQuery;
use Quermy\Http\ConnectionSession;
use Quermy\Http\Json;
use Quermy\Http\Route;
use Quermy\Storage\CredentialVault;

final class AiChatController extends BaseController
{
    public function __construct(
        private CredentialVault $vault,
        private ConnectionSession $session,
    ) {}

    #[Route('POST', '/api/ai/chat/stream')]
    public function stream(): void
    {
        $body     = Json::readBody();
        $keyId    = trim((string)($body['keyId'] ?? ''));
        $model    = trim((string)($body['model'] ?? ''));
        $messages = $body['messages'] ?? [];

        if ($keyId === '') Json::error('keyId is required', 422);
        if ($model === '') Json::error('model is required', 422);
        if (!is_array($messages) || $messages === []) {
            Json::error('messages must be a non-empty array', 422);
        }

        $creds = $this->vault->aiKeyGetDecrypted($keyId);
        if ($creds === null) {
            Json::error('API key not found. Add one via the key manager.', 422);
        }

        // Tools that need request-scoped state (the active connection) get
        // it via constructor injection. If you move to a DI container later,
        // these become regular tagged services and this list goes away.
        $tools = [
            new GetDatabases($this->session),
            new ListTables($this->session),
            new RunSelectQuery($this->session),
        ];

        // Drop any output buffers so SSE frames flush immediately.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // disable nginx proxy buffering

        try {
            $chat = new ChatService($tools);
            foreach ($chat->stream($creds['provider'], $creds['apiKey'], $messages, $model) as $event) {
                echo 'data: ' . json_encode($event) . "\n\n";
                flush();
            }
            echo "data: [DONE]\n\n";
            flush();
        } catch (\Throwable $e) {
            echo 'data: ' . json_encode(['type' => 'error', 'error' => $e->getMessage()]) . "\n\n";
            flush();
        }
        exit;
    }
}
