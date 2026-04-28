<script>
    import { onMount, onDestroy } from "svelte";
    import { api } from "./lib/api.js";
    import { session, toast } from "./lib/store.js";

    import ConnectView from "./views/ConnectView.svelte";
    import TreeView from "./components/TreeView.svelte";
    import AIChatPanel from "./components/AIChatPanel.svelte";
    import DataTable from "./components/DataTable.svelte";
    import Toaster from "./components/Toaster.svelte";
    import ResizeHandle from "./components/ResizeHandle.svelte";
    import SqlEditor from "./components/SqlEditor.svelte";

    let bootstrapping = true;

    // --- URL hash helpers ---
    function buildHash(ctx) {
        if (!ctx) return "";
        const p = new URLSearchParams({
            db: ctx.db,
            table: ctx.table,
            mode: ctx.mode,
        });
        return "#" + p.toString();
    }

    function parseHash() {
        const raw = location.hash.slice(1);
        if (!raw) return null;
        try {
            const p = new URLSearchParams(raw);
            const db = p.get("db");
            const table = p.get("table");
            const mode = p.get("mode") || "data";
            if (db && table) return { db, table, mode };
        } catch (_) {}
        return null;
    }

    function handlePopState() {
        const ctx = parseHash();
        if (ctx && $session) {
            loadTableFromUrl(ctx);
        } else {
            tableContext = null;
            result = null;
        }
    }

    // --- DB picker + SQL editor state
    let sql = `SELECT NOW() AS now, VERSION() AS version;`;
    let queryDb = "";
    let databases = [];
    let busy = false;
    let result = null; // { columns, rows, total, durationMs, isSelect, affected }
    let errors = []; // [{ message, time }] — persistent SQL error log, newest first
    let tableContext = null; // { db, table, mode } — set when browsing via tree

    // Resizable panes
    let sqlPaneHeight = 220;
    let leftWidth = 220;
    let rightWidth = 260;

    onMount(async () => {
        window.addEventListener("popstate", handlePopState);
        try {
            const r = await api.getSession();
            if (r.active) {
                session.set(r.active);
                // Restore state from URL after session is confirmed
                const ctx = parseHash();
                if (ctx) loadTableFromUrl(ctx);
            }
        } catch (_) {
            /* no session — show connect view */
        } finally {
            bootstrapping = false;
        }
    });

    onDestroy(() => {
        window.removeEventListener("popstate", handlePopState);
    });

    // Reload databases whenever session becomes active
    $: if ($session) loadDatabases();

    async function loadDatabases() {
        try {
            const r = await api.listDatabases();
            databases = r.databases || [];
        } catch (_) {}
    }

    async function disconnect() {
        try {
            await api.disconnect();
            session.set(null);
            databases = [];
            result = null;
            errors = [];
            tableContext = null;
            history.replaceState(null, "", location.pathname);
            toast("Disconnected");
        } catch (e) {
            toast(e.message, "error");
        }
    }

    async function run() {
        if (!sql.trim() || busy) return;
        busy = true;
        tableContext = null; // manual SQL run clears table context
        try {
            const r = await api.runQuery(queryDb, sql);
            result = r;
            if (!r.isSelect) {
                toast(
                    `OK · ${r.affected} row${r.affected === 1 ? "" : "s"} affected · ${r.durationMs.toFixed(2)} ms`,
                    "success",
                );
            }
        } catch (e) {
            errors = [{ message: e.message, time: new Date() }, ...errors];
        } finally {
            busy = false;
        }
    }

    function onGlobalKeydown(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
            e.preventDefault();
            run();
        }
    }

    function handleRunSql(e) {
        const { db, sql: newSql } = e.detail;
        sql = newSql;
        if (db) queryDb = db;
        run();
    }

    async function handleOpenTable(e) {
        const { db: tDb, table: tTbl, mode: tMode } = e.detail;
        const newCtx = { db: tDb, table: tTbl, mode: tMode };
        history.pushState(newCtx, "", buildHash(newCtx));
        await loadTableFromUrl(newCtx);
    }

    async function loadTableFromUrl(ctx) {
        const { db: tDb, table: tTbl, mode: tMode } = ctx;
        queryDb = tDb;

        // Also populate the SQL editor for reference / manual tweaking
        const qDb = "`" + tDb.replace(/`/g, "``") + "`";
        const qTbl = "`" + tTbl.replace(/`/g, "``") + "`";
        sql =
            tMode === "data"
                ? `SELECT *\nFROM ${qDb}.${qTbl}\nLIMIT 100;`
                : `SHOW COLUMNS FROM ${qDb}.${qTbl};`;

        busy = true;
        tableContext = null;
        result = null;

        try {
            const t0 = performance.now();
            const r = await api.browseTable(tDb, tTbl);
            const dt = performance.now() - t0;
            result = {
                columns: r.columns,
                rows: r.rows,
                affected: r.total,
                isSelect: true,
                durationMs: Math.round(dt * 100) / 100,
            };
            tableContext = { db: tDb, table: tTbl, mode: tMode };
        } catch (e) {
            errors = [{ message: e.message, time: new Date() }, ...errors];
        } finally {
            busy = false;
        }
    }

    async function handleRefresh() {
        if (!tableContext) return;
        busy = true;
        try {
            const t0 = performance.now();
            const r = await api.browseTable(
                tableContext.db,
                tableContext.table,
            );
            const dt = performance.now() - t0;
            result = {
                columns: r.columns,
                rows: r.rows,
                affected: r.total,
                isSelect: true,
                durationMs: Math.round(dt * 100) / 100,
            };
        } catch (e) {
            toast(e.message, "error");
        } finally {
            busy = false;
        }
    }

    function handleSqlResize({ detail }) {
        sqlPaneHeight = Math.max(
            100,
            Math.min(600, sqlPaneHeight + detail.delta),
        );
    }

    function handleLeftResize({ detail }) {
        leftWidth = Math.max(150, Math.min(500, leftWidth + detail.delta));
    }

    function handleRightResize({ detail }) {
        rightWidth = Math.max(150, Math.min(500, rightWidth - detail.delta));
    }
</script>

<svelte:window on:keydown={onGlobalKeydown} />

<Toaster />

{#if bootstrapping}
    <div class="boot mono">initializing…</div>
{:else if !$session}
    <div class="connect-scroll">
        <ConnectView />
    </div>
{:else}
    <div class="app-shell">
        <!-- Topbar -->
        <header class="topbar">
            <div class="brand">
                <span class="brand-mark">Q</span>
                <span class="brand-name">Quermy</span>
            </div>

            <div class="conn-pill">
                <span class="conn-dot"></span>
                <span class="conn-text mono">
                    {$session.username}@{$session.host}{$session.port !== 3306
                        ? ":" + $session.port
                        : ""}
                </span>
                <span class="conn-engine mono">{$session.engine}</span>
            </div>

            <div class="spacer"></div>

            <button
                class="btn btn-ghost topbar-disconnect"
                on:click={disconnect}
            >
                Disconnect
            </button>
        </header>

        <!-- 3-panel workspace -->
        <div class="workspace">
            <!-- Left: Explorer tree -->
            <aside class="sidebar-left" style="width: {leftWidth}px">
                <TreeView
                    {databases}
                    {busy}
                    activeContext={tableContext}
                    on:runSql={handleRunSql}
                    on:openTable={handleOpenTable}
                />
            </aside>
            <ResizeHandle orientation="vertical" on:resize={handleLeftResize} />

            <!-- Middle: SQL editor + results -->
            <div class="workspace-middle">
                <!-- SQL Editor pane (resizable) -->
                <div class="sql-pane" style="height: {sqlPaneHeight}px">
                    <div class="sql-head">
                        <label class="db-pick mono">
                            <span class="db-lbl">DB</span>
                            <select class="db-sel" bind:value={queryDb}>
                                <option value="">(none)</option>
                                {#each databases as d}
                                    <option value={d}>{d}</option>
                                {/each}
                            </select>
                        </label>
                        <div class="sql-head-right">
                            <span class="char-count muted mono"
                                >{sql.length} chars</span
                            >
                            <button
                                class="btn btn-primary run-btn"
                                on:click={run}
                                disabled={busy || !sql.trim()}
                            >
                                {busy ? "Running…" : "Run"}
                                <span class="kbd">⌘↵</span>
                            </button>
                        </div>
                    </div>
                    <SqlEditor bind:value={sql} />
                </div>

                <!-- Resize handle -->
                <ResizeHandle on:resize={handleSqlResize} />

                <!-- Result pane -->
                <div class="result-pane">
                    {#if result}
                        {#if result.isSelect}
                            <DataTable
                                columns={result.columns}
                                rows={result.rows}
                                total={result.affected}
                                durationMs={result.durationMs}
                                db={tableContext?.db ?? null}
                                table={tableContext?.table ?? null}
                                mode={tableContext?.mode ?? "data"}
                                on:refresh={handleRefresh}
                            />
                        {:else}
                            <div class="ok-result">
                                <div class="ok-tag mono">OK</div>
                                <div>
                                    <strong class="mono"
                                        >{result.affected}</strong
                                    >
                                    row{result.affected === 1 ? "" : "s"} affected
                                    <span class="muted"> · </span>
                                    <span class="mono"
                                        >{result.durationMs.toFixed(2)} ms</span
                                    >
                                </div>
                            </div>
                        {/if}
                    {:else}
                        <div class="result-hint">
                            Press <span class="kbd">⌘ Enter</span> or click
                            <strong>Run</strong> to execute, or pick a table from
                            the explorer.
                        </div>
                    {/if}

                    {#if errors.length > 0}
                        <div class="error-log">
                            <div class="error-log-header">
                                <span class="error-log-title mono"
                                    >SQL ERRORS</span
                                >
                                <span class="error-log-count mono"
                                    >{errors.length}</span
                                >
                                <button
                                    class="btn btn-ghost error-log-clear"
                                    on:click={() => (errors = [])}>Clear</button
                                >
                            </div>
                            {#each errors as err}
                                <div class="error-entry">
                                    <div class="error-entry-meta">
                                        <span class="err-tag mono">ERROR</span>
                                        <span class="error-ts mono"
                                            >{err.time.toLocaleTimeString([], {
                                                hour: "2-digit",
                                                minute: "2-digit",
                                                second: "2-digit",
                                            })}</span
                                        >
                                    </div>
                                    <pre
                                        class="error-entry-msg mono">{err.message}</pre>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>
            </div>

            <!-- Right: AI chat -->
            <ResizeHandle
                orientation="vertical"
                on:resize={handleRightResize}
            />
            <aside class="sidebar-right" style="width: {rightWidth}px">
                <AIChatPanel />
            </aside>
        </div>
    </div>
{/if}

<style>
    /* ---- Boot / connect wrapper ---- */
    .boot {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--ink-3);
    }

    .connect-scroll {
        flex: 1;
        overflow-y: auto;
    }

    /* ---- App shell (workspace) ---- */
    .app-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    /* ---- Topbar ---- */
    .topbar {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 0 18px;
        height: 44px;
        flex-shrink: 0;
        background: rgba(10, 12, 10, 0.85);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--line);
        z-index: 50;
    }

    .brand {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .brand-mark {
        width: 24px;
        height: 24px;
        border-radius: 5px;
        background: var(--acc);
        color: #0a0c0a;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-family: var(--font-display);
        font-weight: 600;
        font-size: 15px;
        line-height: 1;
        box-shadow: 0 0 10px var(--acc-glow);
    }
    .brand-name {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 17px;
        letter-spacing: -0.01em;
        color: var(--ink-0);
    }

    .conn-pill {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 10px 4px 8px;
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: 999px;
        font-size: 11.5px;
    }
    .conn-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--ok);
        box-shadow: 0 0 6px rgba(127, 217, 127, 0.5);
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%,
        100% {
            opacity: 1;
        }
        50% {
            opacity: 0.5;
        }
    }
    .conn-text {
        color: var(--ink-1);
    }
    .conn-engine {
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--acc);
        background: rgba(200, 255, 90, 0.08);
        border: 1px solid rgba(200, 255, 90, 0.2);
        padding: 2px 5px;
        border-radius: 3px;
        font-weight: 600;
    }

    .spacer {
        flex: 1;
    }

    .topbar-disconnect {
        font-size: 12px;
        padding: 4px 10px;
    }

    /* ---- Workspace (3 columns) ---- */
    .workspace {
        flex: 1;
        min-height: 0;
        display: flex;
        overflow: hidden;
    }

    .sidebar-left {
        flex-shrink: 0;
        background: var(--bg-1);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .sidebar-right {
        flex-shrink: 0;
        background: var(--bg-1);
        overflow: hidden;
    }

    .workspace-middle {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    /* ---- SQL pane ---- */
    .sql-pane {
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-height: 100px;
    }

    .sql-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 6px 12px;
        border-bottom: 1px solid var(--line);
        background: var(--bg-2);
        flex-shrink: 0;
        gap: 10px;
    }

    .db-pick {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .db-lbl {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ink-3);
        font-weight: 700;
    }
    .db-sel {
        background: transparent;
        border: 0;
        color: var(--ink-1);
        font-family: var(--font-mono);
        font-size: 11.5px;
        padding: 0;
        min-width: 60px;
    }
    .db-sel:focus {
        outline: none;
    }

    .sql-head-right {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .char-count {
        font-size: 10.5px;
    }

    .run-btn {
        padding: 4px 11px;
        font-size: 12px;
        gap: 5px;
    }
    .run-btn .kbd {
        background: rgba(10, 12, 10, 0.15);
        color: rgba(10, 12, 10, 0.65);
        border-color: transparent;
        font-size: 9px;
        margin-left: 1px;
    }

    /* Make CodeMirror fill the sql-pane */
    .sql-pane :global(.cm-editor) {
        flex: 1;
        min-height: 0;
        height: 100%;
        background: var(--bg-0);
    }
    .sql-pane :global(.cm-scroller) {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }

    /* ---- Result pane ---- */
    .result-pane {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        padding: 10px;
        gap: 10px;
    }

    .result-hint {
        color: var(--ink-3);
        text-align: center;
        padding: 32px 16px;
        background: var(--bg-1);
        border: 1px dashed var(--line-strong);
        border-radius: var(--radius-lg);
        font-size: 13px;
    }

    .ok-result {
        background: var(--bg-1);
        border: 1px solid rgba(127, 217, 127, 0.25);
        border-radius: var(--radius-lg);
        padding: 14px 16px;
        display: flex;
        gap: 12px;
        align-items: center;
        color: var(--ink-1);
        font-size: 13px;
    }
    .ok-tag {
        font-size: 9.5px;
        background: rgba(127, 217, 127, 0.12);
        color: var(--ok);
        padding: 3px 7px;
        border-radius: 3px;
        font-weight: 700;
        letter-spacing: 0.06em;
    }

    /* ---- Error log ---- */
    .error-log {
        flex-shrink: 0;
        background: var(--bg-1);
        border: 1px solid rgba(255, 115, 103, 0.25);
        border-radius: var(--radius-lg);
        overflow: hidden;
        height: 250px;
        overflow-y: auto;
    }

    .error-log-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-bottom: 1px solid rgba(255, 115, 103, 0.15);
        background: rgba(255, 115, 103, 0.05);
    }

    .error-log-title {
        font-size: 9.5px;
        font-weight: 700;
        letter-spacing: 0.08em;
        color: var(--danger);
    }

    .error-log-count {
        font-size: 9.5px;
        background: rgba(255, 115, 103, 0.15);
        color: var(--danger);
        padding: 1px 6px;
        border-radius: 999px;
        font-weight: 600;
    }

    .error-log-clear {
        margin-left: auto;
        font-size: 11px;
        padding: 2px 8px;
        color: var(--ink-3);
    }

    .error-entry {
        padding: 10px 12px;
        border-bottom: 1px solid var(--line);
    }
    .error-entry:last-child {
        border-bottom: none;
    }

    .error-entry-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
    }

    .error-ts {
        font-size: 10.5px;
        color: var(--ink-3);
    }

    .error-entry-msg {
        margin: 0;
        white-space: pre-wrap;
        color: var(--ink-0);
        font-size: 12px;
        line-height: 1.5;
    }
</style>
