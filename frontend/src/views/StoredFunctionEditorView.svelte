<script>
    import { tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";
    import Modal from "../components/Modal.svelte";
    import CodeEditor from "../components/CodeEditor.svelte";

    export let db = "";
    export let capabilities = {};

    let loadingItems = true;
    let savingCreate = false;
    let loadingEdit = false;
    let savingEdit = false;
    let dropping = false;

    let items = [];
    let definitions = {};
    let lastLoadedDb = null;
    let loadToken = 0;

    let showCreateModal = false;
    let createName = "";
    let createDefinition = "";
    let createEditor;

    let showEditModal = false;
    let editName = "";
    let editDefinition = "";
    let editEditor;

    let showDeleteModal = false;
    let deleteName = "";
    let deleteConfirm = "";

    $: if (db && db !== lastLoadedDb) {
        lastLoadedDb = db;
        resetState();
        loadItems();
    }

    function quoteIdent(name) {
        const ioOpen = capabilities?.identifierOpen ?? "`";
        if (ioOpen === '"') return '"' + name.replace(/"/g, '""') + '"';
        if (ioOpen === "[") return "[" + name.replace(/]/g, "]]") + "]";
        return "`" + name.replace(/`/g, "``") + "`";
    }

    function buildTemplateSql(name) {
        const ioOpen = capabilities?.identifierOpen ?? "`";
        const n = name || "function_name";
        if (ioOpen === '"') {
            return `CREATE OR REPLACE FUNCTION public.${quoteIdent(n)}()\nRETURNS VOID\nLANGUAGE plpgsql\nAS $$\nBEGIN\n  -- function body\nEND;\n$$;`;
        }
        if (ioOpen === "[") {
            return `CREATE FUNCTION [dbo].${quoteIdent(n)}()\nRETURNS INT\nAS\nBEGIN\n  RETURN 0;\nEND`;
        }
        return `CREATE FUNCTION ${quoteIdent(n)}() RETURNS INT\nBEGIN\n  RETURN 0;\nEND`;
    }

    function resetState() {
        items = [];
        definitions = {};
        showCreateModal = false;
        createName = "";
        createDefinition = "";
        showEditModal = false;
        editName = "";
        editDefinition = "";
        showDeleteModal = false;
        deleteName = "";
        deleteConfirm = "";
    }

    function truncateSql(sql, maxLen = 120) {
        const normalized = (sql ?? "").replace(/\s+/g, " ").trim();
        if (!normalized) return "No SQL preview available.";
        if (normalized.length <= maxLen) return normalized;
        return normalized.slice(0, maxLen - 1) + "…";
    }

    async function loadItems() {
        if (!db) return;

        const token = ++loadToken;
        const sourceDb = db;
        loadingItems = true;

        try {
            const r = await api.listFunctions(sourceDb);
            const list = r.functions || [];
            const defs = await Promise.all(
                list.map(async (name) => {
                    try {
                        const res = await api.getFunctionDefinition(
                            sourceDb,
                            name,
                        );
                        return [name, res.definition ?? ""];
                    } catch (_) {
                        return [name, ""];
                    }
                }),
            );

            if (token !== loadToken) return;
            items = list;
            definitions = Object.fromEntries(defs);
        } catch (e) {
            if (token !== loadToken) return;
            toast(e.message, "error");
            items = [];
            definitions = {};
        } finally {
            if (token === loadToken) {
                loadingItems = false;
            }
        }
    }

    async function openCreateModal() {
        createName = "";
        createDefinition = buildTemplateSql("");
        showCreateModal = true;
        await tick();
        createEditor?.setValue(createDefinition);
    }

    function closeCreateModal() {
        if (savingCreate) return;
        showCreateModal = false;
    }

    async function createItem() {
        if (!db) return;

        const targetName = createName.trim();
        const sql = createDefinition.trim();

        if (!targetName) {
            toast("Function name is required", "error");
            return;
        }
        if (!sql) {
            toast("Function SQL is required", "error");
            return;
        }

        const existed = items.includes(targetName);
        savingCreate = true;
        try {
            await api.saveFunctionDefinition(db, targetName, createDefinition);
            toast(
                existed
                    ? `Function "${targetName}" updated`
                    : `Function "${targetName}" created`,
                "success",
            );
            showCreateModal = false;
            await loadItems();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingCreate = false;
        }
    }

    async function openEditModal(name) {
        if (!db || !name) return;

        editName = name;
        editDefinition = definitions[name] ?? "";
        showEditModal = true;
        loadingEdit = true;

        await tick();
        editEditor?.setValue(editDefinition);

        try {
            const r = await api.getFunctionDefinition(db, name);
            editDefinition = r.definition ?? "";
            definitions = { ...definitions, [name]: editDefinition };

            await tick();
            editEditor?.setValue(editDefinition);
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loadingEdit = false;
        }
    }

    function closeEditModal() {
        if (savingEdit) return;
        showEditModal = false;
    }

    async function saveEdited() {
        if (!db || !editName) return;
        if (!editDefinition.trim()) {
            toast("Function SQL is required", "error");
            return;
        }

        savingEdit = true;
        try {
            await api.saveFunctionDefinition(db, editName, editDefinition);
            definitions = { ...definitions, [editName]: editDefinition };
            toast(`Function "${editName}" saved`, "success");
            showEditModal = false;
            await loadItems();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingEdit = false;
        }
    }

    function openDeleteModal(name) {
        if (!name) return;
        deleteName = name;
        deleteConfirm = "";
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        if (dropping) return;
        showDeleteModal = false;
    }

    async function confirmDelete() {
        if (!db || !deleteName) return;
        if (deleteConfirm !== deleteName) return;

        dropping = true;
        try {
            await api.dropFunction(db, deleteName);
            toast(`Dropped function "${deleteName}"`, "success");
            showDeleteModal = false;
            await loadItems();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dropping = false;
        }
    }

    $: if (createName && showCreateModal) {
        const newDef = buildTemplateSql(createName);
        createDefinition = newDef;
        createEditor?.setValue(newDef);
    }
</script>

<section class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden">
    <header
        class="flex items-center justify-between gap-2 px-3.5 py-2.5 border-b border-(--line) bg-(--bg-2)"
    >
        <div class="flex flex-col">
            <h2 class="mono text-(--ink-0) text-[12.5px]">
                Stored Functions · {db}
            </h2>
            <p class="mono text-(--ink-3) text-[10.5px]">
                Browse, edit, and delete stored functions.
            </p>
        </div>
        <div class="flex items-center gap-1.5">
            <Btn
                variant="primary"
                class="text-[11px] px-2.5 py-1!"
                on:click={openCreateModal}
            >
                Create Function
            </Btn>
            <Btn
                variant="ghost"
                class="text-[11px] px-2.5 py-1!"
                disabled={loadingItems}
                on:click={loadItems}
            >
                {loadingItems ? "Refreshing..." : "Refresh"}
            </Btn>
        </div>
    </header>

    <div class="p-2.5">
        {#if loadingItems}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                Loading functions…
            </div>
        {:else if items.length === 0}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                No stored functions found in {db}.
            </div>
        {:else}
            <ul class="divide-y divide-(--line)">
                {#each items as name}
                    <li class="px-2.5 py-2.5">
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <p
                                    class="mono text-(--ink-0) text-[12.5px] font-semibold"
                                >
                                    {name}
                                </p>
                                <p
                                    class="mono text-(--ink-3) text-[11px] truncate mt-0.5"
                                    title={definitions[name] ?? ""}
                                >
                                    {truncateSql(definitions[name])}
                                </p>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5">
                                <Btn
                                    variant="ghost"
                                    class="text-[11px] px-2.5 py-1!"
                                    on:click={() => openEditModal(name)}
                                >
                                    Edit
                                </Btn>
                                <Btn
                                    variant="danger"
                                    class="text-[11px] px-2.5 py-1!"
                                    on:click={() => openDeleteModal(name)}
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
    title={`Create Function · ${db}`}
    maxWidth="max-w-5xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Function Name
            </span>
            <Input
                placeholder="e.g. calculate_discount"
                bind:value={createName}
                class="text-[12px] py-2!"
            />
        </label>
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                Function SQL
            </span>
            <div
                class="h-[360px] bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={createDefinition}
                    bind:this={createEditor}
                    placeholder="CREATE FUNCTION ..."
                    minHeight="320px"
                    mode="sql"
                />
            </div>
        </div>
    </div>
    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn
            variant="ghost"
            disabled={savingCreate}
            on:click={closeCreateModal}
        >
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={savingCreate ||
                !createName.trim() ||
                !createDefinition.trim()}
            on:click={createItem}
        >
            {savingCreate ? "Creating…" : "Create Function"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showEditModal}
    title={`Edit Function · ${editName}`}
    maxWidth="max-w-5xl"
    on:close={closeEditModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <div class="flex items-center justify-between gap-2">
            <div class="mono text-[11px] text-(--ink-2)">
                Editing <span class="text-(--ink-0)">{editName}</span>
            </div>
            {#if loadingEdit}
                <div class="mono text-[10px] text-(--ink-3)">
                    Loading latest SQL…
                </div>
            {/if}
        </div>
        <div
            class="h-[420px] bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
        >
            <CodeEditor
                bind:value={editDefinition}
                bind:this={editEditor}
                placeholder="CREATE FUNCTION ..."
                minHeight="360px"
                mode="sql"
            />
        </div>
    </div>
    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={savingEdit} on:click={closeEditModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={savingEdit || loadingEdit || !editDefinition.trim()}
            on:click={saveEdited}
        >
            {savingEdit ? "Saving…" : "Save Function"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showDeleteModal}
    title={`Delete Function · ${deleteName}`}
    maxWidth="max-w-md"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-[12px] text-(--ink-2)">
            This action is permanent. Type
            <span class="mono text-(--ink-0)">{deleteName}</span> to confirm.
        </p>
        <Input
            placeholder={deleteName
                ? `Type ${deleteName}`
                : "Type function name"}
            bind:value={deleteConfirm}
            class="text-[12px] py-2!"
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
            disabled={dropping || deleteConfirm !== deleteName}
            on:click={confirmDelete}
        >
            {dropping ? "Deleting…" : "Delete Function"}
        </Btn>
    </div>
</Modal>
