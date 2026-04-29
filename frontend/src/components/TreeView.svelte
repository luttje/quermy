<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let databases = [];
    export let busy = false;
    export let activeContext = null; // { db, table, mode } — set when restoring from URL

    const dispatch = createEventDispatcher();

    let expandedDbs = new Set();
    let expandedTables = new Set(); // "db\0table"
    let tableMap = {}; // db -> tables[]
    let loadingDbs = new Set();
    let activeNode = null; // "db\0table\0mode"

    function dbKey(db) {
        return db;
    }
    function tableKey(db, table) {
        return `${db}\0${table}`;
    }
    function leafKey(db, table, mode) {
        return `${db}\0${table}\0${mode}`;
    }

    async function toggleDb(db) {
        if (expandedDbs.has(db)) {
            expandedDbs.delete(db);
            expandedDbs = new Set(expandedDbs);
            return;
        }
        expandedDbs.add(db);
        expandedDbs = new Set(expandedDbs);
        if (!tableMap[db]) {
            loadingDbs.add(db);
            loadingDbs = new Set(loadingDbs);
            try {
                const r = await api.listTables(db);
                tableMap[db] = r.tables || [];
                tableMap = { ...tableMap };
            } catch (e) {
                toast(e.message, "error");
                expandedDbs.delete(db);
                expandedDbs = new Set(expandedDbs);
            } finally {
                loadingDbs.delete(db);
                loadingDbs = new Set(loadingDbs);
            }
        }
    }

    function toggleTable(db, table) {
        const k = tableKey(db, table);
        if (expandedTables.has(k)) {
            expandedTables.delete(k);
        } else {
            expandedTables.add(k);
        }
        expandedTables = new Set(expandedTables);
    }

    function selectLeaf(db, table, mode) {
        activeNode = leafKey(db, table, mode);
        dispatch("openTable", { db, table, mode });
    }

    let searchQuery = "";
    let filteredDatabases = databases;

    $: {
        const q = searchQuery.trim().toLowerCase();
        if (!q) {
            filteredDatabases = databases;
        } else {
            filteredDatabases = databases.filter((db) => {
                if (db.toLowerCase().includes(q)) return true;
                const tables = tableMap[db];
                return (
                    tables &&
                    tables.some((t) => t.name.toLowerCase().includes(q))
                );
            });
        }
    }

    function visibleTables(db, _tableMap) {
        const tables = _tableMap[db];
        if (!tables) return null;
        const q = searchQuery.trim().toLowerCase();
        if (!q || db.toLowerCase().includes(q)) return tables;
        return tables.filter((t) => t.name.toLowerCase().includes(q));
    }

    function collapseAll() {
        expandedDbs = new Set();
        expandedTables = new Set();
    }

    // Sync tree expansion/active state when activeContext is set externally (URL restore / popstate)
    let _lastSyncedContext = null;
    $: if (activeContext && activeContext !== _lastSyncedContext) {
        _lastSyncedContext = activeContext;
        syncFromContext(activeContext);
    }

    async function syncFromContext(ctx) {
        const { db, table, mode } = ctx;

        if (!expandedDbs.has(db)) {
            expandedDbs.add(db);
            expandedDbs = new Set(expandedDbs);
        }

        if (!tableMap[db]) {
            loadingDbs.add(db);
            loadingDbs = new Set(loadingDbs);
            try {
                const r = await api.listTables(db);
                tableMap[db] = r.tables || [];
                tableMap = { ...tableMap };
            } catch (e) {
                toast(e.message, "error");
                return;
            } finally {
                loadingDbs.delete(db);
                loadingDbs = new Set(loadingDbs);
            }
        }

        const tk = tableKey(db, table);
        if (!expandedTables.has(tk)) {
            expandedTables.add(tk);
            expandedTables = new Set(expandedTables);
        }

        activeNode = leafKey(db, table, mode);
    }
</script>

