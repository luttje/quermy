<script>
    import { createEventDispatcher, onMount } from "svelte";
    import UploadIcon from "~icons/heroicons/arrow-up-tray-solid";
    import { api } from "../lib/api.js";
    import { sqlErrors } from "../lib/store.js";
    import Btn from "../components/ui/Btn.svelte";
    import Select from "../components/ui/Select.svelte";

    export let initialDb = "";
    export let initialTable = "";

    const dispatch = createEventDispatcher();

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    let file = null;

    let hasHeader = true;
    let delimiter = ",";
    let enclosure = '"';
    let lineEnding = "\\r\\n";
    let commentChar = "#";
    let duplicateHandling = "INSERT IGNORE";

    let databases = [];
    let targetDb = "";
    let tables = [];
    let targetTable = "";

    let tableColumns = [];
    let csvHeaders = [];
    let columnMapping = {};

    let loadingDbs = true;
    let loadingTables = false;
    let loadingColumns = false;
    let importing = false;
    let errorMessage = "";
    let importResult = null;

    const DUPLICATE_OPTIONS = [
        {
            id: "INSERT",
            label: "INSERT (error on duplicate)",
        },
        {
            id: "INSERT IGNORE",
            label: "INSERT IGNORE (ignore rows that cause errors)",
        },
        {
            id: "REPLACE",
            label: "REPLACE (overwrite duplicates)",
        },
    ];

    const LINE_ENDINGS = [
        {
            id: "\\r\\n",
            label: "\\r\\n  (Windows / CRLF)",
        },
        {
            id: "\\n",
            label: "\\n  (Unix / LF)",
        },
        {
            id: "\\r",
            label: "\\r  (Classic Mac / CR)",
        },
    ];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    onMount(async () => {
        try {
            const r = await api.listDatabases();
            databases = r.databases || [];

            // Default to the currently open database/table, or the only available one
            if (initialDb && databases.includes(initialDb)) {
                targetDb = initialDb;
            } else if (databases.length === 1) {
                targetDb = databases[0];
            }

            if (targetDb) {
                await loadTables();
                if (initialTable && tables.includes(initialTable)) {
                    targetTable = initialTable;
                    await onTableChange();
                }
            }
        } catch (e) {
            errorMessage = e.message;
        } finally {
            loadingDbs = false;
        }
    });

    // -------------------------------------------------------------------------
    // CSV header parsing — reruns whenever file or parsing options change
    // -------------------------------------------------------------------------

    $: (file, hasHeader, delimiter, enclosure, commentChar),
        scheduleHeaderParse();

    function scheduleHeaderParse() {
        parseCsvHeaders()
            .then((headers) => {
                csvHeaders = headers;
                if (tableColumns.length > 0) initMapping();
            })
            .catch(() => {
                csvHeaders = [];
            });
    }

    async function parseCsvHeaders() {
        if (!file) return [];
        const chunk = await readFileChunk(file, 65536);
        const lines = chunk.split(/\r\n|\r|\n/);
        const cc = commentChar.length > 0 ? commentChar[0] : null;
        const firstLine = lines.find(
            (l) => l.trim() !== "" && (cc === null || !l.startsWith(cc)),
        );
        if (!firstLine) return [];
        const delim = delimiter.length > 0 ? delimiter[0] : ",";
        const encl = enclosure.length > 0 ? enclosure[0] : '"';
        const cols = parseCsvLine(firstLine, delim, encl);
        return hasHeader ? cols : cols.map((_, i) => String(i));
    }

    function readFileChunk(f, bytes) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = reject;
            reader.readAsText(f.slice(0, bytes));
        });
    }

    function parseCsvLine(line, delim, encl) {
        const result = [];
        let inQuote = false;
        let current = "";
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (inQuote) {
                if (ch === encl && line[i + 1] === encl) {
                    current += encl;
                    i++;
                } else if (ch === encl) {
                    inQuote = false;
                } else {
                    current += ch;
                }
            } else {
                if (ch === encl) {
                    inQuote = true;
                } else if (ch === delim) {
                    result.push(current);
                    current = "";
                } else {
                    current += ch;
                }
            }
        }
        result.push(current);
        return result;
    }

    // -------------------------------------------------------------------------
    // Target selection
    // -------------------------------------------------------------------------

    async function onDbChange() {
        targetTable = "";
        tables = [];
        tableColumns = [];
        columnMapping = {};
        await loadTables();
    }

    async function loadTables() {
        if (!targetDb) return;
        loadingTables = true;
        try {
            const r = await api.listTables(targetDb);
            tables = (r.tables || []).map((t) => t.name);
        } catch (e) {
            errorMessage = e.message;
        } finally {
            loadingTables = false;
        }
    }

    async function onTableChange() {
        tableColumns = [];
        columnMapping = {};
        if (!targetDb || !targetTable) return;
        loadingColumns = true;
        try {
            const r = await api.browseTable(targetDb, targetTable, 1, 0);
            tableColumns = r.columns || [];
            initMapping();
        } catch (e) {
            errorMessage = e.message;
        } finally {
            loadingColumns = false;
        }
    }

    function initMapping() {
        const mapping = {};
        for (const col of tableColumns) {
            if (hasHeader && csvHeaders.length > 0) {
                const match = csvHeaders.find(
                    (h) => h.toLowerCase() === col.name.toLowerCase(),
                );
                mapping[col.name] = match ?? "";
            } else {
                mapping[col.name] = "";
            }
        }
        columnMapping = mapping;
    }

    // -------------------------------------------------------------------------
    // File input
    // -------------------------------------------------------------------------

    function onFileChange(e) {
        const f = e.target.files[0];
        file = f || null;
        importResult = null;
        errorMessage = "";
    }

    // -------------------------------------------------------------------------
    // Derived state
    // -------------------------------------------------------------------------

    $: showMapping =
        file !== null &&
        targetDb !== "" &&
        targetTable !== "" &&
        tableColumns.length > 0;
    $: mappedCount = Object.values(columnMapping).filter(
        (v) => v !== "",
    ).length;
    $: canImport =
        file && targetDb && targetTable && mappedCount > 0 && !importing;

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    async function doImport() {
        if (!canImport) return;
        errorMessage = "";
        importResult = null;
        importing = true;
        try {
            const formData = new FormData();
            formData.append("file", file);
            formData.append("database", targetDb);
            formData.append("table", targetTable);
            formData.append("hasHeader", hasHeader ? "1" : "0");
            formData.append("delimiter", delimiter || ",");
            formData.append("enclosure", enclosure);
            formData.append("lineEnding", lineEnding);
            formData.append("commentChar", commentChar);
            formData.append("duplicateHandling", duplicateHandling);
            formData.append("columnMapping", JSON.stringify(columnMapping));
            importResult = await api.importData(formData);
            for (const { row, error } of importResult.errors) {
                sqlErrors.update((list) => [
                    { message: `Row ${row}: ${error}`, time: new Date() },
                    ...list,
                ]);
            }
        } catch (e) {
            errorMessage = e.message;
        } finally {
            importing = false;
        }
    }
