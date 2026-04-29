// API client for the Quermy backend.
// All requests are same-origin; we send cookies so the PHP session sticks.

// Strip trailing slash from the Vite base so we can append /api cleanly.
// Generic build: BASE_URL = '/'        → BASE = '/api'
// Laragon build: BASE_URL = '/quermy/' → BASE = '/quermy/api'
export const BASE = import.meta.env.BASE_URL.replace(/\/$/, '') + '/api';

async function request(method, path, body) {
  const opts = {
    method,
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  };
  if (body !== undefined) opts.body = JSON.stringify(body);

  const res = await fetch(`${BASE}${path}`, opts);
  const text = await res.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch { data = { error: text }; }
  if (!res.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.status = res.status;
    throw err;
  }
  return data;
}

export const api = {
  // session
  getSession: () => request('GET', '/session'),
  disconnect: () => request('POST', '/session/disconnect'),
  getEngines: () => request('GET', '/engines'),
  getCapabilities: () => request('GET', '/capabilities'),

  // saved connections
  listConnections: () => request('GET', '/connections'),
  saveConnection: (c) => request('POST', '/connections', c),
  deleteConnection: (id) => request('DELETE', `/connections/${id}`),

  // connect
  connect: (c) => request('POST', '/connect', c),
  connectSaved: (id) => request('POST', `/connect/saved/${id}`),

  // data
  listDatabases: () => request('GET', '/databases'),
  listTables: (db) => request('GET', `/databases/${encodeURIComponent(db)}/tables`),
  browseTable: (db, t, limit = 100, offset = 0) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}?limit=${limit}&offset=${offset}`),
  runQuery: (db, sql) => request('POST', '/query', { database: db, sql }),

  // row mutations
  insertRow: (db, t, values) =>
    request('POST', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/rows`, { values }),
  updateRow: (db, t, where, values) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/rows`, { where, values }),
  deleteRow: (db, t, where) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/rows`, { where }),

  // column mutations
  addColumn: (db, t, definition) =>
    request('POST', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/columns`, definition),
  modifyColumn: (db, t, colName, definition) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/columns/${encodeURIComponent(colName)}`, definition),
  deleteColumn: (db, t, colName) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/columns/${encodeURIComponent(colName)}`),

  // User settings (non-sensitive, plaintext)
  getSettings: () => request('GET', '/settings'),
  updateSettings: (values) => request('PATCH', '/settings', values),

  // AI providers (static, no auth)
  getAiProviders: () => request('GET', '/ai/providers'),

  // AI key management
  listAiKeys: () => request('GET', '/ai/keys'),
  addAiKey: (label, provider, apiKey, model) =>
    request('POST', '/ai/keys', { label, provider, apiKey, model }),
  updateAiKey: (id, changes) => request('PATCH', `/ai/keys/${id}`, changes),
  deleteAiKey: (id) => request('DELETE', `/ai/keys/${id}`),
  getKeyModels: (id) => request('GET', `/ai/keys/${id}/models`),

  /**
   * Stream an AI chat response as an async generator of typed event objects.
   *
   * Yields one of:
   *   { type: 'text',        chunk: string }
   *   { type: 'tool_call',   name: string, arguments: object }
   *   { type: 'tool_result', name: string, ok: boolean, result?: object, error?: string }
   *
   * Previously this yielded raw strings. Callers that only care about text
   * should filter for event.type === 'text' and read event.chunk.
   *
   * @param {string} keyId
   * @param {string} model
   * @param {Array<{role:string,content:string}>} messages
   * @returns {AsyncGenerator<object>}
   */
  async *aiChatStream(keyId, model, messages) {
    const res = await fetch(`${BASE}/ai/chat/stream`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ keyId, model, messages }),
    });

    if (!res.ok) {
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch { data = { error: text }; }
      const err = new Error(data.error || `HTTP ${res.status}`);
      err.status = res.status;
      throw err;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    // The PHP backend emits tool_call (with arguments) before tool_result
    // (with only ok/error — no result payload). We bridge the gap by
    // caching each tool's arguments here and attaching them as `result`
    // when the matching tool_result arrives.
    const pendingToolArgs = {};

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';
        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          const raw = line.slice(6);
          if (raw === '[DONE]') return;
          const parsed = JSON.parse(raw);
          if (parsed.error) throw new Error(parsed.error);

          if (parsed.type === 'text') {
            yield { type: 'text', chunk: parsed.chunk };
          } else if (parsed.type === 'tool_call') {
            // Stash the arguments so we can attach them to the result below
            pendingToolArgs[parsed.name] = parsed.arguments ?? {};
            yield { type: 'tool_call', name: parsed.name, arguments: parsed.arguments };
          } else if (parsed.type === 'tool_result') {
            // Attach the previously-stashed arguments as `result` so callers
            // (e.g. the suggest_query handler in AIChatPanel) have the payload
            // they need without requiring PHP to re-emit it.
            const result = pendingToolArgs[parsed.name] ?? null;
            delete pendingToolArgs[parsed.name];
            yield {
              type: 'tool_result',
              name: parsed.name,
              ok: parsed.ok,
              result,
              error: parsed.error ?? null,
            };
          }
          // Unknown event types are silently ignored for forward-compatibility.
        }
      }
    } finally {
      reader.cancel();
    }
  },
};
