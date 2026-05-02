<script>
    export let variant = "default"; // "default" | "primary" | "ghost" | "danger"
    export let type = "button";
    export let disabled = false;
    let extraClass = "";
    export { extraClass as class };

    const variantClasses = {
        default:
            "bg-(--bg-2) border-(--line) text-(--ink-0) hover:bg-(--bg-3) hover:border-(--line-strong)",
        primary:
            "bg-(--acc) text-[#0a0c0a] border-[var(--acc)] font-semibold hover:bg-[var(--acc-d)] hover:border-[var(--acc-d)]",
        ghost: "bg-transparent border-transparent text-(--ink-1) hover:bg-(--bg-2) hover:text-(--ink-0)",
        danger: "bg-(--bg-2) border-(--line) text-(--danger) hover:bg-[rgba(255,115,103,0.08)] hover:border-[rgba(255,115,103,0.3)]",
    };
</script>

<button
    {type}
    {disabled}
    class="inline-flex flex-wrap justify-center items-center gap-2 border rounded-(--radius) px-3.5 py-2 font-medium text-[13px] cursor-pointer transition-[background,border-color,transform] duration-100 ease-out active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed {variantClasses[
        variant
    ] ?? variantClasses.default} {extraClass}"
    on:click
>
    <slot />
</button>
