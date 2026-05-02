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
    export let capabilities = {};
    export let databases = [];

    let loadingItems = true;
    let savingCreate = false;
    let loadingEdit = false;
    let savingEdit = false;
    let dropping = false;

    let items = [];
    let definitions = {};
    let lastLoadedDb = null;
    let loadToken = 0;

    const INTERVAL_UNITS = [
        "SECOND",
        "MINUTE",
        "HOUR",
        "DAY",
        "WEEK",
        "MONTH",
        "QUARTER",
        "YEAR",
    ];

    // Create modal
    let showCreateModal = false;
    let createName = "";
    let createSchema = "";
    let createDefiner = "CURRENT_USER";
    let createStatus = "ENABLED";
    let createScheduleType = "RECURRING";
    let createExecuteAt = "";
    let createStartTime = "";
    let createEndTime = "";
    let createIntervalValue = "1";
    let createIntervalUnit = "HOUR";
    let createCompletion = "NOT PRESERVE";
    let createComment = "";
    let createBody = "BEGIN\n  -- event body\nEND";
    let createEditor;

    // Edit modal
    let showEditModal = false;
    let editName = "";
    let editDefiner = "CURRENT_USER";
    let editStatus = "ENABLED";
    let editScheduleType = "RECURRING";
    let editExecuteAt = "";
    let editStartTime = "";
    let editEndTime = "";
    let editIntervalValue = "1";
    let editIntervalUnit = "HOUR";
    let editCompletion = "NOT PRESERVE";
    let editComment = "";
    let editBody = "";
    let editEditor;

    // Delete modal
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

    /** Convert datetime-local value (2024-01-01T14:30) to MySQL timestamp string. */
    function dtToSql(dt) {
        if (!dt) return null;
        const base = dt.replace("T", " ");
        // datetime-local has at most HH:MM; append :00 seconds if needed
        return base.includes(":") && base.split(":").length < 3
            ? base + ":00"
            : base;
    }

    /** Convert MySQL timestamp string to datetime-local value. */
    function sqlToDt(sql) {
        if (!sql) return "";
        return sql.replace(" ", "T").replace(/:\d{2}$/, "");
    }

    /**
     * Parse a full CREATE EVENT SQL statement (from SHOW CREATE EVENT) into
     * structured fields. Best-effort; returns safe defaults on parse failure.
     */
    function parseEventSql(sql) {
        const result = {
            definer: "CURRENT_USER",
            scheduleType: "RECURRING",
            executeAt: "",
            startTime: "",
            endTime: "",
            intervalValue: "1",
            intervalUnit: "HOUR",
            completion: "NOT PRESERVE",
            status: "ENABLED",
            comment: "",
            body: "BEGIN\n  -- event body\nEND",
        };
        if (!sql) return result;

        // DEFINER
        const definerM = sql.match(
            /CREATE\s+DEFINER\s*=\s*((?:`[^`]*`|'[^']*')@(?:`[^`]*`|'[^']*')|\S+)\s+EVENT\b/i,
        );
        if (definerM) result.definer = definerM[1].trim();

        // AT schedule
        const atM = sql.match(/\bON\s+SCHEDULE\s+AT\s+'([^']+)'/i);
        if (atM) {
            result.scheduleType = "ONE TIME";
            result.executeAt = sqlToDt(atM[1]);
        } else {
            // EVERY schedule
            const everyM = sql.match(
                /\bON\s+SCHEDULE\s+EVERY\s+(\d+)\s+(\w+)([\s\S]*?)(?=\s*(?:ON\s+COMPLETION|\bENABLE\b|\bDISABLE\b|\bCOMMENT\b|\bDO\b))/i,
            );
            if (everyM) {
                result.intervalValue = everyM[1];
                result.intervalUnit = everyM[2].toUpperCase();
                const rest = everyM[3];
                const startsM = rest.match(/\bSTARTS\s+'([^']+)'/i);
                if (startsM) result.startTime = sqlToDt(startsM[1]);
                const endsM = rest.match(/\bENDS\s+'([^']+)'/i);
                if (endsM) result.endTime = sqlToDt(endsM[1]);
            }
        }

        // ON COMPLETION
        const compM = sql.match(/\bON\s+COMPLETION\s+(NOT\s+PRESERVE|PRESERVE)\b/i);
        if (compM)
            result.completion = compM[1].toUpperCase().replace(/\s+/g, " ");

        // ENABLE / DISABLE (ignore DISABLE ON REPLICA/SLAVE)
        if (/\bENABLE\b/i.test(sql)) result.status = "ENABLED";
        else if (/\bDISABLE\b/i.test(sql)) result.status = "DISABLED";

        // COMMENT
        const commentM = sql.match(/\bCOMMENT\s+'((?:[^'\\]|\\.)*)'/i);
        if (commentM)
            result.comment = commentM[1]
                .replace(/\\'/g, "'")
                .replace(/\\\\/g, "\\");

        // DO body — everything after the DO keyword
        const doM = sql.match(/\bDO\b\s+([\s\S]*)$/i);
        if (doM) result.body = doM[1].trim().replace(/;?\s*$/, "");

        return result;
    }

    /** Build the full CREATE EVENT SQL from structured fields + DO body. */
    function compileEventSql(fields) {
        const qName = quoteIdent(fields.name || "event_name");
        const qSchema = fields.schema ? quoteIdent(fields.schema) : null;
        const qualName = qSchema ? `${qSchema}.${qName}` : qName;

        const definerClause = fields.definer
            ? `DEFINER = ${fields.definer} `
            : "";

        let schedule;
        if (fields.scheduleType === "ONE TIME") {
            const at = fields.executeAt
                ? `'${dtToSql(fields.executeAt)}'`
                : "NOW() + INTERVAL 1 HOUR";
            schedule = `AT ${at}`;
        } else {
            schedule = `EVERY ${fields.intervalValue || 1} ${fields.intervalUnit}`;
            if (fields.startTime)
                schedule += ` STARTS '${dtToSql(fields.startTime)}'`;
            if (fields.endTime)
                schedule += ` ENDS '${dtToSql(fields.endTime)}'`;
        }

        const commentLine = fields.comment
            ? `COMMENT '${fields.comment.replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'\n`
            : "";

        const cleanBody = (fields.body || "-- event body").trim();

        const statusKeyword = fields.status === "DISABLED" ? "DISABLE" : "ENABLE";

        return [
            `CREATE ${definerClause}EVENT ${qualName}`,
            `ON SCHEDULE ${schedule}`,
            `ON COMPLETION ${fields.completion}`,
            statusKeyword,
            commentLine ? commentLine.trim() : null,
            `DO ${cleanBody}`,
        ]
            .filter(Boolean)
            .join("\n");
    }

    function resetState() {
        items = [];
        definitions = {};

        showCreateModal = false;
        createName = "";
        createSchema = "";
        createDefiner = "CURRENT_USER";
        createStatus = "ENABLED";
        createScheduleType = "RECURRING";
        createExecuteAt = "";
        createStartTime = "";
        createEndTime = "";
        createIntervalValue = "1";
        createIntervalUnit = "HOUR";
        createCompletion = "NOT PRESERVE";
        createComment = "";
        createBody = "BEGIN\n  -- event body\nEND";

        showEditModal = false;
        editName = "";
        editDefiner = "CURRENT_USER";
        editStatus = "ENABLED";
        editScheduleType = "RECURRING";
        editExecuteAt = "";
        editStartTime = "";
        editEndTime = "";
        editIntervalValue = "1";
        editIntervalUnit = "HOUR";
        editCompletion = "NOT PRESERVE";
        editComment = "";
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
        if (!db) return;

        const token = ++loadToken;
        const sourceDb = db;
        loadingItems = true;

        try {
            const r = await api.listEvents(sourceDb);
            const list = r.events || [];
            const defs = await Promise.all(
                list.map(async (name) => {
                    try {
                        const res = await api.getEventDefinition(
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
        createSchema = db;
        createDefiner = "CURRENT_USER";
        createStatus = "ENABLED";
        createScheduleType = "RECURRING";
        createExecuteAt = "";
        createStartTime = "";
        createEndTime = "";
        createIntervalValue = "1";
        createIntervalUnit = "HOUR";
        createCompletion = "NOT PRESERVE";
        createComment = "";
        createBody = "BEGIN\n  -- event body\nEND";
        showCreateModal = true;
        await tick();
        createEditor?.setValue(createBody);
    }

    function closeCreateModal() {
        if (savingCreate) return;
        showCreateModal = false;
    }

    async function createItem() {
        if (!db) return;

        const targetName = createName.trim();
        if (!targetName) {
            toast("Event name is required", "error");
            return;
        }
        if (!createBody.trim()) {
            toast("Event body is required", "error");
            return;
        }
        if (createScheduleType === "ONE TIME" && !createExecuteAt) {
            toast("Execute At is required for a one-time event", "error");
            return;
        }

        const compiledSql = compileEventSql({
            name: targetName,
            schema: createSchema || db,
            definer: createDefiner,
            status: createStatus,
            scheduleType: createScheduleType,
            executeAt: createExecuteAt,
            startTime: createStartTime,
            endTime: createEndTime,
            intervalValue: createIntervalValue,
            intervalUnit: createIntervalUnit,
            completion: createCompletion,
            comment: createComment,
            body: createBody,
        });

        const existed = items.includes(targetName);
        savingCreate = true;
        try {
            await api.saveEventDefinition(
                createSchema || db,
                targetName,
                compiledSql,
            );
            toast(
                existed
                    ? `Event "${targetName}" updated`
                    : `Event "${targetName}" created`,
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
        // Apply defaults while loading
        editDefiner = "CURRENT_USER";
        editStatus = "ENABLED";
        editScheduleType = "RECURRING";
        editExecuteAt = "";
        editStartTime = "";
        editEndTime = "";
        editIntervalValue = "1";
        editIntervalUnit = "HOUR";
        editCompletion = "NOT PRESERVE";
        editComment = "";
        editBody = "BEGIN\n  -- event body\nEND";
        showEditModal = true;
        loadingEdit = true;

        await tick();
        editEditor?.setValue(editBody);

        try {
            const r = await api.getEventDefinition(db, name);
            const parsed = parseEventSql(r.definition ?? "");

            editDefiner = parsed.definer;
            editStatus = parsed.status;
            editScheduleType = parsed.scheduleType;
            editExecuteAt = parsed.executeAt;
            editStartTime = parsed.startTime;
            editEndTime = parsed.endTime;
            editIntervalValue = parsed.intervalValue;
            editIntervalUnit = parsed.intervalUnit;
            editCompletion = parsed.completion;
            editComment = parsed.comment;
            editBody = parsed.body;

            definitions = { ...definitions, [name]: r.definition ?? "" };

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

    async function saveEdited() {
        if (!db || !editName) return;
        if (!editBody.trim()) {
            toast("Event body is required", "error");
            return;
        }
        if (editScheduleType === "ONE TIME" && !editExecuteAt) {
            toast("Execute At is required for a one-time event", "error");
            return;
        }

        const compiledSql = compileEventSql({
            name: editName,
            schema: db,
            definer: editDefiner,
            status: editStatus,
            scheduleType: editScheduleType,
            executeAt: editExecuteAt,
            startTime: editStartTime,
            endTime: editEndTime,
            intervalValue: editIntervalValue,
            intervalUnit: editIntervalUnit,
            completion: editCompletion,
            comment: editComment,
            body: editBody,
        });

        savingEdit = true;
        try {
            await api.saveEventDefinition(db, editName, compiledSql);
            definitions = { ...definitions, [editName]: compiledSql };
            toast(`Event "${editName}" saved`, "success");
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
            await api.dropEvent(db, deleteName);
            toast(`Dropped event "${deleteName}"`, "success");
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
        <div class="flex flex-col">
            <h2 class="mono text-(--ink-0) text-[12.5px]">Events · {db}</h2>
            <p class="mono text-(--ink-3) text-[10.5px]">
                Browse, edit, and delete scheduled events.
            </p>
        </div>
        <div class="flex items-center gap-1.5">
            <Btn
                variant="primary"
                class="text-[11px] px-2.5 py-1!"
                on:click={openCreateModal}
            >
                Create Event
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
                Loading events…
            </div>
        {:else if items.length === 0}
            <div
                class="px-2 py-4 text-center mono text-(--ink-3) text-[11.5px]"
            >
                No events found in {db}.
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
    title={`Create Event · ${db}`}
    maxWidth="max-w-5xl"
    on:close={closeCreateModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <!-- Row 1: Name + Schema + Definer -->
        <div class="grid grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Event Name</span
                >
                <Input
                    placeholder="e.g. cleanup_old_records"
                    bind:value={createName}
                    class="text-[12px] py-2!"
                />
            </label>
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Schema</span
                >
                <Select bind:value={createSchema} class="text-[12px]">
                    {#each databases.length ? databases : [db] as d}
                        <option value={d}>{d}</option>
                    {/each}
                </Select>
            </label>
            <label class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Definer</span
                >
                <Input
                    bind:value={createDefiner}
                    placeholder="CURRENT_USER"
                    class="text-[12px] py-2!"
                />
            </label>
        </div>

        <!-- Row 2: Status + ON COMPLETION -->
        <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Status</span
                >
                <div class="flex gap-4 py-2.5">
                    {#each ["ENABLED", "DISABLED"] as v}
                        <label
                            class="flex items-center gap-1.5 cursor-pointer select-none"
                        >
                            <input
                                type="radio"
                                bind:group={createStatus}
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
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >ON COMPLETION</span
                >
                <div class="flex gap-4 py-2.5">
                    {#each ["PRESERVE", "NOT PRESERVE"] as v}
                        <label
                            class="flex items-center gap-1.5 cursor-pointer select-none"
                        >
                            <input
                                type="radio"
                                bind:group={createCompletion}
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

        <!-- Row 3: Schedule Type -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Schedule Type</span
            >
            <div class="flex gap-4 py-1">
                {#each ["RECURRING", "ONE TIME"] as v}
                    <label
                        class="flex items-center gap-1.5 cursor-pointer select-none"
                    >
                        <input
                            type="radio"
                            bind:group={createScheduleType}
                            value={v}
                            class="accent-(--acc) cursor-pointer"
                        />
                        <span class="mono text-[12px] text-(--ink-1)">{v}</span
                        >
                    </label>
                {/each}
            </div>
        </div>

        <!-- ONE TIME: Execute At -->
        {#if createScheduleType === "ONE TIME"}
            <label class="flex flex-col gap-1 max-w-72">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Execute At</span
                >
                <Input
                    type="datetime-local"
                    bind:value={createExecuteAt}
                    class="text-[12px] py-2!"
                />
            </label>
        {/if}

        <!-- RECURRING: Start / End / Interval -->
        {#if createScheduleType === "RECURRING"}
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Start Time <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
                    >
                    <Input
                        type="datetime-local"
                        bind:value={createStartTime}
                        class="text-[12px] py-2!"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >End Time <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
                    >
                    <Input
                        type="datetime-local"
                        bind:value={createEndTime}
                        class="text-[12px] py-2!"
                    />
                </label>
            </div>
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Interval</span
                >
                <div class="flex gap-2">
                    <Input
                        type="number"
                        bind:value={createIntervalValue}
                        class="text-[12px] py-2! w-24"
                        placeholder="1"
                    />
                    <Select
                        bind:value={createIntervalUnit}
                        class="text-[12px] w-36"
                    >
                        {#each INTERVAL_UNITS as u}
                            <option value={u}>{u}</option>
                        {/each}
                    </Select>
                </div>
            </div>
        {/if}

        <!-- Comment -->
        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Comment <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
            >
            <Input
                bind:value={createComment}
                placeholder="Describe the purpose of this event"
                class="text-[12px] py-2!"
            />
        </label>

        <!-- DO body -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Event Body (DO)</span
            >
            <div
                class="h-56 bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={createBody}
                    bind:this={createEditor}
                    placeholder="BEGIN&#10;  -- event body&#10;END"
                    minHeight="200px"
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
            on:click={createItem}
        >
            {savingCreate ? "Creating…" : "Create Event"}
        </Btn>
    </div>
</Modal>

<!-- ─── Edit Modal ────────────────────────────────────────────────────────── -->
<Modal
    open={showEditModal}
    title={`Edit Event · ${editName}`}
    maxWidth="max-w-5xl"
    on:close={closeEditModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <div class="flex items-center justify-between gap-2">
            <div class="mono text-[11px] text-(--ink-2)">
                Editing <span class="text-(--ink-0)">{editName}</span>
                <span class="text-(--ink-3)">in {db}</span>
            </div>
            {#if loadingEdit}
                <div class="mono text-[10px] text-(--ink-3)">
                    Parsing event definition…
                </div>
            {/if}
        </div>

        <!-- Row 1: Definer -->
        <label class="flex flex-col gap-1 max-w-72">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Definer</span
            >
            <Input
                bind:value={editDefiner}
                placeholder="CURRENT_USER"
                class="text-[12px] py-2!"
            />
        </label>

        <!-- Row 2: Status + ON COMPLETION -->
        <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Status</span
                >
                <div class="flex gap-4 py-2.5">
                    {#each ["ENABLED", "DISABLED"] as v}
                        <label
                            class="flex items-center gap-1.5 cursor-pointer select-none"
                        >
                            <input
                                type="radio"
                                bind:group={editStatus}
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
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >ON COMPLETION</span
                >
                <div class="flex gap-4 py-2.5">
                    {#each ["PRESERVE", "NOT PRESERVE"] as v}
                        <label
                            class="flex items-center gap-1.5 cursor-pointer select-none"
                        >
                            <input
                                type="radio"
                                bind:group={editCompletion}
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

        <!-- Schedule Type -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Schedule Type</span
            >
            <div class="flex gap-4 py-1">
                {#each ["RECURRING", "ONE TIME"] as v}
                    <label
                        class="flex items-center gap-1.5 cursor-pointer select-none"
                    >
                        <input
                            type="radio"
                            bind:group={editScheduleType}
                            value={v}
                            class="accent-(--acc) cursor-pointer"
                        />
                        <span class="mono text-[12px] text-(--ink-1)">{v}</span
                        >
                    </label>
                {/each}
            </div>
        </div>

        <!-- ONE TIME -->
        {#if editScheduleType === "ONE TIME"}
            <label class="flex flex-col gap-1 max-w-72">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Execute At</span
                >
                <Input
                    type="datetime-local"
                    bind:value={editExecuteAt}
                    class="text-[12px] py-2!"
                />
            </label>
        {/if}

        <!-- RECURRING -->
        {#if editScheduleType === "RECURRING"}
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Start Time <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
                    >
                    <Input
                        type="datetime-local"
                        bind:value={editStartTime}
                        class="text-[12px] py-2!"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span
                        class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >End Time <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
                    >
                    <Input
                        type="datetime-local"
                        bind:value={editEndTime}
                        class="text-[12px] py-2!"
                    />
                </label>
            </div>
            <div class="flex flex-col gap-1">
                <span
                    class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Interval</span
                >
                <div class="flex gap-2">
                    <Input
                        type="number"
                        bind:value={editIntervalValue}
                        class="text-[12px] py-2! w-24"
                        placeholder="1"
                    />
                    <Select
                        bind:value={editIntervalUnit}
                        class="text-[12px] w-36"
                    >
                        {#each INTERVAL_UNITS as u}
                            <option value={u}>{u}</option>
                        {/each}
                    </Select>
                </div>
            </div>
        {/if}

        <!-- Comment -->
        <label class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Comment <span class="normal-case tracking-normal font-normal text-(--ink-3) text-[10px]">(optional)</span></span
            >
            <Input
                bind:value={editComment}
                placeholder="Describe the purpose of this event"
                class="text-[12px] py-2!"
            />
        </label>

        <!-- DO body -->
        <div class="flex flex-col gap-1">
            <span
                class="mono text-[10px] uppercase tracking-[0.08em] text-(--ink-3)"
                >Event Body (DO)</span
            >
            <div
                class="h-56 bg-(--bg-input) border border-(--line) rounded-(--radius) overflow-hidden"
            >
                <CodeEditor
                    bind:value={editBody}
                    bind:this={editEditor}
                    placeholder="BEGIN&#10;  -- event body&#10;END"
                    minHeight="200px"
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
            on:click={saveEdited}
        >
            {savingEdit ? "Saving…" : "Save Event"}
        </Btn>
    </div>
</Modal>

<!-- ─── Delete Modal ──────────────────────────────────────────────────────── -->
<Modal
    open={showDeleteModal}
    title={`Delete Event · ${deleteName}`}
    maxWidth="max-w-md"
    on:close={closeDeleteModal}
>
    <div class="p-5 flex flex-col gap-3.5">
        <p class="text-[12px] text-(--ink-2)">
            This action is permanent. Type
            <span class="mono text-(--ink-0)">{deleteName}</span> to confirm.
        </p>
        <Input
            placeholder={deleteName ? `Type ${deleteName}` : "Type event name"}
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
            {dropping ? "Deleting…" : "Delete Event"}
        </Btn>
    </div>
</Modal>
