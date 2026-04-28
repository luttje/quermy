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
    class="resize-handle {orientation}"
    class:dragging
    on:mousedown={onMouseDown}
    role="separator"
    aria-orientation={orientation}
    tabindex="0"
></div>

<style>
    .resize-handle {
        flex-shrink: 0;
        background: var(--line);
        transition: background 80ms;
        position: relative;
        user-select: none;
    }

    .horizontal {
        height: 4px;
        width: 100%;
        cursor: row-resize;
    }
    .horizontal::after {
        content: "";
        position: absolute;
        inset: -4px 0;
    }

    .vertical {
        width: 4px;
        height: 100%;
        cursor: col-resize;
    }
    .vertical::after {
        content: "";
        position: absolute;
        inset: 0 -4px;
    }

    .resize-handle:hover,
    .resize-handle.dragging {
        background: var(--acc);
    }
</style>
