import { writable } from 'svelte/store';

// Top-level "view" controlling which page renders.
//   { name: 'connect' }
//   { name: 'databases' }
//   { name: 'tables', database: 'foo' }
//   { name: 'browse', database: 'foo', table: 'bar' }
//   { name: 'query', database: 'foo' }     // database optional
export const view = writable({ name: 'connect' });

// The currently bound session, or null.
export const session = writable(null);

// Toast notifications.
export const toasts = writable([]);

let nextId = 1;
export function toast(message, type = 'info') {
    const id = nextId++;
    toasts.update((list) => [...list, { id, message, type }]);
    setTimeout(() => {
        toasts.update((list) => list.filter((t) => t.id !== id));
    }, 4500);
}

// AI configuration — source of truth is the server (CredentialVault, encrypted at rest).
// Shape: { configured: boolean, model: string }
// Populated on mount by AIChatPanel via api.getAiConfig().
export const aiConfig = writable({ configured: false, model: 'gpt-4o-mini' });

