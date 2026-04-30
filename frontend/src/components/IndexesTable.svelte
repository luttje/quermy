<script>
    import { createEventDispatcher } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let db;
    export let table;
    export let indexes = [];
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    let showAddForm = false;
    let addType = "index"; // "primary" | "unique" | "index"
    let addName = "";
    let addColumns = "";
    let adding = false;
    let dropping = null; // index name being dropped

    $: canManage = capabilities?.supportsIndexManagement ?? false;
    $: canManagePK = capabilities?.supportsPrimaryKeyManagement ?? false;

    async function handleDrop(idx) {
        if (
            !confirm(
                `Drop ${idx.primary ? "primary key" : `index "${idx.name}"`}?`,
            )
        )
            return;
        dropping = idx.name;
        try {
            await api.dropIndex(db, table, idx.name, idx.primary);
            toast(`Dropped ${idx.primary ? "primary key" : idx.name}`, "ok");
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dropping = null;
        }
    }

    async function handleAdd() {
        const cols = addColumns
            .split(",")
            .map((c) => c.trim())
            .filter(Boolean);
        if (!cols.length) {
            toast("Enter at least one column", "error");
            return;
        }
        if (addType !== "primary" && !addName.trim()) {
            toast("Index name is required", "error");
            return;
        }
        adding = true;
        try {
            await api.createIndex(db, table, {
                name: addName.trim(),
                columns: cols,
                unique: addType === "unique",
                primary: addType === "primary",
            });
            toast("Index created", "ok");
            showAddForm = false;
            addName = "";
            addColumns = "";
            addType = "index";
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            adding = false;
        }
    }

    function typeBadge(idx) {
        if (idx.primary)
            return {
                label: "PRIMARY",
                cls: "bg-[rgba(200,255,90,0.15)] text-(--acc)",
            };
        if (idx.unique)
            return {
                label: "UNIQUE",
                cls: "bg-[rgba(127,200,255,0.15)] text-[var(--ink-1)]",
            };
        return {
            label: "INDEX",
            cls: "bg-[rgba(127,127,127,0.12)] text-(--ink-2)",
        };
    }
</script>

<div class="flex flex-col gap-2.5 min-h-0">
    <!-- Header -->
    <div class="flex items-center justify-between gap-2">
        <span
            class="mono text-[11px] text-(--ink-3) tracking-[0.06em] uppercase"
            >Indexes · {table}</span
        >
        {#if canManage}
            <button
                class="text-[11.5px] px-2.5 py-1 rounded border border-(--line) bg-(--bg-1) text-(--ink-1) hover:text-(--ink-0) hover:bg-(--bg-2) transition-colors cursor-pointer"
                on:click={() => (showAddForm = !showAddForm)}
            >
                {showAddForm ? "Cancel" : "+ Add Index"}
            </button>
        {/if}
    </div>

    <!-- Add form -->
    {#if showAddForm && canManage}
        <div
            class="bg-(--bg-1) border border-(--line) rounded-lg p-3 flex flex-col gap-2"
        >
            <div class="flex gap-2 flex-wrap">
                <div class="flex flex-col gap-1 flex-1 min-w-[120px]">
                    <label class="text-[11px] text-(--ink-3)">Type</label>
                    <select
                        bind:value={addType}
                        class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0)"
                    >
                        {#if canManagePK}
                            <option value="primary">PRIMARY KEY</option>
                        {/if}
                        <option value="unique">UNIQUE INDEX</option>
                        <option value="index">INDEX</option>
                    </select>
                </div>
                {#if addType !== "primary"}
                    <div class="flex flex-col gap-1 flex-1 min-w-[120px]">
                        <label class="text-[11px] text-(--ink-3)">Name</label>
                        <input
                            bind:value={addName}
                            placeholder="idx_name"
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0) mono"
                        />
                    </div>
                {/if}
                <div class="flex flex-col gap-1 flex-1 min-w-[160px]">
                    <label class="text-[11px] text-(--ink-3)"
                        >Columns (comma-separated)</label
                    >
                    <input
                        bind:value={addColumns}
                        placeholder="col1, col2"
                        class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0) mono"
                    />
                </div>
            </div>
            <div class="flex justify-end">
                <button
                    disabled={adding}
                    on:click={handleAdd}
                    class="text-[11.5px] px-3 py-1 rounded bg-(--acc) text-[#0a0a0a] font-semibold hover:opacity-90 disabled:opacity-50 cursor-pointer transition-opacity"
                >
                    {adding ? "Creating…" : "Create"}
                </button>
            </div>
        </div>
    {/if}

    <!-- Table -->
    {#if indexes.length === 0}
        <div
            class="text-(--ink-3) text-[13px] text-center py-6 bg-(--bg-1) border border-dashed border-(--line-strong) rounded-lg"
        >
            No indexes found on this table.
        </div>
    {:else}
        <div
            class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden"
        >
            <table class="w-full text-[12.5px]">
                <thead>
                    <tr
                        class="border-b border-(--line) text-(--ink-3) text-left"
                    >
                        <th
                            class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                            >Type</th
                        >
                        <th
                            class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                            >Name</th
                        >
                        <th
                            class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                            >Columns</th
                        >
                        {#if canManage}
                            <th class="px-3 py-2 w-16"></th>
                        {/if}
                    </tr>
                </thead>
                <tbody>
                    {#each indexes as idx}
                        {@const badge = typeBadge(idx)}
                        <tr
                            class="border-b border-(--line) last:border-0 hover:bg-(--bg-2) transition-colors"
                        >
                            <td class="px-3 py-2">
                                <span
                                    class="mono text-[10px] font-bold tracking-[0.05em] px-1.5 py-0.5 rounded {badge.cls}"
                                >
                                    {badge.label}
                                </span>
                            </td>
                            <td class="px-3 py-2 mono text-(--ink-1)"
                                >{idx.name}</td
                            >
                            <td class="px-3 py-2 mono text-(--ink-2)"
                                >{idx.columns.join(", ")}</td
                            >
                            {#if canManage}
                                <td class="px-3 py-2 text-right">
                                    <button
                                        disabled={dropping === idx.name ||
                                            (idx.primary && !canManagePK)}
                                        title={idx.primary && !canManagePK
                                            ? "Primary key management not supported"
                                            : "Drop"}
                                        on:click={() => handleDrop(idx)}
                                        class="text-[11px] px-2 py-0.5 rounded text-(--danger) hover:bg-[rgba(255,115,103,0.1)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors cursor-pointer"
                                    >
                                        {dropping === idx.name ? "…" : "Drop"}
                                    </button>
                                </td>
                            {/if}
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
