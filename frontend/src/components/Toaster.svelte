<script>
    import { toasts } from "../lib/store.js";
</script>

<div
    class="fixed bottom-5 right-5 flex flex-col-reverse gap-2 z-1000 pointer-events-none"
>
    {#each $toasts as t (t.id)}
        <div
            class="pointer-events-auto flex gap-2.5 items-center bg-(--bg-2) border border-(--line-strong) text-(--ink-0) py-2.5 pr-4 pl-3 rounded-(--radius) text-[13px] shadow-(--shadow-1) max-w-95 animate-[slideIn_200ms_ease_both]"
        >
            <div
                class="w-1.75 h-1.75 rounded-full shrink-0
                    {t.type === 'error'
                    ? 'bg-(--danger) shadow-[0_0_8px_rgba(255,115,103,0.4)]'
                    : t.type === 'success'
                      ? 'bg-(--ok)'
                      : 'bg-(--acc) shadow-[0_0_8px_var(--acc-glow)]'}"
            ></div>
            <div>{t.message}</div>
        </div>
    {/each}
</div>
