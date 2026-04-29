<script>
    import { createEventDispatcher } from "svelte";

    /**
     * orientation:
     *   "horizontal" — splits top/bottom (row-resize cursor), e.g. SQL pane vs result
     *   "vertical"   — splits left/right (col-resize cursor), e.g. sidebars
     */
    export let orientation = "horizontal";

    const dispatch = createEventDispatcher();

    let dragging = false;
    let lastPos = 0;

    $: orientationClasses =
        orientation === "horizontal"
            ? "h-1 w-full cursor-row-resize after:content-[''] after:absolute after:-inset-y-1 after:inset-x-0"
            : "w-1 h-full cursor-col-resize after:content-[''] after:absolute after:inset-y-0 after:-inset-x-1";

    function onMouseDown(e) {
        dragging = true;
        lastPos = orientation === "horizontal" ? e.clientY : e.clientX;
        window.addEventListener("mousemove", onMouseMove);
        window.addEventListener("mouseup", onMouseUp);
        e.preventDefault();
    }

    function onMouseMove(e) {
        if (!dragging) return;
        const pos = orientation === "horizontal" ? e.clientY : e.clientX;
        dispatch("resize", { delta: pos - lastPos });
        lastPos = pos;
    }

    function onMouseUp() {
        dragging = false;
        window.removeEventListener("mousemove", onMouseMove);
        window.removeEventListener("mouseup", onMouseUp);
    }
</script>

<!-- svelte-ignore a11y-no-noninteractive-element-interactions -->
<!-- svelte-ignore a11y-no-noninteractive-tabindex -->
<div
    class="shrink-0 bg-(--line) relative select-none transition-[background] duration-80 hover:bg-(--acc) {orientationClasses} {dragging
        ? 'bg-(--acc)'
        : ''}"
    on:mousedown={onMouseDown}
    role="separator"
    aria-orientation={orientation}
    tabindex="0"
></div>
