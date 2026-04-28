<script>
    import { api } from "../lib/api.js";
    import { view, toast } from "../lib/store.js";
    import DataTable from "../components/DataTable.svelte";
    import { onMount } from "svelte";

    export let database = "";

    let sql = `SELECT NOW() AS now, VERSION() AS version;`;
    let busy = false;
    let result = null; // { columns, rows, durationMs, isSelect, affected }
    let errorMsg = null;
    let textarea;
    let databases = [];

    onMount(async () => {
        // Pre-load the database list so the user can pick one if they came in
        // without context.
        try {
            const r = await api.listDatabases();
            databases = r.databases || [];
        } catch (_) {
            /* ignore — user may not need it */
        }
    });

    async function run() {
        if (!sql.trim() || busy) return;
        busy = true;
        errorMsg = null;
        try {
            const r = await api.runQuery(database, sql);
            result = r;
            if (!r.isSelect) {
                toast(
                    `OK · ${r.affected} row${r.affected === 1 ? "" : "s"} affected · ${r.durationMs.toFixed(2)} ms`,
                    "success",
                );
            }
        } catch (e) {
            errorMsg = e.message;
            result = null;
        } finally {
            busy = false;
        }
    }

    // Cmd/Ctrl+Enter to run
    function onKeydown(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
            e.preventDefault();
            run();
        }
    }

    // Tab inserts spaces in the textarea
    function onTab(e) {
        if (e.key === "Tab") {
            e.preventDefault();
            const s = e.target.selectionStart;
            const en = e.target.selectionEnd;
            sql = sql.slice(0, s) + "  " + sql.slice(en);
            // restore caret on the next tick
            requestAnimationFrame(() => {
                textarea.selectionStart = textarea.selectionEnd = s + 2;
            });
        }
    }
</script>

<svelte:window on:keydown={onKeydown} />

<div class="page animate-in">
    <div class="page-head">
        <div>
            <div class="crumbs mono">
                <button
                    class="crumb"
                    on:click={() => view.set({ name: "databases" })}
                    >databases</button
                >
                {#if database}
                    <span class="sep">/</span>
                    <button
                        class="crumb"
                        on:click={() => view.set({ name: "tables", database })}
                        >{database}</button
                    >
                {/if}
                <span class="sep">/</span>
                <span class="here">query</span>
            </div>
            <h1>Query editor</h1>
        </div>

        <div class="actions">
            <label class="db-pick mono">
                <span class="lbl">DB</span>
                <select class="select" bind:value={database}>
                    <option value="">(none)</option>
                    {#each databases as d}<option value={d}>{d}</option>{/each}
                </select>
            </label>
            <button
                class="btn btn-primary"
                on:click={run}
                disabled={busy || !sql.trim()}
            >
                {busy ? "Running…" : "Run"}
                <span class="kbd">⌘↵</span>
            </button>
        </div>
    </div>

    <div class="editor-wrap">
        <div class="editor-head">
            <span class="mono">SQL</span>
            <span class="muted mono">{sql.length} chars</span>
        </div>
        <textarea
            bind:this={textarea}
            class="textarea editor"
            bind:value={sql}
            on:keydown={onTab}
            placeholder="SELECT * FROM ..."
            spellcheck="false"
        ></textarea>
    </div>

    {#if errorMsg}
        <div class="error">
            <div class="err-tag mono">ERROR</div>
            <pre class="mono">{errorMsg}</pre>
        </div>
    {:else if result}
        {#if result.isSelect}
            <DataTable
                columns={result.columns}
                rows={result.rows}
                total={result.affected}
                durationMs={result.durationMs}
            />
        {:else}
            <div class="ok-result">
                <div class="ok-tag mono">OK</div>
                <div>
                    <strong class="mono">{result.affected}</strong>
                    row{result.affected === 1 ? "" : "s"} affected
                    <span class="muted"> · </span>
                    <span class="mono">{result.durationMs.toFixed(2)} ms</span>
                </div>
            </div>
        {/if}
    {:else}
        <div class="hint">
            <span class="mono">↵</span> Press <span class="kbd">⌘ Enter</span> or
            click Run to execute.
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
        gap: 18px;
    }

    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
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
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 36px;
        letter-spacing: -0.02em;
        margin: 0;
    }
    .actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .db-pick {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 10px 4px 12px;
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: var(--radius);
    }
    .db-pick .lbl {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--ink-3);
        font-weight: 600;
    }
    .db-pick .select {
        background: transparent;
        border: 0;
        padding: 6px 4px 6px 0;
        width: auto;
        min-width: 80px;
    }
    .db-pick .select:focus {
        box-shadow: none;
    }

    .btn-primary .kbd {
        background: rgba(10, 12, 10, 0.15);
        color: rgba(10, 12, 10, 0.7);
        border-color: transparent;
        font-size: 10px;
        margin-left: 2px;
    }

    .editor-wrap {
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }
    .editor-head {
        padding: 9px 16px;
        border-bottom: 1px solid var(--line);
        background: var(--bg-2);
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--ink-3);
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 600;
    }
    .editor {
        background: var(--bg-1);
        border: 0;
        border-radius: 0;
        min-height: 180px;
        resize: vertical;
        padding: 16px;
        font-size: 13.5px;
        line-height: 1.6;
        color: var(--ink-0);
    }
    .editor:focus {
        box-shadow: none;
        outline: none;
    }

    .error {
        background: var(--bg-1);
        border: 1px solid rgba(255, 115, 103, 0.3);
        border-radius: var(--radius-lg);
        padding: 16px;
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }
    .err-tag {
        font-size: 10px;
        background: rgba(255, 115, 103, 0.1);
        color: var(--danger);
        padding: 3px 7px;
        border-radius: 3px;
        font-weight: 700;
        letter-spacing: 0.06em;
        margin-top: 2px;
    }
    .error pre {
        margin: 0;
        white-space: pre-wrap;
        color: var(--ink-0);
        font-size: 13px;
    }

    .ok-result {
        background: var(--bg-1);
        border: 1px solid rgba(127, 217, 127, 0.25);
        border-radius: var(--radius-lg);
        padding: 16px;
        display: flex;
        gap: 14px;
        align-items: center;
        color: var(--ink-1);
    }
    .ok-tag {
        font-size: 10px;
        background: rgba(127, 217, 127, 0.12);
        color: var(--ok);
        padding: 3px 7px;
        border-radius: 3px;
        font-weight: 700;
        letter-spacing: 0.06em;
    }

    .hint {
        color: var(--ink-3);
        text-align: center;
        padding: 36px 16px;
        background: var(--bg-1);
        border: 1px dashed var(--line-strong);
        border-radius: var(--radius-lg);
        font-size: 13px;
    }
</style>
