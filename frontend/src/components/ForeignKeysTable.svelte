<script>
    import { createEventDispatcher } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Btn from "./ui/Btn.svelte";
    import Input from "./ui/Input.svelte";
    import Select from "./ui/Select.svelte";
    import SearchableSelect from "./ui/SearchableSelect.svelte";
    import Modal from "./Modal.svelte";

    export let db;
    export let table;
    export let outgoing = [];
    export let incoming = [];
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    const FALLBACK_ACTIONS = ["RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT", "NO ACTION"];
    $: referentialActions = capabilities?.referentialActions?.length
        ? capabilities.referentialActions
        : FALLBACK_ACTIONS;

    // --- Meta options ---
    let tableOptions = [];
    let srcColumnOptions = [];
    let refColumnOptions = [];

    async function loadMeta() {
        try {
            const [tablesRes, colsRes] = await Promise.all([
                api.listTables(db),
                api.browseTable(db, table, 1, 0),
            ]);
            tableOptions = (tablesRes.tables ?? []).map((t) => t.name);
            srcColumnOptions = (colsRes.columns ?? []).map((c) => c.name);
        } catch (_) {}
    }

    async function loadRefColumnOptions(refTable) {
        if (!refTable) { refColumnOptions = []; return; }
        try {
            const r = await api.browseTable(db, refTable, 1, 0);
            refColumnOptions = (r.columns ?? []).map((c) => c.name);
        } catch (_) {
            refColumnOptions = [];
        }
    }

    // --- Create modal ---
    let showCreateModal = false;
    let creating = false;
    let createName = "";
    let createColumnsList = [""];
    let createRefTable = "";
    let createRefColumnsList = [""];
    let createOnUpdate = "RESTRICT";
    let createOnDelete = "RESTRICT";

    // --- Edit modal ---
    let showEditModal = false;
    let savingEdit = false;
    let editTarget = null;
    let editName = "";
    let editColumnsList = [""];
    let editRefTable = "";
    let editRefColumnsList = [""];
    let editOnUpdate = "RESTRICT";
    let editOnDelete = "RESTRICT";

    // --- Delete modal ---
    let showDeleteModal = false;
    let dropping = false;
    let deleteTarget = null;
    let deleteConfirm = "";

    $: canManage = capabilities?.supportsForeignKeyManagement ?? false;

    // Reload ref table columns when selection changes
    $: if (showCreateModal) loadRefColumnOptions(createRefTable);
    $: if (showEditModal) loadRefColumnOptions(editRefTable);

    function columnsPreview(columns, maxLen = 90) {
        const text = (columns ?? []).join(", ");
        if (!text) return "No columns";
        if (text.length <= maxLen) return text;
        return text.slice(0, maxLen - 1) + "…";
    }

    function normalizeAction(action) {
        const value = (action ?? "").toString().trim().toUpperCase();
        return referentialActions.includes(value) ? value : (referentialActions[0] ?? "RESTRICT");
    }

    function sameColumns(a, b) {
        if ((a?.length ?? 0) !== (b?.length ?? 0)) return false;
        return (a ?? []).every((col, i) => col === b[i]);
    }

    function openCreateModal() {
        if (!canManage) return;
        createName = "";
        createColumnsList = [""];
        createRefTable = "";
        createRefColumnsList = [""];
        createOnUpdate = referentialActions[0] ?? "RESTRICT";
        createOnDelete = referentialActions[0] ?? "RESTRICT";
        refColumnOptions = [];
        showCreateModal = true;
        loadMeta();
    }

    function closeCreateModal() {
        if (creating) return;
        showCreateModal = false;
    }

    async function createForeignKey() {
        if (!canManage) return;

        const cols = createColumnsList.filter(Boolean);
        const refCols = createRefColumnsList.filter(Boolean);

        if (!createName.trim()) {
            toast("Constraint name is required", "error");
            return;
        }
        if (!cols.length) {
            toast("Enter at least one source column", "error");
            return;
        }
        if (!createRefTable.trim()) {
            toast("Referenced table is required", "error");
            return;
        }
        if (!refCols.length) {
            toast("Enter at least one referenced column", "error");
            return;
        }
        if (cols.length !== refCols.length) {
            toast("Columns and referenced columns must have the same count", "error");
            return;
        }

        creating = true;
        try {
            await api.createForeignKey(db, table, {
                name: createName.trim(),
                columns: cols,
                referencedTable: createRefTable.trim(),
                referencedColumns: refCols,
                onUpdate: createOnUpdate,
                onDelete: createOnDelete,
            });
            toast("Foreign key created", "success");
            showCreateModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            creating = false;
        }
    }

    function openEditModal(fk) {
        if (!canManage) return;
        if (!fk?.constraintName) return;

        editTarget = fk;
        editName = fk.constraintName;
        editColumnsList = fk.columns?.length ? [...fk.columns] : [""];
        editRefTable = fk.referencedTable ?? "";
        editRefColumnsList = fk.referencedColumns?.length ? [...fk.referencedColumns] : [""];
        editOnUpdate = normalizeAction(fk.onUpdate);
        editOnDelete = normalizeAction(fk.onDelete);
        refColumnOptions = [];
        showEditModal = true;
        loadMeta();
    }

    function closeEditModal() {
        if (savingEdit) return;
        showEditModal = false;
    }

    async function saveEditedForeignKey() {
        if (!canManage || !editTarget?.constraintName) return;

        const cols = editColumnsList.filter(Boolean);
        const refCols = editRefColumnsList.filter(Boolean);

        if (!editName.trim()) {
            toast("Constraint name is required", "error");
            return;
        }
        if (!cols.length) {
            toast("Enter at least one source column", "error");
            return;
        }
        if (!editRefTable.trim()) {
            toast("Referenced table is required", "error");
            return;
        }
        if (!refCols.length) {
            toast("Enter at least one referenced column", "error");
            return;
        }
        if (cols.length !== refCols.length) {
            toast("Columns and referenced columns must have the same count", "error");
            return;
        }

        const sameDefinition = editName.trim() === editTarget.constraintName
            && sameColumns(cols, editTarget.columns ?? [])
            && editRefTable.trim() === (editTarget.referencedTable ?? "")
            && sameColumns(refCols, editTarget.referencedColumns ?? [])
            && editOnUpdate === normalizeAction(editTarget.onUpdate)
            && editOnDelete === normalizeAction(editTarget.onDelete);

        if (sameDefinition) {
            showEditModal = false;
            return;
        }

        savingEdit = true;
        try {
            await api.dropForeignKey(db, table, editTarget.constraintName);
            await api.createForeignKey(db, table, {
                name: editName.trim(),
                columns: cols,
                referencedTable: editRefTable.trim(),
                referencedColumns: refCols,
                onUpdate: editOnUpdate,
                onDelete: editOnDelete,
            });
            toast(`Constraint "${editName.trim()}" updated`, "success");
            showEditModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingEdit = false;
        }
    }

    function openDeleteModal(fk) {
        if (!canManage) return;
        if (!fk?.constraintName) return;
        deleteTarget = fk;
        deleteConfirm = "";
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        if (dropping) return;
        showDeleteModal = false;
    }

    async function confirmDelete() {
        if (!deleteTarget?.constraintName) return;
        if (deleteConfirm !== deleteTarget.constraintName) return;

        dropping = true;
        try {
            await api.dropForeignKey(db, table, deleteTarget.constraintName);
            toast(`Constraint "${deleteTarget.constraintName}" dropped`, "success");
            showDeleteModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dropping = false;
        }
    }

    function outgoingTargetLabel(fk) {
        const refTable = fk?.referencedTable ?? "";
        const refDb = fk?.referencedDatabase ?? "";
        if (refDb && refDb !== db) return `${refDb}.${refTable}`;
        return refTable;
    }

    function incomingSourceTableLabel(fk) {
        const sourceTable = fk?.referencingTable ?? fk?.referencedTable ?? "";
        const sourceDb = fk?.referencingDatabase ?? "";
        if (sourceDb && sourceDb !== db) return `${sourceDb}.${sourceTable}`;
        return sourceTable;
    }

    function incomingSourceColumns(fk) {
        if (Array.isArray(fk?.referencingColumns) && fk.referencingColumns.length > 0) {
            return fk.referencingColumns;
        }
        if (Array.isArray(fk?.referencedColumns) && fk.referencedColumns.length > 0) {
            return fk.referencedColumns;
        }
        return [];
    }

    function incomingTargetColumns(fk) {
        if (Array.isArray(fk?.columns) && fk.columns.length > 0) {
            return fk.columns;
        }
        return [];
    }
