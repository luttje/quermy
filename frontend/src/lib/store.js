import { writable } from 'svelte/store';

// The currently bound session, or null.
export const session = writable(null);

// Toast notifications.
export const toasts = writable([]);

// Persistent SQL error log — [{ message, time, query? }], newest first.
export const sqlErrors = writable([]);

// Current SQL editor content — kept in sync by App.svelte.
export const currentSql = writable("");

let nextId = 1;
export function toast(message, type = 'info') {
    const id = nextId++;
    toasts.update((list) => [...list, { id, message, type }]);
    setTimeout(() => {
        toasts.update((list) => list.filter((t) => t.id !== id));
    }, 4500);
    if (type === 'error') {
        sqlErrors.update((list) => [{ message, time: new Date() }, ...list]);
    }
}

// AI key manager state — populated on mount by AIChatPanel.
// Shape: Array<{ id, label, provider, model, createdAt }>
export const aiKeys = writable([]);

// The active key + model chosen for the current chat session.
// Shape: { keyId: string, model: string } | null
export const activeAiKey = writable(null);

// Capabilities of the currently connected database engine, or null when
// disconnected. Shape mirrors the PHP DriverInterface::getCapabilities() return.
// {
//   columnTypes: string[],
//   supportsAutoIncrement: boolean,
//   supportsColumnAfter: boolean,
//   supportsModifyColumn: boolean,
//   supportsDropColumn: boolean,
//   supportsGetCreateTable: boolean,
//   supportsExplain: boolean,
//   supportsForeignKeys: boolean,
//   welcomeQuery: string,
//   structureQueryTemplate: string,
//   identifierOpen: string,
//   identifierClose: string,
// }
export const capabilities = writable(null);

