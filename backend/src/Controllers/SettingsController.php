<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Json;
use Quermy\Http\Route;
use Quermy\Storage\UserSettings;

final class SettingsController extends BaseController
{
    public function __construct(private UserSettings $settings) {}

    /** GET /api/settings — return all persisted settings. */
    #[Route('GET', '/api/settings')]
    public function index(): void
    {
        Json::send(['settings' => $this->settings->all()]);
    }

    /**
     * PATCH /api/settings — shallow-merge the supplied object into saved
     * settings. Pass null for a key to remove it.
     */
    #[Route('PATCH', '/api/settings')]
    public function update(): void
    {
        $body = Json::readBody();
        if (!is_array($body) || array_is_list($body)) {
            Json::error('Request body must be a JSON object', 422);
        }
        $this->settings->set($body);
        Json::send(['settings' => $this->settings->all()]);
    }
}
