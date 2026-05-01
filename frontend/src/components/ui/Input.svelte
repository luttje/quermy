<script>
    export let type = "text";
    export let value = "";
    export let placeholder = "";
    export let required = false;
    export let disabled = false;
    let extraClass = "";
    export { extraClass as class };

    let inputEl;
    export const focus = () => inputEl.focus();
    export const select = () => inputEl.select();
</script>

<input
    bind:this={inputEl}
    {type}
    {placeholder}
    {required}
    {disabled}
    bind:value
    on:input
    on:change
    on:keydown
    on:keyup
    on:blur
    on:focus
    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2.25 text-(--ink-0) mono text-[13px] transition-[border-color,box-shadow] duration-120 ease-in-out placeholder:text-(--ink-3) focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)] {extraClass}"
/>
