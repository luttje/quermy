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
    class="shrink-0 bg-[var(--line)] relative select-none transition-[background] duration-[80ms] hover:bg-[var(--acc)]"
    class:horizontal={orientation === "horizontal"}
    class:vertical={orientation === "vertical"}
    class:dragging
    on:mousedown={onMouseDown}
    role="separator"
    aria-orientation={orientation}
    tabindex="0"
></div>

<style>
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
    .dragging {
        background: var(--acc);
    }
</style>
