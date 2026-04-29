/**
 * Non-sensitive user settings stored in localStorage.
 *
 * Values are plain scalars or arrays — nothing sensitive here.
 * Use vault.js for credentials and API keys.
 */

const KEY = 'quermy-settings';

function read() {
    try {
        const raw = localStorage.getItem(KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
    } catch {
        return {};
    }
}

/** Return all persisted settings as a key → value map. */
export function getSettings() {
    return read();
}

/**
 * Shallow-merge supplied values into the persisted settings.
 * Pass `null` as a value to remove a key.
 * Returns the updated settings object.
 */
export function updateSettings(values) {
    const data = read();
    for (const [key, value] of Object.entries(values)) {
        if (value === null) {
            delete data[key];
        } else {
            data[key] = value;
        }
    }
    localStorage.setItem(KEY, JSON.stringify(data));
    return data;
}
