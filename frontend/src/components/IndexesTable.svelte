<script>
    import { createEventDispatcher } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Btn from "./ui/Btn.svelte";
    import Input from "./ui/Input.svelte";
    import Select from "./ui/Select.svelte";
    import SearchableSelect from "./ui/SearchableSelect.svelte";
    import Modal from "./Modal.svelte";
    import AddIcon from "~icons/heroicons/plus-solid";
    import DeleteIcon from "~icons/heroicons/trash-solid";

    export let db;
    export let table;
    export let indexes = [];
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    // --- Meta options ---
    let columnOptions = [];

    async function loadColumnOptions() {
        try {
            const r = await api.browseTable(db, table, 1, 0);
            columnOptions = (r.columns ?? []).map((c) => c.name);
        } catch (_) {
            columnOptions = [];
        }
    }

    let showCreateModal = false;
    let creating = false;
    let createType = "index";
    let createName = "";
    let createColumnsList = [""];

    let showEditModal = false;
    let savingEdit = false;
    let editTarget = null;
    let editType = "index";
    let editName = "";
    let editColumnsList = [""];

    let showDeleteModal = false;
    let dropping = false;
    let deleteTarget = null;
    let deleteConfirm = "";

    $: canManage = capabilities?.supportsIndexManagement ?? false;
    $: canManagePK = capabilities?.supportsPrimaryKeyManagement ?? false;
    $: canCreate = canManage || canManagePK;
    $: typeOptions = [
        ...(canManagePK ? [{ value: "primary", label: "PRIMARY KEY" }] : []),
        ...(canManage
            ? [
                  { value: "unique", label: "UNIQUE INDEX" },
                  { value: "index", label: "INDEX" },
              ]
            : []),
    ];
    $: if (
        typeOptions.length > 0 &&
        !typeOptions.some((o) => o.value === createType)
    ) {
        createType = typeOptions[0].value;
    }

    function typeMeta(idx) {
        if (idx.primary) {
            return {
                label: "PRIMARY",
                cls: "bg-[rgba(200,255,90,0.15)] text-(--acc)",
            };
        }
        if (idx.unique) {
            return {
                label: "UNIQUE",
                cls: "bg-[rgba(127,200,255,0.15)] text-(--ink-1)",
            };
        }
        return {
            label: "INDEX",
            cls: "bg-[rgba(127,127,127,0.12)] text-(--ink-2)",
        };
    }

    function columnsPreview(columns, maxLen = 100) {
        const text = (columns ?? []).join(", ");
        if (!text) return "No columns";
        if (text.length <= maxLen) return text;
        return text.slice(0, maxLen - 1) + "…";
    }

    function indexType(idx) {
        if (idx?.primary) return "primary";
        if (idx?.unique) return "unique";
        return "index";
    }

    function sameColumns(a, b) {
        if ((a?.length ?? 0) !== (b?.length ?? 0)) return false;
        return (a ?? []).every((col, i) => col === b[i]);
    }

    function openCreateModal() {
        if (!canCreate) return;
        createType = typeOptions[0]?.value ?? "index";
        createName = "";
        createColumnsList = [""];
        showCreateModal = true;
        loadColumnOptions();
    }

    function closeCreateModal() {
        if (creating) return;
        showCreateModal = false;
    }

    async function createIndex() {
        if (!canCreate) return;

        if (createType === "primary" && !canManagePK) {
            toast("Primary key management is not supported", "error");
            return;
        }
        if ((createType === "unique" || createType === "index") && !canManage) {
            toast("Index management is not supported", "error");
            return;
        }

        const cols = createColumnsList.filter(Boolean);
        if (!cols.length) {
            toast("Enter at least one column", "error");
            return;
        }
        if (createType !== "primary" && !createName.trim()) {
            toast("Index name is required", "error");
            return;
        }

        creating = true;
        try {
            await api.createIndex(db, table, {
                name: createType === "primary" ? "" : createName.trim(),
                columns: cols,
                unique: createType === "unique",
                primary: createType === "primary",
            });
            toast("Index created", "success");
            showCreateModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            creating = false;
        }
    }

    function openEditModal(idx) {
        if (!canDelete(idx)) return;
        editTarget = idx;
        editType = indexType(idx);
        editName = idx?.primary ? "" : (idx?.name ?? "");
        editColumnsList = idx?.columns?.length ? [...idx.columns] : [""];
        showEditModal = true;
        loadColumnOptions();
    }

    function closeEditModal() {
        if (savingEdit) return;
        showEditModal = false;
    }

    async function saveEditedIndex() {
        if (!canCreate || !editTarget) return;

        if (editType === "primary" && !canManagePK) {
            toast("Primary key management is not supported", "error");
            return;
        }
        if ((editType === "unique" || editType === "index") && !canManage) {
            toast("Index management is not supported", "error");
            return;
        }

        const cols = editColumnsList.filter(Boolean);
        if (!cols.length) {
            toast("Enter at least one column", "error");
            return;
        }
        if (editType !== "primary" && !editName.trim()) {
            toast("Index name is required", "error");
            return;
        }

        const nextType = editType;
        const nextName = nextType === "primary" ? "" : editName.trim();
        const sameDefinition =
            nextType === indexType(editTarget) &&
            nextName === (editTarget.primary ? "" : (editTarget.name ?? "")) &&
            sameColumns(editTarget.columns ?? [], cols);

        if (sameDefinition) {
            showEditModal = false;
            return;
        }

        savingEdit = true;
        try {
            await api.dropIndex(db, table, editTarget.name, editTarget.primary);
            await api.createIndex(db, table, {
                name: nextName,
                columns: cols,
                unique: nextType === "unique",
                primary: nextType === "primary",
            });
            toast("Index updated", "success");
            showEditModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingEdit = false;
        }
    }

    function canDelete(idx) {
        if (!idx) return false;
        if (idx.primary) return canManagePK;
        return canManage;
    }

    function openDeleteModal(idx) {
        if (!canDelete(idx)) return;
        deleteTarget = idx;
        deleteConfirm = "";
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        if (dropping) return;
        showDeleteModal = false;
    }

    function deleteToken(idx) {
        if (!idx) return "";
        return idx.primary ? "PRIMARY" : idx.name;
    }

    async function confirmDelete() {
        if (!deleteTarget) return;
        if (deleteConfirm !== deleteToken(deleteTarget)) return;

        dropping = true;
        try {
            await api.dropIndex(
                db,
                table,
                deleteTarget.name,
                deleteTarget.primary,
            );
            toast(
                deleteTarget.primary
                    ? "Primary key dropped"
                    : `Index "${deleteTarget.name}" dropped`,
                "success",
            );
            showDeleteModal = false;
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dropping = false;
        }
    }
