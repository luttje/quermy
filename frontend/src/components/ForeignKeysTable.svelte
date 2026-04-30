<script>
    import { createEventDispatcher } from "svelte";
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";

    export let db;
    export let table;
    export let outgoing = [];
    export let incoming = [];
    export let capabilities = null;

    const dispatch = createEventDispatcher();

    const REFERENTIAL_ACTIONS = [
        "RESTRICT",
        "CASCADE",
        "SET NULL",
        "SET DEFAULT",
        "NO ACTION",
    ];

    let showAddForm = false;
    let addName = "";
    let addColumns = "";
    let addRefTable = "";
    let addRefColumns = "";
    let addOnUpdate = "RESTRICT";
    let addOnDelete = "RESTRICT";
    let adding = false;
    let dropping = null; // constraint name being dropped

    $: canManage = capabilities?.supportsForeignKeyManagement ?? false;

    async function handleDrop(constraintName) {
        if (!confirm(`Drop foreign key constraint "${constraintName}"?`))
            return;
        dropping = constraintName;
        try {
            await api.dropForeignKey(db, table, constraintName);
            toast(`Dropped constraint ${constraintName}`, "ok");
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
        const refCols = addRefColumns
            .split(",")
            .map((c) => c.trim())
            .filter(Boolean);
        if (!addName.trim()) {
            toast("Constraint name is required", "error");
            return;
        }
        if (!cols.length) {
            toast("Enter at least one column", "error");
            return;
        }
        if (!addRefTable.trim()) {
            toast("Referenced table is required", "error");
            return;
        }
        if (!refCols.length) {
            toast("Enter at least one referenced column", "error");
            return;
        }

        adding = true;
        try {
            await api.createForeignKey(db, table, {
                name: addName.trim(),
                columns: cols,
                referencedTable: addRefTable.trim(),
                referencedColumns: refCols,
                onUpdate: addOnUpdate,
                onDelete: addOnDelete,
            });
            toast("Foreign key created", "ok");
            showAddForm = false;
            addName = addColumns = addRefTable = addRefColumns = "";
            addOnUpdate = addOnDelete = "RESTRICT";
            dispatch("refresh");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            adding = false;
        }
    }
</script>

<div class="flex flex-col gap-4 min-h-0">
    <!-- Outgoing FKs -->
    <div class="flex flex-col gap-2.5">
        <div class="flex items-center justify-between gap-2">
            <span
                class="mono text-[11px] text-(--ink-3) tracking-[0.06em] uppercase"
            >
                Outgoing Foreign Keys · {table}
            </span>
            {#if canManage}
                <button
                    class="text-[11.5px] px-2.5 py-1 rounded border border-(--line) bg-(--bg-1) text-(--ink-1) hover:text-(--ink-0) hover:bg-(--bg-2) transition-colors cursor-pointer"
                    on:click={() => (showAddForm = !showAddForm)}
                >
                    {showAddForm ? "Cancel" : "+ Add Foreign Key"}
                </button>
            {/if}
        </div>

        <!-- Add form -->
        {#if showAddForm && canManage}
            <div
                class="bg-(--bg-1) border border-(--line) rounded-lg p-3 flex flex-col gap-2"
            >
                <div class="flex gap-2 flex-wrap">
                    <div class="flex flex-col gap-1 flex-1 min-w-[140px]">
                        <label class="text-[11px] text-(--ink-3)"
                            >Constraint Name</label
                        >
                        <input
                            bind:value={addName}
                            placeholder="fk_name"
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0) mono"
                        />
                    </div>
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
                <div class="flex gap-2 flex-wrap">
                    <div class="flex flex-col gap-1 flex-1 min-w-[140px]">
                        <label class="text-[11px] text-(--ink-3)"
                            >Referenced Table</label
                        >
                        <input
                            bind:value={addRefTable}
                            placeholder="other_table"
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0) mono"
                        />
                    </div>
                    <div class="flex flex-col gap-1 flex-1 min-w-[160px]">
                        <label class="text-[11px] text-(--ink-3)"
                            >Referenced Columns (comma-separated)</label
                        >
                        <input
                            bind:value={addRefColumns}
                            placeholder="id, col"
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0) mono"
                        />
                    </div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <div class="flex flex-col gap-1 flex-1 min-w-[120px]">
                        <label class="text-[11px] text-(--ink-3)"
                            >ON UPDATE</label
                        >
                        <select
                            bind:value={addOnUpdate}
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0)"
                        >
                            {#each REFERENTIAL_ACTIONS as a}<option>{a}</option
                                >{/each}
                        </select>
                    </div>
                    <div class="flex flex-col gap-1 flex-1 min-w-[120px]">
                        <label class="text-[11px] text-(--ink-3)"
                            >ON DELETE</label
                        >
                        <select
                            bind:value={addOnDelete}
                            class="bg-(--bg-0) border border-(--line) rounded px-2 py-1.5 text-[12.5px] text-(--ink-0)"
                        >
                            {#each REFERENTIAL_ACTIONS as a}<option>{a}</option
                                >{/each}
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button
                            disabled={adding}
                            on:click={handleAdd}
                            class="text-[11.5px] px-3 py-1.5 rounded bg-(--acc) text-[#0a0a0a] font-semibold hover:opacity-90 disabled:opacity-50 cursor-pointer transition-opacity"
                        >
                            {adding ? "Creating…" : "Create"}
                        </button>
                    </div>
                </div>
            </div>
        {/if}

        {#if outgoing.length === 0}
            <div
                class="text-(--ink-3) text-[13px] text-center py-5 bg-(--bg-1) border border-dashed border-(--line-strong) rounded-lg"
            >
                No outgoing foreign keys on this table.
            </div>
        {:else}
            <div
                class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden overflow-x-auto"
            >
                <table class="w-full text-[12.5px] min-w-[560px]">
                    <thead>
                        <tr
                            class="border-b border-(--line) text-(--ink-3) text-left"
                        >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >Constraint</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >Columns</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >References</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >ON UPDATE</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >ON DELETE</th
                            >
                            {#if canManage}
                                <th class="px-3 py-2 w-16"></th>
                            {/if}
                        </tr>
                    </thead>
                    <tbody>
                        {#each outgoing as fk}
                            <tr
                                class="border-b border-(--line) last:border-0 hover:bg-(--bg-2) transition-colors"
                            >
                                <td class="px-3 py-2 mono text-(--ink-1)"
                                    >{fk.constraintName}</td
                                >
                                <td class="px-3 py-2 mono text-(--ink-2)"
                                    >{fk.columns.join(", ")}</td
                                >
                                <td class="px-3 py-2 mono text-(--ink-2)">
                                    {fk.referencedTable}({fk.referencedColumns.join(
                                        ", ",
                                    )})
                                </td>
                                <td
                                    class="px-3 py-2 mono text-(--ink-2) text-[11px]"
                                    >{fk.onUpdate}</td
                                >
                                <td
                                    class="px-3 py-2 mono text-(--ink-2) text-[11px]"
                                    >{fk.onDelete}</td
                                >
                                {#if canManage}
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            disabled={dropping ===
                                                fk.constraintName}
                                            on:click={() =>
                                                handleDrop(fk.constraintName)}
                                            class="text-[11px] px-2 py-0.5 rounded text-(--danger) hover:bg-[rgba(255,115,103,0.1)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors cursor-pointer"
                                        >
                                            {dropping === fk.constraintName
                                                ? "…"
                                                : "Drop"}
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

    <!-- Incoming references -->
    <div class="flex flex-col gap-2.5">
        <span
            class="mono text-[11px] text-(--ink-3) tracking-[0.06em] uppercase"
        >
            Incoming References (other tables referencing {table})
        </span>

        {#if incoming.length === 0}
            <div
                class="text-(--ink-3) text-[13px] text-center py-5 bg-(--bg-1) border border-dashed border-(--line-strong) rounded-lg"
            >
                No other tables reference this table.
            </div>
        {:else}
            <div
                class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden overflow-x-auto"
            >
                <table class="w-full text-[12.5px] min-w-[480px]">
                    <thead>
                        <tr
                            class="border-b border-(--line) text-(--ink-3) text-left"
                        >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >Constraint</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >From Table</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >Via Columns</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >ON UPDATE</th
                            >
                            <th
                                class="px-3 py-2 font-medium text-[11px] tracking-[0.05em] uppercase"
                                >ON DELETE</th
                            >
                        </tr>
                    </thead>
                    <tbody>
                        {#each incoming as fk}
                            <tr
                                class="border-b border-(--line) last:border-0 hover:bg-(--bg-2) transition-colors"
                            >
                                <td class="px-3 py-2 mono text-(--ink-1)"
                                    >{fk.constraintName}</td
                                >
                                <td class="px-3 py-2 mono text-(--ink-2)"
                                    >{fk.referencedTable}</td
                                >
                                <td class="px-3 py-2 mono text-(--ink-2)"
                                    >{fk.columns.join(", ")}</td
                                >
                                <td
                                    class="px-3 py-2 mono text-(--ink-2) text-[11px]"
                                    >{fk.onUpdate}</td
                                >
                                <td
                                    class="px-3 py-2 mono text-(--ink-2) text-[11px]"
                                    >{fk.onDelete}</td
                                >
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </div>
</div>