<nav class="h-full flex flex-col overflow-hidden">
    <!-- header -->
    <div class="border-b border-[var(--line)] shrink-0">
        <div class="flex items-center px-3 py-[9px] pb-2">
            <span
                class="flex-1 mono text-[9.5px] uppercase tracking-[0.1em] text-[var(--ink-3)] font-semibold"
                >Explorer</span
            >
            <button
                class="bg-transparent border-0 text-[var(--ink-3)] cursor-pointer px-1 py-[2px] rounded-[3px] text-[13px] leading-none transition-[background,color] duration-[60ms] hover:bg-[var(--bg-2)] hover:text-[var(--ink-1)]"
                title="Collapse all"
                on:click={collapseAll}>⊟</button
            >
        </div>
        {#if databases.length > 0}
            <div class="px-2 pb-2">
                <input
                    class="w-full bg-[var(--bg-2)] border border-[var(--line)] rounded text-[var(--ink-1)] mono text-[11px] px-[7px] py-1 outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)]"
                    type="search"
                    placeholder="filter…"
                    bind:value={searchQuery}
                />
            </div>
        {/if}
    </div>

    <!-- body -->
    <div class="flex-1 overflow-y-auto py-1 px-[6px]">
        {#if filteredDatabases.length === 0}
            <div class="mono px-[10px] py-4 text-[var(--ink-3)] text-[11.5px]">
                {#if searchQuery.trim()}no match{:else}no databases{/if}
            </div>
        {:else}
            {#each filteredDatabases as db}
                <div class="flex flex-col">
                    <button
                        class="w-full flex items-center gap-[5px] bg-transparent border-0 py-1 px-1 pr-2 text-left text-[var(--ink-1)] rounded min-w-0 transition-[background,color] duration-[60ms] hover:bg-[var(--bg-2)] hover:text-[var(--ink-0)]"
                        on:click={() => toggleDb(db)}
                        title={db}
                    >
                        <span
                            class="text-[11px] text-[var(--ink-3)] w-[10px] shrink-0 inline-block leading-none transition-transform duration-[120ms] ease-in-out"
                            class:rotate-90={expandedDbs.has(db)}>›</span
                        >
                        <span
                            class="text-[11px] text-[rgba(200,255,90,0.6)] w-[14px] text-center shrink-0"
                            >◎</span
                        >
                        <span
                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[12px]"
                            >{db}</span
                        >
                        {#if loadingDbs.has(db)}
                            <span
                                class="shrink-0 w-[10px] h-[10px] border-[1.5px] border-[var(--ink-3)] border-t-[var(--acc)] rounded-full animate-spin"
                                aria-label="Loading"
                            ></span>
                        {/if}
                    </button>

                    {#if expandedDbs.has(db)}
                        {@const vt = visibleTables(db, tableMap)}
                        {#if vt}
                            <div class="pl-[10px]">
                                {#if vt.length === 0}
                                    <div
                                        class="mono px-[10px] py-1 pb-[6px] text-[var(--ink-3)] text-[11px]"
                                    >
                                        no tables
                                    </div>
                                {:else}
                                    {#each vt as t}
                                        {@const tk = tableKey(db, t.name)}
                                        <div class="flex flex-col">
                                            <button
                                                class="w-full flex items-center gap-[5px] bg-transparent border-0 py-1 px-1 pr-2 text-left text-[var(--ink-1)] rounded min-w-0 transition-[background,color] duration-[60ms] hover:bg-[var(--bg-2)] hover:text-[var(--ink-0)]"
                                                on:click={() =>
                                                    toggleTable(db, t.name)}
                                                title={t.name}
                                            >
                                                <span
                                                    class="text-[11px] text-[var(--ink-3)] w-[10px] shrink-0 inline-block leading-none transition-transform duration-[120ms] ease-in-out"
                                                    class:rotate-90={expandedTables.has(
                                                        tk,
                                                    )}>›</span
                                                >
                                                <span
                                                    class="text-[11px] text-[var(--ink-3)] w-[14px] text-center shrink-0"
                                                    >▦</span
                                                >
                                                <span
                                                    class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[12px]"
                                                    >{t.name}</span
                                                >
                                            </button>

                                            {#if expandedTables.has(tk)}
                                                <div class="pl-[22px]">
                                                    <button
                                                        class="w-full flex items-center gap-[5px] bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-[60ms] {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'data',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-[var(--acc)]'
                                                            : 'text-[var(--ink-2)] hover:bg-[var(--bg-2)] hover:text-[var(--ink-0)]'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "data",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-[14px] text-center shrink-0 text-[var(--ink-3)]"
                                                            >≡</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Data</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "data")}
                                                            <span
                                                                class="shrink-0 w-[10px] h-[10px] border-[1.5px] border-[var(--ink-3)] border-t-[var(--acc)] rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                    <button
                                                        class="w-full flex items-center gap-[5px] bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-[60ms] {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'structure',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-[var(--acc)]'
                                                            : 'text-[var(--ink-2)] hover:bg-[var(--bg-2)] hover:text-[var(--ink-0)]'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "structure",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-[14px] text-center shrink-0 text-[var(--ink-3)]"
                                                            >#</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Structure</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "structure")}
                                                            <span
                                                                class="shrink-0 w-[10px] h-[10px] border-[1.5px] border-[var(--ink-3)] border-t-[var(--acc)] rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                </div>
                                            {/if}
                                        </div>
                                    {/each}
                                {/if}
                            </div>
                        {/if}
                    {/if}
                </div>
            {/each}
        {/if}
    </div>
</nav>
