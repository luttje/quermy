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

    // AI config & chat
    getAiConfig: () => request('GET', '/ai/config'),
    saveAiConfig: (apiKey, model) => request('POST', '/ai/config', { apiKey, model }),
    deleteAiConfig: () => request('DELETE', '/ai/config'),
    aiChat: (messages) => request('POST', '/ai/chat', { messages }),

    /**
     * Stream an AI chat response as an async generator of string chunks.
     * Yields each text fragment as it arrives via Server-Sent Events.
     *
     * @param {Array<{role:string,content:string}>} messages
     * @returns {AsyncGenerator<string>}
     */
    async *aiChatStream(messages) {
        const res = await fetch(`${BASE}/ai/chat/stream`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages }),
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
                    if (parsed.chunk !== undefined) yield parsed.chunk;
                }
            }
        } finally {
            reader.cancel();
        }
    },
};