</script>

<div class="flex flex-col gap-0 h-full">
    <!-- Settings section -->
    <div
        class="px-5 py-4 flex flex-col gap-4 border-b border-(--line) shrink-0"
    >
        <!-- File picker -->
        <div class="flex flex-col gap-2">
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >File</span
            >
            <label
                class="flex items-center gap-3 px-3 py-2 bg-(--bg-2) border border-(--line) rounded-(--radius) cursor-pointer hover:border-(--line-strong) transition-colors"
            >
                <input
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    class="hidden"
                    on:change={onFileChange}
                />
                <UploadIcon class="w-4 h-4 text-(--ink-3) shrink-0" />
                <span
                    class="text-[13px] mono truncate {file
                        ? 'text-(--ink-0)'
                        : 'text-(--ink-3)'}"
                >
                    {file ? file.name : "Choose a CSV file…"}
                </span>
                {#if file}
                    <span class="text-[11px] text-(--ink-3) shrink-0 ml-auto">
                        {(file.size / 1024).toFixed(1)} KB
                    </span>
                {/if}
            </label>
        </div>

        <!-- CSV options grid -->
        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <!-- Field separator -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Field separator</label
                >
                <input
                    type="text"
                    maxlength="1"
                    bind:value={delimiter}
                    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2 text-(--ink-0) mono text-[13px] focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)]"
                    placeholder=","
                />
            </div>

            <!-- Field enclosure -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Field enclosure</label
                >
                <input
                    type="text"
                    maxlength="1"
                    bind:value={enclosure}
                    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2 text-(--ink-0) mono text-[13px] focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)]"
                    placeholder=""
                />
            </div>

            <!-- Line ending -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Row ending</label
                >
                <Select bind:value={lineEnding}>
                    {#each LINE_ENDINGS as le}
                        <option value={le.id}>{le.label}</option>
                    {/each}
                </Select>
            </div>

            <!-- Comment char -->
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Comment prefix</label
                >
                <input
                    type="text"
                    maxlength="1"
                    bind:value={commentChar}
                    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2 text-(--ink-0) mono text-[13px] focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)]"
                    placeholder="#"
                />
            </div>
        </div>

        <!-- Has header row toggle -->
        <label class="flex items-center gap-2.5 cursor-pointer select-none">
            <input
                type="checkbox"
                bind:checked={hasHeader}
                class="w-4 h-4 accent-(--acc)"
            />
            <span class="text-[13px] text-(--ink-1)"
                >First row is a header row</span
            >
        </label>

        <!-- Duplicate handling -->
        <div class="flex flex-col gap-1.5">
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >Duplicate rows</span
            >
            <Select bind:value={duplicateHandling}>
                {#each DUPLICATE_OPTIONS as opt}
                    <option value={opt.id}>{opt.label}</option>
                {/each}
            </Select>
        </div>
    </div>

    <!-- Target + column mapping (scrollable) -->
    <div class="flex-1 overflow-y-auto min-h-0 flex flex-col max-h-[50vh]">
        <!-- Target header -->
        <div
            class="px-5 py-2.5 flex items-center border-b border-(--line) shrink-0"
        >
            <span
                class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                >Target</span
            >
        </div>

        <!-- Database + table selects -->
        <div
            class="px-5 py-3 flex flex-col gap-3 shrink-0 border-b border-(--line)"
        >
            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Database</label
                >
                {#if loadingDbs}
                    <div class="text-[13px] text-(--ink-3)">Loading…</div>
                {:else}
                    <Select bind:value={targetDb} on:change={onDbChange}>
                        <option value="">— Select database —</option>
                        {#each databases as db}
                            <option value={db}>{db}</option>
                        {/each}
                    </Select>
                {/if}
            </div>

            <div class="flex flex-col gap-1.5">
                <label
                    class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                    >Table</label
                >
                <Select
                    bind:value={targetTable}
                    on:change={onTableChange}
                    disabled={!targetDb || loadingTables}
                >
                    <option value="">
                        {loadingTables ? "Loading…" : "— Select table —"}
                    </option>
                    {#each tables as t}
                        <option value={t}>{t}</option>
                    {/each}
                </Select>
            </div>
        </div>

        <!-- Column mapping -->
        {#if showMapping || loadingColumns}
            <div class="shrink-0">
                <!-- Mapping header -->
                <div
                    class="px-5 py-2.5 flex items-center justify-between border-b border-(--line)"
                >
                    <span
                        class="text-[11px] font-semibold tracking-[0.04em] uppercase text-(--ink-2)"
                        >Column mapping</span
                    >
                    {#if mappedCount > 0}
                        <span
                            class="mono text-[9.5px] bg-(--acc) text-[#0a0c0a] px-1.5 py-0.5 rounded-full font-bold"
                            >{mappedCount}</span
                        >
                    {/if}
                </div>

                {#if loadingColumns}
                    <div class="px-5 py-3 text-(--ink-3) text-[13px]">
                        Loading columns…
                    </div>
                {:else if csvHeaders.length === 0 && file}
                    <div class="px-5 py-3 text-(--warn) text-[12px]">
                        Could not detect CSV columns from the file. Check your
                        delimiter and enclosure settings.
                    </div>
                {:else}
                    <!-- Column rows -->
                    <div class="divide-y divide-(--line)">
                        {#each tableColumns as col}
                            <div class="flex items-center gap-3 px-5 py-2">
                                <div class="flex-1 min-w-0">
                                    <span
                                        class="text-[13px] mono text-(--ink-0) truncate block"
                                        >{col.name}</span
                                    >
                                    <span class="text-[11px] text-(--ink-3)"
                                        >{col.type}{col.nullable
                                            ? ""
                                            : " · required"}</span
                                    >
                                </div>
                                <div class="w-44 shrink-0">
                                    <Select
                                        bind:value={columnMapping[col.name]}
                                    >
                                        <option value="">— skip —</option>
                                        {#each csvHeaders as h}
                                            <option value={h}>{h}</option>
                                        {/each}
                                    </Select>
                                </div>
                            </div>
                        {/each}
                    </div>
                {/if}
            </div>
        {:else if targetDb && targetTable && !loadingColumns}
            <div class="px-5 py-3 text-(--ink-3) text-[13px]">
                No columns found in this table.
            </div>
        {:else if !targetDb || !targetTable}
            <div class="px-5 py-3 text-(--ink-3) text-[13px]">
                Select a database and table to configure column mapping.
            </div>
        {/if}
    </div>

    <!-- Footer -->
    <div
        class="px-5 py-3.5 border-t border-(--line) flex items-center justify-between gap-4 shrink-0"
    >
        <div class="flex-1 min-w-0">
            {#if importResult}
                <p
                    class="text-[12px] m-0 {importResult.errors.length > 0
                        ? 'text-(--warn)'
                        : 'text-(--success, var(--acc))'}"
                >
                    {importResult.imported} row{importResult.imported === 1
                        ? ""
                        : "s"} imported.{importResult.errors.length > 0
                        ? ` ${importResult.errors.length} failed.`
                        : ""}
                </p>
            {:else if errorMessage}
                <p class="text-(--danger) text-[12px] m-0 truncate">
                    {errorMessage}
                </p>
            {:else if !file}
                <p class="text-(--ink-3) text-[12px] m-0">
                    Select a CSV file to import.
                </p>
            {:else if !targetDb || !targetTable}
                <p class="text-(--ink-3) text-[12px] m-0">
                    Select a target database and table.
                </p>
            {:else if mappedCount === 0}
                <p class="text-(--ink-3) text-[12px] m-0">
                    Map at least one column.
                </p>
            {/if}
        </div>
        <Btn
            variant="primary"
            disabled={!canImport}
            on:click={doImport}
            class="shrink-0 text-[12px] py-1.5 px-4"
        >
            {#if importing}
                Importing…
            {:else}
                Import CSV
                <UploadIcon />
            {/if}
        </Btn>
    </div>
</div>
