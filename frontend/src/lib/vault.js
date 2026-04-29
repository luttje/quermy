/**
 * Client-side credential vault.
 *
 * Connections and AI provider keys are stored in IndexedDB.  Sensitive fields
 * (passwords, API keys) are either stored as-is (plain mode) or encrypted with
 * AES-256-GCM using a key derived from a user-supplied master password via
 * PBKDF2 (protected mode).
 *
 * Vault modes
 * - 'uninitialized'  First run — user hasn't chosen a mode yet.
 * - 'plain'          Credentials stored without encryption (no password).
 * - 'protected'      AES-256-GCM; key derived from master password.
 *                    Only the PBKDF2 salt + a verifier blob are stored.
 *                    The master password and derived key are NEVER stored.
 *
 * In protected mode the vault is "locked" after each page load.  Call
 * unlockVault(masterPassword) once per session to load the derived key into
 * memory.  Call lockVault() to wipe it.
 *
 * Non-sensitive settings live in localStorage; see settings.js.
 */

const DB_NAME = 'quermy-vault';
const DB_VERSION = 1;

// ---------------------------------------------------------------------------
// IndexedDB helpers
// ---------------------------------------------------------------------------

let _db = null;

function openDb() {
    if (_db) return Promise.resolve(_db);
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'k' });
            if (!db.objectStoreNames.contains('connections')) db.createObjectStore('connections', { keyPath: 'id' });
            if (!db.objectStoreNames.contains('aiKeys')) db.createObjectStore('aiKeys', { keyPath: 'id' });
        };
        req.onsuccess = () => { _db = req.result; resolve(_db); };
        req.onerror = () => reject(req.error);
    });
}

function idbGet(store, key) {
    return openDb().then(db => new Promise((res, rej) => {
        const req = db.transaction(store, 'readonly').objectStore(store).get(key);
        req.onsuccess = () => res(req.result ?? null);
        req.onerror = () => rej(req.error);
    }));
}

function idbGetAll(store) {
    return openDb().then(db => new Promise((res, rej) => {
        const req = db.transaction(store, 'readonly').objectStore(store).getAll();
        req.onsuccess = () => res(req.result);
        req.onerror = () => rej(req.error);
    }));
}

function idbPut(store, value) {
    return openDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction(store, 'readwrite');
        tx.objectStore(store).put(value);
        tx.oncomplete = res;
        tx.onerror = () => rej(tx.error);
    }));
}

function idbDelete(store, key) {
    return openDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction(store, 'readwrite');
        tx.objectStore(store).delete(key);
        tx.oncomplete = res;
        tx.onerror = () => rej(tx.error);
    }));
}

function idbClearStore(store) {
    return openDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction(store, 'readwrite');
        tx.objectStore(store).clear();
        tx.oncomplete = res;
        tx.onerror = () => rej(tx.error);
    }));
}

// ---------------------------------------------------------------------------
// Vault lifecycle
// ---------------------------------------------------------------------------

/**
 * In-memory derived key.  Non-null only when the vault is in 'protected'
 * mode AND has been unlocked this session.  Never written to disk.
 */
let _cryptoKey = null;

const PBKDF2_ITERATIONS = 200_000;
const VERIFIER_PLAINTEXT = 'quermy-vault-v1';

/**
 * Returns the current vault mode: 'uninitialized' | 'plain' | 'protected'.
 *
 * - 'uninitialized' — first run; call setupVault() before anything else.
 * - 'plain'         — credentials stored without encryption.
 * - 'protected'     — AES-256-GCM; key derived from master password via PBKDF2.
 */
export async function getVaultMode() {
    const cfg = await idbGet('meta', 'vaultConfig');
    if (!cfg) return 'uninitialized';
    return cfg.v.mode;
}

/**
 * Returns true when the vault is ready for credential operations:
 *   - always true in 'plain' mode
 *   - true in 'protected' mode only after unlockVault() has been called
 *   - false when 'uninitialized'
 */
export async function isUnlocked() {
    const mode = await getVaultMode();
    if (mode === 'plain') return true;
    if (mode === 'protected') return _cryptoKey !== null;
    return false;
}

/**
 * Initialise the vault (call once when getVaultMode() returns 'uninitialized').
 * Clears any stale records left from a previous vault incarnation.
 *
 * @param {string|null} masterPassword
 *   null / ''  → plain mode (credentials stored without encryption)
 *   string     → protected mode; key derived via PBKDF2 and kept in memory only
 */
