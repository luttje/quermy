<script>
    import { createEventDispatcher } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import {
        parseColumnType,
        buildType,
        supportsLength,
        isAutoIncrement,
        isInteractiveTarget,
    } from "../lib/dataTableUtils.js";
    import Input from "./ui/Input.svelte";
    import Checkbox from "./ui/Checkbox.svelte";
    import SearchableSelect from "./ui/SearchableSelect.svelte";

    export let columns = [];
    export let durationMs = null;
    export let db = null;
    export let table = null;
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    $: isEditable = !!(db && table);

    let editingColIdx = null;
    let editColForm = {
        name: "",
        type: "",
        typeLength: "",
        nullable: true,
        default: "",
        autoIncrement: false,
    };
    let addingCol = false;
    $: _defaultParsed = parseColumnType(capabilities?.defaultColumnType ?? "");
    $: defaultColBase = _defaultParsed.base;
    $: defaultColLength = _defaultParsed.length;
    let newColForm = {
        name: "",
        type: "",
        typeLength: "",
        nullable: true,
        default: "",
        autoIncrement: false,
    };

    $: editTypeSupportsLength = supportsLength(editColForm.type, capabilities);
    $: newTypeSupportsLength = supportsLength(newColForm.type, capabilities);

    let structBusy = false;
    let selectedCol = null;

    let dragSrcIdx = null;
    let dragOverIdx = null;
    let dragAbove = false;

    function handleColDragStart(i, e) {
        dragSrcIdx = i;
        e.dataTransfer.effectAllowed = "move";
        e.dataTransfer.setData("text/plain", String(i));
    }

    function handleColDragOver(i, e) {
        if (dragSrcIdx === null || dragSrcIdx === i) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = "move";
        const rect = e.currentTarget.getBoundingClientRect();
        dragAbove = e.clientY - rect.top < rect.height / 2;
        dragOverIdx = i;
    }

    function handleColDragLeave(i) {
        if (dragOverIdx === i) {
            dragOverIdx = null;
            dragAbove = false;
        }
    }

    async function handleColDrop(i, e) {
        e.preventDefault();
        const src = dragSrcIdx;
        dragSrcIdx = null;
        dragOverIdx = null;
        dragAbove = false;

        if (src === null || src === i) return;

        let afterColumn;
        const rect = e.currentTarget.getBoundingClientRect();
        const isAboveHalf = e.clientY - rect.top < rect.height / 2;

        if (isAboveHalf) {
            afterColumn = i > 0 ? columns[i - 1].name : null;
        } else {
            afterColumn = columns[i].name;
        }

        const srcName = columns[src].name;
        if (afterColumn === srcName) return;
        const srcPrevName = src > 0 ? columns[src - 1].name : null;
        if (afterColumn === srcPrevName) return;

        structBusy = true;
        try {
            await api.reorderColumn(db, table, srcName, afterColumn);
            dispatch("refresh");
        } catch (err) {
            toast(err.message, "error");
        } finally {
            structBusy = false;
        }
    }

    function handleColDragEnd() {
        dragSrcIdx = null;
        dragOverIdx = null;
        dragAbove = false;
    }

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
        const { base, length } = parseColumnType(col.type ?? "");
        editColForm = {
            name: col.name,
            type: base,
            typeLength: length,
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
        if (isInteractiveTarget(e)) return;
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
                type: buildType(
                    editColForm.type,
                    editTypeSupportsLength ? editColForm.typeLength : "",
                ),
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
                type: buildType(
                    newColForm.type,
                    newTypeSupportsLength ? newColForm.typeLength : "",
                ),
                nullable: newColForm.nullable,
                autoIncrement: newColForm.autoIncrement,
                default: newColForm.default !== "" ? newColForm.default : null,
            });
            toast(`Column "${newColForm.name}" added`, "success");
            addingCol = false;
            newColForm = {
                name: "",
                type: defaultColBase,
                typeLength: defaultColLength,
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
</script>

<div
    class="flex flex-col flex-1 min-h-0 bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden"
>
    <!-- Meta / toolbar -->
    <div
        class="flex items-center gap-6 py-2 px-3 pl-4 border-b border-(--line) bg-(--bg-2) text-[12px] shrink-0 min-h-9.5"
    >
        <span class="flex gap-2 items-baseline">
            <span
                class="muted text-[10px] tracking-[0.06em] uppercase font-semibold"
            >
                Columns
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
                {#if editingColIdx !== null}
                    <button
                        class="tb-btn tb-discard"
                        on:click={cancelEditCol}
                        disabled={structBusy}
                    >
                        Cancel
                    </button>
                    <button
                        class="tb-btn tb-save"
                        on:click={saveEditCol}
                        disabled={structBusy}
                    >
                        {structBusy ? "Saving…" : "Save Column"}
                    </button>
                {:else if addingCol}
                    <button
                        class="tb-btn tb-discard"
                        on:click={() => {
                            addingCol = false;
                        }}
                        disabled={structBusy}
                    >
                        Cancel
                    </button>
                    <button
                        class="tb-btn tb-save"
                        on:click={saveNewCol}
                        disabled={structBusy}
                    >
                        {structBusy ? "Adding…" : "Add Column"}
                    </button>
                {:else}
                    {#if selectedCol !== null}
                        <button
                            class="tb-btn tb-edit"
                            on:click={() => startEditCol(selectedCol)}
                            disabled={structBusy ||
                                !capabilities?.supportsModifyColumn}
                        >
                            Edit
                        </button>
                        <button
                            class="tb-btn tb-delete"
                            on:click={deleteSelectedCol}
                            disabled={structBusy ||
                                !capabilities?.supportsDropColumn}
                        >
                            Delete
                        </button>
                    {/if}
                    <button
                        class="tb-btn tb-add"
                        on:click={() => {
                            addingCol = true;
                            newColForm = {
                                name: "",
                                type: defaultColBase,
                                typeLength: defaultColLength,
                                nullable: true,
                                default: "",
                                autoIncrement: false,
                            };
                        }}
                        disabled={structBusy}
                    >
                        + Add Column
                    </button>
                {/if}
            </div>
        {/if}
    </div>

    <div class="flex-1 overflow-auto min-h-0">
        <table class="border-separate border-spacing-0 w-full text-[12.5px]">
            <thead>
                <tr>
                    <th
                        class="rownum sticky top-0 z-2 text-right mono text-[11px] select-none w-[1%]"
                    ></th>
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Name
                    </th>
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Type
                    </th>
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Size
                    </th>
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Nullable
                    </th>
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Key
                    </th>
                    {#if capabilities?.supportsAutoIncrement}
                        <th
                            class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) border-r border-r-(--line) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                        >
                            A_I
                        </th>
                    {/if}
                    <th
                        class="sticky top-0 z-1 bg-(--bg-2) text-left px-3.5 py-2.5 border-b border-b-(--line-strong) whitespace-nowrap text-(--ink-0) font-semibold text-[13px]"
                    >
                        Default
                    </th>
                </tr>
            </thead>
            <tbody>
                {#each columns as col, i}
                    <tr
                        class="transition-[background] duration-80 ease-out cursor-pointer hover:bg-(--bg-2)"
                        class:row-editing={editingColIdx === i}
                        class:row-selected={editingColIdx !== i &&
                            selectedCol === i}
                        class:drop-above={dragOverIdx === i &&
                            dragAbove &&
                            dragSrcIdx !== i}
                        class:drop-below={dragOverIdx === i &&
                            !dragAbove &&
                            dragSrcIdx !== i}
                        draggable={isEditable &&
                            !!capabilities?.supportsReorderColumn &&
                            editingColIdx === null &&
                            !structBusy}
                        on:click={(e) => handleColClick(i, e)}
                        on:dblclick={(e) => {
                            if (
                                isEditable &&
                                editingColIdx !== i &&
                                !isInteractiveTarget(e)
                            )
                                startEditCol(i);
                        }}
                        on:dragstart={(e) => handleColDragStart(i, e)}
                        on:dragover={(e) => handleColDragOver(i, e)}
                        on:dragleave={() => handleColDragLeave(i)}
                        on:drop={(e) => handleColDrop(i, e)}
                        on:dragend={handleColDragEnd}
                    >
                        <td
                            class="rownum mono text-right text-[11px] select-none w-[1%]"
                        >
                            {#if isEditable && capabilities?.supportsReorderColumn && editingColIdx === null}
                                <span
                                    class="drag-handle color-(--ink-3) cursor-grab text-[14px] leading-1 inline-block px-1"
                                    title="Drag to reorder">⠿</span
                                >
                            {:else}
                                {i + 1}
                            {/if}
                        </td>
                        {#if editingColIdx === i}
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <Input
                                    class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
                                    bind:value={editColForm.name}
                                    placeholder="column_name"
                                />
                            </td>
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <SearchableSelect
                                    class="w-full min-w-18"
                                    triggerClass="py-0.75! px-1.75! text-[12px]! rounded-[3px]!"
                                    bind:value={editColForm.type}
                                    options={capabilities?.columnTypes ?? []}
                                    allowCustom={true}
                                    placeholder="VARCHAR"
                                />
                            </td>
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <Input
                                    class="py-0.75! px-1.75! text-[12px]! min-w-14 rounded-[3px]! box-border disabled:opacity-40 disabled:cursor-not-allowed"
                                    bind:value={editColForm.typeLength}
                                    placeholder="e.g. 255"
                                    disabled={!editTypeSupportsLength}
                                />
                            </td>
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <label
                                    class="inline-flex items-center gap-1.5 cursor-pointer text-[11.5px] text-(--ink-1) whitespace-nowrap"
                                >
                                    <Checkbox
                                        bind:checked={editColForm.nullable}
                                    />
                                    <span class="mono">
                                        {editColForm.nullable ? "YES" : "NO"}
                                    </span>
                                </label>
                            </td>
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--acc) text-[11px] font-semibold tracking-[0.04em]"
                            >
                                {col.key || "—"}
                            </td>
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <label
                                    class="inline-flex items-center gap-1.5 cursor-pointer text-[11.5px] text-(--ink-1) whitespace-nowrap"
                                >
                                    <Checkbox
                                        bind:checked={editColForm.autoIncrement}
                                    />
                                    <span class="mono">
                                        {editColForm.autoIncrement
                                            ? "YES"
                                            : "NO"}
                                    </span>
                                </label>
                            </td>
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line)"
                            >
                                <Input
                                    class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
                                    bind:value={editColForm.default}
                                    placeholder="NULL"
                                />
                            </td>
                        {:else}
                            {@const _p = parseColumnType(col.type ?? "")}
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) font-semibold text-(--ink-0)"
                            >
                                {col.name}
                            </td>
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--ink-1)"
                            >
                                {_p.base || col.type}
                            </td>
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--ink-1)"
                            >
                                {#if _p.length}
                                    {_p.length}
                                {:else}
                                    <span class="text-(--ink-3)">—</span>
                                {/if}
                            </td>
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--ink-1)"
                            >
                                {col.nullable !== false ? "YES" : "NO"}
                            </td>
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--acc) text-[11px] font-semibold tracking-[0.04em]"
                            >
                                {col.key || "—"}
                            </td>
                            {#if capabilities?.supportsAutoIncrement}
                                <td
                                    class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--ok)"
                                >
                                    {isAutoIncrement(col) ? "✓" : "—"}
                                </td>
                            {/if}
                            <td
                                class="mono py-1.75 px-3.5 border-b border-b-(--line) text-(--ink-1)"
                            >
                                {#if col.default !== null && col.default !== undefined}
                                    {col.default}
                                {:else}
                                    <span
                                        class="text-(--ink-3) text-[10px] tracking-[0.06em] font-semibold border border-dashed border-(--line-strong) px-1.5 py-px rounded-[3px]"
                                    >
                                        NULL
                                    </span>
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
                            class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            ><Input
                                class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
                                bind:value={newColForm.name}
                                placeholder="column_name"
                            /></td
                        >
                        <td
                            class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            ><SearchableSelect
                                class="w-full min-w-18"
                                triggerClass="py-0.75! px-1.75! text-[12px]! rounded-[3px]!"
                                bind:value={newColForm.type}
                                options={capabilities?.columnTypes ?? []}
                                allowCustom={true}
                                placeholder="VARCHAR"
                            /></td
                        >
                        <td
                            class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            ><Input
                                class="py-0.75! px-1.75! text-[12px]! min-w-14 rounded-[3px]! box-border disabled:opacity-40 disabled:cursor-not-allowed"
                                bind:value={newColForm.typeLength}
                                placeholder="e.g. 255"
                                disabled={!newTypeSupportsLength}
                            /></td
                        >
                        <td
                            class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                        >
                            <label
                                class="inline-flex items-center gap-1.5 cursor-pointer text-[11.5px] text-(--ink-1) whitespace-nowrap"
                            >
                                <Checkbox bind:checked={newColForm.nullable} />
                                <span class="mono">
                                    {newColForm.nullable ? "YES" : "NO"}
                                </span>
                            </label>
                        </td>
                        <td
                            class="mono py-1.75 px-3.5 border-b border-b-(--line) border-r border-r-(--line) text-(--ink-1)"
                            >—</td
                        >
                        {#if capabilities?.supportsAutoIncrement}
                            <td
                                class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line) border-r border-r-(--line)"
                            >
                                <label
                                    class="inline-flex items-center gap-1.5 cursor-pointer text-[11.5px] text-(--ink-1) whitespace-nowrap"
                                >
                                    <Checkbox
                                        bind:checked={newColForm.autoIncrement}
                                    />
                                    <span class="mono">
                                        {newColForm.autoIncrement
                                            ? "YES"
                                            : "NO"}
                                    </span>
                                </label>
                            </td>
                        {/if}
                        <td
                            class="edit-cell py-1! px-1.5! align-middle border-b border-b-(--line)"
                            ><Input
                                class="py-0.75! px-1.75! text-[12px]! min-w-18 rounded-[3px]! box-border"
                                bind:value={newColForm.default}
                                placeholder="NULL"
                            /></td
                        >
                    </tr>
                {/if}
            </tbody>
        </table>

        {#if columns.length === 0 && !addingCol}
            <div class="py-7 px-7 text-center text-(--ink-3) text-[13px]">
                <span class="mono">// no columns</span>
            </div>
        {/if}
    </div>
</div>

<style>
    /* Drop indicators for column drag-to-reorder */
    tr.drop-above td {
        box-shadow: inset 0 2px 0 0 var(--acc);
    }
    tr.drop-below td {
        box-shadow: inset 0 -2px 0 0 var(--acc);
    }
    tr[draggable="true"] {
        cursor: grab;
    }
    tr[draggable="true"]:active {
        cursor: grabbing;
    }
    tr[draggable="true"]:hover .drag-handle {
        color: var(--ink-1);
    }
</style>
