<script>
    import { toasts } from "../lib/store.js";
</script>

<div class="toast-stack">
    {#each $toasts as t (t.id)}
        <div
            class="toast"
            class:error={t.type === "error"}
            class:success={t.type === "success"}
        >
            <div class="dot"></div>
            <div class="msg">{t.message}</div>
        </div>
    {/each}
</div>

<style>
    .toast-stack {
        position: fixed;
        bottom: 20px;
        right: 20px;
        display: flex;
        flex-direction: column-reverse;
        gap: 8px;
        z-index: 1000;
        pointer-events: none;
    }
    .toast {
        pointer-events: auto;
        display: flex;
        gap: 10px;
        align-items: center;
        background: var(--bg-2);
        border: 1px solid var(--line-strong);
        color: var(--ink-0);
        padding: 10px 16px 10px 12px;
        border-radius: var(--radius);
        font-size: 13px;
        box-shadow: var(--shadow-1);
        animation: slideIn 200ms ease both;
        max-width: 380px;
    }
    .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--acc);
        box-shadow: 0 0 8px var(--acc-glow);
    }
    .toast.error .dot {
        background: var(--danger);
        box-shadow: 0 0 8px rgba(255, 115, 103, 0.4);
    }
    .toast.success .dot {
        background: var(--ok);
    }
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
</style>