export async function setupVault(masterPassword) {
    // Wipe stale records — they cannot be read without the old key anyway.
    await idbClearStore('connections');
    await idbClearStore('aiKeys');
    await idbDelete('meta', 'cryptoKey'); // remove old auto-key if present

    if (!masterPassword) {
        await idbPut('meta', { k: 'vaultConfig', v: { mode: 'plain' } });
        _cryptoKey = null;
        return;
    }

    const salt = crypto.getRandomValues(new Uint8Array(32));
    _cryptoKey = await deriveKey(masterPassword, salt);

    // Verifier: encrypt a known string so future unlocks can detect wrong passwords.
    const verifier = await encryptWithKey(_cryptoKey, VERIFIER_PLAINTEXT);
    await idbPut('meta', {
        k: 'vaultConfig',
        v: { mode: 'protected', salt: Array.from(salt), verifier },
    });
}

/**
 * Unlock a protected vault with the master password.
 * The derived key is held in memory for the rest of the page session.
 * @returns {Promise<boolean>} true if the password was correct
 */
export async function unlockVault(masterPassword) {
    const cfg = await idbGet('meta', 'vaultConfig');
    if (!cfg || cfg.v.mode !== 'protected') return false;
    try {
        const salt = new Uint8Array(cfg.v.salt);
        const key = await deriveKey(masterPassword, salt);
        // AES-GCM authentication will throw if the tag doesn't match.
        const check = await decryptWithKey(key, cfg.v.verifier);
        if (check !== VERIFIER_PLAINTEXT) return false;
        _cryptoKey = key;
        return true;
    } catch {
        return false;
    }
}

/** Wipe the derived key from memory (locks the vault until next unlockVault). */
export function lockVault() {
    _cryptoKey = null;
}

// ---------------------------------------------------------------------------
// PBKDF2 key derivation
// ---------------------------------------------------------------------------

async function deriveKey(password, salt) {
    const base = await crypto.subtle.importKey(
        'raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveKey'],
    );
    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, hash: 'SHA-256', iterations: PBKDF2_ITERATIONS },
        base,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

// ---------------------------------------------------------------------------
// Low-level encrypt / decrypt (require an explicit CryptoKey argument)
// ---------------------------------------------------------------------------

/** Encrypt with a given key → base64(12-byte-IV ‖ ciphertext). */
async function encryptWithKey(key, plain) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv }, key, new TextEncoder().encode(plain),
    );
    const buf = new Uint8Array(iv.length + ct.byteLength);
    buf.set(iv, 0);
    buf.set(new Uint8Array(ct), iv.length);
    return btoa(String.fromCharCode(...buf));
}

/** Decrypt a base64 blob (produced by encryptWithKey) with a given key. */
async function decryptWithKey(key, blob) {
    const raw = Uint8Array.from(atob(blob), c => c.charCodeAt(0));
    const pt = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: raw.slice(0, 12) }, key, raw.slice(12),
    );
    return new TextDecoder().decode(pt);
}

// ---------------------------------------------------------------------------
// Mode-aware encrypt / decrypt (use these inside the vault API)
// ---------------------------------------------------------------------------

/**
 * Encrypt a plaintext string according to the current vault mode.
 * - plain:         returns the string unchanged
 * - protected:     encrypts with the in-memory derived key
 * - locked/uninit: throws
 */
async function encrypt(plain) {
    const mode = await getVaultMode();
    if (mode === 'plain') return plain;
    if (mode === 'uninitialized') throw new Error('Vault is not initialised.');
    if (!_cryptoKey) throw new Error('Vault is locked.');
    return encryptWithKey(_cryptoKey, plain);
}

/**
 * Decrypt a blob produced by encrypt().
 * - plain:         returns the blob unchanged (it was stored as plaintext)
 * - protected:     decrypts with the in-memory derived key
 * - locked/uninit: throws
 */
async function decrypt(blob) {
    const mode = await getVaultMode();
    if (mode === 'plain') return blob;
    if (mode === 'uninitialized') throw new Error('Vault is not initialised.');
    if (!_cryptoKey) throw new Error('Vault is locked.');
    return decryptWithKey(_cryptoKey, blob);
}

function randomId() {
    // 16 hex chars (64 bits) — same length as the backend used to generate.
    return Array.from(crypto.getRandomValues(new Uint8Array(8)))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}

