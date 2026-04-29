<script>
    export let label = "";
    export let optional = false;
    let extraClass = "";
    export { extraClass as class };
</script>

<label class="block {extraClass}">
    <span
        class="block text-[11px] font-semibold tracking-[0.04em] uppercase muted mb-1.5"
    >
        {label}{#if optional}<span
                class="text-(--ink-3) normal-case tracking-normal font-normal ml-1 text-[11px]"
                >optional</span
            >{/if}
    </span>
    <slot />
</label>
