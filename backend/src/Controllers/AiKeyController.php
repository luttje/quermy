<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Ai\ProviderRegistry;
use Quermy\Http\Json;
use Quermy\Http\Route;
use Quermy\Storage\CredentialVault;

final class AiKeyController extends BaseController
{
    public function __construct(
        private CredentialVault $vault,
    ) {}

    #[Route('GET', '/api/ai/keys')]
    public function list(): void
    {
        Json::send(['keys' => $this->vault->aiKeyList()]);
    }

    #[Route('POST', '/api/ai/keys')]
    public function create(): void
    {
        $body     = Json::readBody();
        $label    = trim((string)($body['label']    ?? ''));
        $provider = trim((string)($body['provider'] ?? ''));
        $apiKey   = trim((string)($body['apiKey']   ?? ''));
        $model    = trim((string)($body['model']    ?? ''));

        if ($label  === '') Json::error('label is required',  422);
        if ($apiKey === '') Json::error('apiKey is required', 422);
        if ($model  === '') Json::error('model is required',  422);

        $providers = ProviderRegistry::providers();
        if (!isset($providers[$provider])) {
            Json::error(
                'Unknown provider. Supported: ' . implode(', ', array_keys($providers)),
                422
            );
        }

        $entry = $this->vault->aiKeyAdd($label, $provider, $apiKey, $model);
        Json::send(['key' => $entry], 201);
    }

    #[Route('PATCH', '/api/ai/keys/{id}')]
    public function update(string $id): void
    {
        $body   = Json::readBody();
        $label  = isset($body['label'])  ? trim((string)$body['label'])  : null;
        $model  = isset($body['model'])  ? trim((string)$body['model'])  : null;
        $apiKey = isset($body['apiKey']) ? trim((string)$body['apiKey']) : null;
        // Treat empty strings as "no change"
        if ($label  === '') $label  = null;
        if ($model  === '') $model  = null;
        if ($apiKey === '') $apiKey = null;

        $entry = $this->vault->aiKeyUpdate($id, $label, $model, $apiKey);
        if ($entry === null) Json::error('Key not found', 404);

        Json::send(['key' => $entry]);
    }

    #[Route('DELETE', '/api/ai/keys/{id}')]
    public function delete(string $id): void
    {
        $ok = $this->vault->aiKeyDelete($id);
        Json::send(['ok' => $ok], $ok ? 200 : 404);
    }

    #[Route('GET', '/api/ai/keys/{id}/models')]
    public function modelsForKey(string $id): void
    {
        $entry = $this->vault->aiKeyGetDecrypted($id);
        if ($entry === null) Json::error('Key not found', 404);

        Json::send(['models' => ProviderRegistry::textModels($entry['provider'])]);
    }

    #[Route('GET', '/api/ai/providers')]
    public function providers(): void
    {
        $result = [];
        foreach (ProviderRegistry::providers() as $id => $label) {
            $result[] = [
                'id'           => $id,
                'label'        => $label,
                'defaultModel' => ProviderRegistry::defaultModel($id),
                'models'       => ProviderRegistry::textModels($id),
            ];
        }
        Json::send(['providers' => $result]);
    }
}
