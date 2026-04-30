<script>
    export let value = "";
    export let id = "";
    export let name = "";
    export let disabled = false;
    let extraClass = "";
    export { extraClass as class };
</script>

<select
    {id}
    {name}
    {disabled}
    bind:value
    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2.25 text-(--ink-0) mono text-[13px] transition-[border-color,box-shadow] duration-120 ease-in-out focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)] {extraClass}"
    on:change
    on:input
    on:focus
    on:blur
>
    <slot />
</select>
