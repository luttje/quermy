<script>
    export let value = "";
    let extraClass = "";
    export { extraClass as class };
</script>

<select
    bind:value
    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2.25 text-(--ink-0) mono text-[13px] transition-[border-color,box-shadow] duration-120 ease-in-out focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)] {extraClass}"
>
    <slot />
</select>
