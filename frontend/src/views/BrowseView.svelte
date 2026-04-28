<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { view, toast } from "../lib/store.js";
    import DataTable from "../components/DataTable.svelte";

    export let database;
    export let table;

    let columns = [];
    let rows = [];
    let total = 0;
    let loading = true;
    let limit = 100;
    let offset = 0;

    async function load() {
        loading = true;
        try {
            const r = await api.browseTable(database, table, limit, offset);
            columns = r.columns;
            rows = r.rows;
            total = r.total;
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    }

    onMount(load);

    $: page = Math.floor(offset / limit) + 1;
    $: pages = Math.max(1, Math.ceil(total / limit));

    function next() {
        if (offset + limit < total) {
            offset += limit;
            load();
        }
    }
    function prev() {
        if (offset >= limit) {
            offset -= limit;
            load();
        }
    }
    function first() {
        offset = 0;
        load();
    }
    function last() {
        offset = (pages - 1) * limit;
        load();
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
                <button
                    class="crumb"
                    on:click={() => view.set({ name: "tables", database })}
                    >{database}</button
                >
                <span class="sep">/</span>
                <span class="here">{table}</span>
            </div>
            <h1 class="mono">{table}</h1>
        </div>
        <div class="actions">
            <button
                class="btn"
                on:click={() => view.set({ name: "query", database })}
            >
                <span class="mono">⌘</span> Query
            </button>
            <button class="btn btn-ghost" on:click={load} disabled={loading}>
                ↻ Refresh
            </button>
        </div>
    </div>

    {#if loading && rows.length === 0}
        <div class="loading mono">loading rows…</div>
    {:else}
        <DataTable {columns} {rows} {total} />

        <div class="pager">
            <div class="page-info mono">
                page <span class="strong">{page}</span> of {pages.toLocaleString()}
                <span class="muted"> · </span>
                rows <span class="strong">{offset + 1}</span>–<span
                    class="strong">{Math.min(offset + limit, total)}</span
                >
            </div>
            <div class="page-controls">
                <select
                    class="select limit"
                    bind:value={limit}
                    on:change={() => {
                        offset = 0;
                        load();
                    }}
                >
                    <option value={25}>25 / page</option>
                    <option value={100}>100 / page</option>
                    <option value={250}>250 / page</option>
                    <option value={1000}>1000 / page</option>
                </select>
                <button
                    class="btn btn-ghost"
                    on:click={first}
                    disabled={offset === 0}>«</button
                >
                <button
                    class="btn btn-ghost"
                    on:click={prev}
                    disabled={offset === 0}>‹</button
                >
                <button
                    class="btn btn-ghost"
                    on:click={next}
                    disabled={offset + limit >= total}>›</button
                >
                <button
                    class="btn btn-ghost"
                    on:click={last}
                    disabled={offset + limit >= total}>»</button
                >
            </div>
        </div>
    {/if}
</div>

<style>
    .page {
        flex: 1;
        padding: 28px 32px 32px;
        max-width: 1600px;
        width: 100%;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 18px;
        gap: 16px;
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
        font: inherit;
        padding: 0;
    }
    .crumb:hover {
        color: var(--acc);
    }
    .here {
        color: var(--ink-0);
    }
    h1 {
        font-size: 24px;
        margin: 0;
        color: var(--ink-0);
        letter-spacing: -0.01em;
        font-weight: 600;
    }
    .actions {
        display: flex;
        gap: 8px;
    }

    .pager {
        margin-top: 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    .page-info {
        color: var(--ink-2);
        font-size: 12px;
    }
    .page-info .strong {
        color: var(--ink-0);
    }
    .page-controls {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .limit {
        width: auto;
        padding: 6px 10px;
        font-size: 12px;
    }

    .loading {
        color: var(--ink-2);
        padding: 48px 0;
        text-align: center;
    }
</style>
