<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Ai\ChatService;
use Quermy\Ai\Tools\DescribeTable;
use Quermy\Ai\Tools\ExplainQuery;
use Quermy\Ai\Tools\GetCreateTable;
use Quermy\Ai\Tools\GetDatabases;
use Quermy\Ai\Tools\GetForeignKeys;
use Quermy\Ai\Tools\ListTables;
use Quermy\Ai\Tools\SampleTable;
use Quermy\Ai\Tools\SearchSchema;
use Quermy\Ai\Tools\SuggestQuery;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class AiChatController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('POST', '/api/ai/chat/stream')]
    public function stream(): void
    {
        $body     = Json::readBody();
        $provider = trim((string)($body['provider'] ?? ''));
        $apiKey   = trim((string)($body['apiKey']   ?? ''));
        $model    = trim((string)($body['model']    ?? ''));
        $messages = $body['messages'] ?? [];

        if ($provider === '') Json::error('provider is required', 422);
        if ($apiKey   === '') Json::error('apiKey is required',   422);
        if ($model    === '') Json::error('model is required',    422);
        if (!is_array($messages) || $messages === []) {
            Json::error('messages must be a non-empty array', 422);
        }

        // Tool order isn't significant — the agent picks based on the
        // #[AsTool] descriptions — but grouping by purpose is easier to
        // reason about: discovery first, then introspection, then action.
        $tools = [
            // Discovery
            new GetDatabases($this->session),
            new ListTables($this->session),
            new SearchSchema($this->session),

            // Introspection
            new DescribeTable($this->session),
            new GetForeignKeys($this->session),
            new GetCreateTable($this->session),
            new SampleTable($this->session),

            // Query authoring
            new ExplainQuery($this->session),
            new SuggestQuery(), // no DB access — just validates and echoes the SQL back
        ];

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // disable nginx proxy buffering

        try {
            $chat = new ChatService($tools);
            foreach ($chat->stream($provider, $apiKey, $messages, $model) as $event) {
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
