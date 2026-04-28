<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { view, toast } from "../lib/store.js";

    export let database;

    let tables = [];
    let loading = true;
    let filter = "";

    onMount(async () => {
        try {
            const r = await api.listTables(database);
            tables = r.tables || [];
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

    $: filtered = filter
        ? tables.filter((t) =>
              t.name.toLowerCase().includes(filter.toLowerCase()),
          )
        : tables;

    function fmtRows(n) {
        if (n === null || n === undefined) return "—";
        if (n < 1000) return String(n);
        if (n < 1_000_000) return (n / 1000).toFixed(1) + "K";
        return (n / 1_000_000).toFixed(1) + "M";
    }
    function fmtSize(b) {
        if (b === null || b === undefined) return "—";
        if (b < 1024) return b + " B";
        if (b < 1024 ** 2) return (b / 1024).toFixed(1) + " KB";
        if (b < 1024 ** 3) return (b / 1024 ** 2).toFixed(1) + " MB";
        return (b / 1024 ** 3).toFixed(1) + " GB";
    }

    function open(t) {
        view.set({ name: "browse", database, table: t.name });
    }
</script>

<div class="page animate-in">
    <div class="page-head">
        <div>
            <div class="crumbs mono">
                <button
                    class="crumb"
                    on:click={() => view.set({ name: "databases" })}
                    >databases</button
                >
                <span class="sep">/</span>
                <span class="here">{database}</span>
            </div>
            <h1>{database}</h1>
            <p class="muted">
                {tables.length}
                {tables.length === 1 ? "table" : "tables"}
            </p>
        </div>
        <div class="actions">
            <input
                class="input search"
                type="search"
                placeholder="filter tables…"
                bind:value={filter}
            />
            <button
                class="btn"
                on:click={() => view.set({ name: "query", database })}
            >
                <span class="mono">⌘</span> Query
            </button>
        </div>
    </div>

    {#if loading}
        <div class="loading mono">loading tables…</div>
    {:else if tables.length === 0}
        <div class="empty">
            <div class="empty-mark">∅</div>
            <div>This database has no tables.</div>
        </div>
    {:else}
        <div class="table-list">
            <div class="row head mono">
                <div class="col-icon"></div>
                <div class="col-name">Name</div>
                <div class="col-num">Rows</div>
                <div class="col-num">Size</div>
                <div class="col-arrow"></div>
            </div>
            {#each filtered as t, i}
                <button class="row" on:click={() => open(t)} style="--i: {i}">
                    <div class="col-icon mono">▦</div>
                    <div class="col-name">{t.name}</div>
                    <div class="col-num mono">{fmtRows(t.rows)}</div>
                    <div class="col-num mono">{fmtSize(t.size)}</div>
                    <div class="col-arrow">→</div>
                </button>
            {/each}
        </div>
        {#if filter && filtered.length === 0}
            <div class="empty small">
                No tables match <span class="mono">{filter}</span>.
            </div>
        {/if}
    {/if}
</div>

<style>
    .page {
        flex: 1;
        padding: 28px 32px 48px;
        max-width: 1280px;
        width: 100%;
        margin: 0 auto;
    }
    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 28px;
        gap: 24px;
        flex-wrap: wrap;
    }
    .crumbs {
        font-size: 11.5px;
        color: var(--ink-3);
        margin-bottom: 6px;
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .crumb {
        background: transparent;
        border: 0;
        color: var(--ink-2);
        padding: 0;
        font-family: inherit;
        font-size: inherit;
    }
    .crumb:hover {
        color: var(--acc);
    }
    .sep {
        color: var(--ink-3);
    }
    .here {
        color: var(--ink-0);
    }

    h1 {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 36px;
        letter-spacing: -0.02em;
        margin: 0 0 4px;
    }
    p {
        margin: 0;
    }

    .actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .search {
        width: 220px;
    }

    .table-list {
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }

    .row {
        width: 100%;
        display: grid;
        grid-template-columns: 36px 1fr 120px 120px 36px;
        align-items: center;
        padding: 13px 18px;
        background: transparent;
        border: 0;
        border-bottom: 1px solid var(--line);
        text-align: left;
        color: var(--ink-1);
        transition: background 80ms ease;
        animation: fadeUp 200ms cubic-bezier(0.2, 0.7, 0.2, 1) both;
        animation-delay: calc(var(--i, 0) * 12ms);
    }
    .row:last-child {
        border-bottom: 0;
    }
    .row.head {
        background: var(--bg-2);
        color: var(--ink-3);
        font-size: 10.5px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        padding: 10px 18px;
        cursor: default;
        animation: none;
    }
    .row:not(.head):hover {
        background: var(--bg-2);
    }
    .row:not(.head):hover .col-name {
        color: var(--acc);
    }
    .row:not(.head):hover .col-arrow {
        color: var(--acc);
        transform: translateX(3px);
    }

    .col-icon {
        color: var(--ink-3);
        font-size: 14px;
    }
    .col-name {
        font-family: var(--font-mono);
        font-size: 13.5px;
        color: var(--ink-0);
        transition: color 100ms ease;
    }
    .col-num {
        font-size: 12.5px;
        color: var(--ink-2);
        text-align: right;
    }
    .col-arrow {
        color: var(--ink-3);
        text-align: right;
        transition:
            color 120ms ease,
            transform 120ms ease;
    }

    .loading {
        color: var(--ink-2);
        padding: 48px 0;
        text-align: center;
    }
    .empty {
        text-align: center;
        padding: 80px 24px;
        color: var(--ink-2);
    }
    .empty.small {
        padding: 32px 24px;
    }
    .empty-mark {
        font-family: var(--font-mono);
        font-size: 36px;
        color: var(--ink-3);
        margin-bottom: 8px;
    }
</style>
