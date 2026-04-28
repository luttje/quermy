<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { view, toast } from "../lib/store.js";

    let databases = [];
    let loading = true;
    let filter = "";

    onMount(async () => {
        try {
            const r = await api.listDatabases();
            databases = r.databases || [];
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

    $: filtered = filter
        ? databases.filter((d) =>
              d.toLowerCase().includes(filter.toLowerCase()),
          )
        : databases;

    function open(db) {
        view.set({ name: "tables", database: db });
    }
</script>

<div class="page animate-in">
    <div class="page-head">
        <div>
            <h1>Databases</h1>
            <p class="muted">Pick one to explore its tables.</p>
        </div>
        <div class="actions">
            <input
                class="input search"
                type="search"
                placeholder="filter databases…"
                bind:value={filter}
            />
            <button class="btn" on:click={() => view.set({ name: "query" })}>
                <span class="mono">⌘</span> Query
            </button>
        </div>
    </div>

    {#if loading}
        <div class="loading mono">loading databases…</div>
    {:else if databases.length === 0}
        <div class="empty">
            <div class="empty-mark">∅</div>
            <div>This server reports no databases for the current user.</div>
        </div>
    {:else}
        <div class="grid">
            {#each filtered as db, i}
                <button class="card" on:click={() => open(db)} style="--i: {i}">
                    <div class="card-corner mono">db</div>
                    <div class="card-name mono">{db}</div>
                    <div class="card-arrow">→</div>
                </button>
            {/each}
        </div>
        {#if filter && filtered.length === 0}
            <div class="empty small">
                No databases match <span class="mono">{filter}</span>.
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
    .page-head h1 {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 36px;
        letter-spacing: -0.02em;
        margin: 0 0 4px;
    }
    .page-head p {
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

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }

    .card {
        position: relative;
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        padding: 22px 22px 26px;
        text-align: left;
        transition:
            border-color 120ms ease,
            transform 120ms ease,
            background 120ms ease;
        overflow: hidden;
        animation: fadeUp 240ms cubic-bezier(0.2, 0.7, 0.2, 1) both;
        animation-delay: calc(var(--i) * 18ms);
    }

    .card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(
            120% 80% at 0% 0%,
            rgba(200, 255, 90, 0.04),
            transparent 60%
        );
        opacity: 0;
        transition: opacity 200ms ease;
        pointer-events: none;
    }

    .card:hover {
        border-color: var(--acc);
        background: var(--bg-2);
        transform: translateY(-1px);
    }
    .card:hover::before {
        opacity: 1;
    }
    .card:hover .card-arrow {
        color: var(--acc);
        transform: translateX(4px);
    }

    .card-corner {
        position: absolute;
        top: 12px;
        right: 14px;
        font-size: 10px;
        color: var(--ink-3);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .card-name {
        font-size: 17px;
        color: var(--ink-0);
        font-weight: 500;
        word-break: break-all;
        line-height: 1.3;
        margin-bottom: 14px;
    }

    .card-arrow {
        color: var(--ink-3);
        font-size: 18px;
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
