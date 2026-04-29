<script>
    export let value = "";
    let extraClass = "";
    export { extraClass as class };
</script>

<select
    bind:value
    class="w-full bg-[var(--bg-input)] border border-[var(--line)] rounded-[var(--radius)] px-3 py-[9px] text-[var(--ink-0)] mono text-[13px] transition-[border-color,box-shadow] duration-[120ms] ease-in-out focus:outline-none focus:border-[var(--acc)] focus:shadow-[0_0_0_3px_var(--acc-glow)] {extraClass}"
>
    <slot />
</select>
