<script>
    /**
     * Generic data table. Used for both:
     *   - browsing a table (columns include type/key/nullable metadata)
     *   - showing query results (columns include only name/type)
     */
    export let columns = [];
    export let rows = [];
    export let total = null; // optional — total row count
    export let durationMs = null; // optional — query duration

    function formatCell(v) {
        if (v === null || v === undefined) return null;
        if (typeof v === "object") return JSON.stringify(v);
        return String(v);
    }

    function isPrimary(c) {
        return c && c.key === "PRI";
    }
</script>

<div class="datatable-wrap">
    {#if columns.length === 0}
        <div class="empty-state">
            <div class="empty-mark">∅</div>
            <div>No columns to display.</div>
        </div>
    {:else}
        <div class="meta-bar">
            <span class="meta-item">
                <span class="meta-label">Rows</span>
                <span class="meta-value mono"
                    >{rows.length}{total !== null
                        ? ` / ${total.toLocaleString()}`
                        : ""}</span
                >
            </span>
            <span class="meta-item">
                <span class="meta-label">Cols</span>
                <span class="meta-value mono">{columns.length}</span>
            </span>
            {#if durationMs !== null}
                <span class="meta-item">
                    <span class="meta-label">Time</span>
                    <span class="meta-value mono"
                        >{durationMs.toFixed(2)} ms</span
                    >
                </span>
            {/if}
        </div>

        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th class="rownum"></th>
                        {#each columns as c}
                            <th class:pk={isPrimary(c)}>
                                <div class="th-name">
                                    {c.name}
                                    {#if isPrimary(c)}<span
                                            class="pk-badge"
                                            title="Primary key">PK</span
                                        >{/if}
                                </div>
                                {#if c.type}<div class="th-type mono">
                                        {c.type}{c.nullable === false
                                            ? " · NOT NULL"
                                            : ""}
                                    </div>{/if}
                            </th>
                        {/each}
                    </tr>
                </thead>
                <tbody>
                    {#each rows as row, i}
                        <tr>
                            <td class="rownum mono">{i + 1}</td>
                            {#each columns as c}
                                {@const v = formatCell(row[c.name])}
                                <td class="mono" class:null-cell={v === null}>
                                    {#if v === null}
                                        <span class="null-tag">NULL</span>
                                    {:else if v.length > 200}
                                        <span title={v}>{v.slice(0, 200)}…</span
                                        >
                                    {:else}
                                        {v}
                                    {/if}
                                </td>
                            {/each}
                        </tr>
                    {/each}
                </tbody>
            </table>
            {#if rows.length === 0}
                <div class="empty-rows">
                    <span class="mono">// no rows</span>
                </div>
            {/if}
        </div>
    {/if}
</div>

<style>
    .datatable-wrap {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }

    .meta-bar {
        display: flex;
        gap: 24px;
        padding: 10px 16px;
        border-bottom: 1px solid var(--line);
        background: var(--bg-2);
        font-size: 12px;
    }
    .meta-item {
        display: flex;
        gap: 8px;
        align-items: baseline;
    }
    .meta-label {
        color: var(--ink-2);
        font-size: 10px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-weight: 600;
    }
    .meta-value {
        color: var(--ink-0);
        font-size: 12px;
    }

    .scroll {
        flex: 1;
        overflow: auto;
        min-height: 0;
    }

    table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        font-size: 12.5px;
    }

    thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: var(--bg-2);
        text-align: left;
        padding: 10px 14px;
        border-bottom: 1px solid var(--line-strong);
        border-right: 1px solid var(--line);
        white-space: nowrap;
        vertical-align: top;
    }
    thead th:last-child {
        border-right: 0;
    }
    thead th.pk {
        background: linear-gradient(
            180deg,
            rgba(200, 255, 90, 0.06),
            var(--bg-2)
        );
    }
    .th-name {
        color: var(--ink-0);
        font-weight: 600;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .th-type {
        color: var(--ink-3);
        font-size: 10.5px;
        margin-top: 2px;
        letter-spacing: 0.02em;
    }
    .pk-badge {
        font-family: var(--font-mono);
        font-size: 9px;
        background: var(--acc);
        color: #0a0c0a;
        padding: 1px 4px;
        border-radius: 3px;
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    tbody tr {
        transition: background 80ms ease;
    }
    tbody tr:hover {
        background: var(--bg-2);
    }
    tbody tr:hover td.rownum {
        background: var(--bg-3);
    }

    tbody td {
        padding: 8px 14px;
        border-bottom: 1px solid var(--line);
        border-right: 1px solid var(--line);
        color: var(--ink-1);
        max-width: 360px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    tbody td:last-child {
        border-right: 0;
    }

    .rownum {
        background: var(--bg-2);
        color: var(--ink-3);
        text-align: right;
        font-size: 11px;
        user-select: none;
        width: 1%;
        border-right: 1px solid var(--line-strong) !important;
        position: sticky;
        left: 0;
    }
    thead .rownum {
        z-index: 2;
    }

    .null-tag {
        color: var(--ink-3);
        font-size: 10px;
        letter-spacing: 0.06em;
        font-weight: 600;
        background: transparent;
        border: 1px dashed var(--line-strong);
        padding: 1px 6px;
        border-radius: 3px;
    }

    .empty-rows {
        padding: 28px;
        text-align: center;
        color: var(--ink-3);
        font-size: 13px;
    }
    .empty-state {
        padding: 64px 24px;
        text-align: center;
        color: var(--ink-2);
    }
    .empty-mark {
        font-family: var(--font-mono);
        font-size: 36px;
        color: var(--ink-3);
        margin-bottom: 8px;
    }
</style>
