<script>
    import { createEventDispatcher, tick, onDestroy } from "svelte";

    /**
     * @typedef {string | { value: string, label?: string, disabled?: boolean }} Option
     */

    /** Bound value (string). Use bind:value. */
    export let value = "";

    /** List of options. Either strings or `{ value, label?, disabled? }` objects. */
    export let options = /** @type {Option[]} */ ([]);

    /** Placeholder shown when no value is selected. */
    export let placeholder = "Select…";

    /** Placeholder for the search input inside the dropdown. */
    export let searchPlaceholder = "Search…";

    /** Disable the whole control. */
    export let disabled = false;

    /** Allow clearing the selection with an "x" button. */
    export let clearable = false;

    /** Allow values not present in `options` (free input). */
    export let allowCustom = false;

    /** Max height of the dropdown list (px). Will be clamped to available viewport space. */
    export let maxListHeight = 240;

    /** Optional id for label association. */
    export let id = "";

    /** Optional name (rendered as a hidden input so native forms work). */
    export let name = "";

    /** Custom filter. Receives `(option, query)` and returns boolean. */
    export let filter =
        /** @type {(opt: {value:string,label:string}, q: string) => boolean} */ (
            (opt, q) => {
                const needle = q.toLowerCase();
                return (
                    opt.label.toLowerCase().includes(needle) ||
                    opt.value.toLowerCase().includes(needle)
                );
            }
        );

    let extraClass = "";
    export { extraClass as class };

    const dispatch = createEventDispatcher();

    let open = false;
    let query = "";
    let highlightedIndex = 0;
    let searchInputEl;
    let listEl;
    let triggerEl;
    let panelEl;

    // Floating-panel positioning state.
    let panelStyle = "";
    let panelMaxHeight = maxListHeight;

    // Normalize options into a consistent shape.
    $: normalized = options.map((o) =>
        typeof o === "string"
            ? { value: o, label: o, disabled: false }
            : {
                  value: o.value,
                  label: o.label ?? o.value,
                  disabled: !!o.disabled,
              },
    );

    $: filtered = query.trim()
        ? normalized.filter((o) => filter(o, query.trim()))
        : normalized;

    $: selectedOption = normalized.find((o) => o.value === value) || null;

    $: displayLabel = selectedOption
        ? selectedOption.label
        : value && allowCustom
          ? value
          : "";

    // Keep highlight in range when the filtered list changes.
    $: if (highlightedIndex >= filtered.length) {
        highlightedIndex = Math.max(0, filtered.length - 1);
    }

    // ---- Positioning ----

    const GAP = 4; // px between trigger and panel
    const VIEWPORT_MARGIN = 8; // breathing room from viewport edges
    const HEADER_H = 38; // approx. height of search input header

    function computePosition() {
        if (!triggerEl) return;
        const rect = triggerEl.getBoundingClientRect();
        const vh = window.innerHeight;
        const vw = window.innerWidth;

        const spaceBelow = vh - rect.bottom - GAP - VIEWPORT_MARGIN;
        const spaceAbove = rect.top - GAP - VIEWPORT_MARGIN;

        // Prefer below; flip up only if below is genuinely cramped AND above is roomier.
        const minDesired = Math.min(maxListHeight, 160) + HEADER_H;
        const placeAbove = spaceBelow < minDesired && spaceAbove > spaceBelow;

        const available = placeAbove ? spaceAbove : spaceBelow;
        panelMaxHeight = Math.max(
            80,
            Math.min(maxListHeight, available - HEADER_H),
        );

        // Horizontal: align to trigger; clamp so it stays in the viewport.
        let left = rect.left;
        const width = rect.width;
        if (left + width > vw - VIEWPORT_MARGIN) {
            left = Math.max(VIEWPORT_MARGIN, vw - VIEWPORT_MARGIN - width);
        }
        if (left < VIEWPORT_MARGIN) left = VIEWPORT_MARGIN;

        const top = placeAbove ? rect.top - GAP : rect.bottom + GAP;

        panelStyle = [
            "position: fixed",
            `top: ${top}px`,
            `left: ${left}px`,
            `width: ${width}px`,
            placeAbove ? "transform: translateY(-100%)" : "",
            "z-index: 1000",
        ]
            .filter(Boolean)
            .join("; ");
    }

    function attachReposition() {
        // Capture-phase scroll listener catches scrolling on ANY ancestor
        // (modal body, page, etc.) — not just window scroll.
        window.addEventListener("scroll", computePosition, true);
        window.addEventListener("resize", computePosition);
    }

    function detachReposition() {
        window.removeEventListener("scroll", computePosition, true);
        window.removeEventListener("resize", computePosition);
    }

    onDestroy(detachReposition);

    // Svelte action: portal the node to <body> so it escapes ancestor
    // overflow/transform/contain stacking contexts.
    function portal(node) {
        document.body.appendChild(node);
        return {
            destroy() {
                if (node.parentNode) node.parentNode.removeChild(node);
            },
        };
    }

    // ---- Open/close ----

    async function openDropdown() {
        if (disabled || open) return;
        open = true;
        query = "";
        const idx = normalized.findIndex((o) => o.value === value);
        highlightedIndex = idx >= 0 ? idx : 0;
        await tick();
        computePosition();
        attachReposition();
        searchInputEl?.focus();
        scrollHighlightedIntoView();
    }

    function closeDropdown() {
        if (!open) return;
        open = false;
        query = "";
        detachReposition();
    }

    function toggle() {
        open ? closeDropdown() : openDropdown();
    }

    function selectOption(opt) {
        if (!opt || opt.disabled) return;
        const prev = value;
        value = opt.value;
        closeDropdown();
        if (prev !== value) dispatch("change", { value });
    }

    function clear(e) {
        e.stopPropagation();
        if (disabled) return;
        const prev = value;
        value = "";
        if (prev !== "") dispatch("change", { value });
    }

    function commitCustom() {
        const trimmed = query.trim();
        if (!trimmed) return;
        const match = normalized.find(
            (o) => o.value === trimmed || o.label === trimmed,
        );
        if (match) {
            selectOption(match);
            return;
        }
        if (allowCustom) {
            const prev = value;
            value = trimmed;
            closeDropdown();
            if (prev !== value) dispatch("change", { value });
        }
    }

    async function scrollHighlightedIntoView() {
        await tick();
        if (!listEl) return;
        const el = listEl.querySelector(`[data-idx="${highlightedIndex}"]`);
        if (el) el.scrollIntoView({ block: "nearest" });
    }

    function handleKeydown(e) {
        if (!open) {
            if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                openDropdown();
            }
            return;
        }

        switch (e.key) {
            case "ArrowDown": {
                e.preventDefault();
                if (!filtered.length) return;
                let next = highlightedIndex;
                for (let i = 0; i < filtered.length; i++) {
                    next = (next + 1) % filtered.length;
                    if (!filtered[next].disabled) break;
                }
                highlightedIndex = next;
                scrollHighlightedIntoView();
                break;
            }
            case "ArrowUp": {
                e.preventDefault();
                if (!filtered.length) return;
                let prev = highlightedIndex;
                for (let i = 0; i < filtered.length; i++) {
                    prev = (prev - 1 + filtered.length) % filtered.length;
                    if (!filtered[prev].disabled) break;
                }
                highlightedIndex = prev;
                scrollHighlightedIntoView();
                break;
            }
            case "Home":
                e.preventDefault();
                highlightedIndex = 0;
                scrollHighlightedIntoView();
                break;
            case "End":
                e.preventDefault();
                highlightedIndex = Math.max(0, filtered.length - 1);
                scrollHighlightedIntoView();
                break;
            case "Enter":
                e.preventDefault();
                if (filtered[highlightedIndex]) {
                    selectOption(filtered[highlightedIndex]);
                } else if (allowCustom) {
                    commitCustom();
                }
                break;
            case "Escape":
                e.preventDefault();
                closeDropdown();
                triggerEl?.focus();
                break;
            case "Tab":
                closeDropdown();
                break;
        }
    }

    // Outside-click: must consider the trigger AND the portaled panel,
    // since the panel is no longer a DOM descendant of the component root.
    function handleDocumentMousedown(e) {
        if (!open) return;
        const target = e.target;
        if (triggerEl && triggerEl.contains(target)) return;
        if (panelEl && panelEl.contains(target)) return;
        closeDropdown();
    }
