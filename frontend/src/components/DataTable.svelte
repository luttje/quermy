<script>
    /**
     * Generic data table.
     *
     * Modes:
     *   "data"      — Browse rows. When db+table are provided, enables row
     *                 editing (inline) and row insertion.
     *   "structure" — Browse column definitions. When db+table are provided,
     *                 enables column editing and adding new columns.
     *
     * Row editing requires at least one PRI key column.
     * Dispatches "refresh" when the caller should reload data.
     */
    import { createEventDispatcher, tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let columns = []; // full column defs: {name,type,nullable,key,default}
    export let rows = [];
    export let total = null; // optional grand total
    export let durationMs = null;
    export let db = null; // set to enable editing
    export let table = null; // set to enable editing
    export let mode = "data"; // "data" | "structure"

    const dispatch = createEventDispatcher();

    // Derived
    $: isEditable = !!(db && table);
    $: pkCols =
        mode === "data"
            ? columns.filter((c) => c.key === "PRI").map((c) => c.name)
            : [];
    $: canEditRows = isEditable && mode === "data" && pkCols.length > 0;

    // Data-mode edit state
    // pendingEdits: { [rowIndex]: { [colName]: editedValue } }
    let pendingEdits = {};
    let addingRow = false;
    let newRowValues = {};
    let dataBusy = false;
    let selectedRow = null; // index of currently selected row (null = none)
    let newRowEl = null; // bound to the add-row <tr> for scroll-into-view

    // Reset when data changes (after refresh)
    let _prevRows = null;
    $: if (rows !== _prevRows) {
        _prevRows = rows;
        pendingEdits = {};
        addingRow = false;
        newRowValues = {};
        selectedRow = null;
    }

    $: pendingCount = Object.keys(pendingEdits).length;

    function startEditRow(rowIdx) {
        const row = rows[rowIdx];
        if (!row) return;
        const vals = {};
        for (const c of columns) {
            const v = row[c.name];
            vals[c.name] = v === null || v === undefined ? "" : String(v);
        }
        pendingEdits = { ...pendingEdits, [rowIdx]: vals };
    }

    function cancelEditRow(rowIdx) {
        const { [rowIdx]: _dropped, ...rest } = pendingEdits;
        pendingEdits = rest;
    }

    function handleRowClick(i, e) {
        if (e.target.tagName === "INPUT") return;
        // Keep selected when clicking into an editing row
        selectedRow = selectedRow === i ? (pendingEdits[i] ? i : null) : i;
    }

    async function startAddRow() {
        addingRow = true;
        newRowValues = {};
        await tick();
        newRowEl?.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }

    async function deleteSelectedRow() {
        if (dataBusy || selectedRow === null) return;
        const row = rows[selectedRow];
        if (!row) return;
        dataBusy = true;
        const where = {};
        for (const pk of pkCols) {
            const v = row[pk];
            where[pk] = v !== null && v !== undefined ? String(v) : null;
        }
        try {
            await api.deleteRow(db, table, where);
            toast("Row deleted", "success");
            const { [selectedRow]: _d, ...rest } = pendingEdits;
            pendingEdits = rest;
            selectedRow = null;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dataBusy = false;
        }
    }

    function handleCellInput(rowIdx, colName, e) {
        pendingEdits = {
            ...pendingEdits,
            [rowIdx]: { ...pendingEdits[rowIdx], [colName]: e.target.value },
        };
    }

    function isDirtyCell(rowIdx, colName) {
        if (!pendingEdits[rowIdx]) return false;
        const curr = pendingEdits[rowIdx][colName];
        const orig = rows[rowIdx]?.[colName];
        const origStr = orig === null || orig === undefined ? "" : String(orig);
        return curr !== origStr;
    }

    function discardAll() {
        pendingEdits = {};
        addingRow = false;
        newRowValues = {};
    }

    async function saveAll() {
        if (dataBusy || pendingCount === 0) return;
        dataBusy = true;
        let saved = 0;

        for (const [idxStr, edits] of Object.entries(pendingEdits)) {
            const rowIdx = Number(idxStr);
            const row = rows[rowIdx];
            if (!row) continue;

            const where = {};
            for (const pk of pkCols) {
                const v = row[pk];
                where[pk] = v !== null && v !== undefined ? String(v) : null;
            }

            const values = {};
            let anyChange = false;
            for (const [col, newVal] of Object.entries(edits)) {
                const orig = row[col];
                const origStr =
                    orig === null || orig === undefined ? "" : String(orig);
                if (newVal !== origStr) {
                    values[col] = newVal === "" ? null : newVal;
                    anyChange = true;
                }
            }
            if (!anyChange) continue;

            try {
                await api.updateRow(db, table, where, values);
                saved++;
            } catch (e) {
                toast(`Row ${rowIdx + 1}: ${e.message}`, "error");
            }
        }

        dataBusy = false;
        pendingEdits = {};
        if (saved > 0) {
            toast(`${saved} row${saved !== 1 ? "s" : ""} saved`, "success");
            dispatch("refresh");
        }
    }

    async function saveNewRow() {
        if (dataBusy) return;
        dataBusy = true;
        try {
            const values = {};
            for (const col of columns) {
                const v = newRowValues[col.name];
                if (v !== undefined && v !== "") values[col.name] = v;
            }
            await api.insertRow(db, table, values);
            toast("Row inserted", "success");
            addingRow = false;
            newRowValues = {};
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dataBusy = false;
        }
    }

    // Structure-mode edit state
    let editingColIdx = null;
    let editColForm = {
        name: "",
        type: "",
        nullable: true,
        default: "",
        autoIncrement: false,
    };
    let addingCol = false;
    let newColForm = {
        name: "",
        type: "VARCHAR(255)",
        nullable: true,
        default: "",
        autoIncrement: false,
    };

    const MYSQL_TYPES = [
        // Numeric
        "INT",
        "TINYINT",
        "SMALLINT",
        "MEDIUMINT",
        "BIGINT",
        "INT UNSIGNED",
        "TINYINT UNSIGNED",
        "SMALLINT UNSIGNED",
        "MEDIUMINT UNSIGNED",
        "BIGINT UNSIGNED",
        "DECIMAL(10,2)",
        "FLOAT",
        "DOUBLE",
        "BIT(1)",
        // String
        "CHAR(1)",
        "VARCHAR(255)",
        "TINYTEXT",
        "TEXT",
        "MEDIUMTEXT",
        "LONGTEXT",
        "BINARY(1)",
        "VARBINARY(255)",
        "TINYBLOB",
        "BLOB",
        "MEDIUMBLOB",
        "LONGBLOB",
        "ENUM('a','b')",
        "SET('a','b')",
        // Date/Time
        "DATE",
        "TIME",
        "DATETIME",
        "TIMESTAMP",
        "YEAR",
        // Other
        "JSON",
    ];
    let structBusy = false;
    let selectedCol = null; // index of selected column row

    let _prevCols = null;
    $: if (columns !== _prevCols) {
        _prevCols = columns;
        editingColIdx = null;
        addingCol = false;
        selectedCol = null;
    }

    function startEditCol(idx) {
        const col = columns[idx];
        editingColIdx = idx;
        editColForm = {
            name: col.name,
            type: col.type,
            nullable: col.nullable !== false,
            autoIncrement: isAutoIncrement(col),
            default:
                col.default !== null && col.default !== undefined
                    ? String(col.default)
                    : "",
        };
    }

    function cancelEditCol() {
        editingColIdx = null;
    }

    function handleColClick(i, e) {
        if (e.target.tagName === "INPUT") return;
        selectedCol = selectedCol === i ? (editingColIdx === i ? i : null) : i;
    }

    async function deleteSelectedCol() {
        if (structBusy || selectedCol === null) return;
        const colName = columns[selectedCol].name;
        structBusy = true;
        try {
            await api.deleteColumn(db, table, colName);
            toast(`Column "${colName}" deleted`, "success");
            selectedCol = null;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            structBusy = false;
        }
    }

    async function saveEditCol() {
        if (structBusy || editingColIdx === null) return;
        structBusy = true;
        const origName = columns[editingColIdx].name;
        try {
            await api.modifyColumn(db, table, origName, {
                name: editColForm.name.trim(),
                type: editColForm.type.trim(),
                nullable: editColForm.nullable,
                autoIncrement: editColForm.autoIncrement,
                default:
                    editColForm.default !== "" ? editColForm.default : null,
            });
            toast(`Column "${editColForm.name}" updated`, "success");
            editingColIdx = null;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            structBusy = false;
        }
    }

    async function saveNewCol() {
        if (structBusy || !newColForm.name.trim() || !newColForm.type.trim())
            return;
        structBusy = true;
        try {
            await api.addColumn(db, table, {
                name: newColForm.name.trim(),
                type: newColForm.type.trim(),
                nullable: newColForm.nullable,
                autoIncrement: newColForm.autoIncrement,
                default: newColForm.default !== "" ? newColForm.default : null,
            });
            toast(`Column "${newColForm.name}" added`, "success");
            addingCol = false;
            newColForm = {
                name: "",
                type: "VARCHAR(255)",
                nullable: true,
                default: "",
                autoIncrement: false,
            };
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            structBusy = false;
        }
    }

    /*
     * Generic helpers
     */

    function formatCell(v) {
        if (v === null || v === undefined) return null;
        if (typeof v === "object") return JSON.stringify(v);
        return String(v);
    }

    function isAutoIncrement(c) {
        return !!(c?.extra && c.extra.toLowerCase().includes("auto_increment"));
    }

    function isPrimary(c) {
        return c && c.key === "PRI";
    }
</script>

<!-- ═══════════════════════════════════════════════════════════ DATA mode -->
{#if mode === "data"}
    <div
        class="flex flex-col flex-1 min-h-0 bg-[var(--bg-1)] border border-[var(--line)] rounded-[var(--radius-lg)] overflow-hidden"
    >
        {#if columns.length === 0}
            <div class="py-16 px-6 text-center text-[var(--ink-2)]">
                <div class="mono text-[36px] text-[var(--ink-3)] mb-2">∅</div>
                <div>No columns to display.</div>
            </div>
        {:else}
            <!-- Meta / toolbar -->
            <div
                class="flex items-center gap-6 py-2 px-3 pl-4 border-b border-[var(--line)] bg-[var(--bg-2)] text-[12px] shrink-0 min-h-[38px]"
            >
                <span class="flex gap-2 items-baseline">
                    <span
                        class="text-[var(--ink-2)] text-[10px] tracking-[0.06em] uppercase font-semibold"
                        >Rows</span
                    >
                    <span class="text-[var(--ink-0)] text-[12px] mono">
                        {rows.length}{total !== null
                            ? ` / ${total.toLocaleString()}`
                            : ""}
                    </span>
                </span>
                <span class="flex gap-2 items-baseline">
                    <span
                        class="text-[var(--ink-2)] text-[10px] tracking-[0.06em] uppercase font-semibold"
                        >Cols</span
                    >
                    <span class="text-[var(--ink-0)] text-[12px] mono"
                        >{columns.length}</span
                    >
                </span>
                {#if durationMs !== null}
                    <span class="flex gap-2 items-baseline">
                        <span
                            class="text-[var(--ink-2)] text-[10px] tracking-[0.06em] uppercase font-semibold"
                            >Time</span
                        >
                        <span class="text-[var(--ink-0)] text-[12px] mono"
                            >{durationMs.toFixed(2)} ms</span
                        >
                    </span>
                {/if}

                {#if isEditable}
                    <div class="ml-auto flex items-center gap-[6px]">
                        {#if pendingCount > 0}
                            <button
                                class="tb-btn tb-discard"
                                on:click={discardAll}
                                disabled={dataBusy}>Discard</button
                            >
                            <button
                                class="tb-btn tb-save"
                                on:click={saveAll}
                                disabled={dataBusy}
                            >
                                {dataBusy
                                    ? "Saving…"
                                    : `Save ${pendingCount} change${pendingCount !== 1 ? "s" : ""}`}
                            </button>
                        {/if}
                        {#if canEditRows}
                            {#if addingRow}
                                <button
                                    class="tb-btn tb-discard"
                                    on:click={() => {
                                        addingRow = false;
                                        newRowValues = {};
                                    }}
                                    disabled={dataBusy}>Cancel</button
                                >
                                <button
                                    class="tb-btn tb-save"
                                    on:click={saveNewRow}
                                    disabled={dataBusy}
                                    >{dataBusy
                                        ? "Inserting…"
                                        : "Insert Row"}</button
                                >
                            {:else}
                                {#if selectedRow !== null}
                                    {#if pendingEdits[selectedRow]}
                                        <button
                                            class="tb-btn tb-discard"
                                            on:click={() =>
                                                cancelEditRow(selectedRow)}
                                            disabled={dataBusy}
                                            >Cancel Edit</button
                                        >
                                    {:else}
                                        <button
                                            class="tb-btn tb-edit"
                                            on:click={() =>
                                                startEditRow(selectedRow)}
                                            disabled={dataBusy}>Edit</button
                                        >
                                        <button
                                            class="tb-btn tb-delete"
                                            on:click={deleteSelectedRow}
                                            disabled={dataBusy}>Delete</button
                                        >
                                    {/if}
                                {/if}
                                <button
                                    class="tb-btn tb-add"
                                    on:click={startAddRow}
                                    disabled={dataBusy}>+ Add Row</button
                                >
                            {/if}
                        {:else}
                            <span class="mono text-[var(--ink-3)] text-[10.5px]"
                                >no PK — editing disabled</span
                            >
                        {/if}
                    </div>
                {/if}
            </div>

            <div class="flex-1 overflow-auto min-h-0">
                <table
                    class="border-separate border-spacing-0 w-full text-[12.5px]"
                >
                    <thead>
                        <tr>
                            <th
                                class="rownum sticky top-0 z-[2] text-right mono text-[11px] select-none w-[1%]"
                            ></th>
                            {#each columns as c}
                                <th
                                    class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] last:border-r-0 whitespace-nowrap align-top text-[var(--ink-0)] font-semibold text-[13px]"
                                    class:pk={isPrimary(c)}
                                >
                                    <div class="flex items-center gap-[6px]">
                                        {c.name}
                                        {#if isPrimary(c)}
                                            <span
                                                class="mono text-[9px] bg-[var(--acc)] text-[#0a0c0a] px-1 py-[1px] rounded-[3px] font-bold tracking-[0.04em]"
                                                title="Primary key">PK</span
                                            >
                                        {/if}
                                    </div>
                                    {#if c.type}
                                        <div
                                            class="mono text-[var(--ink-3)] text-[10.5px] mt-[2px] font-normal"
                                        >
                                            {c.type}{c.nullable === false
                                                ? " · NOT NULL"
                                                : ""}
                                        </div>
                                    {/if}
                                </th>
                            {/each}
                        </tr>
                    </thead>
                    <tbody>
                        {#each rows as row, i}
                            {@const editing = !!pendingEdits[i]}
                            <tr
                                class="transition-[background] duration-[80ms] ease-out cursor-pointer hover:bg-[var(--bg-2)]"
                                class:row-editing={editing}
                                class:row-selected={!editing &&
                                    selectedRow === i}
                                on:click={(e) => handleRowClick(i, e)}
                                on:dblclick={(e) => {
                                    if (
                                        canEditRows &&
                                        !editing &&
                                        e.target.tagName !== "INPUT"
                                    )
                                        startEditRow(i);
                                }}
                            >
                                <td
                                    class="rownum mono text-right text-[11px] select-none w-[1%]"
                                    >{i + 1}</td
                                >
                                {#each columns as c}
                                    {#if editing}
                                        <td
                                            class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)] last:border-r-0"
                                            class:cell-dirty={isDirtyCell(
                                                i,
                                                c.name,
                                            )}
                                        >
                                            <input
                                                class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                                value={pendingEdits[i][c.name]}
                                                on:input={(e) =>
                                                    handleCellInput(
                                                        i,
                                                        c.name,
                                                        e,
                                                    )}
                                                placeholder="NULL"
                                            />
                                        </td>
                                    {:else}
                                        {@const v = formatCell(row[c.name])}
                                        <td
                                            class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] last:border-r-0 text-[var(--ink-1)] max-w-[360px] overflow-hidden text-ellipsis whitespace-nowrap"
                                        >
                                            {#if v === null}
                                                <span class="null-tag"
                                                    >NULL</span
                                                >
                                            {:else if v.length > 200}
                                                <span title={v}
                                                    >{v.slice(0, 200)}…</span
                                                >
                                            {:else}
                                                {v}
                                            {/if}
                                        </td>
                                    {/if}
                                {/each}
                            </tr>
                        {/each}

                        {#if addingRow}
                            <tr class="row-new" bind:this={newRowEl}>
                                <td
                                    class="rownum mono text-right text-[11px] select-none w-[1%]"
                                    >*</td
                                >
                                {#each columns as c}
                                    <td
                                        class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)] last:border-r-0"
                                    >
                                        <input
                                            class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                            bind:value={newRowValues[c.name]}
                                            placeholder={isAutoIncrement(c)
                                                ? "auto"
                                                : c.nullable !== false
                                                  ? "NULL"
                                                  : "(required)"}
                                        />
                                    </td>
                                {/each}
                            </tr>
                        {/if}
                    </tbody>
                </table>

                {#if rows.length === 0 && !addingRow}
                    <div
                        class="py-7 px-7 text-center text-[var(--ink-3)] text-[13px]"
                    >
                        <span class="mono">// no rows</span>
                    </div>
                {/if}
            </div>
        {/if}
    </div>

    <!-- ══════════════════════════════════════════════ STRUCTURE mode -->
{:else}
    <div
        class="flex flex-col flex-1 min-h-0 bg-[var(--bg-1)] border border-[var(--line)] rounded-[var(--radius-lg)] overflow-hidden"
    >
        <!-- Meta / toolbar -->
        <div
            class="flex items-center gap-6 py-2 px-3 pl-4 border-b border-[var(--line)] bg-[var(--bg-2)] text-[12px] shrink-0 min-h-[38px]"
        >
            <span class="flex gap-2 items-baseline">
                <span
                    class="text-[var(--ink-2)] text-[10px] tracking-[0.06em] uppercase font-semibold"
                    >Columns</span
                >
                <span class="text-[var(--ink-0)] text-[12px] mono"
                    >{columns.length}</span
                >
            </span>
            {#if durationMs !== null}
                <span class="flex gap-2 items-baseline">
                    <span
                        class="text-[var(--ink-2)] text-[10px] tracking-[0.06em] uppercase font-semibold"
                        >Time</span
                    >
                    <span class="text-[var(--ink-0)] text-[12px] mono"
                        >{durationMs.toFixed(2)} ms</span
                    >
                </span>
            {/if}
            {#if isEditable}
                <div class="ml-auto flex items-center gap-[6px]">
                    {#if editingColIdx !== null}
                        <button
                            class="tb-btn tb-discard"
                            on:click={cancelEditCol}
                            disabled={structBusy}>Cancel</button
                        >
                        <button
                            class="tb-btn tb-save"
                            on:click={saveEditCol}
                            disabled={structBusy}
                            >{structBusy ? "Saving…" : "Save Column"}</button
                        >
                    {:else if addingCol}
                        <button
                            class="tb-btn tb-discard"
                            on:click={() => {
                                addingCol = false;
                            }}
                            disabled={structBusy}>Cancel</button
                        >
                        <button
                            class="tb-btn tb-save"
                            on:click={saveNewCol}
                            disabled={structBusy}
                            >{structBusy ? "Adding…" : "Add Column"}</button
                        >
                    {:else}
                        {#if selectedCol !== null}
                            <button
                                class="tb-btn tb-edit"
                                on:click={() => startEditCol(selectedCol)}
                                disabled={structBusy}>Edit</button
                            >
                            <button
                                class="tb-btn tb-delete"
                                on:click={deleteSelectedCol}
                                disabled={structBusy}>Delete</button
                            >
                        {/if}
                        <button
                            class="tb-btn tb-add"
                            on:click={() => {
                                addingCol = true;
                                newColForm = {
                                    name: "",
                                    type: "VARCHAR(255)",
                                    nullable: true,
                                    default: "",
                                    autoIncrement: false,
                                };
                            }}
                            disabled={structBusy}>+ Add Column</button
                        >
                    {/if}
                </div>
            {/if}
        </div>

        <div class="flex-1 overflow-auto min-h-0">
            <table
                class="border-separate border-spacing-0 w-full text-[12.5px]"
            >
                <thead>
                    <tr>
                        <th
                            class="rownum sticky top-0 z-[2] text-right mono text-[11px] select-none w-[1%]"
                        ></th>
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >Name</th
                        >
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >Type</th
                        >
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >Nullable</th
                        >
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >Key</th
                        >
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] border-r border-r-[var(--line)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >AI</th
                        >
                        <th
                            class="sticky top-0 z-[1] bg-[var(--bg-2)] text-left px-[14px] py-[10px] border-b border-b-[var(--line-strong)] whitespace-nowrap text-[var(--ink-0)] font-semibold text-[13px]"
                            >Default</th
                        >
                    </tr>
                </thead>
                <tbody>
                    {#each columns as col, i}
                        <tr
                            class="transition-[background] duration-[80ms] ease-out cursor-pointer hover:bg-[var(--bg-2)]"
                            class:row-editing={editingColIdx === i}
                            class:row-selected={editingColIdx !== i &&
                                selectedCol === i}
                            on:click={(e) => handleColClick(i, e)}
                            on:dblclick={(e) => {
                                if (
                                    isEditable &&
                                    editingColIdx !== i &&
                                    e.target.tagName !== "INPUT"
                                )
                                    startEditCol(i);
                            }}
                        >
                            <td
                                class="rownum mono text-right text-[11px] select-none w-[1%]"
                                >{i + 1}</td
                            >
                            {#if editingColIdx === i}
                                <td
                                    class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                >
                                    <input
                                        class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                        bind:value={editColForm.name}
                                        placeholder="column_name"
                                    />
                                </td>
                                <td
                                    class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                >
                                    <input
                                        class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                        list="mysql-types"
                                        bind:value={editColForm.type}
                                        placeholder="VARCHAR(255)"
                                    />
                                </td>
                                <td
                                    class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                >
                                    <label
                                        class="inline-flex items-center gap-[6px] cursor-pointer text-[11.5px] text-[var(--ink-1)] whitespace-nowrap"
                                    >
                                        <input
                                            type="checkbox"
                                            class="accent-[var(--acc)]"
                                            bind:checked={editColForm.nullable}
                                        />
                                        <span class="mono"
                                            >{editColForm.nullable
                                                ? "YES"
                                                : "NO"}</span
                                        >
                                    </label>
                                </td>
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--acc)] text-[11px] font-semibold tracking-[0.04em]"
                                    >{col.key || "—"}</td
                                >
                                <td
                                    class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                >
                                    <label
                                        class="inline-flex items-center gap-[6px] cursor-pointer text-[11.5px] text-[var(--ink-1)] whitespace-nowrap"
                                    >
                                        <input
                                            type="checkbox"
                                            class="accent-[var(--acc)]"
                                            bind:checked={
                                                editColForm.autoIncrement
                                            }
                                        />
                                        <span class="mono"
                                            >{editColForm.autoIncrement
                                                ? "YES"
                                                : "NO"}</span
                                        >
                                    </label>
                                </td>
                                <td
                                    class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)]"
                                >
                                    <input
                                        class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                        bind:value={editColForm.default}
                                        placeholder="NULL"
                                    />
                                </td>
                            {:else}
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] font-semibold text-[var(--ink-0)]"
                                    >{col.name}</td
                                >
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--ink-1)]"
                                    >{col.type}</td
                                >
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--ink-1)]"
                                    >{col.nullable !== false ? "YES" : "NO"}</td
                                >
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--acc)] text-[11px] font-semibold tracking-[0.04em]"
                                    >{col.key || "—"}</td
                                >
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--ok)]"
                                    >{isAutoIncrement(col) ? "✓" : "—"}</td
                                >
                                <td
                                    class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] text-[var(--ink-1)]"
                                >
                                    {#if col.default !== null && col.default !== undefined}
                                        {col.default}
                                    {:else}
                                        <span class="null-tag">NULL</span>
                                    {/if}
                                </td>
                            {/if}
                        </tr>
                    {/each}

                    {#if addingCol}
                        <tr class="row-new">
                            <td
                                class="rownum mono text-right text-[11px] select-none w-[1%]"
                                >*</td
                            >
                            <td
                                class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                ><input
                                    class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                    bind:value={newColForm.name}
                                    placeholder="column_name"
                                /></td
                            >
                            <td
                                class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                                ><input
                                    class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                    list="mysql-types"
                                    bind:value={newColForm.type}
                                    placeholder="VARCHAR(255)"
                                /></td
                            >
                            <td
                                class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                            >
                                <label
                                    class="inline-flex items-center gap-[6px] cursor-pointer text-[11.5px] text-[var(--ink-1)] whitespace-nowrap"
                                >
                                    <input
                                        type="checkbox"
                                        class="accent-[var(--acc)]"
                                        bind:checked={newColForm.nullable}
                                    />
                                    <span class="mono"
                                        >{newColForm.nullable
                                            ? "YES"
                                            : "NO"}</span
                                    >
                                </label>
                            </td>
                            <td
                                class="mono py-[7px] px-[14px] border-b border-b-[var(--line)] border-r border-r-[var(--line)] text-[var(--ink-1)]"
                                >—</td
                            >
                            <td
                                class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)] border-r border-r-[var(--line)]"
                            >
                                <label
                                    class="inline-flex items-center gap-[6px] cursor-pointer text-[11.5px] text-[var(--ink-1)] whitespace-nowrap"
                                >
                                    <input
                                        type="checkbox"
                                        class="accent-[var(--acc)]"
                                        bind:checked={newColForm.autoIncrement}
                                    />
                                    <span class="mono"
                                        >{newColForm.autoIncrement
                                            ? "YES"
                                            : "NO"}</span
                                    >
                                </label>
                            </td>
                            <td
                                class="edit-cell !py-1 !px-[6px] align-middle border-b border-b-[var(--line)]"
                                ><input
                                    class="w-full min-w-[72px] bg-[var(--bg-1)] border border-[var(--line)] rounded-[3px] text-[var(--ink-0)] mono text-[12px] py-[3px] px-[7px] outline-none focus:border-[var(--acc)] placeholder:text-[var(--ink-3)] box-border"
                                    bind:value={newColForm.default}
                                    placeholder="NULL"
                                /></td
                            >
                        </tr>
                    {/if}
                </tbody>
            </table>

            {#if columns.length === 0 && !addingCol}
                <div
                    class="py-7 px-7 text-center text-[var(--ink-3)] text-[13px]"
                >
                    <span class="mono">// no columns</span>
                </div>
            {/if}
        </div>
    </div>
{/if}

<datalist id="mysql-types">
    {#each MYSQL_TYPES as t}
        <option value={t}></option>
    {/each}
</datalist>

<style>
    /* State classes toggled via Svelte class: directive */
    .row-editing {
        background: rgba(200, 255, 90, 0.04) !important;
    }
    .row-selected {
        background: rgba(80, 160, 255, 0.08) !important;
        outline: 1px solid rgba(80, 160, 255, 0.25);
        outline-offset: -1px;
    }
    .row-new {
        background: rgba(90, 200, 255, 0.04) !important;
    }
    .cell-dirty {
        background: rgba(255, 200, 50, 0.09) !important;
    }

    /* Sticky row-number column */
    .rownum {
        background: var(--bg-2);
        color: var(--ink-3);
        border-right: 1px solid var(--line-strong) !important;
        position: sticky;
        left: 0;
    }
    thead .rownum {
        z-index: 2;
    }
    tbody tr:hover .rownum {
        background: var(--bg-2);
    }

    /* PK column gradient header */
    thead th.pk {
        background: linear-gradient(
            180deg,
            rgba(200, 255, 90, 0.06),
            var(--bg-2)
        );
    }

    /* NULL tag */
    .null-tag {
        color: var(--ink-3);
        font-size: 10px;
        letter-spacing: 0.06em;
        font-weight: 600;
        border: 1px dashed var(--line-strong);
        padding: 1px 6px;
        border-radius: 3px;
    }

    /* Toolbar buttons */
    .tb-btn {
        font-family: var(--font-mono);
        font-size: 11px;
        padding: 3px 10px;
        border-radius: 4px;
        border: 1px solid var(--line);
        cursor: pointer;
        background: transparent;
        transition:
            background 60ms,
            color 60ms,
            border-color 60ms;
        white-space: nowrap;
    }
    .tb-btn:disabled {
        opacity: 0.4;
        cursor: default;
    }
    .tb-add {
        color: var(--acc);
        border-color: rgba(200, 255, 90, 0.35);
    }
    .tb-add:hover:not(:disabled) {
        background: rgba(200, 255, 90, 0.1);
        border-color: var(--acc);
    }
    .tb-edit {
        color: var(--ink-1);
    }
    .tb-edit:hover:not(:disabled) {
        background: var(--bg-1);
        color: var(--ink-0);
        border-color: var(--line-strong);
    }
    .tb-delete {
        color: #ff6b6b;
        border-color: rgba(255, 100, 100, 0.35);
    }
    .tb-delete:hover:not(:disabled) {
        background: rgba(255, 100, 100, 0.1);
        border-color: #ff6b6b;
    }
    .tb-save {
        background: var(--acc);
        color: #0a0c0a;
        border-color: var(--acc);
        font-weight: 600;
    }
    .tb-save:hover:not(:disabled) {
        filter: brightness(1.1);
    }
    .tb-discard {
        color: var(--ink-2);
    }
    .tb-discard:hover:not(:disabled) {
        background: var(--bg-2);
        color: var(--ink-1);
    }
</style>
