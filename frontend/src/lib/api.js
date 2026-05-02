// API client for the Quermy backend.
// All requests are same-origin; we send cookies so the PHP session sticks.

// Strip trailing slash from the Vite base so we can append /api cleanly.
export const BASE = import.meta.env.BASE_URL.replace(/\/$/, '');
export const BASE_API = `${BASE}/api`;

async function request(method, path, body) {
  const opts = {
    method,
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  };

  if (body !== undefined)
    opts.body = JSON.stringify(body);

  const res = await fetch(`${BASE_API}${path}`, opts);
  const text = await res.text();

  let data = {};

  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    console.error('Failed to parse JSON response:', text);
    data = { error: `HTTP ${res.status}: See console for more details` };
  }

  if (!res.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.status = res.status;

    throw err;
  }

  return data;
}

export const api = {
  checkSystem: async () => {
    const result = await fetch(`${BASE}/health`, { method: 'GET' })
      .catch(err => { throw new Error(`Failed to connect to backend: ${err.message}`); });
    const content = await result.json().catch(() => null);

    return {
      ok: result.ok,
      error: content?.error || (result.ok ? null : `HTTP ${result.status}`),
    };
  },

  // session
  getSession: () => request('GET', '/session'),
  disconnect: () => request('POST', '/session/disconnect'),
  getEngines: () => request('GET', '/engines'),
  getCapabilities: () => request('GET', '/capabilities'),
  getServerConfig: () => request('GET', '/server-config'),

  // connect
  connect: (c) => request('POST', '/connect', c),

  // data
  getDatabaseInfo: (db) => request('GET', `/databases/${encodeURIComponent(db)}/info`),
  listDatabaseCollations: (db) => request('GET', `/databases/${encodeURIComponent(db)}/collations`),
  renameDatabase: (db, newName) => request('POST', `/databases/${encodeURIComponent(db)}/rename`, { newName }),
  alterDatabaseCollation: (db, collation) => request('PATCH', `/databases/${encodeURIComponent(db)}/collation`, { collation }),
  dropDatabase: (db) => request('DELETE', `/databases/${encodeURIComponent(db)}`),

  // data
  listDatabases: () => request('GET', '/databases'),
  listTables: (db) => request('GET', `/databases/${encodeURIComponent(db)}/tables`),
  listViews: (db) => request('GET', `/databases/${encodeURIComponent(db)}/views`),
  getViewDefinition: (db, view) =>
    request('GET', `/databases/${encodeURIComponent(db)}/views/${encodeURIComponent(view)}`),
  saveViewDefinition: (db, view, definition) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/views/${encodeURIComponent(view)}`, { definition }),
  dropView: (db, view) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/views/${encodeURIComponent(view)}`),

  listProcedures: (db) => request('GET', `/databases/${encodeURIComponent(db)}/procedures`),
  getProcedureDefinition: (db, procedure) =>
    request('GET', `/databases/${encodeURIComponent(db)}/procedures/${encodeURIComponent(procedure)}`),
  saveProcedureDefinition: (db, procedure, definition) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/procedures/${encodeURIComponent(procedure)}`, { definition }),
  dropProcedure: (db, procedure) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/procedures/${encodeURIComponent(procedure)}`),

  listFunctions: (db) => request('GET', `/databases/${encodeURIComponent(db)}/functions`),
  getFunctionDefinition: (db, fn) =>
    request('GET', `/databases/${encodeURIComponent(db)}/functions/${encodeURIComponent(fn)}`),
  saveFunctionDefinition: (db, fn, definition) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/functions/${encodeURIComponent(fn)}`, { definition }),
  dropFunction: (db, fn) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/functions/${encodeURIComponent(fn)}`),

  listEvents: (db) => request('GET', `/databases/${encodeURIComponent(db)}/events`),
  getEventDefinition: (db, event) =>
    request('GET', `/databases/${encodeURIComponent(db)}/events/${encodeURIComponent(event)}`),
  saveEventDefinition: (db, event, definition) =>
    request('PUT', `/databases/${encodeURIComponent(db)}/events/${encodeURIComponent(event)}`, { definition }),
  dropEvent: (db, event) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/events/${encodeURIComponent(event)}`),
  // table settings
  getTableInfo: (db, t) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/info`),
  listTableCollations: (db, t) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/collations`),
  alterTableCollation: (db, t, collation) =>
    request('PATCH', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/collation`, { collation }),
  listTableEngines: (db, t) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/engines`),
  alterTableEngine: (db, t, engine) =>
    request('PATCH', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/engine`, { engine }),
  alterTableAutoIncrement: (db, t, value) =>
    request('PATCH', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/auto-increment`, { value }),
  dropTable: (db, t, force = false) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}`, { force }),
  truncateTable: (db, t, force = false) =>
    request('POST', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/truncate`, { force }),

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
  reorderColumn: (db, t, colName, afterColName) =>
    request('PATCH', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/columns/${encodeURIComponent(colName)}/position`, { after: afterColName ?? null }),

  // index management
  getTableIndexes: (db, t) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/indexes`),
  createIndex: (db, t, def) =>
    request('POST', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/indexes`, def),
  dropIndex: (db, t, indexName, isPrimary) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/indexes/${encodeURIComponent(indexName)}`, { isPrimary: !!isPrimary }),

  // foreign key management
  getTableForeignKeys: (db, t) =>
    request('GET', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/foreign-keys`),
  createForeignKey: (db, t, def) =>
    request('POST', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/foreign-keys`, def),
  dropForeignKey: (db, t, constraint) =>
    request('DELETE', `/databases/${encodeURIComponent(db)}/tables/${encodeURIComponent(t)}/foreign-keys/${encodeURIComponent(constraint)}`),

  /**
   * Export selected tables in the given format. Returns { blob, filename }.
   * @param {Record<string, string[]>} databases  { dbName: [table, …] }
   * @param {'csv'|'sql'|'xml'|'json'} format
   * @param {boolean} includeStructure
   * @param {boolean} includeData
   */
  exportData: async (databases, format, includeStructure, includeData) => {
    const res = await fetch(`${BASE_API}/export`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ databases, format, includeStructure, includeData }),
    });
    if (!res.ok) {
      const text = await res.text();
      let data = {};
      try { data = JSON.parse(text); } catch { data = { error: text }; }
      const err = new Error(data.error || `HTTP ${res.status}`);
      err.status = res.status;
      throw err;
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') ?? '';
    const filename = cd.match(/filename="([^"]+)"/)?.[1] ?? `export.${format}`;
    return { blob, filename };
  },

  // AI providers (static, no auth)
  getAiProviders: () => request('GET', '/ai/providers'),

  /**
   * Stream an AI chat response as an async generator of typed event objects.
   *
   * Yields one of:
   *   { type: 'text',        chunk: string }
   *   { type: 'tool_call',   name: string, arguments: object }
   *   { type: 'tool_result', name: string, ok: boolean, result?: object, error?: string }
   *
   * The caller is responsible for obtaining `provider` and `apiKey` by calling
   * vault.getDecryptedAiKey() first — this function simply forwards them to the
   * backend which uses them for a single request and does not persist them.
   * @param {string} apiKey   Decrypted API key (obtained from vault.getDecryptedAiKey).
   * @param {string} model
   * @param {Array<{role:string,content:string}>} messages
   * @returns {AsyncGenerator<object>}
   */
  async *aiChatStream(provider, apiKey, model, messages) {
    const res = await fetch(`${BASE_API}/ai/chat/stream`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ provider, apiKey, model, messages }),
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
