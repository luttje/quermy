<script>
    import DataTable from "./DataTable.svelte";
    import CloseIcon from "~icons/heroicons/x-mark-solid";

    /** @type {Array<{columns:any[], rows:any[], affected:number, isSelect:boolean, durationMs:number, sql:string}>} */
    export let results = [];
    export let capabilities = null;

    let activeIndex = 0;

    $: if (results.length > 0 && activeIndex >= results.length)
        activeIndex = results.length - 1;
    $: active = results[activeIndex] ?? null;

    function closeTab(i) {
        results = results.filter((_, idx) => idx !== i);
        if (activeIndex >= results.length)
            activeIndex = Math.max(0, results.length - 1);
    }

    function tabLabel(r, i) {
        return r.isSelect ? `Result ${i + 1}` : `Query ${i + 1}`;
    }

    function tabBadge(r) {
        if (r.isSelect)
            return `${r.rows.length} row${r.rows.length === 1 ? "" : "s"}`;
        return `${r.affected} affected`;
    }

    /** Truncate SQL for tooltip display */
    function sqlPreview(sql) {
        const oneLiner = sql.replace(/\s+/g, " ").trim();
        return oneLiner.length > 120 ? oneLiner.slice(0, 117) + "…" : oneLiner;
    }
</script>

<div class="flex flex-col h-full min-h-0">
    <!-- Tab bar -->
    <div
        class="flex items-end gap-px border-b border-(--line) bg-(--bg-0) px-2 pt-1.5 overflow-x-auto shrink-0 scrollbar-thin"
    >
        {#each results as r, i}
            <button
                class="group relative flex items-center gap-1.5 px-3 py-1.5 text-[12px] rounded-t-[5px] border border-b-0 transition-colors whitespace-nowrap cursor-pointer select-none
                    {activeIndex === i
                    ? 'bg-(--bg-1) text-(--ink-0) border-(--line-strong) -mb-px pb-1.75'
                    : 'bg-(--bg-0) text-(--ink-3) border-transparent hover:text-(--ink-1) hover:bg-(--bg-2) hover:border-(--line)'}"
                title={sqlPreview(r.sql)}
                on:click={() => (activeIndex = i)}
            >
                <span
                    class="mono text-[8.5px] font-bold tracking-[0.06em] px-1 py-px rounded-[3px]
                    {r.isSelect
                        ? 'bg-[rgba(127,217,127,0.12)] text-(--ok)'
                        : 'bg-[rgba(200,255,90,0.1)] text-(--acc-d)'}"
                >
                    {r.isSelect ? "SEL" : "OK"}
                </span>
                <span>{tabLabel(r, i)}</span>
                <span
                    class="mono text-[10px] text-(--ink-3) {activeIndex === i
                        ? 'muted'
                        : ''}"
                >
                    {tabBadge(r)}
                </span>
                <span
                    class="mono text-[10px] text-(--ink-3) {activeIndex === i
                        ? 'muted'
                        : ''}"
                >
                    · {r.durationMs.toFixed(2)} ms
                </span>
                <span
                    role="button"
                    tabindex="0"
                    class="ml-0.5 opacity-0 group-hover:opacity-60 hover:opacity-100! text-(--ink-3) hover:text-(--ink-0) transition-opacity rounded cursor-pointer"
                    title="Close tab"
                    on:click|stopPropagation={() => closeTab(i)}
                    on:keydown={(e) =>
                        (e.key === "Enter" || e.key === " ") && closeTab(i)}
                >
                    <CloseIcon class="w-3 h-3" />
                </span>
            </button>
        {/each}
    </div>

    <!-- Tab content -->
    <div class="flex-1 min-h-0 overflow-y-auto">
        {#if active}
            {#if active.isSelect}
                <DataTable
                    columns={active.columns}
                    rows={active.rows}
                    total={active.rows.length}
                    durationMs={active.durationMs}
                    db={null}
                    table={null}
                    mode="data"
                    {capabilities}
                />
            {:else}
                <div class="p-3">
                    <div
                        class="bg-(--bg-1) border border-[rgba(127,217,127,0.25)] rounded-lg px-4 py-3.5 flex gap-3 items-center text-(--ink-1) text-[13px]"
                    >
                        <div
                            class="mono text-[9.5px] bg-[rgba(127,217,127,0.12)] text-(--ok) px-1.75 py-0.75 rounded-[3px] font-bold tracking-[0.06em]"
                        >
                            OK
                        </div>
                        <div>
                            <strong class="mono">{active.affected}</strong>
                            row{active.affected === 1 ? "" : "s"} affected
                            <span class="muted"> · </span>
                            <span class="mono"
                                >{active.durationMs.toFixed(2)} ms</span
                            >
                        </div>
                    </div>
                    {#if active.sql}
                        <pre
                            class="mt-2.5 m-0 mono text-[11px] text-(--ink-3) leading-normal whitespace-pre-wrap break-all bg-(--bg-1) border border-(--line) rounded-lg px-3 py-2.5">{active.sql}</pre>
                    {/if}
                </div>
            {/if}
        {/if}
    </div>
</div>
