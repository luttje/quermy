<?php declare(strict_types=1);

namespace Quermy\Ai;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Builds an Agent with the Quermy toolbox and streams its response.
 *
 * The agent decides when to call tools (e.g. get_databases, list_tables,
 * run_query). Tools execute server-side; the final natural-language reply
 * is streamed back to the browser as TextDelta chunks.
 */
class ChatService
{
    /**
     * @param iterable<object> $tools  Tools tagged with #[AsTool]. Pass the
     *                                 services that need request-scoped state
     *                                 (e.g. the active ConnectionSession).
     */
    public function __construct(
        private iterable $tools = [],
    ) {}

    /**
     * Stream the agent's reply.
     *
     * Yields associative arrays describing each event so the controller can
     * decide how to encode them as SSE frames:
     *   ['type' => 'text',      'chunk' => string]
     *   ['type' => 'tool_call', 'name' => string, 'arguments' => array]
     *   ['type' => 'tool_result', 'name' => string, 'ok' => bool, 'error' => ?string]
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<int,array<string,mixed>>
     */
    public function stream(string $provider, #[\SensitiveParameter] string $apiKey, array $messages, string $model): \Generator
    {
        $platform = ProviderRegistry::createPlatform($provider, $apiKey);

        // Event dispatcher lets us surface "tool was called" notifications
        // to the UI. Without it the user just sees the stream pause while
        // a tool runs, with no clue what's happening.
        $dispatcher = new EventDispatcher();
        $events     = [];

        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $e) use (&$events): void {
            $events[] = [
                'type'      => 'tool_call',
                'name'      => $e->getMetadata()->getName(),
                'arguments' => $e->getArguments(),
            ];
        });
        $dispatcher->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $e) use (&$events): void {
            $events[] = [
                'type' => 'tool_result',
                'name' => $e->getMetadata()->getName(),
                'ok'   => true,
            ];
        });
        $dispatcher->addListener(ToolCallFailed::class, static function (ToolCallFailed $e) use (&$events): void {
            $events[] = [
                'type'  => 'tool_result',
                'name'  => $e->getMetadata()->getName(),
                'ok'    => false,
                'error' => $e->getException()->getMessage(),
            ];
        });

        // FaultTolerantToolbox catches tool exceptions and feeds the error
        // back to the LLM so it can recover (e.g. ask the user, try a
        // different tool) instead of aborting the whole stream.
        $toolbox = new FaultTolerantToolbox(
            new Toolbox($this->materializeTools(), eventDispatcher: $dispatcher),
        );
        $processor = new AgentProcessor($toolbox);

        $agent = new Agent($platform, $model, [$processor], [$processor]);

        set_time_limit(60 * 5); // 5 minutes should be enough for now.

        $result = $agent->call($this->buildBag($messages), ['stream' => true]);

        // The agent runs tools synchronously, then streams the final reply.
        // We interleave any tool events that fired before each text delta so
        // the UI can show "🔧 calling get_databases…" before the answer.
        foreach ($result->getContent() as $delta) {
            // Flush any tool events that have accumulated.
            while ($events !== []) {
                yield array_shift($events);
            }

            if ($delta instanceof TextDelta) {
                yield ['type' => 'text', 'chunk' => (string) $delta];
            }
        }

        // Drain any trailing events (rare, but possible).
        while ($events !== []) {
            yield array_shift($events);
        }
    }

    /** @return list<object> */
    private function materializeTools(): array
    {
        $out = [];
        foreach ($this->tools as $t) {
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    private function buildBag(array $messages): MessageBag
    {
        $bag = new MessageBag();

        foreach ($messages as $msg) {
            $role    = $msg['role']    ?? 'user';
            $content = $msg['content'] ?? '';

            match ($role) {
                'system'    => $bag->add(Message::forSystem($content)),
                'assistant' => $bag->add(Message::ofAssistant($content)),
                default     => $bag->add(Message::ofUser($content)),
            };
        }

        return $bag;
    }
}
