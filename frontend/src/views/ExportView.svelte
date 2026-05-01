<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import Btn from "../components/ui/Btn.svelte";
    import Checkbox from "../components/ui/Checkbox.svelte";

    const dispatch = createEventDispatcher();

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    let databases = [];
    let tablesByDb = {}; // { [db]: string[] }
    let expandedDbs = {}; // { [db]: boolean }
    let loadingDbs = {}; // { [db]: boolean }

    /** { [db]: Set<tableName> } — which tables are checked */
    let selected = {};

    let format = "sql";
    let includeStructure = true;
    let includeData = true;

    let loadingDatabases = true;
    let exporting = false;
    let errorMessage = "";
    let supportsGetCreateTable = true;

    const FORMATS = [
        { id: "sql", label: "SQL" },
        { id: "xml", label: "XML" },
        { id: "json", label: "JSON" },
        { id: "csv", label: "CSV (Single File)" },
    ];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    onMount(async () => {
        try {
            const [dbRes, caps] = await Promise.all([
                api.listDatabases(),
                api.getCapabilities(),
            ]);
            databases = dbRes.databases || [];
            for (const db of databases) {
                selected[db] = new Set();
            }
            supportsGetCreateTable = caps.supportsGetCreateTable ?? true;
            if (format === "sql" && !supportsGetCreateTable) {
                includeStructure = false;
            }
        } catch (e) {
            errorMessage = e.message;
        } finally {
            loadingDatabases = false;
        }
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    async function toggleExpand(db) {
        if (expandedDbs[db]) {
            expandedDbs[db] = false;
            expandedDbs = expandedDbs;
            return;
        }
        expandedDbs[db] = true;
        expandedDbs = expandedDbs;

        if (!tablesByDb[db]) {
            loadingDbs[db] = true;
            loadingDbs = loadingDbs;
            try {
                const r = await api.listTables(db);
                tablesByDb[db] = (r.tables || []).map((t) => t.name);
            } catch (e) {
                errorMessage = e.message;
            } finally {
                loadingDbs[db] = false;
                loadingDbs = loadingDbs;
            }
        }
    }

    function isAllSelected(db) {
        const tables = tablesByDb[db];
        if (!tables || tables.length === 0) return false;
        return tables.every((t) => selected[db]?.has(t));
    }

    function isPartiallySelected(db) {
        const tables = tablesByDb[db];
        if (!tables || tables.length === 0) return false;
        const sel = selected[db] || new Set();
        return !isAllSelected(db) && tables.some((t) => sel.has(t));
    }

    async function toggleDb(db) {
        // If tables haven't been loaded yet, load them first then select all
        if (!tablesByDb[db]) {
            loadingDbs[db] = true;
            loadingDbs = loadingDbs;
            expandedDbs[db] = true;
            expandedDbs = expandedDbs;
            try {
                const r = await api.listTables(db);
                tablesByDb[db] = (r.tables || []).map((t) => t.name);
                selected[db] = new Set(tablesByDb[db]);
                selected = selected;
            } catch (e) {
                errorMessage = e.message;
            } finally {
                loadingDbs[db] = false;
                loadingDbs = loadingDbs;
            }
            return;
        }
        if (isAllSelected(db)) {
            selected[db] = new Set();
        } else {
            selected[db] = new Set(tablesByDb[db]);
        }
        selected = selected;
    }

    function toggleTable(db, table) {
        if (!selected[db]) selected[db] = new Set();
        if (selected[db].has(table)) {
            selected[db].delete(table);
        } else {
            selected[db].add(table);
        }
        selected = selected;
    }

    // Only works for loaded tables, so was a bit unintuitive.
    // TODO: Perhaps if we eager load all tables on mount, these could work
    // function selectAll() {
    //     for (const db of databases) {
    //         const tables = tablesByDb[db];
    //         if (tables) {
    //             selected[db] = new Set(tables);
    //         }
    //     }
    //     selected = selected;
    // }

    // function deselectAll() {
    //     for (const db of databases) {
    //         selected[db] = new Set();
    //     }
    //     selected = selected;
    // }

    // -------------------------------------------------------------------------
    // Derived state
    // -------------------------------------------------------------------------

    $: totalSelected = Object.values(selected).reduce(
        (sum, s) => sum + s.size,
        0,
    );
    $: canExport =
        totalSelected > 0 && (includeStructure || includeData) && !exporting;

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    async function doExport() {
        if (!canExport) return;
        errorMessage = "";

        /** @type {Record<string, string[]>} */
        const payload = {};
        for (const [db, set] of Object.entries(selected)) {
            if (set.size > 0) payload[db] = [...set];
        }

        exporting = true;
        try {
            const { blob, filename } = await api.exportData(
                payload,
                format,
                includeStructure,
                includeData,
            );
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = filename;
            a.click();
            URL.revokeObjectURL(url);
            dispatch("done");
        } catch (e) {
            errorMessage = e.message;
        } finally {
            exporting = false;
        }
    }
</script>

<div class="flex flex-col gap-0 h-full">
    <!-- Top controls (format + include) -->
    <div
        class="px-5 py-4 flex flex-col gap-4 border-b border-(--line) shrink-0"
    >
        <!-- Format picker -->
        <div class="flex flex-col gap-2">
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >Format</span
            >
            <div class="flex gap-1.5">
                {#each FORMATS as f}
                    <button
                        on:click={() => (format = f.id)}
                        class="px-3.5 py-1.5 text-[12px] font-semibold mono rounded-(--radius) border transition-colors cursor-pointer
                            {format === f.id
                            ? 'bg-(--acc) text-[#0a0c0a] border-(--acc)'
                            : 'bg-(--bg-2) text-(--ink-1) border-(--line) hover:border-(--line-strong) hover:text-(--ink-0)'}"
                    >
                        {f.label}
                    </button>
                {/each}
            </div>
        </div>

        <!-- Include toggles -->
        <div class="flex flex-col gap-2">
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >Include</span
            >
            <div class="flex gap-4">
                <label
                    class="flex items-center gap-2 select-none {format ===
                        'sql' && !supportsGetCreateTable
                        ? 'opacity-40 cursor-not-allowed'
                        : 'cursor-pointer'}"
                    title={format === "sql" && !supportsGetCreateTable
                        ? "Structure export is not supported by this database engine"
                        : ""}
                >
                    <Checkbox
                        bind:checked={includeStructure}
                        disabled={format === "sql" && !supportsGetCreateTable}
                    />
                    <span class="text-[13px] text-(--ink-1)">Structure</span>
                </label>
                <label
                    class="flex items-center gap-2 cursor-pointer select-none"
                >
                    <Checkbox bind:checked={includeData} />
                    <span class="text-[13px] text-(--ink-1)">Data</span>
                </label>
            </div>
            {#if !includeStructure && !includeData}
                <p class="text-(--danger) text-[12px] m-0">
                    Select at least one option.
                </p>
            {/if}
            {#if format === "csv" && includeStructure && !includeData}
                <p class="text-(--warn) text-[12px] m-0">
                    CSV structure export includes column headers only.
                </p>
            {/if}
        </div>
    </div>

    <!-- Table selector -->
    <div class="flex-1 overflow-y-auto min-h-0 flex flex-col max-h-[40vh]">
        <!-- Selector header -->
        <div
            class="px-5 py-2.5 flex items-center justify-between border-b border-(--line) shrink-0"
        >
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >Tables
                {#if totalSelected > 0}
                    <span
                        class="ml-1.5 mono text-[9.5px] bg-(--acc) text-[#0a0c0a] px-1.5 py-0.5 rounded-full font-bold"
                        >{totalSelected}</span
                    >
                {/if}
            </span>
            <!-- <div class="flex gap-2">
                <button
                    on:click={selectAll}
                    class="text-[11px] text-(--ink-3) hover:text-(--ink-1) transition-colors cursor-pointer"
                    >All</button
                >
                <span class="text-(--ink-3) text-[11px]">/</span>
                <button
                    on:click={deselectAll}
                    class="text-[11px] text-(--ink-3) hover:text-(--ink-1) transition-colors cursor-pointer"
                    >None</button
                >
            </div> -->
        </div>

        <!-- Tree -->
        <div class="flex-1 overflow-y-auto">
            {#if loadingDatabases}
                <div class="px-5 py-4 text-(--ink-3) text-[13px]">
                    Loading databases…
                </div>
            {:else if databases.length === 0}
                <div class="px-5 py-4 text-(--ink-3) text-[13px]">
                    No databases found.
                </div>
            {:else}
                {#each databases as db}
                    <div class="border-b border-(--line) last:border-b-0">
                        <!-- DB row -->
                        <div
                            class="flex items-center gap-2 px-3 py-2 hover:bg-(--bg-2) group"
                        >
                            <!-- Expand chevron -->
                            <button
                                on:click={() => toggleExpand(db)}
                                class="w-4 h-4 flex items-center justify-center text-(--ink-3) hover:text-(--ink-0) cursor-pointer shrink-0 transition-transform duration-150
                                    {expandedDbs[db] ? 'rotate-90' : ''}"
                                aria-label={expandedDbs[db]
                                    ? "Collapse"
                                    : "Expand"}
                            >
                                <svg
                                    width="8"
                                    height="8"
                                    viewBox="0 0 8 8"
                                    fill="none"
                                >
                                    <path
                                        d="M2 1.5L5.5 4L2 6.5"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </button>

                            <!-- DB checkbox -->
                            <Checkbox
                                checked={isAllSelected(db)}
                                indeterminate={isPartiallySelected(db)}
                                on:change={() => toggleDb(db)}
                                class="shrink-0"
                            />

                            <!-- DB name (click to expand) -->
                            <button
                                on:click={() => toggleExpand(db)}
                                class="flex-1 text-left font-medium text-(--ink-0) text-[13px] mono truncate cursor-pointer hover:text-(--acc) transition-colors"
                            >
                                {db}
                            </button>

                            {#if tablesByDb[db]}
                                <span
                                    class="text-(--ink-3) text-[11px] mono shrink-0"
                                    >{tablesByDb[db].length}</span
                                >
                            {/if}
                        </div>

                        <!-- Table rows (expanded) -->
                        {#if expandedDbs[db]}
                            {#if loadingDbs[db]}
                                <div
                                    class="pl-10 py-2 text-(--ink-3) text-[12px]"
                                >
                                    Loading…
                                </div>
                            {:else if tablesByDb[db]?.length === 0}
                                <div
                                    class="pl-10 py-2 text-(--ink-3) text-[12px]"
                                >
                                    No tables.
                                </div>
                            {:else if tablesByDb[db]}
                                {#each tablesByDb[db] as table}
                                    <!-- svelte-ignore a11y-click-events-have-key-events a11y-no-static-element-interactions -->
                                    <div
                                        class="flex items-center gap-2 pl-9 pr-3 py-1.5 hover:bg-(--bg-2) cursor-pointer"
                                        on:click={() => toggleTable(db, table)}
                                    >
                                        <Checkbox
                                            checked={selected[db]?.has(table)}
                                            on:click={(e) =>
                                                e.stopPropagation()}
                                            on:mouseover={(e) => {
                                                if (e.buttons === 1) {
                                                    toggleTable(db, table);
                                                }
                                            }}
                                            on:focus={() => {}}
                                            on:change={(e) => {
                                                e.stopPropagation();
                                                toggleTable(db, table);
                                            }}
                                            class="shrink-0"
                                        />
                                        <span
                                            class="text-[12.5px] text-(--ink-1) mono truncate select-none"
                                            >{table}</span
                                        >
                                    </div>
                                {/each}
                            {/if}
                        {/if}
                    </div>
                {/each}
            {/if}
        </div>
    </div>

    <!-- Footer: error + export button -->
    <div
        class="px-5 py-3.5 border-t border-(--line) flex items-center justify-between gap-4 shrink-0"
    >
        <div class="flex-1 min-w-0">
            {#if errorMessage}
                <p class="text-(--danger) text-[12px] m-0 truncate">
                    {errorMessage}
                </p>
            {:else if totalSelected === 0}
                <p class="text-(--ink-3) text-[12px] m-0">
                    Select at least one table to export.
                </p>
            {/if}
        </div>
        <Btn
            variant="primary"
            disabled={!canExport}
            on:click={doExport}
            class="shrink-0 text-[12px] py-1.5 px-4"
        >
            {#if exporting}
                Exporting…
            {:else}
                Export as {format.toUpperCase()}
                <svg
                    width="12"
                    height="12"
                    viewBox="0 0 12 12"
                    fill="none"
                    class="ml-1"
                >
                    <path
                        d="M6 1v7M3 6l3 3 3-3M1 10h10"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            {/if}
        </Btn>
    </div>
</div>
