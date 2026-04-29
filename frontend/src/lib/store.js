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

// AI key manager state — populated on mount by AIChatPanel.
// Shape: Array<{ id, label, provider, model, createdAt }>
export const aiKeys = writable([]);

// The active key + model chosen for the current chat session.
// Shape: { keyId: string, model: string } | null
export const activeAiKey = writable(null);