</script>

<section class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden">
    <header
        class="flex items-center justify-between gap-2 px-3.5 py-2.5 border-b border-(--line) bg-(--bg-2)"
    >
        <div class="flex flex-col">
            <h2 class="mono text-(--ink-0) text-[12.5px]">Indexes · {table}</h2>
            <p class="mono text-(--ink-3) text-[10.5px]">
                Create and drop table indexes.
            </p>
        </div>
        <div class="flex items-center gap-1.5">
            {#if canCreate}
                <Btn
                    variant="primary"
                    class="text-[11px] px-2.5 py-1!"
                    on:click={openCreateModal}
                >
                    Create Index
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
        {#if indexes.length === 0}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                No indexes found on this table.
            </div>
        {:else}
            <ul class="divide-y divide-(--line)">
                {#each indexes as idx}
                    {@const meta = typeMeta(idx)}
                    <li class="px-2.5 py-2.5">
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="mono text-[10px] font-bold tracking-[0.06em] px-1.5 py-0.5 rounded {meta.cls}"
                                    >
                                        {meta.label}
                                    </span>
                                    <p
                                        class="mono text-(--ink-0) text-[12.5px] font-semibold truncate"
                                    >
                                        {idx.primary ? "PRIMARY" : idx.name}
                                    </p>
                                </div>
                                <p
                                    class="mono text-(--ink-3) text-[11px] truncate mt-0.5"
                                    title={(idx.columns ?? []).join(", ")}
                                >
                                    {columnsPreview(idx.columns)}
                                </p>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5">
                                <Btn
                                    variant="ghost"
                                    class="text-[11px] px-2.5 py-1!"
                                    disabled={!canDelete(idx)}
                                    title={!canDelete(idx)
                                        ? "Index management not supported by this driver"
                                        : "Edit"}
                                    on:click={() => openEditModal(idx)}
                                >
                                    Edit
                                </Btn>
                                <Btn
                                    variant="danger"
                                    class="text-[11px] px-2.5 py-1!"
                                    disabled={!canDelete(idx)}
                                    title={!canDelete(idx)
                                        ? "Index management not supported by this driver"
                                        : "Delete"}
                                    on:click={() => openDeleteModal(idx)}
                                >
                                    Delete
                                </Btn>
                            </div>
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}
    </div>
</section>

<Modal
    open={showCreateModal}
    title={`Create Index · ${table}`}
    maxWidth="max-w-xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Type
            </span>
            <Select bind:value={createType} class="text-[12px] py-2!">
                {#each typeOptions as option}
                    <option value={option.value}>{option.label}</option>
                {/each}
            </Select>
        </label>

        {#if createType !== "primary"}
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >
                    Name
                </span>
                <Input
                    bind:value={createName}
                    class="text-[12px] py-2!"
                    placeholder="e.g. idx_users_email"
                />
            </label>
        {/if}

        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Columns
            </span>
            <div class="flex flex-col gap-1">
                {#each createColumnsList as _, i}
                    <div class="flex items-center gap-1">
                        <div class="flex-1">
                            <SearchableSelect
                                bind:value={createColumnsList[i]}
                                options={columnOptions}
                                placeholder="Select column…"
                                allowCustom={true}
                                triggerClass="text-[12px] py-2! px-3!"
                            />
                        </div>
                        {#if createColumnsList.length > 1}
                            <Btn
                                variant="ghost"
                                class="text-[12px] px-2 py-1! shrink-0"
                                on:click={() => {
                                    createColumnsList =
                                        createColumnsList.filter(
                                            (_, idx) => idx !== i,
                                        );
                                }}
                            >
                                <DeleteIcon />
                            </Btn>
                        {/if}
                    </div>
                {/each}
                <Btn
                    variant="ghost"
                    class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                    on:click={() => {
                        createColumnsList = [...createColumnsList, ""];
                    }}
                >
                    <AddIcon />
                    Add column
                </Btn>
            </div>
        </div>
    </div>

    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={creating} on:click={closeCreateModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={creating ||
                !createColumnsList.some(Boolean) ||
                (createType !== "primary" && !createName.trim())}
            on:click={createIndex}
        >
            {creating ? "Creating…" : "Create Index"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showEditModal}
    title={`Edit Index · ${table}`}
    maxWidth="max-w-xl"
    on:close={closeEditModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <div class="mono text-[11px] text-(--ink-2)">
            Editing
            <span class="text-(--ink-0)">
                {editTarget?.primary ? "PRIMARY" : (editTarget?.name ?? "")}
            </span>
        </div>
        <p class="mono text-[10.5px] text-(--ink-3)">
            Saving will recreate this index (drop then create).
        </p>

        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Type
            </span>
            <Select bind:value={editType} class="text-[12px] py-2!">
                {#each typeOptions as option}
                    <option value={option.value}>{option.label}</option>
                {/each}
            </Select>
        </label>

        {#if editType !== "primary"}
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >
                    Name
                </span>
                <Input
                    bind:value={editName}
                    class="text-[12px] py-2!"
                    placeholder="e.g. idx_users_email"
                />
            </label>
        {/if}

        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Columns
            </span>
            <div class="flex flex-col gap-1">
                {#each editColumnsList as _, i}
                    <div class="flex items-center gap-1">
                        <div class="flex-1">
                            <SearchableSelect
                                bind:value={editColumnsList[i]}
                                options={columnOptions}
                                placeholder="Select column…"
                                allowCustom={true}
                                triggerClass="text-[12px] py-2! px-3!"
                            />
                        </div>
                        {#if editColumnsList.length > 1}
                            <Btn
                                variant="ghost"
                                class="text-[12px] px-2 py-1! shrink-0"
                                on:click={() => {
                                    editColumnsList = editColumnsList.filter(
                                        (_, idx) => idx !== i,
                                    );
                                }}
                            >
                                <DeleteIcon />
                            </Btn>
                        {/if}
                    </div>
                {/each}
                <Btn
                    variant="ghost"
                    class="text-[11px] px-2 py-0.5! self-start mt-0.5"
                    on:click={() => {
                        editColumnsList = [...editColumnsList, ""];
                    }}
                >
                    + Add column
                </Btn>
            </div>
        </div>
    </div>

    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={savingEdit} on:click={closeEditModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={savingEdit ||
                !editColumnsList.some(Boolean) ||
                (editType !== "primary" && !editName.trim())}
            on:click={saveEditedIndex}
        >
            {savingEdit ? "Saving…" : "Save Index"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showDeleteModal}
    title={`Delete Index · ${table}`}
    maxWidth="max-w-md"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-[12px] text-(--ink-2)">
            This action is permanent. Type
            <span class="mono text-(--ink-0)">{deleteToken(deleteTarget)}</span>
            to confirm.
        </p>
        <Input
            bind:value={deleteConfirm}
            class="text-[12px] py-2!"
            placeholder={deleteTarget
                ? `Type ${deleteToken(deleteTarget)}`
                : "Type index name"}
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
                !deleteTarget ||
                deleteConfirm !== deleteToken(deleteTarget)}
            on:click={confirmDelete}
        >
            {dropping ? "Deleting…" : "Delete Index"}
        </Btn>
    </div>
</Modal>
