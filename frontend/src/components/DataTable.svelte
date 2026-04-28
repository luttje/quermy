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
    <div class="datatable-wrap">
        {#if columns.length === 0}
            <div class="empty-state">
                <div class="empty-mark">∅</div>
                <div>No columns to display.</div>
            </div>
        {:else}
            <!-- Meta / toolbar -->
            <div class="meta-bar">
                <span class="meta-item">
                    <span class="meta-label">Rows</span>
                    <span class="meta-value mono">
                        {rows.length}{total !== null
                            ? ` / ${total.toLocaleString()}`
                            : ""}
                    </span>
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

                {#if isEditable}
                    <div class="toolbar-right">
                        {#if pendingCount > 0}
                            <button
                                class="tb-btn tb-discard"
                                on:click={discardAll}
                                disabled={dataBusy}
                            >
                                Discard
                            </button>
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
                            <span class="no-pk-hint mono"
                                >no PK — editing disabled</span
                            >
                        {/if}
                    </div>
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
                                        {#if isPrimary(c)}
                                            <span
                                                class="pk-badge"
                                                title="Primary key">PK</span
                                            >
                                        {/if}
                                    </div>
                                    {#if c.type}
                                        <div class="th-type mono">
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
                                class:row-editing={editing}
                                class:row-selected={!editing &&
                                    selectedRow === i}
                                on:click={(e) => handleRowClick(i, e)}
                            >
                                <td class="rownum mono">{i + 1}</td>
                                {#each columns as c}
                                    {#if editing}
                                        <td
                                            class="edit-cell"
                                            class:cell-dirty={isDirtyCell(
                                                i,
                                                c.name,
                                            )}
                                        >
                                            <input
                                                class="cell-input mono"
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
                                            class="mono"
                                            class:null-cell={v === null}
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
                                <td class="rownum mono">*</td>
                                {#each columns as c}
                                    <td class="edit-cell">
                                        <input
                                            class="cell-input mono"
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
                    <div class="empty-rows">
                        <span class="mono">// no rows</span>
                    </div>
                {/if}
            </div>
        {/if}
    </div>

    <!-- ══════════════════════════════════════════════ STRUCTURE mode -->
{:else}
    <div class="datatable-wrap">
        <div class="meta-bar">
            <span class="meta-item">
                <span class="meta-label">Columns</span>
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
            {#if isEditable}
                <div class="toolbar-right">
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

        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th class="rownum"></th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Nullable</th>
                        <th>Key</th>
                        <th>AI</th>
                        <th>Default</th>
                    </tr>
                </thead>
                <tbody>
                    {#each columns as col, i}
                        <tr
                            class:row-editing={editingColIdx === i}
                            class:row-selected={editingColIdx !== i &&
                                selectedCol === i}
                            on:click={(e) => handleColClick(i, e)}
                            style="cursor: pointer"
                        >
                            <td class="rownum mono">{i + 1}</td>
                            {#if editingColIdx === i}
                                <td class="edit-cell">
                                    <input
                                        class="cell-input mono"
                                        bind:value={editColForm.name}
                                        placeholder="column_name"
                                    />
                                </td>
                                <td class="edit-cell">
                                    <input
                                        class="cell-input mono"
                                        list="mysql-types"
                                        bind:value={editColForm.type}
                                        placeholder="VARCHAR(255)"
                                    />
                                </td>
                                <td class="edit-cell edit-cell-check">
                                    <label class="check-label">
                                        <input
                                            type="checkbox"
                                            bind:checked={editColForm.nullable}
                                        />
                                        <span class="mono"
                                            >{editColForm.nullable
                                                ? "YES"
                                                : "NO"}</span
                                        >
                                    </label>
                                </td>
                                <td class="mono col-key">{col.key || "—"}</td>
                                <td class="edit-cell edit-cell-check">
                                    <label class="check-label">
                                        <input
                                            type="checkbox"
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
                                <td class="edit-cell">
                                    <input
                                        class="cell-input mono"
                                        bind:value={editColForm.default}
                                        placeholder="NULL"
                                    />
                                </td>
                            {:else}
                                <td class="mono col-name">{col.name}</td>
                                <td class="mono">{col.type}</td>
                                <td class="mono"
                                    >{col.nullable !== false ? "YES" : "NO"}</td
                                >
                                <td class="mono col-key">{col.key || "—"}</td>
                                <td class="mono ai-cell"
                                    >{isAutoIncrement(col) ? "✓" : "—"}</td
                                >
                                <td class="mono">
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
                            <td class="rownum mono">*</td>
                            <td class="edit-cell"
                                ><input
                                    class="cell-input mono"
                                    bind:value={newColForm.name}
                                    placeholder="column_name"
                                /></td
                            >
                            <td class="edit-cell"
                                ><input
                                    class="cell-input mono"
                                    list="mysql-types"
                                    bind:value={newColForm.type}
                                    placeholder="VARCHAR(255)"
                                /></td
                            >
                            <td class="edit-cell edit-cell-check">
                                <label class="check-label">
                                    <input
                                        type="checkbox"
                                        bind:checked={newColForm.nullable}
                                    />
                                    <span class="mono"
                                        >{newColForm.nullable
                                            ? "YES"
                                            : "NO"}</span
                                    >
                                </label>
                            </td>
                            <td class="mono">—</td>
                            <td class="edit-cell edit-cell-check">
                                <label class="check-label">
                                    <input
                                        type="checkbox"
                                        bind:checked={newColForm.autoIncrement}
                                    />
                                    <span class="mono"
                                        >{newColForm.autoIncrement
                                            ? "YES"
                                            : "NO"}</span
                                    >
                                </label>
                            </td>
                            <td class="edit-cell"
                                ><input
                                    class="cell-input mono"
                                    bind:value={newColForm.default}
                                    placeholder="NULL"
                                /></td
                            >
                        </tr>
                    {/if}
                </tbody>
            </table>

            {#if columns.length === 0 && !addingCol}
                <div class="empty-rows">
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
        align-items: center;
        gap: 24px;
        padding: 8px 12px 8px 16px;
        border-bottom: 1px solid var(--line);
        background: var(--bg-2);
        font-size: 12px;
        flex-shrink: 0;
        min-height: 38px;
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

    .toolbar-right {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .tb-btn {
        font-family: var(--font-mono);
        font-size: 11px;
        padding: 3px 10px;
        border-radius: 4px;
        border: 1px solid var(--line);
        cursor: pointer;
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
        background: transparent;
        color: var(--acc);
        border-color: rgba(200, 255, 90, 0.35);
    }
    .tb-add:hover:not(:disabled) {
        background: rgba(200, 255, 90, 0.1);
        border-color: var(--acc);
    }
    .tb-edit {
        background: transparent;
        color: var(--ink-1);
        border-color: var(--line);
    }
    .tb-edit:hover:not(:disabled) {
        background: var(--bg-1);
        color: var(--ink-0);
        border-color: var(--line-strong);
    }
    .tb-delete {
        background: transparent;
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
        background: transparent;
        color: var(--ink-2);
        border-color: var(--line);
    }
    .tb-discard:hover:not(:disabled) {
        background: var(--bg-2);
        color: var(--ink-1);
    }

    .no-pk-hint {
        color: var(--ink-3);
        font-size: 10.5px;
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
        color: var(--ink-0);
        font-weight: 600;
        font-size: 13px;
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
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .th-type {
        color: var(--ink-3);
        font-size: 10.5px;
        margin-top: 2px;
        letter-spacing: 0.02em;
        font-weight: 400;
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
        cursor: pointer;
    }
    tbody tr:hover {
        background: var(--bg-2);
    }
    tbody tr:hover td.rownum {
        background: var(--bg-2);
    }

    tbody td {
        padding: 7px 14px;
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
        border: 1px dashed var(--line-strong);
        padding: 1px 6px;
        border-radius: 3px;
    }

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

    .edit-cell {
        padding: 4px 6px !important;
        vertical-align: middle;
    }
    .cell-dirty {
        background: rgba(255, 200, 50, 0.09) !important;
    }

    .cell-input {
        width: 100%;
        min-width: 72px;
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: 3px;
        color: var(--ink-0);
        font-size: 12px;
        padding: 3px 7px;
        outline: none;
        box-sizing: border-box;
    }
    .cell-input:focus {
        border-color: var(--acc, #c8ff5a);
    }
    .cell-input::placeholder {
        color: var(--ink-3);
    }

    .edit-cell-check {
        vertical-align: middle;
    }
    .check-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        font-size: 11.5px;
        color: var(--ink-1);
        white-space: nowrap;
    }
    .check-label input[type="checkbox"] {
        accent-color: var(--acc, #c8ff5a);
        cursor: pointer;
    }

    .actions-th {
        width: 32px;
        border-right: 0 !important;
    }
    .actions-cell {
        width: 32px;
        padding: 0 4px !important;
        text-align: center;
        vertical-align: middle;
        border-right: 0 !important;
    }
    .actions-cell-pair {
        width: 56px;
        display: flex;
        gap: 3px;
        align-items: center;
        justify-content: center;
        padding: 3px 4px !important;
    }

    .row-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 4px;
        border: 1px solid transparent;
        background: transparent;
        cursor: pointer;
        font-size: 13px;
        line-height: 1;
        color: var(--ink-3);
        opacity: 0;
        transition:
            opacity 60ms,
            background 60ms,
            color 60ms,
            border-color 60ms;
        flex-shrink: 0;
        padding: 0;
    }
    .row-btn:disabled {
        opacity: 0.3 !important;
        cursor: default;
    }
    tr:hover .row-btn,
    .row-editing .row-btn,
    .row-new .row-btn {
        opacity: 1;
    }

    .row-edit:hover {
        background: var(--bg-2);
        color: var(--ink-0);
        border-color: var(--line);
    }
    .row-ok {
        color: var(--acc);
        border-color: rgba(200, 255, 90, 0.3);
        opacity: 1;
    }
    .row-ok:hover:not(:disabled) {
        background: rgba(200, 255, 90, 0.1);
        border-color: var(--acc);
    }
    .row-x {
        color: var(--ink-3);
        opacity: 1;
    }
    .row-x:hover {
        color: #ff6b6b;
        border-color: rgba(255, 100, 100, 0.4);
        background: rgba(255, 100, 100, 0.06);
    }

    .col-name {
        font-weight: 600;
        color: var(--ink-0);
    }
    .col-key {
        color: var(--acc);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.04em;
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
