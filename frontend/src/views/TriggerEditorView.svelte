<script>
    import { tick } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";
    import Modal from "../components/Modal.svelte";
    import CodeEditor from "../components/CodeEditor.svelte";
    import Select from "../components/ui/Select.svelte";

    export let db = "";
    export let table = "";
    export let capabilities = {};

    let loadingItems = true;
    let savingCreate = false;
    let loadingEdit = false;
    let savingEdit = false;
    let dropping = false;

    let items = [];
    let definitions = {};
    let lastLoadedKey = null;
    let loadToken = 0;

    const TIMINGS = ["BEFORE", "AFTER"];
    const EVENTS = ["INSERT", "UPDATE", "DELETE"];

    // Create modal
    let showCreateModal = false;
    let createName = "";
    let createTiming = "BEFORE";
    let createEvent = "INSERT";
    let createBody = "BEGIN\n  -- trigger body\nEND";
    let createEditor;

    // Edit modal
    let showEditModal = false;
    let editOriginalName = "";
    let editName = "";
    let editTiming = "BEFORE";
    let editEvent = "INSERT";
    let editBody = "";
    let editEditor;

    // Delete modal
    let showDeleteModal = false;
    let deleteName = "";
    let deleteConfirm = "";

    $: loadedKey = `${db}\0${table}`;
    $: if (loadedKey && loadedKey !== lastLoadedKey) {
        lastLoadedKey = loadedKey;
        resetState();
        loadItems();
    }

    function quoteIdent(name) {
        const ioOpen = capabilities?.identifierOpen ?? "`";
        if (ioOpen === '"') return '"' + name.replace(/"/g, '""') + '"';
        if (ioOpen === "[") return "[" + name.replace(/]/g, "]]") + "]";
        return "`" + name.replace(/`/g, "``") + "`";
    }

    /**
     * Parse a CREATE TRIGGER statement into structured fields.
     * Best-effort; returns safe defaults on failure.
     */
    function parseTriggerSql(sql) {
        const result = {
            name: "",
            timing: "BEFORE",
            event: "INSERT",
            body: "BEGIN\n  -- trigger body\nEND",
        };
        if (!sql) return result;

        const m = sql.match(
            /CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?TRIGGER\s+(?:`[^`]*`|\S+)\s+(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+(?:`[^`]*`\.)?(?:`[^`]*`|\S+)\s+FOR\s+EACH\s+ROW\s+([\s\S]*)$/i,
        );
        if (m) {
            result.timing = m[1].toUpperCase();
            result.event = m[2].toUpperCase();
            result.body = m[3].trim().replace(/;?\s*$/, "");
        }

        return result;
    }

    /** Build the full CREATE TRIGGER SQL from structured fields. */
    function compileTriggerSql(fields) {
        const qName = quoteIdent(fields.name || "trigger_name");
        const qDb = quoteIdent(db);
        const qTable = quoteIdent(table);
        const cleanBody = (fields.body || "-- trigger body").trim();

        return [
            `CREATE TRIGGER ${qDb}.${qName}`,
            `${fields.timing} ${fields.event} ON ${qDb}.${qTable}`,
            `FOR EACH ROW`,
            cleanBody,
        ].join("\n");
    }

    function resetState() {
        items = [];
        definitions = {};
        showCreateModal = false;
        createName = "";
        createTiming = "BEFORE";
        createEvent = "INSERT";
        createBody = "BEGIN\n  -- trigger body\nEND";
        showEditModal = false;
        editOriginalName = "";
        editName = "";
        editTiming = "BEFORE";
        editEvent = "INSERT";
        editBody = "";
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
        if (!db || !table) return;

        const token = ++loadToken;
        const sourceDb = db;
        const sourceTable = table;
        loadingItems = true;

        try {
            const r = await api.listTriggers(sourceDb, sourceTable);
            const list = r.triggers || [];
            const defs = await Promise.all(
                list.map(async (name) => {
                    try {
                        const res = await api.getTriggerDefinition(
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
        createTiming = "BEFORE";
        createEvent = "INSERT";
        createBody = "BEGIN\n  -- trigger body\nEND";
        showCreateModal = true;
        await tick();
        createEditor?.setValue(createBody);
    }

    function closeCreateModal() {
        if (savingCreate) return;
        showCreateModal = false;
    }

    async function createItem() {
        if (!db || !table) return;

        const targetName = createName.trim();
        if (!targetName) {
            toast("Trigger name is required", "error");
            return;
        }
        if (!createBody.trim()) {
            toast("Trigger body is required", "error");
            return;
        }

        const compiledSql = compileTriggerSql({
            name: targetName,
            timing: createTiming,
            event: createEvent,
            body: createBody,
        });

        savingCreate = true;
        try {
            await api.saveTriggerDefinition(db, table, targetName, compiledSql);
            toast(`Trigger "${targetName}" created`, "success");
            showCreateModal = false;
            await loadItems();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingCreate = false;
        }
    }

    async function openEditModal(name) {
        loadingEdit = true;
        showEditModal = true;
        editOriginalName = name;
        editName = name;
        editTiming = "BEFORE";
        editEvent = "INSERT";
        editBody = "";

        try {
            const res = await api.getTriggerDefinition(db, name);
            const parsed = parseTriggerSql(res.definition ?? "");
            editTiming = parsed.timing;
            editEvent = parsed.event;
            editBody = parsed.body;
            await tick();
            editEditor?.setValue(editBody);
        } catch (e) {
            toast(e.message, "error");
            showEditModal = false;
        } finally {
            loadingEdit = false;
        }
    }

    function closeEditModal() {
        if (savingEdit || loadingEdit) return;
        showEditModal = false;
    }

    async function saveEdited() {
        if (!db || !table) return;

        const targetName = editName.trim();
        if (!targetName) {
            toast("Trigger name is required", "error");
            return;
        }
        if (!editBody.trim()) {
            toast("Trigger body is required", "error");
            return;
        }

        const compiledSql = compileTriggerSql({
            name: targetName,
            timing: editTiming,
            event: editEvent,
            body: editBody,
        });

        savingEdit = true;
        try {
            // If renamed, drop the old trigger first
            if (targetName !== editOriginalName) {
                await api.dropTrigger(db, editOriginalName);
            }
            await api.saveTriggerDefinition(db, table, targetName, compiledSql);
            toast(`Trigger "${targetName}" updated`, "success");
            showEditModal = false;
            await loadItems();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            savingEdit = false;
        }
    }

    function openDeleteModal(name) {
        deleteName = name;
        deleteConfirm = "";
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        if (dropping) return;
        showDeleteModal = false;
    }

    async function dropItem() {
        if (!db) return;
        dropping = true;
        try {
            await api.dropTrigger(db, deleteName);
            toast(`Trigger "${deleteName}" dropped`, "success");
            showDeleteModal = false;
            await loadItems();
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
        <span
            class="flex-1 mono text-[12px] text-(--ink-1) font-semibold truncate"
            >Triggers · <span class="text-(--acc)">{table}</span></span
        >
        <Btn
            variant="primary"
            class="text-[11px] px-2.5 py-1!"
            on:click={openCreateModal}
        >
            Create Trigger
        </Btn>
        <Btn
            variant="ghost"
            class="text-[11px] px-2.5 py-1!"
            disabled={loadingItems}
            on:click={loadItems}
        >
            {loadingItems ? "Refreshing..." : "Refresh"}
        </Btn>
    </header>

    <div class="p-2.5">
        {#if loadingItems}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                Loading triggers…
            </div>
        {:else if items.length === 0}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                No triggers found on <span class="text-(--ink-1)">{table}</span
                >.
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

<!-- ─── Create Modal ──────────────────────────────────────────────────────── -->
<Modal
    open={showCreateModal}
    title={`Create Trigger · ${table}`}
    maxWidth="max-w-3xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <!-- Row 1: Name + Timing + Event -->
        <div class="grid grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Trigger Name</span
                >
                <Input
                    placeholder="e.g. before_insert_users"
                    bind:value={createName}
                    class="text-[12px] py-2!"
                />
            </label>
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Timing</span
                >
                <Select bind:value={createTiming} class="text-[12px]">
                    {#each TIMINGS as t}
                        <option value={t}>{t}</option>
                    {/each}
                </Select>
            </label>
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Event</span
                >
                <Select bind:value={createEvent} class="text-[12px]">
                    {#each EVENTS as e}
                        <option value={e}>{e}</option>
                    {/each}
                </Select>
            </label>
        </div>

        <!-- Body -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Trigger Body</span
            >
            <div class="h-56 border border-(--line) rounded overflow-hidden">
                <CodeEditor
                    bind:value={createBody}
                    bind:this={createEditor}
                    mode="sql"
                />
            </div>
        </div>
    </div>
    <div
        class="px-5 py-3 border-t border-(--line) flex justify-end gap-2 shrink-0"
    >
        <Btn variant="ghost" on:click={closeCreateModal} disabled={savingCreate}
            >Cancel</Btn
        >
        <Btn
            variant="primary"
            on:click={createItem}
            disabled={savingCreate || !createName.trim()}
        >
            {savingCreate ? "Creating…" : "Create Trigger"}
        </Btn>
    </div>
</Modal>

<!-- ─── Edit Modal ────────────────────────────────────────────────────────── -->
<Modal
    open={showEditModal}
    title={`Edit Trigger · ${editOriginalName}`}
    maxWidth="max-w-3xl"
    on:close={closeEditModal}
>
    {#if loadingEdit}
        <div class="p-8 text-center mono text-(--ink-3) text-[11.5px]">
            Loading trigger definition…
        </div>
    {:else}
        <div class="p-5 flex flex-col gap-3.5">
            <!-- Row 1: Name + Timing + Event -->
            <div class="grid grid-cols-3 gap-3">
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Trigger Name</span
                    >
                    <Input bind:value={editName} class="text-[12px] py-2!" />
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Timing</span
                    >
                    <Select bind:value={editTiming} class="text-[12px]">
                        {#each TIMINGS as t}
                            <option value={t}>{t}</option>
                        {/each}
                    </Select>
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Event</span
                    >
                    <Select bind:value={editEvent} class="text-[12px]">
                        {#each EVENTS as e}
                            <option value={e}>{e}</option>
                        {/each}
                    </Select>
                </label>
            </div>

            <!-- Body -->
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Trigger Body</span
                >
                <div
                    class="h-56 border border-(--line) rounded overflow-hidden"
                >
                    <CodeEditor
                        bind:value={editBody}
                        bind:this={editEditor}
                        mode="sql"
                    />
                </div>
            </div>
        </div>
        <div
            class="px-5 py-3 border-t border-(--line) flex justify-end gap-2 shrink-0"
        >
            <Btn variant="ghost" on:click={closeEditModal} disabled={savingEdit}
                >Cancel</Btn
            >
            <Btn
                variant="primary"
                on:click={saveEdited}
                disabled={savingEdit || !editName.trim()}
            >
                {savingEdit ? "Saving…" : "Save Changes"}
            </Btn>
        </div>
    {/if}
</Modal>

<!-- ─── Delete Modal ──────────────────────────────────────────────────────── -->
<Modal
    open={showDeleteModal}
    title={`Drop Trigger · ${deleteName}`}
    maxWidth="max-w-sm"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-(--ink-1) text-[13px]">
            This will permanently drop the trigger
            <strong class="mono">{deleteName}</strong>. Type the trigger name to
            confirm.
        </p>
        <Input
            placeholder={deleteName}
            bind:value={deleteConfirm}
            class="text-[12px] py-2!"
        />
    </div>
    <div
        class="px-5 py-3 border-t border-(--line) flex justify-end gap-2 shrink-0"
    >
        <Btn variant="ghost" on:click={closeDeleteModal} disabled={dropping}
            >Cancel</Btn
        >
        <Btn
            variant="danger"
            on:click={dropItem}
            disabled={dropping || deleteConfirm !== deleteName}
        >
            {dropping ? "Dropping…" : "Drop Trigger"}
        </Btn>
    </div>
</Modal>
