<script>
    import { tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";
    import Modal from "../components/Modal.svelte";
    import CodeEditor from "../components/CodeEditor.svelte";
    import Select from "../components/ui/Select.svelte";
    import Checkbox from "../components/ui/Checkbox.svelte";

    export let db = "";
    export let capabilities = {};
    export let databases = [];

    let loadingViews = true;
    let savingCreate = false;
    let loadingEdit = false;
    let savingEdit = false;
    let dropping = false;

    let views = [];
    let viewDefinitions = {};
    let lastLoadedDb = null;
    let loadViewsToken = 0;

    // Engine detection
    $: isMySQL =
        capabilities?.engineId === "mysql" ||
        capabilities?.engineId === "mariadb";
    $: isPG = capabilities?.engineId === "postgresql";

    // Create modal
    let showCreateModal = false;
    let createName = "";
    let createSchema = "";
    let createReplace = true;
    let createBody = "";
    let createAlgorithm = "UNDEFINED";
    let createDefiner = "CURRENT_USER";
    let createSqlSecurity = "DEFINER";
    let createCheckOption = "NONE";
    let createMaterialized = false;
    let createWithData = "WITH DATA";
    let createSecurityBarrier = false;
    let createEditor;

    // Edit modal
    let showEditModal = false;
    let editViewName = "";
    let editReplace = true;
    let editBody = "";
    let editAlgorithm = "UNDEFINED";
    let editDefiner = "CURRENT_USER";
    let editSqlSecurity = "DEFINER";
    let editCheckOption = "NONE";
    let editMaterialized = false;
    let editWithData = "WITH DATA";
    let editSecurityBarrier = false;
    let editEditor;

    // Delete modal
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

    function buildTemplateBody() {
        return `SELECT *\nFROM ${quoteIdent("your_table")};`;
    }

    /**
     * Compiles structured fields + SELECT body into the full CREATE VIEW SQL.
     * Returns only the SELECT body for engines that handle the prefix themselves (SQLite).
     */
    function compileViewSql(schema, name, body, opts) {
        const cleanBody = body.trim().replace(/;+\s*$/, "");
        const qName = quoteIdent(name || "view_name");

        if (isMySQL) {
            const qSchema = quoteIdent(schema || db);
            const parts = ["CREATE"];
            if (opts.replace) parts.push("OR REPLACE");
            if (opts.algorithm !== "UNDEFINED")
                parts.push(`ALGORITHM = ${opts.algorithm}`);
            if (opts.definer) parts.push(`DEFINER = ${opts.definer}`);
            parts.push(`SQL SECURITY ${opts.sqlSecurity}`);
            parts.push(`VIEW ${qSchema}.${qName} AS`);

            let sql = parts.join(" ") + "\n" + cleanBody;
            if (opts.checkOption !== "NONE") {
                sql += `\nWITH ${opts.checkOption} CHECK OPTION`;
            }
            return sql;
        }

        if (isPG) {
            if (opts.materialized) {
                const ifNotExists = opts.replace ? "" : "IF NOT EXISTS ";
                return (
                    `CREATE MATERIALIZED VIEW ${ifNotExists}${qName} AS\n${cleanBody}\n${opts.withData}`
                );
            }
            const parts = ["CREATE"];
            if (opts.replace) parts.push("OR REPLACE");
            if (opts.securityBarrier) {
                parts.push(`VIEW ${qName} WITH (security_barrier=true) AS`);
            } else {
                parts.push(`VIEW ${qName} AS`);
            }
            return parts.join(" ") + "\n" + cleanBody;
        }

        // SQLite / SQL Server: send SELECT body; backend wraps with CREATE VIEW
        return cleanBody;
    }

    function resetState() {
        views = [];
        viewDefinitions = {};

        showCreateModal = false;
        createName = "";
        createSchema = "";
        createReplace = true;
        createBody = "";
        createAlgorithm = "UNDEFINED";
        createDefiner = "CURRENT_USER";
        createSqlSecurity = "DEFINER";
        createCheckOption = "NONE";
        createMaterialized = false;
        createWithData = "WITH DATA";
        createSecurityBarrier = false;

        showEditModal = false;
        editViewName = "";
        editReplace = true;
        editBody = "";
        editAlgorithm = "UNDEFINED";
        editDefiner = "CURRENT_USER";
        editSqlSecurity = "DEFINER";
        editCheckOption = "NONE";
        editMaterialized = false;
        editWithData = "WITH DATA";
        editSecurityBarrier = false;

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
        createSchema = db;
        createReplace = true;
        createBody = buildTemplateBody();
        createAlgorithm = "UNDEFINED";
        createDefiner = "CURRENT_USER";
        createSqlSecurity = "DEFINER";
        createCheckOption = "NONE";
        createMaterialized = false;
        createWithData = "WITH DATA";
        createSecurityBarrier = false;
        showCreateModal = true;
        await tick();
        createEditor?.setValue(createBody);
    }

    function closeCreateModal() {
        if (savingCreate) return;
        showCreateModal = false;
    }

    async function createView() {
        if (!db) return;

        const targetView = createName.trim();
        if (!targetView) {
            toast("View name is required", "error");
            return;
        }
        if (!createBody.trim()) {
            toast("SELECT body is required", "error");
            return;
        }

        const targetSchema = createSchema || db;
        const compiledSql = compileViewSql(targetSchema, targetView, createBody, {
            replace: createReplace,
            algorithm: createAlgorithm,
            definer: createDefiner,
            sqlSecurity: createSqlSecurity,
            checkOption: createCheckOption,
            materialized: createMaterialized,
            withData: createWithData,
            securityBarrier: createSecurityBarrier,
        });

        const existed = views.includes(targetView);
        savingCreate = true;
        try {
            await api.saveViewDefinition(targetSchema, targetView, compiledSql);
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
        editReplace = true;
        editAlgorithm = "UNDEFINED";
        editDefiner = "CURRENT_USER";
        editSqlSecurity = "DEFINER";
        editCheckOption = "NONE";
        editMaterialized = false;
        editWithData = "WITH DATA";
        editSecurityBarrier = false;
        editBody = viewDefinitions[viewName] ?? "";
        showEditModal = true;
        loadingEdit = true;

        await tick();
        editEditor?.setValue(editBody);

        try {
            const r = await api.getViewDefinition(db, viewName);
            editBody = r.definition ?? "";
            viewDefinitions = {
                ...viewDefinitions,
                [viewName]: editBody,
            };

            await tick();
            editEditor?.setValue(editBody);
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
        if (!editBody.trim()) {
            toast("SELECT body is required", "error");
            return;
        }

        const compiledSql = compileViewSql(db, editViewName, editBody, {
            replace: editReplace,
            algorithm: editAlgorithm,
            definer: editDefiner,
            sqlSecurity: editSqlSecurity,
            checkOption: editCheckOption,
            materialized: editMaterialized,
            withData: editWithData,
            securityBarrier: editSecurityBarrier,
        });

        savingEdit = true;
        try {
            await api.saveViewDefinition(db, editViewName, compiledSql);
            viewDefinitions = {
                ...viewDefinitions,
                [editViewName]: editBody,
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

<!-- ─── Create Modal ──────────────────────────────────────────────────────── -->
<Modal
    open={showCreateModal}
    title={`Create View · ${db}`}
    maxWidth="max-w-5xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <!-- Name + Schema -->
        <div class="grid grid-cols-2 gap-3">
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
                />
            </label>
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >
                    Schema / Database
                </span>
                <Select bind:value={createSchema} class="text-[12px]">
                    {#each databases.length ? databases : [db] as d}
                        <option value={d}>{d}</option>
                    {/each}
                </Select>
            </label>
        </div>

        <!-- Replace toggle -->
        <label
            class="inline-flex items-center gap-2 cursor-pointer select-none"
        >
            <Checkbox bind:checked={createReplace} />
            <span class="mono text-[12px] text-(--ink-1)"
                >Replace if exists</span
            >
        </label>

        <!-- MySQL-specific fields -->
        {#if isMySQL}
            <div class="grid grid-cols-3 gap-3">
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        Algorithm
                    </span>
                    <Select bind:value={createAlgorithm} class="text-[12px]">
                        {#each ["UNDEFINED", "MERGE", "TEMPTABLE"] as v}
                            <option value={v}>{v}</option>
                        {/each}
                    </Select>
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        Definer
                    </span>
                    <Input
                        bind:value={createDefiner}
                        placeholder="CURRENT_USER"
                        class="text-[12px] py-2!"
                    />
                </label>
                <div class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        SQL Security
                    </span>
                    <div class="flex gap-4 py-2.5">
                        {#each ["DEFINER", "INVOKER"] as v}
                            <label
                                class="flex items-center gap-1.5 cursor-pointer select-none"
                            >
                                <input
                                    type="radio"
                                    bind:group={createSqlSecurity}
                                    value={v}
                                    class="accent-(--acc) cursor-pointer"
                                />
                                <span class="mono text-[12px] text-(--ink-1)"
                                    >{v}</span
                                >
                            </label>
                        {/each}
                    </div>
                </div>
            </div>
            <label class="flex flex-col gap-1 max-w-56">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >
                    WITH CHECK OPTION
                </span>
                <Select bind:value={createCheckOption} class="text-[12px]">
                    {#each ["NONE", "CASCADED", "LOCAL"] as v}
                        <option value={v}>{v}</option>
                    {/each}
                </Select>
            </label>
        {/if}

        <!-- PostgreSQL-specific fields -->
        {#if isPG}
            <div class="flex gap-5 flex-wrap">
                <label
                    class="inline-flex items-center gap-2 cursor-pointer select-none"
                >
                    <Checkbox bind:checked={createMaterialized} />
                    <span class="mono text-[12px] text-(--ink-1)"
                        >Materialized View</span
                    >
                </label>
                {#if !createMaterialized}
                    <label
                        class="inline-flex items-center gap-2 cursor-pointer select-none"
                    >
                        <Checkbox bind:checked={createSecurityBarrier} />
                        <span class="mono text-[12px] text-(--ink-1)"
                            >Security Barrier</span
                        >
                    </label>
                {/if}
            </div>
            {#if createMaterialized}
                <div class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        WITH DATA / WITH NO DATA
                    </span>
                    <div class="flex gap-4 py-1">
                        {#each ["WITH DATA", "WITH NO DATA"] as v}
                            <label
                                class="flex items-center gap-1.5 cursor-pointer select-none"
                            >
                                <input
                                    type="radio"
                                    bind:group={createWithData}
                                    value={v}
                                    class="accent-(--acc) cursor-pointer"
                                />
                                <span class="mono text-[12px] text-(--ink-1)"
                                    >{v}</span
                                >
                            </label>
                        {/each}
                    </div>
                </div>
            {/if}
        {/if}

        <!-- SELECT body -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                SELECT Body
            </span>
            <div
                class="h-70 bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={createBody}
                    bind:this={createEditor}
                    placeholder="SELECT ... FROM ..."
                    minHeight="240px"
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
                !createBody.trim()}
            on:click={createView}
        >
            {savingCreate ? "Creating…" : "Create View"}
        </Btn>
    </div>
</Modal>

<!-- ─── Edit Modal ────────────────────────────────────────────────────────── -->
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
                <span class="text-(--ink-3)">in {db}</span>
            </div>
            {#if loadingEdit}
                <div class="mono text-[10px] text-(--ink-3)">
                    Loading latest SQL…
                </div>
            {/if}
        </div>

        <!-- Replace toggle -->
        <label
            class="inline-flex items-center gap-2 cursor-pointer select-none"
        >
            <Checkbox bind:checked={editReplace} />
            <span class="mono text-[12px] text-(--ink-1)"
                >Replace if exists</span
            >
        </label>

        <!-- MySQL-specific fields -->
        {#if isMySQL}
            <div class="grid grid-cols-3 gap-3">
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        Algorithm
                    </span>
                    <Select bind:value={editAlgorithm} class="text-[12px]">
                        {#each ["UNDEFINED", "MERGE", "TEMPTABLE"] as v}
                            <option value={v}>{v}</option>
                        {/each}
                    </Select>
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        Definer
                    </span>
                    <Input
                        bind:value={editDefiner}
                        placeholder="CURRENT_USER"
                        class="text-[12px] py-2!"
                    />
                </label>
                <div class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        SQL Security
                    </span>
                    <div class="flex gap-4 py-2.5">
                        {#each ["DEFINER", "INVOKER"] as v}
                            <label
                                class="flex items-center gap-1.5 cursor-pointer select-none"
                            >
                                <input
                                    type="radio"
                                    bind:group={editSqlSecurity}
                                    value={v}
                                    class="accent-(--acc) cursor-pointer"
                                />
                                <span class="mono text-[12px] text-(--ink-1)"
                                    >{v}</span
                                >
                            </label>
                        {/each}
                    </div>
                </div>
            </div>
            <label class="flex flex-col gap-1 max-w-56">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >
                    WITH CHECK OPTION
                </span>
                <Select bind:value={editCheckOption} class="text-[12px]">
                    {#each ["NONE", "CASCADED", "LOCAL"] as v}
                        <option value={v}>{v}</option>
                    {/each}
                </Select>
            </label>
        {/if}

        <!-- PostgreSQL-specific fields -->
        {#if isPG}
            <div class="flex gap-5 flex-wrap">
                <label
                    class="inline-flex items-center gap-2 cursor-pointer select-none"
                >
                    <Checkbox bind:checked={editMaterialized} />
                    <span class="mono text-[12px] text-(--ink-1)"
                        >Materialized View</span
                    >
                </label>
                {#if !editMaterialized}
                    <label
                        class="inline-flex items-center gap-2 cursor-pointer select-none"
                    >
                        <Checkbox bind:checked={editSecurityBarrier} />
                        <span class="mono text-[12px] text-(--ink-1)"
                            >Security Barrier</span
                        >
                    </label>
                {/if}
            </div>
            {#if editMaterialized}
                <div class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >
                        WITH DATA / WITH NO DATA
                    </span>
                    <div class="flex gap-4 py-1">
                        {#each ["WITH DATA", "WITH NO DATA"] as v}
                            <label
                                class="flex items-center gap-1.5 cursor-pointer select-none"
                            >
                                <input
                                    type="radio"
                                    bind:group={editWithData}
                                    value={v}
                                    class="accent-(--acc) cursor-pointer"
                                />
                                <span class="mono text-[12px] text-(--ink-1)"
                                    >{v}</span
                                >
                            </label>
                        {/each}
                    </div>
                </div>
            {/if}
        {/if}

        <!-- SELECT body -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
            >
                SELECT Body
            </span>
            <div
                class="h-75 bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={editBody}
                    bind:this={editEditor}
                    placeholder="SELECT ... FROM ..."
                    minHeight="260px"
                    mode="sql"
                />
            </div>
        </div>
    </div>
    <div slot="footer" class="px-5 py-3 bg-(--bg-2) flex justify-end gap-2">
        <Btn variant="ghost" disabled={savingEdit} on:click={closeEditModal}>
            Cancel
        </Btn>
        <Btn
            variant="primary"
            disabled={savingEdit || loadingEdit || !editBody.trim()}
            on:click={saveEditedView}
        >
            {savingEdit ? "Saving…" : "Save View"}
        </Btn>
    </div>
</Modal>

<!-- ─── Delete Modal ──────────────────────────────────────────────────────── -->
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
