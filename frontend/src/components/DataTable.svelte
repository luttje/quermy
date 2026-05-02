<script>
    import DataTableData from "./DataTableData.svelte";
    import DataTableStructure from "./DataTableStructure.svelte";

    export let columns = [];
    export let rows = [];
    export let total = null;
    export let durationMs = null;
    export let db = null;
    export let table = null;
    export let mode = "data";
    export let capabilities = null;
</script>

{#if mode === "data"}
    <DataTableData
        {columns}
        {rows}
        {total}
        {durationMs}
        {db}
        {table}
        {capabilities}
        on:refresh
    />
{:else}
    <DataTableStructure
        {columns}
        {durationMs}
        {db}
        {table}
        {capabilities}
        on:refresh
    />
{/if}