// ---------------------------------------------------------------------------
// Connections
// ---------------------------------------------------------------------------

/**
 * List all saved connections — passwords excluded.
 * @returns {Promise<Array<{id,name,engine,host,port,username,database,updatedAt}>>}
 */
export async function listConnections() {
    const rows = await idbGetAll('connections');
    return rows.map(connPublicView);
}

/**
 * Save (or update) a connection record.  Returns the public view (no password).
 * @param {object} conn  May include an `id` to update an existing record.
 */
export async function saveConnection(conn) {
    const id = conn.id ?? randomId();
    const now = new Date().toISOString();
    const record = {
        id,
        name: conn.name || (conn.host ? `${conn.host}:${conn.port}` : conn.database) || id,
        engine: conn.engine,
        host: conn.host ?? null,
        port: conn.port != null ? Number(conn.port) : null,
        username: conn.username ?? null,
        database: conn.database ?? null,
        passwordEnc: await encrypt(String(conn.password ?? '')),
        createdAt: conn.createdAt ?? now,
        updatedAt: now,
    };
    await idbPut('connections', record);
    return connPublicView(record);
}

/** Delete a connection by id.  Returns true if the record existed. */
export async function deleteConnection(id) {
    const existing = await idbGet('connections', id);
    if (!existing) return false;
    await idbDelete('connections', id);
    return true;
}

/**
 * Load full credentials including the decrypted password.
 * For use at connect-time only — never expose this to the UI.
 */
export async function loadConnection(id) {
    const record = await idbGet('connections', id);
    if (!record) return null;
    return { ...connPublicView(record), password: await decrypt(record.passwordEnc) };
}

function connPublicView(c) {
    return {
        id: c.id,
        name: c.name,
        engine: c.engine,
        host: c.host,
        port: c.port,
        username: c.username,
        database: c.database,
        updatedAt: c.updatedAt,
    };
}

// ---------------------------------------------------------------------------
// AI provider keys
// ---------------------------------------------------------------------------

/**
 * List all stored AI keys — raw API key excluded.
 * @returns {Promise<Array<{id,label,provider,model,createdAt}>>}
 */
export async function listAiKeys() {
    const rows = await idbGetAll('aiKeys');
    return rows.map(aiKeyPublicView);
}

/**
 * Add a new AI key.  Returns the public view.
 * @param {string} label
 * @param {string} provider
 * @param {string} apiKey   Raw API key — encrypted before storage.
 * @param {string} model
 */
export async function addAiKey(label, provider, apiKey, model) {
    const entry = {
        id: randomId(),
        label,
        provider,
        model,
        apiKeyEnc: await encrypt(apiKey),
        createdAt: new Date().toISOString(),
    };
    await idbPut('aiKeys', entry);
    return aiKeyPublicView(entry);
}

/**
 * Update an existing AI key's label, model, and/or API key.
 * Returns the updated public view, or null if not found.
 * @param {string}  id
 * @param {{ label?: string, model?: string, apiKey?: string }} changes
 */
export async function updateAiKey(id, { label, model, apiKey } = {}) {
    const existing = await idbGet('aiKeys', id);
    if (!existing) return null;
    if (label != null) existing.label = label;
    if (model != null) existing.model = model;
    if (apiKey != null) existing.apiKeyEnc = await encrypt(apiKey);
    await idbPut('aiKeys', existing);
    return aiKeyPublicView(existing);
}

/** Delete an AI key by id.  Returns true if the record existed. */
export async function deleteAiKey(id) {
    const existing = await idbGet('aiKeys', id);
    if (!existing) return false;
    await idbDelete('aiKeys', id);
    return true;
}

/**
 * Returns the decrypted credentials for a stored key.
 * For use at chat-time only — never expose `apiKey` to the UI.
 * @returns {Promise<{id,provider,apiKey,model}|null>}
 */
export async function getDecryptedAiKey(id) {
    const record = await idbGet('aiKeys', id);
    if (!record) return null;
    return {
        id: record.id,
        provider: record.provider,
        apiKey: await decrypt(record.apiKeyEnc),
        model: record.model,
    };
}

function aiKeyPublicView(e) {
    return {
        id: e.id,
        label: e.label,
        provider: e.provider,
        model: e.model,
        createdAt: e.createdAt,
    };
}
