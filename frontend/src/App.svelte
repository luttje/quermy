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
    import Btn from "./components/ui/Btn.svelte";
    import Kbd from "./components/ui/Kbd.svelte";

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
    <div class="flex-1 flex items-center justify-center text-(--ink-3) mono">
        initializing…
    </div>
{:else if !$session}
    <div class="flex-1 overflow-y-auto">
        <ConnectView />
    </div>
{:else}
    <div class="h-full flex flex-col overflow-hidden">
        <!-- Topbar -->
        <header
            class="flex items-center gap-3.5 px-4.5 h-11 shrink-0 bg-[rgba(10,12,10,0.85)] backdrop-blur-md border-b border-(--line) z-50"
        >
            <div class="flex gap-2 items-center">
                <span
                    class="w-6 h-6 rounded-[5px] bg-(--acc) text-[#0a0c0a] inline-flex items-center justify-center font-(--font-display) text-[15px] leading-none shadow-[0_0_10px_var(--acc-glow)]"
                    >Q</span
                >
                <span
                    class="font-(--font-display) text-[17px] tracking-[-0.01em] text-(--ink-0)"
                    >Quermy</span
                >
            </div>

            <div
                class="flex items-center gap-2 px-2.5 py-1 bg-(--bg-2) border border-(--line) rounded-full text-[11.5px]"
            >
                <span
                    class="w-1.5 h-1.5 rounded-full bg-(--ok) shadow-[0_0_6px_rgba(127,217,127,0.5)] animate-pulse"
                ></span>
                <span class="mono text-(--ink-1)"
                    >{$session.username}@{$session.host}{$session.port !== 3306
                        ? ":" + $session.port
                        : ""}</span
                >
                <span
                    class="mono text-[9.5px] uppercase tracking-[0.08em] text-(--acc) bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] px-1.25 py-0.5 rounded-[3px] font-semibold"
                    >{$session.engine}</span
                >
            </div>

            <div class="flex-1"></div>

            <Btn
                variant="ghost"
                on:click={disconnect}
                class="text-[12px] px-2.5 py-1">Disconnect</Btn
            >
        </header>

        <!-- 3-panel workspace -->
        <div class="flex-1 min-h-0 flex overflow-hidden">
            <!-- Left: Explorer tree -->
            <aside
                class="shrink-0 bg-(--bg-1) overflow-hidden flex flex-col"
                style="width: {leftWidth}px"
            >
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
            <div class="flex-1 min-w-0 flex flex-col overflow-hidden">
                <!-- SQL Editor pane (resizable) -->
                <div
                    class="shrink-0 flex flex-col overflow-hidden min-h-25"
                    style="height: {sqlPaneHeight}px"
                >
                    <div
                        class="flex items-center justify-between px-3 py-1.5 border-b border-(--line) bg-(--bg-2) shrink-0 gap-2.5"
                    >
                        <label
                            class="flex items-center gap-1.5 mono cursor-pointer"
                        >
                            <span
                                class="text-[9px] uppercase tracking-widest text-(--ink-3) font-bold"
                                >DB</span
                            >
                            <select
                                class="bg-transparent border-0 text-(--ink-1) font-(--font-mono) text-[11.5px] p-0 min-w-15 focus:outline-none"
                                bind:value={queryDb}
                            >
                                <option value="">(none)</option>
                                {#each databases as d}
                                    <option value={d}>{d}</option>
                                {/each}
                            </select>
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="muted mono text-[10.5px]"
                                >{sql.length} chars</span
                            >
                            <Btn
                                variant="primary"
                                on:click={run}
                                disabled={busy || !sql.trim()}
                                class="py-1 px-2.75 text-[12px] gap-1.25"
                            >
                                {busy ? "Running…" : "Run"}
                                <span
                                    class="mono text-[9px] bg-[rgba(10,12,10,0.15)] text-[rgba(10,12,10,0.65)] py-0.5 rounded border-0 ml-px"
                                    ><Kbd>⌘↵</Kbd></span
                                >
                            </Btn>
                        </div>
                    </div>
                    <SqlEditor bind:value={sql} />
                </div>

                <!-- Resize handle -->
                <ResizeHandle on:resize={handleSqlResize} />

                <!-- Result pane -->
                <div
                    class="flex-1 min-h-0 overflow-y-auto flex flex-col p-2.5 gap-2.5"
                >
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
                            <div
                                class="bg-(--bg-1) border border-[rgba(127,217,127,0.25)] rounded-lg px-4 py-3.5 flex gap-3 items-center text-(--ink-1) text-[13px]"
                            >
                                <div
                                    class="mono text-[9.5px] bg-[rgba(127,217,127,0.12)] text-(--ok) px-1.75 py-0.75 rounded-[3px] font-bold tracking-[0.06em]"
                                >
                                    OK
                                </div>
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
                        <div
                            class="text-(--ink-3) text-center px-4 py-8 bg-(--bg-1) border border-dashed border-(--line-strong) rounded-lg text-[13px]"
                        >
                            Press <Kbd>⌘ Enter</Kbd> or click
                            <strong>Run</strong> to execute, or pick a table from
                            the explorer.
                        </div>
                    {/if}

                    {#if errors.length > 0}
                        <div
                            class="shrink-0 bg-(--bg-1) border border-[rgba(255,115,103,0.25)] rounded-lg overflow-hidden h-62.5 overflow-y-auto"
                        >
                            <div
                                class="flex items-center gap-2 px-3 py-1.75 border-b border-[rgba(255,115,103,0.15)] bg-[rgba(255,115,103,0.05)]"
                            >
                                <span
                                    class="mono text-[9.5px] font-bold tracking-[0.08em] text-(--danger)"
                                    >SQL ERRORS</span
                                >
                                <span
                                    class="mono text-[9.5px] bg-[rgba(255,115,103,0.15)] text-(--danger) px-1.5 py-px rounded-full font-semibold"
                                    >{errors.length}</span
                                >
                                <Btn
                                    variant="ghost"
                                    on:click={() => (errors = [])}
                                    class="ml-auto text-[11px] px-2 py-0.5 text-(--ink-3)"
                                    >Clear</Btn
                                >
                            </div>
                            {#each errors as err}
                                <div
                                    class="px-3 py-2.5 border-b border-(--line) last:border-b-0"
                                >
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span
                                            class="mono text-[9.5px] font-bold tracking-[0.06em] text-(--danger) bg-[rgba(255,115,103,0.12)] px-1.75 py-0.75 rounded-[3px]"
                                            >ERROR</span
                                        >
                                        <span
                                            class="mono text-[10.5px] text-(--ink-3)"
                                            >{err.time.toLocaleTimeString([], {
                                                hour: "2-digit",
                                                minute: "2-digit",
                                                second: "2-digit",
                                            })}</span
                                        >
                                    </div>
                                    <pre
                                        class="m-0 whitespace-pre-wrap text-(--ink-0) mono text-[12px] leading-normal">{err.message}</pre>
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
            <aside
                class="shrink-0 bg-(--bg-1) overflow-hidden"
                style="width: {rightWidth}px"
            >
                <AIChatPanel />
            </aside>
        </div>
    </div>
{/if}
