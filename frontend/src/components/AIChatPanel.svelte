<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { aiKeys, activeAiKey } from "../lib/store.js";
    import { parse } from "../lib/marked.js";
    import AIKeyManager from "./AIKeyManager.svelte";
    import "highlight.js/styles/atom-one-dark.css";

    // Chat state
    let messages = [
        {
            role: "system",
            content: `You are a helpful assistant for the Quermy SQL client. Your user will ask you questions about their databases, and you will respond with answers, explanations, or SQL queries to run.
            You also have access to tools that can provide information about the databases, such as their structure or query results. Use these tools when needed to answer the user's questions accurately.
            Always try to help the user achieve their goal in as few steps as possible.`,
        },
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

    // Key manager panel toggle
    let showKeyManager = false;

    onMount(async () => {
        try {
            const res = await api.listAiKeys();
            const keys = res.keys ?? [];
            aiKeys.set(keys);
            // Auto-select first key if nothing is active yet
            if (keys.length && !$activeAiKey) {
                activeAiKey.set({ keyId: keys[0].id, model: keys[0].model });
            }
        } catch {
            // Backend unreachable — leave defaults.
        }
    });

    // Active key object (from the list) — for display only
    $: activeKeyObj = $aiKeys.find((k) => k.id === $activeAiKey?.keyId) ?? null;

    function selectKey(id) {
        const key = $aiKeys.find((k) => k.id === id);
        if (key) activeAiKey.set({ keyId: key.id, model: key.model });
    }

    function onModelChange(e) {
        activeAiKey.update((a) => (a ? { ...a, model: e.target.value } : a));
    }

    const PROVIDER_COLORS = {
        openai: "text-emerald-400 bg-emerald-950/40 border-emerald-800/50",
        anthropic: "text-orange-400 bg-orange-950/40 border-orange-800/50",
    };
    function providerColor(id) {
        return (
            PROVIDER_COLORS[id] ?? "text-(--ink-3) bg-(--bg-3) border-(--line)"
        );
    }

    // Models available for the active key's provider — fetched lazily when the key changes
    let availableModels = [];
    $: if ($activeAiKey?.keyId) {
        api.getKeyModels($activeAiKey.keyId)
            .then((r) => {
                availableModels = r.models ?? [];
            })
            .catch(() => {});
    }

    // Messaging
    function scrollToBottom() {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    async function send() {
        const text = input.trim();
        if (!text || busy || !$activeAiKey) return;

        messages = [...messages, { role: "user", content: text }];
        input = "";
        busy = true;
        streamingReply = "";
        setTimeout(scrollToBottom, 0);

        try {
            for await (const chunk of api.aiChatStream(
                $activeAiKey.keyId,
                $activeAiKey.model,
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

    // Start a new conversation but keep the system prompt and initial greeting message
    function clearChat() {
        messages = [
            messages[0], // system prompt
            messages[1], // initial greeting
        ];
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
        class="px-3.5 py-2.25 border-b border-(--line) flex items-center justify-between gap-2 shrink-0 bg-(--bg-2)"
    >
        <div
            class="flex items-center gap-1.75 text-[12.5px] font-medium text-(--ink-1) shrink-0"
        >
            <span class="text-(--acc) text-[13px]">✦</span>
            <span>AI</span>
        </div>

        <!-- Key + model selector (shown when keys exist and not in key manager) -->
        {#if !showKeyManager && $aiKeys.length > 0}
            <div class="flex items-center gap-1 min-w-0 flex-1">
                <!-- Key picker -->
                <select
                    class="min-w-0 flex-1 bg-(--bg-input) border border-(--line) rounded-(--radius) px-1.5 py-0.5 text-[11px] text-(--ink-0) focus:outline-none focus:border-(--acc) truncate"
                    value={$activeAiKey?.keyId ?? ""}
                    on:change={(e) => selectKey(e.target.value)}
                    title="Active API key"
                >
                    {#each $aiKeys as k}
                        <option value={k.id}>{k.label}</option>
                    {/each}
                </select>
                <!-- Model picker -->
                {#if availableModels.length > 0}
                    <select
                        class="min-w-0 flex-1 bg-(--bg-input) border border-(--line) rounded-(--radius) px-1.5 py-0.5 text-[11px] text-(--ink-0) focus:outline-none focus:border-(--acc) truncate"
                        value={$activeAiKey?.model ?? ""}
                        on:change={onModelChange}
                        title="Model"
                    >
                        {#each availableModels as m}
                            <option value={m}>{m}</option>
                        {/each}
                    </select>
                {:else if activeKeyObj}
                    <span
                        class="text-[10.5px] text-(--ink-3) mono truncate shrink-0"
                        >{activeKeyObj.model}</span
                    >
                {/if}
                <!-- Provider badge -->
                {#if activeKeyObj}
                    <span
                        class="inline-flex shrink-0 items-center px-1.5 py-0.25 rounded text-[9px] font-medium border {providerColor(
                            activeKeyObj.provider,
                        )}"
                    >
                        {activeKeyObj.provider}
                    </span>
                {/if}
            </div>
        {/if}

        <!-- Manage keys button -->
        <button
            on:click={() => (showKeyManager = !showKeyManager)}
            class="shrink-0 mono text-[9px] px-1.5 py-0.5 bg-(--bg-3) border rounded-[3px] tracking-[0.08em] uppercase transition-colors duration-80
                   {$aiKeys.length === 0
                ? 'border-orange-600/50 text-orange-400 hover:border-orange-400'
                : showKeyManager
                  ? 'border-(--acc) text-(--acc)'
                  : 'border-(--line-strong) text-(--ink-3) hover:border-(--acc) hover:text-(--acc)'}"
            title="Manage API keys"
        >
            {showKeyManager ? "← Chat" : "⚿ Keys"}
        </button>
    </div>

    {#if showKeyManager}
        <!-- Key manager panel fills the rest of the panel -->
        <div class="flex-1 overflow-hidden">
            <AIKeyManager onClose={() => (showKeyManager = false)} />
        </div>
    {:else if $aiKeys.length === 0}
        <div
            class="flex-1 flex flex-col items-center justify-center gap-3 p-4 text-center"
        >
            <span class="text-[28px] opacity-30">⚿</span>
            <p class="text-[12px] text-(--ink-3) leading-relaxed max-w-48">
                Add an API key to start chatting with an AI provider.
            </p>
            <button
                on:click={() => (showKeyManager = true)}
                class="px-3 py-1.5 rounded-(--radius) text-[11.5px] bg-(--bg-3) border border-(--line-strong) muted hover:border-(--acc) hover:text-(--acc) transition-colors duration-80"
            >
                Add API key
            </button>
        </div>
    {:else}
        <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
        <div
            role="list"
            class="ai-chat flex-1 min-h-0 overflow-y-auto px-2.5 py-3 flex flex-col gap-2.5"
            bind:this={messagesEl}
            on:click={handleMessagesClick}
            on:keydown={handleMessagesKeydown}
        >
            <!-- Toolbar with clear button -->
            <div
                class="flex justify-end bg-(--bg-1) border-b border-(--line) pb-2.5 shrink-0"
            >
                <button
                    on:click={clearChat}
                    class="text-[10px] px-1.5 py-0.5 bg-(--bg-3) border border-(--line) rounded-[3px] text-(--ink-3) hover:border-(--acc) hover:text-(--acc) transition-colors duration-80"
                >
                    Clear Chat
                </button>
            </div>

            {#each messages as msg}
                {#if msg.role !== "system"}
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
                            class="overflow-auto max-w-[88%] min-w-0 px-2.75 py-1.75 rounded-lg text-[12px] leading-normal wrap-break-word
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
                {/if}
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
                            class="prose-md overflow-auto max-w-[88%] min-w-0 px-2.75 py-1.75 rounded-lg text-[12px] leading-normal wrap-break-word bg-(--bg-2) border border-(--line) text-(--ink-1)"
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
            ></textarea>
            <button
                class="w-7.5 h-7.5 bg-(--acc) text-[#0a0c0a] border-0 rounded-(--radius) text-[15px] font-bold flex items-center justify-center shrink-0 transition-[background] duration-80 disabled:bg-(--bg-3) disabled:text-(--ink-3) enabled:hover:bg-(--acc-d)"
                on:click={send}
                disabled={!input.trim() || busy || !$activeAiKey}
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
