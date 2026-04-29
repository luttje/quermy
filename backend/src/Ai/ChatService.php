<?php
declare(strict_types=1);

namespace Quermy\Ai;

use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;

/**
 * Thin wrapper around the Symfony AI Platform for chat completion.
 *
 * The API key is provided per-request by the user (bring-your-own-key).
 */
class ChatService
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function chat(string $apiKey, array $messages, string $model = 'gpt-4o-mini'): string
    {
        $platform = Factory::createPlatform($apiKey);

        $bag = $this->buildBag($messages);

        return $platform->invoke($model, $bag)->asText();
    }

    /**
     * Stream the reply as a Generator of TextDelta chunks.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<TextDelta>
     */
    public function stream(string $apiKey, array $messages, string $model = 'gpt-4o-mini'): \Generator
    {
        $platform = Factory::createPlatform($apiKey);

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