</script>

<svelte:window on:mousedown={handleDocumentMousedown} />

<div class="relative {extraClass}">
    <button
        bind:this={triggerEl}
        type="button"
        {id}
        {disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        on:click={toggle}
        on:keydown={handleKeydown}
        class="w-full flex items-center gap-2 bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2.25 text-(--ink-0) mono text-[13px] text-left transition-[border-color,box-shadow] duration-120 ease-in-out focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_3px_var(--acc-glow)] disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
        class:border-acc={open}
    >
        <span class="flex-1 truncate {displayLabel ? '' : 'text-(--ink-3)'}">
            {displayLabel || placeholder}
        </span>

        {#if clearable && value && !disabled}
            <span
                role="button"
                tabindex="-1"
                aria-label="Clear"
                on:click={clear}
                on:keydown={(e) => e.key === "Enter" && clear(e)}
                class="text-(--ink-3) hover:text-(--ink-0) transition-colors leading-none px-1"
                >×</span
            >
        {/if}

        <svg
            class="w-3 h-3 text-(--ink-3) shrink-0 transition-transform duration-120"
            class:rotate-180={open}
            viewBox="0 0 12 12"
            fill="none"
            aria-hidden="true"
        >
            <path
                d="M3 4.5L6 7.5L9 4.5"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </button>

    {#if name}
        <input type="hidden" {name} {value} />
    {/if}
</div>

{#if open}
    <!-- Portaled to <body> so it escapes ancestor overflow/transform clipping -->
    <div
        bind:this={panelEl}
        use:portal
        style={panelStyle}
        class="bg-(--bg-input) border border-(--line) rounded-(--radius) shadow-lg overflow-hidden flex flex-col"
    >
        <div class="p-1.5 border-b border-(--line) shrink-0">
            <input
                bind:this={searchInputEl}
                bind:value={query}
                on:keydown={handleKeydown}
                type="text"
                placeholder={searchPlaceholder}
                autocomplete="off"
                spellcheck="false"
                class="w-full bg-(--bg-2,transparent) border border-(--line) rounded px-2 py-1 text-(--ink-0) mono text-[12px] outline-none focus:border-(--acc) transition-colors"
            />
        </div>

        <ul
            bind:this={listEl}
            role="listbox"
            class="overflow-y-auto py-1"
            style="max-height: {panelMaxHeight}px;"
        >
            {#if filtered.length === 0}
                <li class="px-3 py-2 text-(--ink-3) mono text-[12px] italic">
                    {allowCustom && query.trim()
                        ? `Press Enter to use "${query.trim()}"`
                        : "No matches"}
                </li>
            {:else}
                {#each filtered as opt, i (opt.value)}
                    <li
                        data-idx={i}
                        role="option"
                        aria-selected={opt.value === value}
                        aria-disabled={opt.disabled}
                        on:click={() => selectOption(opt)}
                        on:mouseenter={() => (highlightedIndex = i)}
                        class="px-3 py-1.5 mono text-[12px] cursor-pointer flex items-center gap-2 transition-colors"
                        class:bg-acc-soft={i === highlightedIndex &&
                            !opt.disabled}
                        class:text-ink-0={i === highlightedIndex &&
                            !opt.disabled}
                        class:opacity-40={opt.disabled}
                        class:cursor-not-allowed={opt.disabled}
                        class:font-medium={opt.value === value}
                    >
                        <span class="flex-1 truncate">{opt.label}</span>
                        {#if opt.value === value}
                            <span class="text-(--acc) text-[11px]">✓</span>
                        {/if}
                    </li>
                {/each}
            {/if}
        </ul>
    </div>
{/if}

<style>
    :global(.bg-acc-soft) {
        background-color: var(--acc-glow, rgba(120, 160, 255, 0.15));
    }
    :global(.text-ink-0) {
        color: var(--ink-0);
    }
    :global(.border-acc) {
        border-color: var(--acc);
    }
</style>