</script>

<div class="flex flex-col gap-2.5 min-h-0">
    <section class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden">
        <header
            class="flex items-center justify-between gap-2 px-3.5 py-2.5 border-b border-(--line) bg-(--bg-2)"
        >
            <div class="flex flex-col">
                <h2 class="mono text-(--ink-0) text-[12.5px]">
                    Foreign Keys · {table}
                </h2>
                <p class="mono text-(--ink-3) text-[10.5px]">
                    Manage outgoing foreign key constraints.
                </p>
            </div>
            <div class="flex items-center gap-1.5">
                {#if canManage}
                    <Btn
                        variant="primary"
                        class="text-[11px] px-2.5 py-1!"
                        on:click={openCreateModal}
                    >
                        Create FK
                    </Btn>
                {/if}
                <Btn
                    variant="ghost"
                    class="text-[11px] px-2.5 py-1!"
                    on:click={() => dispatch("refresh")}
                >
                    Refresh
                </Btn>
            </div>
        </header>

        <div class="p-2.5">
            {#if outgoing.length === 0}
                <div class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]">
                    No outgoing foreign keys on this table.
                </div>
            {:else}
                <ul class="divide-y divide-(--line)">
                    {#each outgoing as fk}
                        <li class="px-2.5 py-2.5">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="mono text-(--ink-0) text-[12.5px] font-semibold truncate">
                                        {fk.constraintName}
                                    </p>
                                    <p class="mono text-(--ink-3) text-[11px] truncate mt-0.5">
                                        {columnsPreview(fk.columns)} → {outgoingTargetLabel(fk)}({columnsPreview(
                                            fk.referencedColumns,
                                        )})
                                    </p>
                                    <p class="mono text-(--ink-3) text-[10px] mt-1">
                                        ON UPDATE: {fk.onUpdate || "—"} · ON DELETE: {fk.onDelete ||
                                            "—"}
                                    </p>
                                </div>
                                {#if canManage}
                                    <div class="shrink-0 flex items-center gap-1.5">
                                        <Btn
                                            variant="ghost"
                                            class="text-[11px] px-2.5 py-1!"
                                            on:click={() => openEditModal(fk)}
                                        >
                                            Edit
                                        </Btn>
                                        <Btn
                                            variant="danger"
                                            class="text-[11px] px-2.5 py-1!"
                                            on:click={() => openDeleteModal(fk)}
                                        >
                                            Delete
                                        </Btn>
                                    </div>
                                {/if}
                            </div>
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>
    </section>

    <section class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden">
        <header class="px-3.5 py-2.5 border-b border-(--line) bg-(--bg-2)">
            <div class="flex flex-col">
                <h2 class="mono text-(--ink-0) text-[12.5px]">
                    Incoming References · {table}
                </h2>
                <p class="mono text-(--ink-3) text-[10.5px]">
                    Other tables that reference this table.
                </p>
            </div>
        </header>

        <div class="p-2.5">
            {#if incoming.length === 0}
                <div class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]">
                    No incoming references found.
                </div>
            {:else}
                <ul class="divide-y divide-(--line)">
                    {#each incoming as fk}
                        <li class="px-2.5 py-2.5">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="mono text-(--ink-0) text-[12.5px] font-semibold truncate">
                                        {fk.constraintName}
                                    </p>
                                    <p class="mono text-(--ink-3) text-[11px] truncate mt-0.5">
                                        {incomingSourceTableLabel(fk)}({columnsPreview(
                                            incomingSourceColumns(fk),
                                        )}) → {table}({columnsPreview(incomingTargetColumns(fk))})
                                    </p>
                                    <p class="mono text-(--ink-3) text-[10px] mt-1">
                                        ON UPDATE: {fk.onUpdate || "—"} · ON DELETE: {fk.onDelete ||
                                            "—"}
                                    </p>
                                </div>
                            </div>
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>
    </section>
</div>

<Modal
    open={showCreateModal}
    title={`Create Foreign Key · ${table}`}
    maxWidth="max-w-2xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <label class="flex flex-col gap-1">
            <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                Constraint Name
            </span>
            <Input
                bind:value={createName}
                class="text-[12px] py-2!"
                placeholder="e.g. fk_orders_user_id"
            />
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
            <div class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    Source Columns
                </span>
                <div class="flex flex-col gap-1">
                    {#each createColumnsList as _, i}
                        <div class="flex items-center gap-1">
                            <div class="flex-1">
                                <SearchableSelect
                                    bind:value={createColumnsList[i]}
                                    options={srcColumnOptions}
                                    placeholder="Select column…"
                                    allowCustom={true}
                                    triggerClass="text-[12px] py-2! px-3!"
                                />
                            </div>
                            {#if createColumnsList.length > 1}
                                <Btn
                                    variant="ghost"
                                    class="text-[12px] px-2 py-1! shrink-0"
                                    on:click={() => { createColumnsList = createColumnsList.filter((_, idx) => idx !== i); }}
                                >
                                    ×
                                </Btn>
                            {/if}
                        </div>
                    {/each}
                    <Btn
                        variant="ghost"
                        class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                        on:click={() => { createColumnsList = [...createColumnsList, ""]; }}
                    >
                        + Add column
                    </Btn>
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    Referenced Table
                </span>
                <SearchableSelect
                    bind:value={createRefTable}
                    options={tableOptions}
                    placeholder="Select table…"
                    allowCustom={true}
                    triggerClass="text-[12px] py-2! px-3!"
                />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                Referenced Columns
            </span>
            <div class="flex flex-col gap-1">
                {#each createRefColumnsList as _, i}
                    <div class="flex items-center gap-1">
                        <div class="flex-1">
                            <SearchableSelect
                                bind:value={createRefColumnsList[i]}
                                options={refColumnOptions}
                                placeholder={createRefTable ? "Select column…" : "Select a table first…"}
                                allowCustom={true}
                                disabled={!createRefTable}
                                triggerClass="text-[12px] py-2! px-3!"
                            />
                        </div>
                        {#if createRefColumnsList.length > 1}
                            <Btn
                                variant="ghost"
                                class="text-[12px] px-2 py-1! shrink-0"
                                on:click={() => { createRefColumnsList = createRefColumnsList.filter((_, idx) => idx !== i); }}
                            >
                                ×
                            </Btn>
                        {/if}
                    </div>
                {/each}
                <Btn
                    variant="ghost"
                    class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                    disabled={!createRefTable}
                    on:click={() => { createRefColumnsList = [...createRefColumnsList, ""]; }}
                >
                    + Add column
                </Btn>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
            <label class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    ON UPDATE
                </span>
                <Select bind:value={createOnUpdate} class="text-[12px] py-2!">
                    {#each referentialActions as action}
                        <option value={action}>{action}</option>
                    {/each}
                </Select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    ON DELETE
                </span>
                <Select bind:value={createOnDelete} class="text-[12px] py-2!">
                    {#each referentialActions as action}
                        <option value={action}>{action}</option>
                    {/each}
                </Select>
            </label>
        </div>
    </div>

    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={creating} on:click={closeCreateModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={creating ||
                !createName.trim() ||
                !createColumnsList.some(Boolean) ||
                !createRefTable.trim() ||
                !createRefColumnsList.some(Boolean)}
            on:click={createForeignKey}
        >
            {creating ? "Creating…" : "Create FK"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showEditModal}
    title={`Edit Foreign Key · ${table}`}
    maxWidth="max-w-2xl"
    on:close={closeEditModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <div class="mono text-[11px] text-(--ink-2)">
            Editing <span class="text-(--ink-0)">{editTarget?.constraintName ?? ""}</span>
        </div>
        <p class="mono text-[10.5px] text-(--ink-3)">
            Saving will recreate this constraint (drop then create).
        </p>

        <label class="flex flex-col gap-1">
            <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                Constraint Name
            </span>
            <Input
                bind:value={editName}
                class="text-[12px] py-2!"
                placeholder="e.g. fk_orders_user_id"
            />
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
            <div class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    Source Columns
                </span>
                <div class="flex flex-col gap-1">
                    {#each editColumnsList as _, i}
                        <div class="flex items-center gap-1">
                            <div class="flex-1">
                                <SearchableSelect
                                    bind:value={editColumnsList[i]}
                                    options={srcColumnOptions}
                                    placeholder="Select column…"
                                    allowCustom={true}
                                    triggerClass="text-[12px] py-2! px-3!"
                                />
                            </div>
                            {#if editColumnsList.length > 1}
                                <Btn
                                    variant="ghost"
                                    class="text-[12px] px-2 py-1! shrink-0"
                                    on:click={() => { editColumnsList = editColumnsList.filter((_, idx) => idx !== i); }}
                                >
                                    ×
                                </Btn>
                            {/if}
                        </div>
                    {/each}
                    <Btn
                        variant="ghost"
                        class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                        on:click={() => { editColumnsList = [...editColumnsList, ""]; }}
                    >
                        + Add column
                    </Btn>
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    Referenced Table
                </span>
                <SearchableSelect
                    bind:value={editRefTable}
                    options={tableOptions}
                    placeholder="Select table…"
                    allowCustom={true}
                    triggerClass="text-[12px] py-2! px-3!"
                />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                Referenced Columns
            </span>
            <div class="flex flex-col gap-1">
                {#each editRefColumnsList as _, i}
                    <div class="flex items-center gap-1">
                        <div class="flex-1">
                            <SearchableSelect
                                bind:value={editRefColumnsList[i]}
                                options={refColumnOptions}
                                placeholder={editRefTable ? "Select column…" : "Select a table first…"}
                                allowCustom={true}
                                disabled={!editRefTable}
                                triggerClass="text-[12px] py-2! px-3!"
                            />
                        </div>
                        {#if editRefColumnsList.length > 1}
                            <Btn
                                variant="ghost"
                                class="text-[12px] px-2 py-1! shrink-0"
                                on:click={() => { editRefColumnsList = editRefColumnsList.filter((_, idx) => idx !== i); }}
                            >
                                ×
                            </Btn>
                        {/if}
                    </div>
                {/each}
                <Btn
                    variant="ghost"
                    class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                    disabled={!editRefTable}
                    on:click={() => { editRefColumnsList = [...editRefColumnsList, ""]; }}
                >
                    + Add column
                </Btn>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
            <label class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    ON UPDATE
                </span>
                <Select bind:value={editOnUpdate} class="text-[12px] py-2!">
                    {#each referentialActions as action}
                        <option value={action}>{action}</option>
                    {/each}
                </Select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)">
                    ON DELETE
                </span>
                <Select bind:value={editOnDelete} class="text-[12px] py-2!">
                    {#each referentialActions as action}
                        <option value={action}>{action}</option>
                    {/each}
                </Select>
            </label>
        </div>
    </div>

    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={savingEdit} on:click={closeEditModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={savingEdit ||
                !editName.trim() ||
                !editColumnsList.some(Boolean) ||
                !editRefTable.trim() ||
                !editRefColumnsList.some(Boolean)}
            on:click={saveEditedForeignKey}
        >
            {savingEdit ? "Saving…" : "Save FK"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showDeleteModal}
    title={`Delete Foreign Key · ${table}`}
    maxWidth="max-w-md"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-[12px] text-(--ink-2)">
            This action is permanent. Type
            <span class="mono text-(--ink-0)">{deleteTarget?.constraintName ?? ""}</span>
            to confirm.
        </p>
        <Input
            bind:value={deleteConfirm}
            class="text-[12px] py-2!"
            placeholder={deleteTarget?.constraintName
                ? `Type ${deleteTarget.constraintName}`
                : "Type constraint name"}
            on:keydown={(e) => {
                if (e.key === "Enter") confirmDelete();
            }}
        />
    </div>

    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={dropping} on:click={closeDeleteModal}>
            Cancel
        </Btn>
        <Btn
            variant="danger"
            disabled={dropping ||
                !deleteTarget?.constraintName ||
                deleteConfirm !== deleteTarget.constraintName}
            on:click={confirmDelete}
        >
            {dropping ? "Deleting…" : "Delete FK"}
        </Btn>
    </div>
</Modal>
