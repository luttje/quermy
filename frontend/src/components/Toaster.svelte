<script>
    import { toasts } from "../lib/store.js";
</script>

<div
    class="fixed bottom-5 right-5 flex flex-col-reverse gap-2 z-[1000] pointer-events-none"
>
    {#each $toasts as t (t.id)}
        <div
            class="pointer-events-auto flex gap-[10px] items-center bg-[var(--bg-2)] border border-[var(--line-strong)] text-[var(--ink-0)] py-[10px] pr-4 pl-3 rounded-[var(--radius)] text-[13px] shadow-[var(--shadow-1)] max-w-[380px] animate-[slideIn_200ms_ease_both]"
        >
            <div
                class="w-[7px] h-[7px] rounded-full shrink-0"
                class:dot-error={t.type === "error"}
                class:dot-success={t.type === "success"}
                class:dot-default={t.type !== "error" && t.type !== "success"}
            ></div>
            <div>{t.message}</div>
        </div>
    {/each}
</div>

<style>
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    .dot-default {
        background: var(--acc);
        box-shadow: 0 0 8px var(--acc-glow);
    }
    .dot-error {
        background: var(--danger);
        box-shadow: 0 0 8px rgba(255, 115, 103, 0.4);
    }
    .dot-success {
        background: var(--ok);
    }
</style>
