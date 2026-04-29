<script>
    export let variant = "default"; // "default" | "primary" | "ghost" | "danger"
    export let type = "button";
    export let disabled = false;
    let extraClass = "";
    export { extraClass as class };

    const variantClasses = {
        default:
            "bg-[var(--bg-2)] border-[var(--line)] text-[var(--ink-0)] hover:bg-[var(--bg-3)] hover:border-[var(--line-strong)]",
        primary:
            "bg-[var(--acc)] text-[#0a0c0a] border-[var(--acc)] font-semibold hover:bg-[var(--acc-d)] hover:border-[var(--acc-d)]",
        ghost: "bg-transparent border-transparent text-[var(--ink-1)] hover:bg-[var(--bg-2)] hover:text-[var(--ink-0)]",
        danger: "bg-[var(--bg-2)] border-[var(--line)] text-[var(--danger)] hover:bg-[rgba(255,115,103,0.08)] hover:border-[rgba(255,115,103,0.3)]",
    };
</script>

<button
    {type}
    {disabled}
    class="inline-flex items-center gap-2 border rounded-[var(--radius)] px-[14px] py-2 font-medium text-[13px] cursor-pointer transition-[background,border-color,transform] duration-100 ease-out active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed {variantClasses[
        variant
    ] ?? variantClasses.default} {extraClass}"
    on:click
>
    <slot />
</button>
