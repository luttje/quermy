<script>
    import { createEventDispatcher, onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import SearchableSelect from "../components/ui/SearchableSelect.svelte";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";

    export let db = "";
    export let capabilities = {};

    const dispatch = createEventDispatcher();

    let info = null;
    let loading = true;

    // rename
    let newName = "";
    let renaming = false;

    // collation
    let newCollation = "";
    let changingCollation = false;
    let collations = [];

    // drop
    let dropConfirm = "";
    let dropping = false;

    onMount(async () => {
        try {
            info = await api.getDatabaseInfo(db);
            newName = db;
            newCollation = info.collation ?? "";
            if (capabilities?.supportsAlterDatabaseCollation) {
                try {
                    const r = await api.listDatabaseCollations(db);
                    collations = r.collations || [];
                } catch (_) {
                    // non-fatal: keep freeform input without suggestions
                }
            }
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

    async function handleRename() {
        if (!newName.trim()) return;
        renaming = true;
        try {
            await api.renameDatabase(db, newName.trim());
            toast(`Renamed "${db}" to "${newName.trim()}"`, "success");
            dispatch("renamed", { oldName: db, newName: newName.trim() });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            renaming = false;
        }
    }

    async function handleCollation() {
        if (!newCollation.trim()) return;
        changingCollation = true;
        try {
            await api.alterDatabaseCollation(db, newCollation.trim());
            info = { ...info, collation: newCollation.trim() };
            toast("Collation updated", "success");
            dispatch("collationChanged", {
                db,
                collation: newCollation.trim(),
            });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            changingCollation = false;
        }
    }

    async function handleDrop() {
        if (dropConfirm !== db) return;
        dropping = true;
        try {
            await api.dropDatabase(db);
            toast(`Database "${db}" dropped`, "success");
            dispatch("dropped", { db });
        } catch (e) {
            toast(e.message, "error");
            dropping = false;
        }
    }
</script>

<div class="flex flex-col gap-5 px-5 py-3.5">
    <!-- header -->
    <div class="flex flex-col gap-0.5">
        <h2 class="text-(--ink-0) text-[14px] font-semibold mono">{db}</h2>
        {#if loading}
            <p class="text-(--ink-3) text-[11.5px]">Loading…</p>
        {:else if info}
            <p class="text-(--ink-3) text-[11.5px]">
                {#if info.charset}
                    charset: <span class="mono muted">{info.charset}</span>
                {/if}
                {#if info.charset && info.collation}
                    ·
                {/if}
                {#if info.collation}
                    collation: <span class="mono muted">{info.collation}</span>
                {/if}
            </p>
        {/if}
    </div>

    <!-- rename -->
    {#if capabilities.supportsRenameDatabase}
        <section class="flex flex-col gap-2">
            <h3
                class="text-(--ink-1) text-[12px] font-medium uppercase tracking-wide"
            >
                Rename
            </h3>
            <div class="flex gap-2">
                <Input
                    type="text"
                    placeholder="New database name"
                    bind:value={newName}
                    on:keydown={(e) => e.key === "Enter" && handleRename()}
                />
                <Btn
                    variant="primary"
                    disabled={!newName.trim() || renaming}
                    on:click={handleRename}
                >
                    {renaming ? "Renaming…" : "Rename"}
                </Btn>
            </div>
        </section>
    {/if}

    <!-- collation -->
    {#if capabilities.supportsAlterDatabaseCollation}
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

    <!-- drop -->
    {#if capabilities.supportsDropDatabase}
        <section
            class="flex flex-col gap-2 border border-red-900/40 rounded p-3"
        >
            <h3
                class="text-red-400 text-[12px] font-medium uppercase tracking-wide"
            >
                Danger zone
            </h3>
            <p class="text-(--ink-3) text-[11.5px]">
                This will permanently delete the database and all its data. Type <span
                    class="mono text-(--ink-1)">{db}</span
                > to confirm.
            </p>
            <div class="flex gap-2">
                <Input
                    type="text"
                    placeholder="Type database name to confirm"
                    bind:value={dropConfirm}
                />
                <Btn
                    variant="danger"
                    disabled={dropConfirm !== db || dropping}
                    on:click={handleDrop}
                >
                    {dropping ? "Dropping…" : "Drop"}
                </Btn>
            </div>
        </section>
    {/if}

    <div class="flex justify-end">
        <Btn variant="secondary" on:click={() => dispatch("close")}>Close</Btn>
    </div>
</div>
