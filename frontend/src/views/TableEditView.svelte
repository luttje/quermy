<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import SearchableSelect from "../components/ui/SearchableSelect.svelte";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";

    export let db = "";
    export let table = "";
    export let capabilities = {};

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

    onMount(async () => {
        try {
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
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

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

    <div class="flex justify-end">
        <Btn variant="secondary" on:click={() => dispatch("close")}>Close</Btn>
    </div>
</div>
