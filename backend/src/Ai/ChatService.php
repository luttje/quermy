<?php
declare(strict_types=1);

namespace Quermy\Ai;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;

/**
 * Thin wrapper around the Symfony AI Platform for chat completion.
 */
class ChatService
{
    /**
     * Stream the reply as a Generator of TextDelta chunks.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<TextDelta>
     */
    public function stream(string $provider, #[\SensitiveParameter] string $apiKey, array $messages, string $model): \Generator
    {
        $platform = ProviderRegistry::createPlatform($provider, $apiKey);

        yield from $platform->invoke($model, $this->buildBag($messages), ['stream' => true])->asTextStream();
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
