<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Ai\ProviderRegistry;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class AiProviderController extends BaseController
{
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
