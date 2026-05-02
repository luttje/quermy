<script>
    import { createEventDispatcher, tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import {
        formatCell,
        isPrimary,
        isAutoIncrement,
        isInteractiveTarget,
    } from "../lib/dataTableUtils.js";
    import Input from "./ui/Input.svelte";

    export let columns = [];
    export let rows = [];
    export let total = null;
    export let durationMs = null;
    export let db = null;
    export let table = null;

    const dispatch = createEventDispatcher();

    $: isEditable = !!(db && table);
    $: pkCols = columns.filter((c) => c.key === "primary").map((c) => c.name);
    $: canEditRows = isEditable && pkCols.length > 0;

    let pendingEdits = {};
    let addingRow = false;
    let newRowValues = {};
    let dataBusy = false;
    let selectedRow = null;
    let newRowEl = null;

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
        if (isInteractiveTarget(e)) return;
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
</script>

<div
    class="flex flex-col flex-1 min-h-0 bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden"
>
    {#if columns.length === 0}
        <div class="py-16 px-6 text-center muted">
            <div class="mono text-[36px] text-(--ink-3) mb-2">∅</div>
            <div>No columns to display.</div>
        </div>
    {:else}
        <!-- Meta / toolbar -->
        <div
            class="flex items-center gap-6 py-2 px-3 pl-4 border-b border-(--line) bg-(--bg-2) text-[12px] shrink-0 min-h-9.5"
        >
            <span class="flex gap-2 items-baseline">
                <span
                    class="muted text-[10px] tracking-[0.06em] uppercase font-semibold"
                >
                    Rows
                </span>
                <span class="text-(--ink-0) text-[12px] mono">
                    {rows.length}{total !== null
                        ? ` / ${total.toLocaleString()}`
                        : ""}
                </span>
            </span>
            <span class="flex gap-2 items-baseline">
                <span
                    class="muted text-[10px] tracking-[0.06em] uppercase font-semibold"
                >
                    Cols
                </span>
                <span class="text-(--ink-0) text-[12px] mono">
                    {columns.length}
                </span>
            </span>
            {#if durationMs !== null}
                <span class="flex gap-2 items-baseline">
                    <span
                        class="muted text-[10px] tracking-[0.06em] uppercase font-semibold"
                    >
                        Time
                    </span>
                    <span class="text-(--ink-0) text-[12px] mono">
                        {durationMs.toFixed(2)} ms
                    </span>
                </span>
            {/if}

            {#if isEditable}
                <div class="ml-auto flex items-center gap-1.5">
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
                            >
                                {dataBusy ? "Inserting…" : "Insert Row"}
                            </button>
                        {:else}
                            {#if selectedRow !== null}
                                {#if pendingEdits[selectedRow]}
                                    <button
                                        class="tb-btn tb-discard"
                                        on:click={() =>
                                            cancelEditRow(selectedRow)}
                                        disabled={dataBusy}
                                    >
                                        Cancel Edit
                                    </button>
                                {:else}
                                    <button
                                        class="tb-btn tb-edit"
                                        on:click={() =>
                                            startEditRow(selectedRow)}
                                        disabled={dataBusy}
                                    >
                                        Edit
                                    </button>
                                    <button
                                        class="tb-btn tb-delete"
                                        on:click={deleteSelectedRow}
                                        disabled={dataBusy}
                                    >
                                        Delete
                                    </button>
                                {/if}
                            {/if}
                            <button
                                class="tb-btn tb-add"
                                on:click={startAddRow}
                                disabled={dataBusy}>+ Add Row</button
                            >
                        {/if}
                    {:else}
                        <span class="mono text-(--ink-3) text-[10.5px]">
                            no PK — editing disabled
                        </span>
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
                            class="rownum sticky top-0 z-2 text-right mono text-[11px] select-none w-[1%]"
                        ></th>
                        {#each columns as c}
                            <th
                                class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) last:border-r-0 whitespace-nowrap align-top text-(--ink-0) font-semibold text-[13px]"
                                class:pk={isPrimary(c)}
                            >
                                <div class="flex items-center gap-1.5">
                                    {c.name}
                                    {#if isPrimary(c)}
                                        <span
                                            class="mono text-[9px] bg-(--acc) text-[#0a0c0a] px-1 py-px rounded-[3px] font-bold tracking-[0.04em]"
                                            title="Primary key"
                                        >
                                            PK
                                        </span>
                                    {/if}
                                </div>
                                {#if c.type}
                                    <div
                                        class="mono text-(--ink-3) text-[10.5px] mt-0.5 font-normal"
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
                            class="transition-[background] duration-80 ease-out cursor-pointer hover:bg-(--bg-2)"
                            class:row-editing={editing}
                            class:row-selected={!editing && selectedRow === i}
                            on:click={(e) => handleRowClick(i, e)}
                            on:dblclick={(e) => {
                                if (
                                    canEditRows &&
                                    !editing &&
                                    !isInteractiveTarget(e)
                                )
                                    startEditRow(i);
                            }}
                        >
                            <td
                                class="rownum mono text-right text-[11px] select-none w-[1%]"
                            >
                                {i + 1}
                            </td>
                            {#each columns as c}
                                {#if editing}
                                    <td
                                        class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line) last:border-r-0"
                                        class:cell-dirty={isDirtyCell(
                                            i,
                                            c.name,
                                        )}
                                    >
                                        <Input
                                            class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
                                            value={pendingEdits[i][c.name]}
                                            on:input={(e) =>
                                                handleCellInput(i, c.name, e)}
                                            placeholder="NULL"
                                        />
                                    </td>
                                {:else}
                                    {@const v = formatCell(row[c.name])}
                                    <td
                                        class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) last:border-r-0 text-(--ink-1) max-w-90 overflow-hidden text-ellipsis whitespace-nowrap"
                                    >
                                        {#if v === null}
                                            <span
                                                class="text-(--ink-3) text-[10px] tracking-[0.06em] font-semibold border border-dashed border-(--line-strong) px-1.5 py-px rounded-[3px]"
                                            >
                                                NULL
                                            </span>
                                        {:else if v.length > 200}
                                            <span title={v}>
                                                {v.slice(0, 200)}…
                                            </span>
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
                                    class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line) last:border-r-0"
                                >
                                    <Input
                                        class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
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
                <div class="py-7 px-7 text-center text-(--ink-3) text-[13px]">
                    <span class="mono">// no rows</span>
                </div>
            {/if}
        </div>
    {/if}
</div>
