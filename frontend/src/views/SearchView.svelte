<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import Btn from "../components/ui/Btn.svelte";
    import Input from "../components/ui/Input.svelte";

    // -------------------------------------------------------------------------
    // Props
    // -------------------------------------------------------------------------

    /** The database currently open in the main view, if any. */
    export let currentDb = "";
    /** The table currently open in the main view, if any. */
    export let currentTable = "";
    /** Full list of databases for the "all databases" scope. */
    export let databases = [];
    /** Driver capabilities (used for identifier quoting). */
    export let capabilities = null;

    // -------------------------------------------------------------------------
    // Identifier quoting helpers
    // -------------------------------------------------------------------------

    $: ioOpen = capabilities?.identifierOpen ?? '"';

    function quoteIdent(name) {
        if (ioOpen === '"') return '"' + name.replace(/"/g, '""') + '"';
        if (ioOpen === "[") return "[" + name.replace(/]/g, "]]") + "]";
        return "`" + name.replace(/`/g, "``") + "`";
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    let searchTerm = "";
    let scope = currentTable ? "table" : currentDb ? "database" : "all";
    let includeFilter = "";
    let excludeFilter = "";

    let searching = false;
    let stopRequested = false;

    /** @type {Array<{db:string, table:string, columns:any[], rows:any[]}>} */
    let results = [];
    let errorCount = 0;
    let searched = false;
    let stopped = false;
    let progress = { current: 0, total: 0 };

    let searchInput;

    onMount(() => {
        searchInput?.focus();
    });

    // Keep scope valid when props change
    $: if (!currentTable && scope === "table") {
        scope = currentDb ? "database" : "all";
    }

    $: totalRows = results.reduce((s, r) => s + r.rows.length, 0);

    // -------------------------------------------------------------------------
    // Column type detection (for cross-engine LIKE compatibility)
    // PostgreSQL throws if you LIKE on non-text columns; MySQL/SQLite don't.
    // -------------------------------------------------------------------------

    const TEXT_TYPE_FALLBACK_RE = /char|text|string|clob|memo|nvar/i;

    function isTextColumn(col) {
        const t = col.type || "";
        const patterns = capabilities?.textColumnTypePatterns;
        if (patterns?.length) return patterns.some((p) => t.toLowerCase().includes(p));
        return TEXT_TYPE_FALLBACK_RE.test(t);
    }

    function getSearchableCols(columns) {
        const textCols = columns.filter(isTextColumn);
        // Fallback to all columns if no text columns detected (e.g. unknown types)
        return textCols.length > 0 ? textCols : columns;
    }

    // -------------------------------------------------------------------------
    // HTML / LIKE helpers
    // -------------------------------------------------------------------------

    function escapeHtml(str) {
        return String(str ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    function highlight(text, term) {
        const escaped = escapeHtml(text);
        if (!term.trim()) return escaped;
        const re = new RegExp(
            term.trim().replace(/[.*+?^${}()|[\]\\]/g, "\\$&"),
            "gi",
        );
        return escaped.replace(
            re,
            (m) =>
                `<mark class="bg-[rgba(200,255,90,0.22)] text-(--acc) rounded-sm px-px">${m}</mark>`,
        );
    }

    /**
     * Escape a value for embedding in a SQL single-quoted string.
     * Single quotes are doubled; other characters are left as-is so the user
     * can intentionally use LIKE wildcards (% and _).
     */
    function prepTerm(term) {
        return term.replace(/'/g, "''");
    }

    // -------------------------------------------------------------------------
    // Search targets
    // -------------------------------------------------------------------------

    async function buildTargets() {
        if (scope === "table") {
            if (!currentDb || !currentTable) return [];
            return [{ db: currentDb, table: currentTable }];
        }

        if (scope === "database") {
            if (!currentDb) return [];
            const r = await api.listTables(currentDb);
            return (r.tables || []).map((t) => ({
                db: currentDb,
                table: t.name,
            }));
        }

        // scope === "all"
        const includes = includeFilter
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean);
        const excludes = excludeFilter
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean);

        let dbs = [...databases];
        if (includes.length) dbs = dbs.filter((d) => includes.includes(d));
        if (excludes.length) dbs = dbs.filter((d) => !excludes.includes(d));

        const targets = [];
        for (const db of dbs) {
            try {
                const r = await api.listTables(db);
                for (const t of r.tables || [])
                    targets.push({ db, table: t.name });
            } catch (_) {
                /* skip inaccessible databases */
            }
        }
        console.log(3, targets);
        return targets;
    }

    // -------------------------------------------------------------------------
    // Search execution
    // -------------------------------------------------------------------------

    async function doSearch() {
        if (!searchTerm.trim() || searching) return;

        searching = true;
        stopRequested = false;
        stopped = false;
        results = [];
        errorCount = 0;
        searched = true;
        progress = { current: 0, total: 0 };

        try {
            const targets = await buildTargets();
            progress = { current: 0, total: targets.length };

            const safe = prepTerm(searchTerm.trim());
            const pattern = `${safe}`;

            for (const { db, table } of targets) {
                if (stopRequested) {
                    stopped = true;
                    break;
                }

                progress = { ...progress, current: progress.current + 1 };

                try {
                    // Fetch column metadata (1 row minimum, just need schema)
                    const meta = await api.browseTable(db, table, 1, 0);
                    const allCols = meta.columns || [];
                    if (!allCols.length) continue;

                    const searchCols = getSearchableCols(allCols);
                    const qDb = quoteIdent(db);
                    const qTbl = quoteIdent(table);
                    const conditions = searchCols
                        .map((c) => `${quoteIdent(c.name)} LIKE '${pattern}'`)
                        .join(" OR ");

                    const sql = `SELECT * FROM ${qDb}.${qTbl} WHERE ${conditions} LIMIT 100`;
                    const r = await api.runQuery(db, sql);

                    if (r.rows?.length) {
                        results = [
                            ...results,
                            {
                                db,
                                table,
                                columns: r.columns || allCols,
                                rows: r.rows,
                            },
                        ];
                    }
                } catch (_) {
                    errorCount++;
                }
            }
        } catch (_) {
            /* buildTargets itself failed */
        } finally {
            searching = false;
        }
    }

    function stopSearch() {
        stopRequested = true;
    }

    function handleKeydown(e) {
        if (e.key === "Enter") doSearch();
    }
</script>

<div class="flex flex-col h-full">
    <!-- ── Controls ────────────────────────────────────────────────────────── -->
    <div
        class="px-5 py-4 border-b border-(--line) shrink-0 flex flex-col gap-3"
    >
        <!-- Search input + button -->
        <div class="flex gap-2">
            <Input
                bind:this={searchInput}
                bind:value={searchTerm}
                on:keydown={handleKeydown}
                type="text"
                placeholder="Search term… (% and _ are LIKE wildcards)"
            />
            {#if searching}
                <Btn variant="danger" on:click={stopSearch}>Stop</Btn>
            {:else}
                <Btn
                    variant="primary"
                    on:click={doSearch}
                    disabled={!searchTerm.trim()}
                >
                    Search
                </Btn>
            {/if}
        </div>

        <!-- Scope picker -->
        <div class="flex gap-1.5 flex-wrap">
            {#each [{ value: "table", label: "Current table", disabled: !currentTable, hint: currentTable ? `${currentDb}.${currentTable}` : "no table open" }, { value: "database", label: "Current database", disabled: !currentDb, hint: currentDb || "no database open" }, { value: "all", label: "All databases", disabled: false, hint: `${databases.length} databases` }] as opt}
                <!-- svelte-ignore a11y-click-events-have-key-events a11y-no-static-element-interactions -->
                <label
                    class="flex flex-col px-3 py-1.75 rounded-(--radius) text-[12px] border cursor-pointer select-none transition-colors
                        {scope === opt.value
                        ? 'bg-(--acc) text-[#0a0c0a] border-(--acc)'
                        : 'bg-(--bg-2) text-(--ink-1) border-(--line) hover:bg-(--bg-3) hover:border-(--line-strong)'}
                        {opt.disabled
                        ? 'opacity-40 cursor-not-allowed pointer-events-none'
                        : ''}"
                >
                    <input
                        type="radio"
                        bind:group={scope}
                        value={opt.value}
                        disabled={opt.disabled}
                        class="hidden"
                    />
                    <span class="font-semibold">{opt.label}</span>
                    <span
                        class="mono text-[10px] {scope === opt.value
                            ? 'text-[rgba(10,12,10,0.55)]'
                            : 'text-(--ink-3)'} leading-tight mt-0.5"
                    >
                        {opt.hint}
                    </span>
                </label>
            {/each}
        </div>

        <!-- Include / Exclude filters (all-databases scope only) -->
        {#if scope === "all"}
            <div class="flex gap-3">
                <div class="flex-1 flex flex-col gap-1">
                    <label
                        for="search-include"
                        class="text-[10px] uppercase tracking-widest text-(--ink-3) font-bold"
                        >Include databases</label
                    >
                    <Input
                        id="search-include"
                        bind:value={includeFilter}
                        type="text"
                        placeholder="db1, db2 — empty means all"
                    />
                </div>
                <div class="flex-1 flex flex-col gap-1">
                    <label
                        for="search-exclude"
                        class="text-[10px] uppercase tracking-widest text-(--ink-3) font-bold"
                        >Exclude databases</label
                    >
                    <Input
                        id="search-exclude"
                        bind:value={excludeFilter}
                        type="text"
                        placeholder="db3, db4"
                    />
                </div>
            </div>
        {/if}
    </div>

    <!-- ── Progress bar ───────────────────────────────────────────────────── -->
    {#if searching || (searched && progress.total > 0)}
        <div
            class="px-5 py-2 border-b border-(--line) bg-(--bg-2) shrink-0 flex items-center gap-3"
        >
            <span class="mono text-[11px] text-(--ink-3) shrink-0">
                {#if searching}
                    Searching {progress.current}/{progress.total} tables…
                {:else if stopped}
                    Stopped after {progress.current}/{progress.total} tables · {results.length}
                    result{results.length === 1 ? "" : "s"} · {totalRows} row{totalRows ===
                    1
                        ? ""
                        : "s"}
                    {#if errorCount > 0}
                        <span class="text-(--danger)"
                            >· {errorCount} error{errorCount === 1
                                ? ""
                                : "s"}</span
                        >
                    {/if}
                {:else}
                    Searched {progress.total} table{progress.total === 1
                        ? ""
                        : "s"} · {results.length}
                    result{results.length === 1 ? "" : "s"} · {totalRows} row{totalRows ===
                    1
                        ? ""
                        : "s"}
                    {#if errorCount > 0}
                        <span class="text-(--danger)"
                            >· {errorCount} table{errorCount === 1 ? "" : "s"} skipped</span
                        >
                    {/if}
                {/if}
            </span>

            {#if searching}
                <div
                    class="flex-1 h-1 bg-(--bg-3) rounded-full overflow-hidden"
                >
                    <div
                        class="h-full bg-(--acc) rounded-full transition-[width] duration-150"
                        style="width: {progress.total > 0
                            ? (progress.current / progress.total) * 100
                            : 0}%"
                    ></div>
                </div>
            {/if}
        </div>
    {/if}

    <!-- ── Results ────────────────────────────────────────────────────────── -->
    <div class="flex-1 overflow-y-auto min-h-0">
        <!-- Empty states -->
        {#if !searched}
            <div
                class="flex flex-col items-center justify-center h-full gap-3 text-(--ink-3) text-[13px] py-16"
            >
                <svg
                    width="36"
                    height="36"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.25"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="opacity-40"
                >
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
                <div class="text-center">
                    <p>
                        Type a term and press <kbd
                            class="mono bg-(--bg-2) border border-(--line) px-1.5 py-0.5 rounded text-[11px] text-(--ink-1)"
                            >Enter</kbd
                        > to search.
                    </p>
                    <p class="text-[11px] mt-1 text-(--ink-3)">
                        % and _ act as LIKE wildcards.
                    </p>
                </div>
            </div>
        {:else if searched && results.length === 0 && !searching}
            <div
                class="flex flex-col items-center justify-center h-full gap-2 text-(--ink-3) text-[13px] py-16"
            >
                <svg
                    width="32"
                    height="32"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.25"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="opacity-30"
                >
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                    <line x1="8" y1="11" x2="14" y2="11" />
                </svg>
                <p>
                    No results for <span class="mono text-(--ink-1)"
                        >"{searchTerm}"</span
                    >
                </p>
            </div>
        {/if}

        <!-- Result groups -->
        {#each results as r}
            <div class="border-b border-(--line)">
                <!-- Table header -->
                <div
                    class="flex items-center gap-2.5 px-5 py-2 bg-(--bg-2) border-b border-(--line)"
                >
                    <span class="mono text-[12px] font-semibold text-(--ink-0)"
                        >{r.db}.<span class="text-(--acc)">{r.table}</span
                        ></span
                    >
                    <span
                        class="mono text-[9.5px] bg-[rgba(200,255,90,0.1)] text-(--acc) border border-[rgba(200,255,90,0.25)] px-1.5 py-0.5 rounded-full font-semibold"
                    >
                        {r.rows.length}{r.rows.length === 100 ? "+" : ""} row{r
                            .rows.length === 1
                            ? ""
                            : "s"}
                    </span>
                </div>

                <!-- Scrollable result table -->
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[12px]">
                        <thead>
                            <tr>
                                {#each r.columns as col}
                                    <th
                                        class="text-left px-3 py-1.5 border-b border-(--line) bg-(--bg-1) text-(--ink-3) font-medium mono text-[10.5px] uppercase tracking-wider whitespace-nowrap sticky top-0"
                                    >
                                        {col.name}
                                    </th>
                                {/each}
                            </tr>
                        </thead>
                        <tbody>
                            {#each r.rows as row}
                                <tr
                                    class="border-b border-(--line) last:border-b-0 hover:bg-(--bg-2) transition-colors"
                                >
                                    {#each r.columns as col}
                                        {@const val = row[col.name]}
                                        <td
                                            class="px-3 py-2 mono text-[12px] whitespace-nowrap max-w-60 overflow-hidden text-ellipsis align-top
                                                {val === null
                                                ? 'text-(--ink-3)'
                                                : 'text-(--ink-1)'}"
                                            title={val === null
                                                ? "NULL"
                                                : String(val)}
                                        >
                                            {#if val === null}
                                                <span
                                                    class="italic text-(--ink-3) text-[11px]"
                                                    >NULL</span
                                                >
                                            {:else}
                                                {@html highlight(
                                                    String(val),
                                                    searchTerm,
                                                )}
                                            {/if}
                                        </td>
                                    {/each}
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>
        {/each}

        <!-- Searching spinner sentinel at the bottom -->
        {#if searching}
            <div
                class="px-5 py-4 flex items-center gap-2.5 text-(--ink-3) text-[12px]"
            >
                <span
                    class="w-3.5 h-3.5 border-2 border-(--line) border-t-(--acc) rounded-full animate-spin"
                ></span>
                <span class="mono"
                    >Scanning {progress.current}/{progress.total}…</span
                >
            </div>
        {/if}
    </div>
</div>
