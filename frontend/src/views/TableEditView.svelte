<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import SearchableSelect from "../components/ui/SearchableSelect.svelte";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";
    import Checkbox from "../components/ui/Checkbox.svelte";

    export let db = "";
    export let table = "";
    export let capabilities = {};
    /** @type {'edit' | 'create'} */
    export let mode = "edit";

    const dispatch = createEventDispatcher();

    let info = null;
    let loading = true;

    // collation
    let newCollation = "";
    let changingCollation = false;
    let collations = [];

    // engine
    let newEngine = "";
    let changingEngine = false;
    let engines = [];

    // auto-increment
    let newAutoIncrement = "";
    let changingAutoIncrement = false;

    // drop / truncate
    let dropConfirm = "";
    let dropping = false;
    let truncateConfirm = "";
    let truncating = false;
    let ignoreForeignKeys = false;

    // create mode
    let newTableName = "";
    let creating = false;

    onMount(async () => {
        try {
            if (mode === "create") {
                await Promise.allSettled([
                    capabilities?.supportsAlterTableCollation
                        ? api.listDatabaseCollations(db).then((r) => {
                              collations = r.collations || [];
                          })
                        : Promise.resolve(),
                    capabilities?.supportsAlterTableEngine
                        ? api.listDatabaseEngines(db).then((r) => {
                              engines = r.engines || [];
                          })
                        : Promise.resolve(),
                ]);
            } else {
                info = await api.getTableInfo(db, table);
                newCollation = info.collation ?? "";
                newEngine = info.engine ?? "";
                newAutoIncrement = info.autoIncrement != null ? String(info.autoIncrement) : "";

                await Promise.allSettled([
                    capabilities?.supportsAlterTableCollation
                        ? api.listTableCollations(db, table).then((r) => {
                              collations = r.collations || [];
                          })
                        : Promise.resolve(),
                    capabilities?.supportsAlterTableEngine
                        ? api.listTableEngines(db, table).then((r) => {
                              engines = r.engines || [];
                          })
                        : Promise.resolve(),
                ]);
            }
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

    async function handleCreate() {
        const name = newTableName.trim();
        if (!name) return;
        creating = true;
        try {
            await api.createTable(db, name, newCollation || null, newEngine || null);
            toast(`Table "${name}" created`, "success");
            dispatch("created", { db, table: name });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            creating = false;
        }
    }

    async function handleCollation() {
        if (!newCollation.trim()) return;
        changingCollation = true;
        try {
            await api.alterTableCollation(db, table, newCollation.trim());
            info = { ...info, collation: newCollation.trim() };
            toast("Collation updated", "success");
            dispatch("changed", { db, table });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            changingCollation = false;
        }
    }

    async function handleEngine() {
        if (!newEngine.trim()) return;
        changingEngine = true;
        try {
            await api.alterTableEngine(db, table, newEngine.trim());
            info = { ...info, engine: newEngine.trim() };
            toast("Engine updated", "success");
            dispatch("changed", { db, table });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            changingEngine = false;
        }
    }

    async function handleTruncate() {
        if (truncateConfirm !== table) return;
        truncating = true;
        try {
            await api.truncateTable(db, table, ignoreForeignKeys);
            toast(`Table "${table}" truncated`, "success");
            truncateConfirm = "";
            dispatch("changed", { db, table });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            truncating = false;
        }
    }

    async function handleDrop() {
        if (dropConfirm !== table) return;
        dropping = true;
        try {
            await api.dropTable(db, table, ignoreForeignKeys);
            toast(`Table "${table}" dropped`, "success");
            dispatch("dropped", { db, table });
        } catch (e) {
            toast(e.message, "error");
            dropping = false;
        }
    }

    async function handleAutoIncrement() {
        const val = parseInt(newAutoIncrement, 10);
        if (isNaN(val) || val < 1) return;
        changingAutoIncrement = true;
        try {
            await api.alterTableAutoIncrement(db, table, val);
            info = { ...info, autoIncrement: val };
            toast("Auto-increment updated", "success");
            dispatch("changed", { db, table });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            changingAutoIncrement = false;
        }
    }
</script>

{#if mode === "create"}
    <div class="flex flex-col gap-5 px-5 py-3.5">
        <!-- header -->
        <div class="flex flex-col gap-0.5">
            <h2 class="text-(--ink-0) text-[14px] font-semibold mono">{db}</h2>
            <p class="text-(--ink-3) text-[11.5px]">New table</p>
        </div>

        <!-- name -->
        <section class="flex flex-col gap-2">
            <h3 class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide">
                Table name
            </h3>
            <Input
                type="text"
                placeholder="table_name"
                bind:value={newTableName}
                on:keydown={(e) => e.key === "Enter" && handleCreate()}
            />
        </section>

        {#if loading}
            <p class="text-(--ink-3) text-[11.5px]">Loading options…</p>
        {:else}
            <!-- collation -->
            {#if capabilities.supportsAlterTableCollation && collations.length > 0}
                <section class="flex flex-col gap-2">
                    <h3 class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide">
                        Collation
                    </h3>
                    <SearchableSelect
                        bind:value={newCollation}
                        options={collations}
                        placeholder="Default"
                        searchPlaceholder="Filter collations…"
                        clearable
                    />
                </section>
            {/if}

            <!-- engine -->
            {#if capabilities.supportsAlterTableEngine && engines.length > 0}
                <section class="flex flex-col gap-2">
                    <h3 class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide">
                        Storage Engine
                    </h3>
                    <SearchableSelect
                        bind:value={newEngine}
                        options={engines}
                        placeholder="Default"
                        searchPlaceholder="Filter engines…"
                        clearable
                    />
                </section>
            {/if}
        {/if}

        <div class="flex justify-end gap-2">
            <Btn variant="secondary" on:click={() => dispatch("close")}>Cancel</Btn>
            <Btn
                variant="primary"
                disabled={!newTableName.trim() || creating}
                on:click={handleCreate}
            >
                {creating ? "Creating…" : "Create table"}
            </Btn>
        </div>
    </div>
{:else}
    <div class="flex flex-col gap-5 px-5 py-3.5">
        <!-- header -->
        <div class="flex flex-col gap-0.5">
            <h2 class="text-(--ink-0) text-[14px] font-semibold mono">{db}.{table}</h2>
            {#if loading}
                <p class="text-(--ink-3) text-[11.5px]">Loading…</p>
            {:else if info}
                <p class="text-(--ink-3) text-[11.5px]">
                    {#if info.engine}
                        engine: <span class="mono muted">{info.engine}</span>
                    {/if}
                    {#if info.engine && info.collation}
                        ·
                    {/if}
                    {#if info.collation}
                        collation: <span class="mono muted">{info.collation}</span>
                    {/if}
                    {#if (info.engine || info.collation) && info.autoIncrement != null}
                        ·
                    {/if}
                    {#if info.autoIncrement != null}
                        auto_increment: <span class="mono muted">{info.autoIncrement}</span>
                    {/if}
                </p>
            {/if}
        </div>

        <!-- collation -->
        {#if capabilities.supportsAlterTableCollation}
            <section class="flex flex-col gap-2">
                <h3
                    class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide"
                >
                    Collation
                </h3>
                <div class="flex gap-2">
                    <SearchableSelect
                        class="flex-1"
                        bind:value={newCollation}
                        options={collations}
                        placeholder={collations.length > 0 ? collations[0] : ""}
                        searchPlaceholder="Filter collations…"
                        clearable
                    />
                    <Btn
                        variant="primary"
                        disabled={!newCollation.trim() || changingCollation}
                        on:click={handleCollation}
                    >
                        {changingCollation ? "Saving…" : "Apply"}
                    </Btn>
                </div>
            </section>
        {/if}

        <!-- engine -->
        {#if capabilities.supportsAlterTableEngine}
            <section class="flex flex-col gap-2">
                <h3
                    class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide"
                >
                    Storage Engine
                </h3>
                <div class="flex gap-2">
                    <SearchableSelect
                        class="flex-1"
                        bind:value={newEngine}
                        options={engines}
                        placeholder={engines.length > 0 ? engines[0] : ""}
                        searchPlaceholder="Filter engines…"
                        clearable
                    />
                    <Btn
                        variant="primary"
                        disabled={!newEngine.trim() || changingEngine}
                        on:click={handleEngine}
                    >
                        {changingEngine ? "Saving…" : "Apply"}
                    </Btn>
                </div>
            </section>
        {/if}

        <!-- auto-increment -->
        {#if capabilities.supportsAlterTableAutoIncrement && info?.autoIncrement != null}
            <section class="flex flex-col gap-2">
                <h3
                    class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide"
                >
                    Auto-increment
                </h3>
                <p class="text-(--ink-3) text-[11.5px]">
                    Sets the next value for auto-increment columns. Must be greater than
                    the current maximum.
                </p>
                <div class="flex gap-2">
                    <Input
                        type="number"
                        min="1"
                        placeholder="Next auto-increment value"
                        bind:value={newAutoIncrement}
                        on:keydown={(e) =>
                            e.key === "Enter" && handleAutoIncrement()}
                    />
                    <Btn
                        variant="primary"
                        disabled={!newAutoIncrement ||
                            isNaN(parseInt(newAutoIncrement, 10)) ||
                            parseInt(newAutoIncrement, 10) < 1 ||
                            changingAutoIncrement}
                        on:click={handleAutoIncrement}
                    >
                        {changingAutoIncrement ? "Saving…" : "Apply"}
                    </Btn>
                </div>
            </section>
        {/if}

        <!-- danger zone -->
        {#if capabilities.supportsDropTable || capabilities.supportsTruncateTable}
            <section
                class="flex flex-col gap-3 border border-red-900/40 rounded p-3"
            >
                <h3
                    class="text-red-400 text-[12px] font-medium uppercase tracking-wide"
                >
                    Danger zone
                </h3>

                {#if capabilities.supportsForeignKeyBypass}
                    <label
                        class="flex items-center gap-2 muted text-[12px] cursor-pointer select-none"
                    >
                        <Checkbox bind:checked={ignoreForeignKeys} />
                        Ignore foreign key constraints
                    </label>
                {/if}

                {#if capabilities.supportsTruncateTable}
                    <div class="flex flex-col gap-1.5">
                        <p class="text-(--ink-3) text-[11.5px]">
                            Truncate removes all rows but keeps the table structure.
                            Type <span class="mono text-(--ink-1)">{table}</span> to
                            confirm.
                        </p>
                        <div class="flex gap-2">
                            <Input
                                type="text"
                                placeholder="Type table name to confirm"
                                bind:value={truncateConfirm}
                                on:keydown={(e) =>
                                    e.key === "Enter" && handleTruncate()}
                            />
                            <Btn
                                variant="danger"
                                disabled={truncateConfirm !== table || truncating}
                                on:click={handleTruncate}
                            >
                                {truncating ? "Truncating…" : "Truncate"}
                            </Btn>
                        </div>
                    </div>
                {/if}

                {#if capabilities.supportsDropTable}
                    <div class="flex flex-col gap-1.5">
                        <p class="text-(--ink-3) text-[11.5px]">
                            Drop permanently deletes the table and all its data. Type
                            <span class="mono text-(--ink-1)">{table}</span> to confirm.
                        </p>
                        <div class="flex gap-2">
                            <Input
                                type="text"
                                placeholder="Type table name to confirm"
                                bind:value={dropConfirm}
                                on:keydown={(e) =>
                                    e.key === "Enter" && handleDrop()}
                            />
                            <Btn
                                variant="danger"
                                disabled={dropConfirm !== table || dropping}
                                on:click={handleDrop}
                            >
                                {dropping ? "Dropping…" : "Drop"}
                            </Btn>
                        </div>
                    </div>
                {/if}
            </section>
        {/if}

        <div class="flex justify-end">
            <Btn variant="secondary" on:click={() => dispatch("close")}>Close</Btn>
        </div>
    </div>
{/if}
