<?php declare(strict_types=1);

namespace Quermy\Storage;

use RuntimeException;

/**
 * Encrypted-at-rest storage of saved connection credentials.
 *
 * Design:
 *   - Master key lives in {storage}/quermy.key (mode 0600). Auto-generated
 *     on first run. Operators can replace it with a key from a secret manager.
 *   - Each saved connection's password is encrypted individually with
 *     AES-256-GCM (authenticated encryption — tamper-evident).
 *   - The vault file (vault.json) holds host/port/username/dbname in
 *     the clear (so the UI can list them) but only ciphertext for passwords.
 *   - Plaintext passwords are NEVER serialized to API responses. The client
 *     references a saved connection by its `id` and the backend looks the
 *     password up server-side at use-time.
 */
class CredentialVault
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private string $vaultPath,
        private string $keyPath,
    ) {}

    /** Save (or update) a connection. Returns the connection record (no password). */
    public function save(array $conn): array
    {
        $all = $this->readAll();

        $id = $conn['id'] ?? $this->randomId();
        $record = [
            'id'       => $id,
            'name'     => $conn['name'] ?? ($conn['host'] . ':' . $conn['port']),
            'engine'   => $conn['engine'],
            'host'     => $conn['host'],
            'port'     => (int)$conn['port'],
            'username' => $conn['username'],
            'database' => $conn['database'] ?? null,
            'password_enc' => $this->encrypt((string)($conn['password'] ?? '')),
            'createdAt'    => $conn['createdAt'] ?? date('c'),
            'updatedAt'    => date('c'),
        ];

        // Replace or append
        $found = false;
        foreach ($all as $i => $existing) {
            if ($existing['id'] === $id) {
                $all[$i] = $record;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $all[] = $record;
        }
        $this->writeAll($all);

        return $this->publicView($record);
    }

    public function delete(string $id): bool
    {
        $all = $this->readAll();
        $filtered = array_values(array_filter($all, fn($c) => $c['id'] !== $id));
        if (count($filtered) === count($all)) {
            return false;
        }
        $this->writeAll($filtered);
        return true;
    }

    /** @return array<int,array> Public view (no passwords). */
    public function listPublic(): array
    {
        return array_map([$this, 'publicView'], $this->readAll());
    }

    /** Internal — fetch full credentials (including decrypted password) for a saved connection. */
    public function loadCredentials(string $id): ?array
    {
        foreach ($this->readAll() as $c) {
            if ($c['id'] === $id) {
                return [
                    'id'       => $c['id'],
                    'engine'   => $c['engine'],
                    'host'     => $c['host'],
                    'port'     => (int)$c['port'],
                    'username' => $c['username'],
                    'password' => $this->decrypt($c['password_enc']),
                    'database' => $c['database'] ?? null,
                ];
            }
        }
        return null;
    }

    /*
     * AI provider keys — multi-key, multi-provider (encrypted at rest, same master key).
     */

    /**
     * Add a new AI provider key. Returns the public view (no decrypted key).
     *
     * @return array{id:string,label:string,provider:string,model:string,createdAt:string}
     */
    public function aiKeyAdd(string $label, string $provider, #[\SensitiveParameter] string $apiKey, string $model): array
    {
        $vault = $this->readVault();
        $entry = [
            'id'        => $this->randomId(),
            'label'     => $label,
            'provider'  => $provider,
            'model'     => $model,
            'key_enc'   => $this->encrypt($apiKey),
            'createdAt' => date('c'),
        ];
        $vault['aiKeys'][] = $entry;
        $this->writeVault($vault);
        return $this->aiKeyPublicView($entry);
    }

    /**
     * Update label and/or model for an existing AI key. Optionally rotate the API key.
     * Returns the updated public view, or null if not found.
     *
     * @return array{id:string,label:string,provider:string,model:string,createdAt:string}|null
     */
    public function aiKeyUpdate(string $id, ?string $label, ?string $model, #[\SensitiveParameter] ?string $apiKey): ?array
    {
        $vault = $this->readVault();
        foreach ($vault['aiKeys'] as &$entry) {
            if ($entry['id'] !== $id) continue;
            if ($label !== null) $entry['label'] = $label;
            if ($model !== null) $entry['model'] = $model;
            if ($apiKey !== null) $entry['key_enc'] = $this->encrypt($apiKey);
            $this->writeVault($vault);
            return $this->aiKeyPublicView($entry);
        }
        return null;
    }

    /** @return list<array{id:string,label:string,provider:string,model:string,createdAt:string}> */
    public function aiKeyList(): array
    {
        return array_values(array_map(
            [$this, 'aiKeyPublicView'],
            $this->readVault()['aiKeys'] ?? [],
        ));
    }

    public function aiKeyDelete(string $id): bool
    {
        $vault = $this->readVault();
        $before = count($vault['aiKeys'] ?? []);
        $vault['aiKeys'] = array_values(array_filter(
            $vault['aiKeys'] ?? [],
            fn($e) => $e['id'] !== $id,
        ));
        if (count($vault['aiKeys']) === $before) return false;
        $this->writeVault($vault);
        return true;
    }

    /**
     * Returns provider + decrypted API key + model for a stored key.
     * For server-side use only — never serialise this.
     *
     * @return array{id:string,provider:string,apiKey:string,model:string}|null
     */
    public function aiKeyGetDecrypted(string $id): ?array
    {
        foreach ($this->readVault()['aiKeys'] ?? [] as $entry) {
            if ($entry['id'] !== $id) continue;
            return [
                'id'       => $entry['id'],
                'provider' => $entry['provider'],
                'apiKey'   => $this->decrypt($entry['key_enc']),
                'model'    => $entry['model'],
            ];
        }
        return null;
    }

    /*
     * Internals
     */

    /** @return array{id:string,label:string,provider:string,model:string,createdAt:string} */
    private function aiKeyPublicView(array $e): array
    {
        return [
            'id'        => $e['id'],
            'label'     => $e['label'],
            'provider'  => $e['provider'],
            'model'     => $e['model'],
            'createdAt' => $e['createdAt'],
        ];
    }

    private function publicView(array $c): array
    {
        return [
            'id'       => $c['id'],
            'name'     => $c['name'],
            'engine'   => $c['engine'],
            'host'     => $c['host'],
            'port'     => $c['port'],
            'username' => $c['username'],
            'database' => $c['database'] ?? null,
            'updatedAt'=> $c['updatedAt'] ?? null,
        ];
    }

    /**
     * Read the entire vault object.
     */
    private function readVault(): array
    {
        if (!is_file($this->vaultPath))
            return ['connections' => [], 'aiKeys' => []];

        $raw = file_get_contents($this->vaultPath);

        if ($raw === false || $raw === '')
            return ['connections' => [], 'aiKeys' => []];

        $data = json_decode($raw, true);

        if (!is_array($data))
            return ['connections' => [], 'aiKeys' => []];

        return [
            'connections' => $data['connections'] ?? [],
            'aiKeys'      => $data['aiKeys']      ?? [],
        ];
    }

    private function readAll(): array
    {
        return $this->readVault()['connections'];
    }

    private function writeAll(array $connections): void
    {
        $vault = $this->readVault();
        $vault['connections'] = $connections;
        $this->writeVault($vault);
    }

    private function writeVault(array $vault): void
    {
        $dir = dirname($this->vaultPath);
        if (!is_dir($dir)) mkdir($dir, 0700, true);

        $tmp = $this->vaultPath . '.tmp';
        file_put_contents($tmp, json_encode($vault, JSON_PRETTY_PRINT));
        @chmod($tmp, 0600);
        rename($tmp, $this->vaultPath);
    }

    private function getKey(): string
    {
        if (!is_file($this->keyPath)) {
            $dir = dirname($this->keyPath);
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            $key = random_bytes(32); // 256-bit
            file_put_contents($this->keyPath, $key);
            @chmod($this->keyPath, 0600);
            return $key;
        }
        $key = file_get_contents($this->keyPath);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid master key.');
        }
        return $key;
    }

    private function encrypt(string $plain): string
    {
        $key = $this->getKey();
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ct);
    }

    private function decrypt(string $blob): string
    {
        $key  = $this->getKey();
        $raw  = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Corrupt ciphertext.');
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new RuntimeException('Decryption failed (key mismatch or tampered data).');
        }
        return $pt;
    }

    private function randomId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
