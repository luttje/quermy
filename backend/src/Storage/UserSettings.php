<?php declare(strict_types=1);

namespace Quermy\Storage;

/**
 * Persistent non-encrypted user settings stored as JSON.
 *
 * Lives alongside the credential vault in the storage/ directory.
 * Values are plain scalars or arrays — nothing sensitive; use
 * CredentialVault for secrets.
 */
class UserSettings
{
    public function __construct(private string $settingsPath) {}

    /** Return all settings as a key → value map. */
    public function all(): array
    {
        return $this->read();
    }

    /** Return a single setting value, or $default when the key is absent. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read()[$key] ?? $default;
    }

    /**
     * Persist one or more settings (shallow-merge into existing values).
     * Pass null as a value to remove a key.
     */
    public function set(array $values): void
    {
        $data = $this->read();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $this->write($data);
    }

    // -------------------------------------------------------------------------

    private function read(): array
    {
        if (!is_file($this->settingsPath)) {
            return [];
        }
        $raw = file_get_contents($this->settingsPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $dir = dirname($this->settingsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = $this->settingsPath . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($tmp, 0600);
        rename($tmp, $this->settingsPath);
    }
}
