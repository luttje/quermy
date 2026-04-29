<?php declare(strict_types=1);

namespace Quermy\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicModelCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Central registry of supported AI providers and their chat-capable models.
 */
final class ProviderRegistry
{
    /** @return array<string,string> id → display label */
    public static function providers(): array
    {
        return [
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
        ];
    }

    /**
     * Text-chat capable models for a provider, sourced from the installed ModelCatalog.
     *
     * @return list<string>
     */
    public static function textModels(string $provider): array
    {
        $catalog = match ($provider) {
            'openai'    => new OpenAiModelCatalog(),
            'anthropic' => new AnthropicModelCatalog(),
            default     => null,
        };

        if ($catalog === null) {
            return [];
        }

        $models = [];
        foreach ($catalog->getModels() as $name => $config) {
            $caps = $config['capabilities'];
            if (
                in_array(Capability::INPUT_MESSAGES, $caps, true) &&
                in_array(Capability::OUTPUT_TEXT, $caps, true) &&
                in_array(Capability::OUTPUT_STREAMING, $caps, true)
            ) {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * Default (recommended) model for a provider.
     */
    public static function defaultModel(string $provider): string
    {
        return match ($provider) {
            'openai'    => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-haiku-latest',
            default     => '',
        };
    }

    /**
     * Build a PlatformInterface for the given provider + API key.
     *
     * @throws \InvalidArgumentException for unknown providers
     */
    public static function createPlatform(string $provider, #[\SensitiveParameter] string $apiKey): PlatformInterface
    {
        return match ($provider) {
            'openai'    => OpenAiFactory::createPlatform($apiKey),
            'anthropic' => AnthropicFactory::createPlatform($apiKey),
            default     => throw new \InvalidArgumentException("Unsupported provider: $provider"),
        };
    }
}
