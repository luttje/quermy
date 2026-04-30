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

    let loadingViews = true;
    let savingCreate = false;
    let loadingEdit = false;
    let savingEdit = false;
    let dropping = false;

    let views = [];
    let viewDefinitions = {};
    let lastLoadedDb = null;
    let loadViewsToken = 0;

    let showCreateModal = false;
    let createName = "";
    let createDefinition = "";
    let createEditor;

    let showEditModal = false;
    let editViewName = "";
    let editDefinition = "";
    let editEditor;

    let showDeleteModal = false;
    let deleteViewName = "";
    let deleteConfirm = "";

    $: if (db && db !== lastLoadedDb) {
        lastLoadedDb = db;
        resetState();
        loadViews();
    }

    function quoteIdent(name) {
        const ioOpen = capabilities?.identifierOpen ?? "`";

        if (ioOpen === '"') return '"' + name.replace(/"/g, '""') + '"';
        if (ioOpen === "[") return "[" + name.replace(/]/g, "]]") + "]";
        return "`" + name.replace(/`/g, "``") + "`";
    }

    function buildTemplateSql() {
        return `SELECT *\nFROM ${quoteIdent("your_table")};`;
    }

    function resetState() {
        views = [];
        viewDefinitions = {};

        showCreateModal = false;
        createName = "";
        createDefinition = "";

        showEditModal = false;
        editViewName = "";
        editDefinition = "";

        showDeleteModal = false;
        deleteViewName = "";
        deleteConfirm = "";
    }

    function truncateSql(sql, maxLen = 120) {
        const normalized = (sql ?? "").replace(/\s+/g, " ").trim();
        if (!normalized) return "No SQL preview available.";
        if (normalized.length <= maxLen) return normalized;
        return normalized.slice(0, maxLen - 1) + "…";
    }

    async function loadViews() {
        if (!db) return;

        const token = ++loadViewsToken;
        const sourceDb = db;
        loadingViews = true;

        try {
            const r = await api.listViews(sourceDb);
            const list = r.views || [];
            const definitions = await Promise.all(
                list.map(async (name) => {
                    try {
                        const defRes = await api.getViewDefinition(
                            sourceDb,
                            name,
                        );
                        return [name, defRes.definition ?? ""];
                    } catch (_) {
                        return [name, ""];
                    }
                }),
            );

            if (token !== loadViewsToken) return;
            views = list;
            viewDefinitions = Object.fromEntries(definitions);
        } catch (e) {
            if (token !== loadViewsToken) return;
            toast(e.message, "error");
            views = [];
            viewDefinitions = {};
        } finally {
            if (token === loadViewsToken) {
                loadingViews = false;
            }
        }
    }

    async function openCreateModal() {
        createName = "";
        createDefinition = buildTemplateSql();
        showCreateModal = true;
        await tick();
        createEditor?.setValue(createDefinition);
    }

    function closeCreateModal() {
        if (savingCreate) return;
        showCreateModal = false;
    }

    async function createView() {
        if (!db) return;

        const targetView = createName.trim();
        const sql = createDefinition.trim();

        if (!targetView) {
            toast("View name is required", "error");
            return;
        }
        if (!sql) {
            toast("View SQL is required", "error");
            return;
        }

        const existed = views.includes(targetView);
        savingCreate = true;
        try {
            await api.saveViewDefinition(db, targetView, createDefinition);
            toast(
                existed
                    ? `View "${targetView}" updated`
                    : `View "${targetView}" created`,
                "success",
            );
            showCreateModal = false;
            await loadViews();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingCreate = false;
        }
    }

    async function openEditModal(viewName) {
        if (!db || !viewName) return;

        editViewName = viewName;
        editDefinition = viewDefinitions[viewName] ?? "";
        showEditModal = true;
        loadingEdit = true;

        await tick();
        editEditor?.setValue(editDefinition);

        try {
            const r = await api.getViewDefinition(db, viewName);
            editDefinition = r.definition ?? "";
            viewDefinitions = {
                ...viewDefinitions,
                [viewName]: editDefinition,
            };

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

    async function saveEditedView() {
        if (!db || !editViewName) return;
        if (!editDefinition.trim()) {
            toast("View SQL is required", "error");
            return;
        }

        savingEdit = true;
        try {
            await api.saveViewDefinition(db, editViewName, editDefinition);
            viewDefinitions = {
                ...viewDefinitions,
                [editViewName]: editDefinition,
            };
            toast(`View "${editViewName}" saved`, "success");
            showEditModal = false;
            await loadViews();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingEdit = false;
        }
    }

    function openDeleteModal(viewName) {
        if (!viewName) return;
        deleteViewName = viewName;
        deleteConfirm = "";
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        if (dropping) return;
        showDeleteModal = false;
    }

    async function confirmDeleteView() {
        if (!db || !deleteViewName) return;
        if (deleteConfirm !== deleteViewName) return;

        dropping = true;
        try {
            await api.dropView(db, deleteViewName);
            toast(`Dropped view "${deleteViewName}"`, "success");
            showDeleteModal = false;
            await loadViews();
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
            <h2 class="mono text-(--ink-0) text-[12.5px]">Views · {db}</h2>
            <p class="mono text-(--ink-3) text-[10.5px]">
                Browse, edit, and delete SQL views.
            </p>
        </div>
        <div class="flex items-center gap-1.5">
            <Btn
                variant="primary"
                class="text-[11px] px-2.5 py-1!"
                on:click={openCreateModal}
            >
                Create View
            </Btn>
            <Btn
                variant="ghost"
                class="text-[11px] px-2.5 py-1!"
                disabled={loadingViews}
                on:click={loadViews}
            >
                {loadingViews ? "Refreshing..." : "Refresh"}
            </Btn>
        </div>
    </header>

    <div class="p-2.5">
        {#if loadingViews}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                Loading views…
            </div>
        {:else if views.length === 0}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                No views found in {db}.
            </div>
        {:else}
            <ul class="divide-y divide-(--line)">
                {#each views as name}
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
                                    title={viewDefinitions[name] ?? ""}
                                >
                                    {truncateSql(viewDefinitions[name])}
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
    title={`Create View · ${db}`}
    maxWidth="max-w-5xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                View Name
            </span>
            <Input
                placeholder="e.g. active_users"
                bind:value={createName}
                class="text-[12px] py-2!"
                on:keydown={(e) => {
                    if (e.key === "Enter") createView();
                }}
            />
        </label>
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                View SQL
            </span>
            <div
                class="h-[360px] bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={createDefinition}
                    bind:this={createEditor}
                    placeholder="SELECT ... FROM ..."
                    minHeight="320px"
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
            on:click={createView}
        >
            {savingCreate ? "Creating…" : "Create View"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showEditModal}
    title={`Edit View · ${editViewName}`}
    maxWidth="max-w-5xl"
    on:close={closeEditModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <div class="flex items-center justify-between gap-2">
            <div class="mono text-[11px] text-(--ink-2)">
                Editing <span class="text-(--ink-0)">{editViewName}</span>
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
                placeholder="SELECT ... FROM ..."
                minHeight="360px"
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
            on:click={saveEditedView}
        >
            {savingEdit ? "Saving…" : "Save View"}
        </Btn>
    </div>
</Modal>

<Modal
    open={showDeleteModal}
    title={`Delete View · ${deleteViewName}`}
    maxWidth="max-w-md"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-[12px] text-(--ink-2)">
            This action is permanent. Type
            <span class="mono text-(--ink-0)">{deleteViewName}</span> to confirm.
        </p>
        <Input
            placeholder={deleteViewName
                ? `Type ${deleteViewName}`
                : "Type view name"}
            bind:value={deleteConfirm}
            class="text-[12px] py-2!"
            on:keydown={(e) => {
                if (e.key === "Enter") confirmDeleteView();
            }}
        />
    </div>
    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={dropping} on:click={closeDeleteModal}>
            Cancel
        </Btn>
        <Btn
            variant="danger"
            disabled={dropping || deleteConfirm !== deleteViewName}
            on:click={confirmDeleteView}
        >
            {dropping ? "Deleting…" : "Delete View"}
        </Btn>
    </div>
</Modal>
