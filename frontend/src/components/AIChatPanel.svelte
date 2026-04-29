<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { aiConfig } from "../lib/store.js";
    import { parse } from "../lib/marked.js";
    import "highlight.js/styles/atom-one-dark.css";

    // Chat state
    let messages = [
        {
            role: "assistant",
            content:
                "Ask me anything about your data — I can help write queries, explain results, or analyse your schema.",
        },
    ];
    let input = "";
    let busy = false;
    let streamingReply = "";
    let messagesEl;

    // Config form state
    let showConfig = false;
    let draftKey = "";
    let draftModel = "gpt-4o-mini";
    let savingConfig = false;

    const MODELS = [
        "gpt-4o-mini",
        "gpt-4o",
        "gpt-4-turbo",
        "gpt-4",
        "gpt-3.5-turbo",
    ];

    onMount(async () => {
        try {
            const cfg = await api.getAiConfig();
            aiConfig.set(cfg);
            draftModel = cfg.model;
        } catch {
            // Backend unreachable — leave defaults.
        }
    });

    function openConfig() {
        // Never pre-fill the key — it's server-side only.
        draftKey = "";
        draftModel = $aiConfig.model;
        showConfig = true;
    }

    async function saveConfig() {
        const key = draftKey.trim();
        if (!key || savingConfig) return;
        savingConfig = true;
        try {
            const cfg = await api.saveAiConfig(key, draftModel);
            aiConfig.set(cfg);
            showConfig = false;
        } catch (err) {
            alert(`Failed to save: ${err.message}`);
        } finally {
            savingConfig = false;
        }
    }

    async function clearConfig() {
        try {
            await api.deleteAiConfig();
            aiConfig.set({ configured: false, model: "gpt-4o-mini" });
            showConfig = false;
        } catch (err) {
            alert(`Failed to clear: ${err.message}`);
        }
    }

    // Messaging
    function scrollToBottom() {
        if (messagesEl) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    async function send() {
        const text = input.trim();
        if (!text || busy) return;

        messages = [...messages, { role: "user", content: text }];
        input = "";
        busy = true;
        streamingReply = "";
        setTimeout(scrollToBottom, 0);

        try {
            for await (const chunk of api.aiChatStream(
                // Strip the initial greeting so we don't send an unlabelled
                // "assistant" opener that confuses the model.
                messages.slice(1),
            )) {
                streamingReply += chunk;
                setTimeout(scrollToBottom, 0);
            }
            messages = [
                ...messages,
                { role: "assistant", content: streamingReply },
            ];
        } catch (err) {
            messages = [
                ...messages,
                {
                    role: "assistant",
                    content: `Error: ${err.message}`,
                    error: true,
                },
            ];
        } finally {
            busy = false;
            streamingReply = "";
            setTimeout(scrollToBottom, 0);
        }
    }

    function onKeydown(e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    }

    function handleMessagesClick(e) {
        const btn = e.target.closest(".copy-code-btn");
        if (!btn) return;
        const code = decodeURIComponent(btn.dataset.code ?? "");
        navigator.clipboard
            .writeText(code)
            .then(() => {
                btn.textContent = "Copied!";
                setTimeout(() => {
                    btn.textContent = "Copy";
                }, 2000);
            })
            .catch(() => {});
    }

    function handleMessagesKeydown(e) {
        if (e.key !== "Enter" && e.key !== " ") return;
        const btn = e.target.closest(".copy-code-btn");
        if (!btn) return;
        e.preventDefault();
        handleMessagesClick(e);
    }
</script>

<div class="h-full flex flex-col overflow-hidden">
    <!-- header -->
    <div
        class="px-3.5 py-2.25 border-b border-(--line) flex items-center justify-between shrink-0 bg-(--bg-2)"
    >
        <div
            class="flex items-center gap-1.75 text-[12.5px] font-medium text-(--ink-1)"
        >
            <span class="text-(--acc) text-[13px]">✦</span>
            <span>AI Assistant</span>
        </div>
        <button
            class="mono text-[9px] px-1.5 py-0.5 bg-(--bg-3) border rounded-[3px] tracking-[0.08em] uppercase transition-colors duration-80
                   {$aiConfig.configured
                ? 'border-(--line-strong) text-(--ink-3) hover:border-(--acc) hover:text-(--acc)'
                : 'border-orange-600/50 text-orange-400 hover:border-orange-400'}"
            on:click={openConfig}
            title="Configure API key"
        >
            {$aiConfig.configured ? $aiConfig.model : "no key"}
        </button>
    </div>

    {#if showConfig}
        <div
            class="flex-1 flex flex-col gap-3 p-3.5 overflow-y-auto bg-(--bg-1)"
        >
            <p class="text-[11.5px] muted leading-relaxed">
                Enter your <strong class="text-(--ink-1)">OpenAI API key</strong
                >. It is encrypted and stored on the server (AES-256-GCM) — it
                never leaves your backend and is never returned to the browser.
            </p>

            <label class="flex flex-col gap-1">
                <span
                    class="text-[11px] text-(--ink-3) uppercase tracking-wider"
                    >API Key</span
                >
                <input
                    type="password"
                    autocomplete="off"
                    placeholder="sk-…"
                    bind:value={draftKey}
                    class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.75 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_2px_var(--acc-glow)] placeholder:text-(--ink-3)"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span
                    class="text-[11px] text-(--ink-3) uppercase tracking-wider"
                    >Model</span
                >
                <select
                    bind:value={draftModel}
                    class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.75 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc)"
                >
                    {#each MODELS as m}
                        <option value={m}>{m}</option>
                    {/each}
                </select>
            </label>

            <div class="flex gap-2 mt-1">
                <button
                    disabled={!draftKey.trim() || savingConfig}
                    on:click={saveConfig}
                    class="flex-1 py-1.75 rounded-(--radius) text-[12px] font-medium bg-(--acc) text-[#0a0c0a] border-0 disabled:opacity-40 enabled:hover:bg-(--acc-d) transition-colors duration-80 flex items-center justify-center gap-1.5"
                >
                    {#if savingConfig}
                        <span
                            class="w-3 h-3 rounded-full border-2 border-[#0a0c0a]/30 border-t-[#0a0c0a] animate-spin"
                        ></span>
                        Saving…
                    {:else}
                        Save
                    {/if}
                </button>
                <button
                    on:click={() => (showConfig = false)}
                    class="px-3 py-1.75 rounded-(--radius) text-[12px] bg-(--bg-3) border border-(--line) muted hover:border-(--line-strong) transition-colors duration-80"
                >
                    Cancel
                </button>
                {#if $aiConfig.configured}
                    <button
                        on:click={clearConfig}
                        class="px-3 py-1.75 rounded-(--radius) text-[12px] bg-(--bg-3) border border-red-900/50 text-red-400 hover:border-red-500/70 transition-colors duration-80"
                    >
                        Clear
                    </button>
                {/if}
            </div>
        </div>
    {:else if !$aiConfig.configured}
        <div
            class="flex-1 flex flex-col items-center justify-center gap-3 p-4 text-center"
        >
            <span class="text-[28px] opacity-30">✦</span>
            <p class="text-[12px] text-(--ink-3) leading-relaxed max-w-45">
                Add your OpenAI API key to start chatting.
            </p>
            <button
                on:click={openConfig}
                class="px-3 py-1.5 rounded-(--radius) text-[11.5px] bg-(--bg-3) border border-(--line-strong) muted hover:border-(--acc) hover:text-(--acc) transition-colors duration-80"
            >
                Configure key
            </button>
        </div>
    {:else}
        <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
        <div
            role="list"
            class="flex-1 min-h-0 overflow-y-auto px-2.5 py-3 flex flex-col gap-2.5"
            bind:this={messagesEl}
            on:click={handleMessagesClick}
            on:keydown={handleMessagesKeydown}
        >
            {#each messages as msg}
                <div
                    class="flex gap-1.75 items-start {msg.role === 'user'
                        ? 'flex-row-reverse'
                        : ''}"
                >
                    {#if msg.role === "assistant"}
                        <div
                            class="w-5.5 h-5.5 rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-(--acc) flex items-center justify-center text-[11px] shrink-0 mt-0.5"
                        >
                            ✦
                        </div>
                    {/if}
                    <div
                        class="max-w-[88%] min-w-0 px-2.75 py-1.75 rounded-lg text-[12px] leading-normal wrap-break-word
                               {msg.error
                            ? 'bg-red-950/30 border border-red-800/50 text-red-300'
                            : msg.role === 'user'
                              ? 'bg-[rgba(200,255,90,0.07)] border border-[rgba(200,255,90,0.15)] text-(--ink-0)'
                              : 'prose-md bg-(--bg-2) border border-(--line) text-(--ink-1)'}"
                    >
                        {#if msg.role === "assistant" && !msg.error}
                            {@html parse(msg.content)}
                        {:else}
                            {msg.content}
                        {/if}
                    </div>
                </div>
            {/each}

            {#if busy}
                <div class="flex gap-1.75 items-start">
                    <div
                        class="w-5.5 h-5.5 rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-(--acc) flex items-center justify-center text-[11px] shrink-0 mt-0.5"
                    >
                        ✦
                    </div>
                    {#if streamingReply}
                        <div
                            class="prose-md max-w-[88%] min-w-0 px-2.75 py-1.75 rounded-lg text-[12px] leading-normal wrap-break-word bg-(--bg-2) border border-(--line) text-(--ink-1)"
                        >
                            {@html parse(streamingReply)}
                        </div>
                    {:else}
                        <div
                            class="bg-(--bg-2) border border-(--line) rounded-lg px-2.75 py-1.75 flex gap-1 items-center"
                        >
                            <span
                                class="w-1 h-1 rounded-full bg-(--ink-3) animate-bounce [animation-delay:0ms]"
                            ></span>
                            <span
                                class="w-1 h-1 rounded-full bg-(--ink-3) animate-bounce [animation-delay:150ms]"
                            ></span>
                            <span
                                class="w-1 h-1 rounded-full bg-(--ink-3) animate-bounce [animation-delay:300ms]"
                            ></span>
                        </div>
                    {/if}
                </div>
            {/if}
        </div>

        <!-- footer -->
        <div
            class="border-t border-(--line) p-2 flex gap-1.5 items-end shrink-0 bg-(--bg-1)"
        >
            <textarea
                class="flex-1 bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.75 text-[12px] text-(--ink-0) resize-none leading-[1.45] min-h-0 focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_2px_var(--acc-glow)] placeholder:text-(--ink-3)"
                placeholder="Ask about your data…"
                bind:value={input}
                on:keydown={onKeydown}
                rows="3"
                disabled={busy}
            ></textarea>
            <button
                class="w-7.5 h-7.5 bg-(--acc) text-[#0a0c0a] border-0 rounded-(--radius) text-[15px] font-bold flex items-center justify-center shrink-0 transition-[background] duration-80 disabled:bg-(--bg-3) disabled:text-(--ink-3) enabled:hover:bg-(--acc-d)"
                on:click={send}
                disabled={!input.trim() || busy}
                title="Send (Enter)">↑</button
            >
        </div>
    {/if}
</div>

<style>
    /* ── Prose typography for assistant messages ── */
    :global(.prose-md p) {
        margin-bottom: 0.45em;
    }
    :global(.prose-md p:last-child) {
        margin-bottom: 0;
    }
    :global(.prose-md ul),
    :global(.prose-md ol) {
        padding-left: 1.35em;
        margin-bottom: 0.45em;
    }
    :global(.prose-md li) {
        margin-bottom: 0.15em;
    }
    :global(.prose-md strong) {
        font-weight: 600;
        color: var(--ink-0);
    }
    :global(.prose-md em) {
        font-style: italic;
    }
    :global(.prose-md a) {
        color: var(--acc);
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    :global(.prose-md blockquote) {
        border-left: 2px solid var(--line-strong);
        padding-left: 0.65em;
        margin: 0.35em 0;
        color: var(--ink-3);
    }
    :global(.prose-md h1),
    :global(.prose-md h2),
    :global(.prose-md h3) {
        font-weight: 600;
        color: var(--ink-0);
        margin: 0.5em 0 0.2em;
        line-height: 1.3;
    }
    /* Inline code */
    :global(.prose-md code:not(.hljs)) {
        font-family: "JetBrains Mono", monospace;
        font-size: 10.5px;
        background: var(--bg-3);
        border: 1px solid var(--line);
        border-radius: 3px;
        padding: 1px 5px;
    }

    /* ── Fenced code blocks ── */
    :global(.md-code-block) {
        margin: 0.45em 0;
        border: 1px solid var(--line);
        border-radius: 5px;
        overflow: hidden;
        font-size: 11px;
    }
    :global(.prose-md .md-code-block:last-child) {
        margin-bottom: 0;
    }
    :global(.md-code-header) {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 3px 10px;
        background: var(--bg-3);
        border-bottom: 1px solid var(--line);
    }
    :global(.md-code-lang) {
        font-family: "JetBrains Mono", monospace;
        font-size: 9.5px;
        color: var(--ink-3);
        text-transform: lowercase;
        letter-spacing: 0.04em;
    }
    :global(.copy-code-btn) {
        font-size: 9.5px;
        color: var(--ink-3);
        background: none;
        border: none;
        cursor: pointer;
        padding: 1px 5px;
        border-radius: 3px;
        transition:
            background 80ms,
            color 80ms;
    }
    :global(.copy-code-btn:hover) {
        background: var(--line);
        color: var(--ink-1);
    }
    :global(.md-code-block pre) {
        margin: 0;
        padding: 10px 12px;
        overflow-x: auto;
        background: var(--bg-1);
    }
    :global(.md-code-block code.hljs) {
        background: transparent;
        padding: 0;
        font-family: "JetBrains Mono", monospace;
        font-size: 11px;
        line-height: 1.5;
    }
</style>
