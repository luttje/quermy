<script>
    export let checked = false;
    export let disabled = false;
    export let indeterminate = false;
    let extraClass = "";
    export { extraClass as class };

    let inputEl;
    export const focus = () => inputEl.focus();

    $: if (inputEl) inputEl.indeterminate = indeterminate;
</script>

<label
    class="inline-flex items-center justify-center w-3.5 h-3.5 shrink-0 relative {disabled
        ? 'cursor-not-allowed opacity-50'
        : 'cursor-pointer'} {extraClass}"
>
    <input
        bind:this={inputEl}
        type="checkbox"
        bind:checked
        {disabled}
        on:change
        on:click
        on:mouseover
        on:focus
        on:blur
        class="peer sr-only"
    />
    <span
        class="w-3.5 h-3.5 rounded-[3px] border border-(--line) bg-(--bg-input)
               transition-[border-color,background-color,box-shadow] duration-120 ease-in-out
               peer-focus-visible:border-(--acc) peer-focus-visible:shadow-[0_0_0_3px_var(--acc-glow)]
               peer-checked:bg-(--acc) peer-checked:border-(--acc)
               peer-indeterminate:bg-(--acc) peer-indeterminate:border-(--acc)
               {disabled ? '' : 'peer-hover:border-(--line-strong)'}"
        aria-hidden="true"
    >
        {#if indeterminate}
            <svg
                class="w-full h-full p-0.5 text-(--bg-0)"
                viewBox="0 0 10 10"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <line
                    x1="2"
                    y1="5"
                    x2="8"
                    y2="5"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                />
            </svg>
        {:else if checked}
            <svg
                class="w-full h-full p-0.5 text-(--bg-0)"
                viewBox="0 0 10 10"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <polyline
                    points="1.5,5 4,7.5 8.5,2.5"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        {/if}
    </span>
</label>
