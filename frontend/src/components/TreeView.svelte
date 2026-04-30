<script>
    import { createEventDispatcher, onMount, tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Input from "./ui/Input.svelte";

    export let databases = [];
    export let busy = false;
    export let activeContext = null; // { db, table, mode } — set when restoring from URL
    export let db = null; // Optional: if set, auto-expand this DB on mount and when it appears in the list
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    let dbRefs = {}; // db name → button element
    let expandedDbs = new Set();
    let expandedTables = new Set(); // "db\0table"
    let tableMap = {}; // db -> tables[]
    let loadingDbs = new Set();
    let activeNode = null; // "db\0table\0mode"
    let commonDbNodes = [];

    $: commonDbNodes = [
        {
            mode: "views",
            label: "Views",
            icon: "◫",
            enabled: capabilities?.supportsViewManagement !== false,
        },
        { mode: "events", label: "Events", icon: "◷", enabled: false },
        {
            mode: "stored-procedures",
            label: "Stored Procedures",
            icon: "λ",
            enabled: false,
        },
        {
            mode: "stored-functions",
            label: "Stored Functions",
            icon: "ƒ",
            enabled: false,
        },
    ];

    function tableKey(db, table) {
        return `${db}\0${table}`;
    }
    function dbNodeKey(db, mode) {
        return `${db}\0\0${mode ?? ""}`;
    }
    function leafKey(db, table, mode) {
        return `${db}\0${table ?? ""}\0${mode ?? ""}`;
    }

    async function ensureDbTablesLoaded(db) {
        if (tableMap[db]) return true;

        loadingDbs.add(db);
        loadingDbs = new Set(loadingDbs);
        try {
            const r = await api.listTables(db);
            tableMap[db] = r.tables || [];
            tableMap = { ...tableMap };
            return true;
        } catch (e) {
            toast(e.message, "error");
            return false;
        } finally {
            loadingDbs.delete(db);
            loadingDbs = new Set(loadingDbs);
        }
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

        dispatch("toggleDb", { db });
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

    function selectDbNode(db, mode, enabled) {
        if (!enabled) return;
        activeNode = dbNodeKey(db, mode);
        dispatch("openTable", { db, table: null, mode });
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
                    commonDbNodes.some((n) =>
                        n.label.toLowerCase().includes(q),
                    ) ||
                    (tables &&
                        tables.some((t) => t.name.toLowerCase().includes(q)))
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

        // Keep table list available when opening db-level nodes (e.g. Views)
        // so the tree state matches table-level navigation behavior.
        if (mode && !tableMap[db]) {
            const ok = await ensureDbTablesLoaded(db);
            if (!ok) {
                expandedDbs.delete(db);
                expandedDbs = new Set(expandedDbs);
                return;
            }
        }

        if (!mode) {
            activeNode = null;
            return;
        }

        if (!table) {
            activeNode = dbNodeKey(db, mode);
            return;
        }

        if (!tableMap[db]) {
            const ok = await ensureDbTablesLoaded(db);
            if (!ok) return;
        }

        const tk = tableKey(db, table);
        if (!expandedTables.has(tk)) {
            expandedTables.add(tk);
            expandedTables = new Set(expandedTables);
        }

        activeNode = leafKey(db, table, mode);
    }

    onMount(async () => {
        if (db) {
            const initialCtx =
                activeContext?.db === db
                    ? activeContext
                    : { db, table: null, mode: null };
            await syncFromContext(initialCtx);
            await tick(); // wait for Svelte to render the expanded state
            const dbButton = dbRefs[db];

            if (dbButton) {
                (dbButton.parentElement ?? dbButton).scrollIntoView({
                    block: "nearest",
                    behavior: "smooth",
                });
            }
        }
    });
</script>

<nav class="h-full flex flex-col overflow-hidden">
    <!-- header -->
    <div class="border-b border-(--line) shrink-0">
        <div class="flex items-center px-3 py-2.25 pb-2">
            <span
                class="flex-1 mono text-[9.5px] uppercase tracking-widest text-(--ink-3) font-semibold"
                >Explorer</span
            >
            <button
                class="bg-transparent border-0 text-(--ink-3) cursor-pointer px-1 py-0.5 rounded-[3px] text-[13px] leading-none transition-[background,color] duration-60 hover:bg-(--bg-2) hover:text-(--ink-1)"
                title="Collapse all"
                on:click={collapseAll}>⊟</button
            >
        </div>
        {#if databases.length > 0}
            <div class="px-2 pb-2">
                <Input
                    class="mono text-[11px]!"
                    type="search"
                    placeholder="filter…"
                    bind:value={searchQuery}
                />
            </div>
        {/if}
    </div>

    <!-- body -->
    <div class="flex-1 overflow-y-auto py-1 px-1.5">
        {#if filteredDatabases.length === 0}
            <div class="mono px-2.5 py-4 text-(--ink-3) text-[11.5px]">
                {#if searchQuery.trim()}no match{:else}no databases{/if}
            </div>
        {:else}
            {#each filteredDatabases as db}
                <div class="flex flex-col group/dbrow">
                    <div class="flex items-center rounded hover:bg-(--bg-2)">
                        <button
                            bind:this={dbRefs[db]}
                            class="cursor-pointer flex-1 flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 text-left text-(--ink-1) min-w-0 transition-[color] duration-60 hover:text-(--ink-0) rounded-l"
                            on:click={() => toggleDb(db)}
                            title={db}
                        >
                            <span
                                class="text-[11px] text-(--ink-3) w-2.5 shrink-0 inline-block leading-none transition-transform duration-120 ease-in-out"
                                class:rotate-90={expandedDbs.has(db)}>›</span
                            >
                            <span
                                class="text-[11px] text-[rgba(200,255,90,0.6)] w-3.5 text-center shrink-0"
                                >◎</span
                            >
                            <span
                                class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[12px]"
                                >{db}</span
                            >
                            {#if loadingDbs.has(db)}
                                <span
                                    class="shrink-0 w-2.5 h-2.5 border-[1.5px] border-(--ink-3) border-t-(--acc) rounded-full animate-spin"
                                    aria-label="Loading"
                                ></span>
                            {/if}
                        </button>
                        <button
                            class="opacity-0 group-hover/dbrow:opacity-100 shrink-0 bg-transparent border-0 text-(--ink-3) cursor-pointer px-1.5 py-1 rounded-r leading-none transition-[opacity,color] duration-60 hover:text-(--ink-1)"
                            title="Database settings"
                            on:click|stopPropagation={() =>
                                dispatch("editDatabase", { db })}>⚙</button
                        >
                    </div>

                    {#if expandedDbs.has(db)}
                        {@const vt = visibleTables(db, tableMap)}
                        <div class="pl-2.5">
                            <div class="pb-1">
                                {#each commonDbNodes as node}
                                    <button
                                        class="w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color,opacity] duration-60 {activeNode ===
                                        dbNodeKey(db, node.mode)
                                            ? 'bg-[rgba(200,255,90,0.1)] text-(--acc)'
                                            : node.enabled
                                              ? 'muted hover:bg-(--bg-2) hover:text-(--ink-0) cursor-pointer'
                                              : 'text-(--ink-3) opacity-55 cursor-not-allowed'}"
                                        disabled={!node.enabled}
                                        on:click={() =>
                                            selectDbNode(
                                                db,
                                                node.mode,
                                                node.enabled,
                                            )}
                                        title={node.enabled
                                            ? node.label
                                            : `${node.label} (coming soon)`}
                                    >
                                        <span
                                            class="text-[11px] w-3.5 text-center shrink-0 text-(--ink-3)"
                                            >{node.icon}</span
                                        >
                                        <span
                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                            >{node.label}</span
                                        >
                                        {#if !node.enabled}
                                            <span
                                                class="mono text-[9px] uppercase tracking-[0.05em] text-(--ink-3)"
                                                >Soon</span
                                            >
                                        {/if}
                                    </button>
                                {/each}
                            </div>

                            {#if vt}
                                {#if vt.length === 0}
                                    <div
                                        class="mono px-2.5 py-1 pb-1.5 text-(--ink-3) text-[11px]"
                                    >
                                        no tables
                                    </div>
                                {:else}
                                    {#each vt as t}
                                        {@const tk = tableKey(db, t.name)}
                                        <div class="flex flex-col">
                                            <button
                                                class="cursor-pointer w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left text-(--ink-1) rounded min-w-0 transition-[background,color] duration-60 hover:bg-(--bg-2) hover:text-(--ink-0)"
                                                on:click={() =>
                                                    toggleTable(db, t.name)}
                                                title={t.name}
                                            >
                                                <span
                                                    class="text-[11px] text-(--ink-3) w-2.5 shrink-0 inline-block leading-none transition-transform duration-120 ease-in-out"
                                                    class:rotate-90={expandedTables.has(
                                                        tk,
                                                    )}>›</span
                                                >
                                                <span
                                                    class="text-[11px] text-(--ink-3) w-3.5 text-center shrink-0"
                                                    >▦</span
                                                >
                                                <span
                                                    class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[12px]"
                                                    >{t.name}</span
                                                >
                                            </button>

                                            {#if expandedTables.has(tk)}
                                                <div class="pl-5.5">
                                                    <button
                                                        class="cursor-pointer w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-60 {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'data',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-(--acc)'
                                                            : 'muted hover:bg-(--bg-2) hover:text-(--ink-0)'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "data",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-3.5 text-center shrink-0 text-(--ink-3)"
                                                            >≡</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Data</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "data")}
                                                            <span
                                                                class="shrink-0 w-2.5 h-2.5 border-[1.5px] border-(--ink-3) border-t-(--acc) rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                    <button
                                                        class="cursor-pointer w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-60 {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'structure',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-(--acc)'
                                                            : 'muted hover:bg-(--bg-2) hover:text-(--ink-0)'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "structure",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-3.5 text-center shrink-0 text-(--ink-3)"
                                                            >#</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Structure</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "structure")}
                                                            <span
                                                                class="shrink-0 w-2.5 h-2.5 border-[1.5px] border-(--ink-3) border-t-(--acc) rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                    <button
                                                        class="cursor-pointer w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-60 {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'indexes',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-(--acc)'
                                                            : 'muted hover:bg-(--bg-2) hover:text-(--ink-0)'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "indexes",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-3.5 text-center shrink-0 text-(--ink-3)"
                                                            >⌗</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Indexes</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "indexes")}
                                                            <span
                                                                class="shrink-0 w-2.5 h-2.5 border-[1.5px] border-(--ink-3) border-t-(--acc) rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                    <button
                                                        class="cursor-pointer w-full flex items-center gap-1.25 bg-transparent border-0 py-1 px-1 pr-2 text-left rounded min-w-0 transition-[background,color] duration-60 {activeNode ===
                                                        leafKey(
                                                            db,
                                                            t.name,
                                                            'foreign-keys',
                                                        )
                                                            ? 'bg-[rgba(200,255,90,0.1)] text-(--acc)'
                                                            : 'muted hover:bg-(--bg-2) hover:text-(--ink-0)'}"
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "foreign-keys",
                                                            )}
                                                    >
                                                        <span
                                                            class="text-[11px] w-3.5 text-center shrink-0 text-(--ink-3)"
                                                            >↗</span
                                                        >
                                                        <span
                                                            class="flex-1 min-w-0 overflow-hidden text-ellipsis whitespace-nowrap mono text-[11.5px]"
                                                            >Foreign Keys</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "foreign-keys")}
                                                            <span
                                                                class="shrink-0 w-2.5 h-2.5 border-[1.5px] border-(--ink-3) border-t-(--acc) rounded-full animate-spin"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                </div>
                                            {/if}
                                        </div>
                                    {/each}
                                {/if}
                            {/if}
                        </div>
                    {/if}
                </div>
            {/each}
        {/if}
    </div>
</nav>
