// API client for the Quermy backend.
// All requests are same-origin; we send cookies so the PHP session sticks.

const BASE = '/api';

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
};
