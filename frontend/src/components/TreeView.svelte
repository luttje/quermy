<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let databases = [];

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
        const qDb = "`" + db.replace(/`/g, "``") + "`";
        const qTbl = "`" + table.replace(/`/g, "``") + "`";
        let sql;
        if (mode === "data") {
            sql = `SELECT *\nFROM ${qDb}.${qTbl}\nLIMIT 100;`;
        } else {
            sql = `SHOW COLUMNS FROM ${qDb}.${qTbl};`;
        }
        dispatch("runSql", { db, sql });
    }
</script>

<nav class="tree">
    <div class="tree-header">
        <span class="tree-title mono">Explorer</span>
    </div>

    <div class="tree-body">
        {#if databases.length === 0}
            <div class="tree-empty mono">no databases</div>
        {:else}
            {#each databases as db}
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
                            <span class="spin mono">…</span>
                        {/if}
                    </button>

                    {#if expandedDbs.has(db) && tableMap[db]}
                        <div class="db-children">
                            {#if tableMap[db].length === 0}
                                <div class="tree-empty-inner mono">
                                    no tables
                                </div>
                            {:else}
                                {#each tableMap[db] as t}
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
                                                </button>
                                            </div>
                                        {/if}
                                    </div>
                                {/each}
                            {/if}
                        </div>
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
        padding: 9px 12px 8px;
        border-bottom: 1px solid var(--line);
        flex-shrink: 0;
    }

    .tree-title {
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ink-3);
        font-weight: 600;
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

    .spin {
        color: var(--ink-3);
        font-size: 11px;
        flex-shrink: 0;
    }
</style>
