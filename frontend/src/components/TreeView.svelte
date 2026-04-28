<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let databases = [];
    export let busy = false;

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
</script>

<nav class="tree">
    <div class="tree-header">
        <div class="tree-header-row">
            <span class="tree-title mono">Explorer</span>
            <button class="icon-btn" title="Collapse all" on:click={collapseAll}
                >⊟</button
            >
        </div>
        {#if databases.length > 0}
            <div class="tree-search-row">
                <input
                    class="tree-search mono"
                    type="search"
                    placeholder="filter…"
                    bind:value={searchQuery}
                />
            </div>
        {/if}
    </div>

    <div class="tree-body">
        {#if filteredDatabases.length === 0}
            <div class="tree-empty mono">
                {#if searchQuery.trim()}no match{:else}no databases{/if}
            </div>
        {:else}
            {#each filteredDatabases as db}
                <div class="db-group">
                    <button
                        class="tree-node db-node"
                        on:click={() => toggleDb(db)}
                        title={db}
                    >
                        <span
                            class="node-arrow"
                            class:open={expandedDbs.has(db)}>›</span
                        >
                        <span class="node-icon db-icon">◎</span>
                        <span class="node-label">{db}</span>
                        {#if loadingDbs.has(db)}
                            <span class="spinner" aria-label="Loading"></span>
                        {/if}
                    </button>

                    {#if expandedDbs.has(db)}
                        {@const vt = visibleTables(db, tableMap)}
                        {#if vt}
                            <div class="db-children">
                                {#if vt.length === 0}
                                    <div class="tree-empty-inner mono">
                                        no tables
                                    </div>
                                {:else}
                                    {#each vt as t}
                                        {@const tk = tableKey(db, t.name)}
                                        <div class="table-group">
                                            <button
                                                class="tree-node table-node"
                                                on:click={() =>
                                                    toggleTable(db, t.name)}
                                                title={t.name}
                                            >
                                                <span
                                                    class="node-arrow"
                                                    class:open={expandedTables.has(
                                                        tk,
                                                    )}>›</span
                                                >
                                                <span class="node-icon">▦</span>
                                                <span class="node-label"
                                                    >{t.name}</span
                                                >
                                            </button>

                                            {#if expandedTables.has(tk)}
                                                <div class="table-children">
                                                    <button
                                                        class="tree-node leaf-node"
                                                        class:active={activeNode ===
                                                            leafKey(
                                                                db,
                                                                t.name,
                                                                "data",
                                                            )}
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "data",
                                                            )}
                                                    >
                                                        <span
                                                            class="node-icon leaf-icon"
                                                            >≡</span
                                                        >
                                                        <span class="node-label"
                                                            >Data</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "data")}
                                                            <span
                                                                class="spinner"
                                                                aria-label="Loading"
                                                            ></span>
                                                        {/if}
                                                    </button>
                                                    <button
                                                        class="tree-node leaf-node"
                                                        class:active={activeNode ===
                                                            leafKey(
                                                                db,
                                                                t.name,
                                                                "structure",
                                                            )}
                                                        on:click={() =>
                                                            selectLeaf(
                                                                db,
                                                                t.name,
                                                                "structure",
                                                            )}
                                                    >
                                                        <span
                                                            class="node-icon leaf-icon"
                                                            >#</span
                                                        >
                                                        <span class="node-label"
                                                            >Structure</span
                                                        >
                                                        {#if busy && activeNode === leafKey(db, t.name, "structure")}
                                                            <span
                                                                class="spinner"
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

<style>
    .tree {
        height: 100%;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .tree-header {
        border-bottom: 1px solid var(--line);
        flex-shrink: 0;
    }

    .tree-header-row {
        display: flex;
        align-items: center;
        padding: 9px 8px 8px 12px;
    }

    .tree-title {
        flex: 1;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ink-3);
        font-weight: 600;
    }

    .icon-btn {
        background: transparent;
        border: 0;
        color: var(--ink-3);
        cursor: pointer;
        padding: 2px 4px;
        border-radius: 3px;
        font-size: 13px;
        line-height: 1;
        transition:
            background 60ms,
            color 60ms;
    }

    .icon-btn:hover {
        background: var(--bg-2);
        color: var(--ink-1);
    }

    .tree-search-row {
        padding: 0 8px 8px;
    }

    .tree-search {
        width: 100%;
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: 4px;
        color: var(--ink-1);
        font-size: 11px;
        padding: 4px 7px;
        outline: none;
        box-sizing: border-box;
    }

    .tree-search::placeholder {
        color: var(--ink-3);
    }

    .tree-search:focus {
        border-color: var(--acc, #c8ff5a);
    }

    .tree-body {
        flex: 1;
        overflow-y: auto;
        padding: 4px 6px;
    }

    .tree-empty {
        padding: 16px 10px;
        color: var(--ink-3);
        font-size: 11.5px;
    }

    .tree-empty-inner {
        padding: 4px 10px 6px;
        color: var(--ink-3);
        font-size: 11px;
    }

    .db-group,
    .table-group {
        display: flex;
        flex-direction: column;
    }

    .db-children {
        padding-left: 10px;
    }

    .table-children {
        padding-left: 22px;
    }

    /* Nodes */
    .tree-node {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 5px;
        background: transparent;
        border: 0;
        padding: 4px 8px 4px 4px;
        text-align: left;
        color: var(--ink-1);
        border-radius: 4px;
        transition:
            background 60ms,
            color 60ms;
        min-width: 0;
    }

    .tree-node:hover {
        background: var(--bg-2);
        color: var(--ink-0);
    }

    .tree-node.active {
        background: rgba(200, 255, 90, 0.1);
        color: var(--acc);
    }

    .node-arrow {
        font-size: 11px;
        color: var(--ink-3);
        width: 10px;
        flex-shrink: 0;
        display: inline-block;
        transition: transform 120ms ease;
        line-height: 1;
    }

    .node-arrow.open {
        transform: rotate(90deg);
    }

    .node-icon {
        font-size: 11px;
        color: var(--ink-3);
        width: 14px;
        text-align: center;
        flex-shrink: 0;
    }

    .db-icon {
        color: rgba(200, 255, 90, 0.6);
    }

    .leaf-icon {
        color: var(--ink-3);
    }

    .node-label {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: var(--font-mono);
        font-size: 12px;
    }

    .leaf-node .node-label {
        color: var(--ink-2);
        font-size: 11.5px;
    }

    .leaf-node:hover .node-label,
    .leaf-node.active .node-label {
        color: inherit;
    }

    .spinner {
        flex-shrink: 0;
        width: 10px;
        height: 10px;
        border: 1.5px solid var(--ink-3);
        border-top-color: var(--acc, #c8ff5a);
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>
