<script>
    import { createEventDispatcher } from "svelte";
    import CloseIcon from "~icons/heroicons/x-mark-solid";

    /** Whether the modal is visible. */
    export let open = false;
    /** Heading shown in the modal chrome. */
    export let title = "";
    /** Max width Tailwind class, e.g. "max-w-2xl" */
    export let maxWidth = "max-w-2xl";

    const dispatch = createEventDispatcher();

    function close() {
        dispatch("close");
    }

    function onKeydown(e) {
        if (open && e.key === "Escape") {
            e.stopPropagation();
            close();
        }
    }
</script>

<svelte:window on:keydown={onKeydown} />

{#if open}
    <!-- Backdrop -->
    <!-- svelte-ignore a11y-click-events-have-key-events a11y-no-static-element-interactions -->
    <div
        class="fixed inset-0 z-[200] flex items-center justify-center p-4"
        aria-modal="true"
        role="dialog"
        aria-labelledby="modal-title"
    >
        <!-- Scrim -->
        <div
            class="absolute inset-0 bg-black/60 backdrop-blur-sm"
            on:click={close}
        ></div>

        <!-- Dialog surface -->
        <div
            class="relative z-10 w-full {maxWidth} max-h-[88vh] flex flex-col bg-(--bg-1) border border-(--line) rounded-[var(--radius-lg)] shadow-[0_24px_64px_rgba(0,0,0,0.7)]"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between px-5 py-3.5 border-b border-(--line) shrink-0"
            >
                <h2
                    id="modal-title"
                    class="font-semibold text-(--ink-0) text-[14px] tracking-[-0.01em]"
                >
                    {title}
                </h2>
                <button
                    on:click={close}
                    class="w-7 h-7 flex items-center justify-center rounded-(--radius) text-(--ink-3) hover:text-(--ink-0) hover:bg-(--bg-3) transition-colors cursor-pointer"
                    aria-label="Close"
                >
                    <CloseIcon />
                </button>
            </div>

            <!-- Slotted content — scrolls if it overflows -->
            <div class="flex-1 overflow-y-auto min-h-0">
                <slot />
            </div>

            <!-- Optional footer slot -->
            {#if $$slots.footer}
                <div class="shrink-0 border-t border-(--line)">
                    <slot name="footer" />
                </div>
            {/if}
        </div>
    </div>
{/if}
