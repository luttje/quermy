<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import * as vault from "../lib/vault.js";
    import { getSettings, updateSettings } from "../lib/settings.js";
    import { aiKeys, activeAiKey } from "../lib/store.js";
    import { parse } from "../lib/marked.js";
    import AIKeyManager from "./AIKeyManager.svelte";
    import VaultGate from "./VaultGate.svelte";
    import { quermySystemPrompt } from "../lib/prompts";
    import "highlight.js/styles/atom-one-dark.css";
    import Btn from "./ui/Btn.svelte";
    import Select from "./ui/Select.svelte";
    import CodeEditor from "./CodeEditor.svelte";
    import ConfigIcon from "~icons/heroicons/cog-6-tooth-solid";
    import KeyIcon from "~icons/heroicons/key-solid";
    import EditIcon from "~icons/heroicons/pencil-square-solid";
    import ClearIcon from "~icons/heroicons/arrow-path-solid";
    import AIIcon from "~icons/heroicons/sparkles-solid";
    import RunIcon from "~icons/heroicons/play-solid";
    import SendIcon from "~icons/heroicons/paper-airplane-solid";

    // Provider metadata — fetched once for model-list lookups.
    let providers = [];
    let messages = [
        {
            role: "system",
            content: quermySystemPrompt,
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

    // Panel toggles (mutually exclusive)
    let showKeyManager = false;
    let showPromptEditor = false;
    let showOptions = false;

    // Prompt editor state
    let promptDraft = quermySystemPrompt;
    let promptSaving = false;
    let promptError = "";

    onMount(async () => {
        try {
            const [keys, provRes] = await Promise.all([
                vault.listAiKeys(),
                api.getAiProviders(),
            ]);

            providers = provRes.providers ?? [];
            aiKeys.set(keys);

            // Auto-select first key if nothing is active yet
            if (keys.length && !$activeAiKey) {
                activeAiKey.set({ keyId: keys[0].id, model: keys[0].model });
            }

            // Apply persisted system prompt if present
            const saved = getSettings()?.systemPrompt;
            if (saved && typeof saved === "string" && saved.trim()) {
                messages[0] = { role: "system", content: saved };
                messages = messages; // trigger reactivity
                promptDraft = saved;
            }
        } catch {
            // Backend unreachable — leave defaults.
        }
    });

    function openPromptEditor() {
        promptDraft = messages[0].content;
        promptError = "";
        showKeyManager = false;
        showOptions = false;
        showPromptEditor = true;
    }

    async function savePrompt() {
        if (promptSaving) return;
        promptError = "";
        promptSaving = true;
        try {
            updateSettings({ systemPrompt: promptDraft });
            messages[0] = { role: "system", content: promptDraft };
            messages = messages;
            showPromptEditor = false;
            showOptions = false;
        } catch (err) {
            promptError = err.message;
        } finally {
            promptSaving = false;
        }
    }

    async function resetPrompt() {
        if (promptSaving) return;
        promptError = "";
        promptSaving = true;
        try {
            updateSettings({ systemPrompt: null });
            messages[0] = { role: "system", content: quermySystemPrompt };
            messages = messages;
            promptDraft = quermySystemPrompt;
            showPromptEditor = false;
            showOptions = false;
        } catch (err) {
            promptError = err.message;
        } finally {
            promptSaving = false;
        }
    }

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

    // Models available for the active key's provider
    let availableModels = [];
    $: {
        if ($activeAiKey?.keyId && $aiKeys.length && providers.length) {
            const key = $aiKeys.find((k) => k.id === $activeAiKey.keyId);
            availableModels = key
                ? (providers.find((p) => p.id === key.provider)?.models ?? [])
                : [];
        } else {
            availableModels = [];
        }
    }

    function scrollToBottom() {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    /*
     * Query suggestion run state
     */

    // Maps suggestionId → 'idle' | 'running' | 'done' | 'error'
    let suggestionStates = {};

    async function runSuggestion(suggestionId, database, sql) {
        suggestionStates = { ...suggestionStates, [suggestionId]: "running" };
        try {
            const result = await api.runQuery(database, sql);
            suggestionStates = { ...suggestionStates, [suggestionId]: "done" };

            // Attach the result to the suggestion message so it renders inline
            messages = messages.map((m) =>
                m.suggestionId === suggestionId
                    ? {
                          ...m,
                          queryResult: formatQueryResult(result),
                          queryError: null,
                      }
                    : m,
            );
            setTimeout(scrollToBottom, 0);
        } catch (err) {
            suggestionStates = { ...suggestionStates, [suggestionId]: "error" };
            messages = messages.map((m) =>
                m.suggestionId === suggestionId
                    ? { ...m, queryError: err.message, queryResult: null }
                    : m,
            );
            setTimeout(scrollToBottom, 0);
        }
    }

    /** Render query results as a markdown table (≤50 rows) or a row-count summary. */
    function formatQueryResult(result) {
        const { columns = [], rows = [], durationMs = 0 } = result;
        if (!rows.length) return `_Query returned no rows (${durationMs}ms)_`;

        if (columns.length && rows.length <= 50) {
            const header = `| ${columns.map((c) => c.name).join(" | ")} |`;
            const sep = `| ${columns.map(() => "---").join(" | ")} |`;
            const body = rows
                .map(
                    (r) =>
                        `| ${columns.map((c) => String(r[c.name] ?? "")).join(" | ")} |`,
                )
                .join("\n");
            return `${header}\n${sep}\n${body}\n\n_${rows.length} row(s) · ${durationMs}ms_`;
        }

        return `_${rows.length} row(s) returned in ${durationMs}ms_`;
    }

    /*
     * Messaging
     */

    function buildContextMessage() {
        try {
            const p = new URLSearchParams(location.hash.slice(1));
            const db = p.get("db");
            const table = p.get("table");
            const context = [];

            if (db) context.push(`database: \`${db}\``);
            else context.push("database: unknown");
            if (table) context.push(`table: \`${table}\``);
            else context.push("table: unknown");

            return `CONTEXT:\n${context.join("\n")}`;
        } catch (_) {}

        return null;
    }

    async function send() {
        const text = input.trim();
        if (!text || busy || !$activeAiKey) return;

        messages = [...messages, { role: "user", content: text }];
        input = "";
        busy = true;
        streamingReply = "";
        setTimeout(scrollToBottom, 0);

        const contextMsg = buildContextMessage();

        // Build the message list to send: strip internal bubbles, then inject
        // a system context message immediately before the last user message.
        const sendable = messages.filter(
            (m) => !m.isSuggestion && !m.isQueryResult,
        );
        const withContext = contextMsg
            ? [
                  ...sendable.slice(0, -1),
                  { role: "assistant", content: contextMsg },
                  sendable[sendable.length - 1],
              ]
            : sendable;

        try {
            // Decrypt the active key before streaming — never stored in component state.
            const decrypted = await vault.getDecryptedAiKey($activeAiKey.keyId);
            if (!decrypted) throw new Error("AI key not found in vault.");

            for await (const event of api.aiChatStream(
                decrypted.provider,
                decrypted.apiKey,
                $activeAiKey.model,
                withContext,
            )) {
                if (event.type === "text") {
                    streamingReply += event.chunk;
                    setTimeout(scrollToBottom, 0);
                } else if (
                    event.type === "tool_result" &&
                    event.name === "suggest_query" &&
                    event.ok &&
                    event.result?.sql
                ) {
                    // Flush any text that arrived before this suggestion
                    if (streamingReply) {
                        messages = [
                            ...messages,
                            { role: "assistant", content: streamingReply },
                        ];
                        streamingReply = "";
                    }
                    messages = [
                        ...messages,
                        {
                            role: "assistant",
                            isSuggestion: true,
                            suggestionId: crypto.randomUUID(),
                            database: event.result.database,
                            sql: event.result.sql,
                            rationale: event.result.rationale,
                        },
                    ];
                    setTimeout(scrollToBottom, 0);
                }
                // tool_call events are currently not shown in the bubble list
            }

            // Flush any remaining streamed text
            if (streamingReply) {
                messages = [
                    ...messages,
                    { role: "assistant", content: streamingReply },
                ];
            }
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

    // Start a new conversation but keep the current system prompt and initial greeting
    function clearChat() {
        messages = [messages[0], messages[1]];
        suggestionStates = {};
    }

    function handleMessagesClick(e) {
        const btn = e.target.closest(".copy-code-btn");
        if (!btn) return;
        const code = decodeURIComponent(btn.dataset.code ?? "");
        navigator.clipboard.writeText(code).then(() => {
            btn.textContent = "Copied!";
            setTimeout(() => {
                btn.textContent = "Copy";
            }, 2000);
        });
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
    <div class="border-b border-(--line) shrink-0 bg-(--bg-2)">
        <!-- title row -->
        <div class="px-3.5 py-2.25 flex items-center justify-between">
            <div
                class="flex items-center gap-1.75 text-[12.5px] font-medium text-(--ink-1)"
            >
                <span class="text-(--acc) text-[13px]">
                    <AIIcon />
                </span>
                <span>AI</span>
                {#if activeKeyObj && !showOptions && !showKeyManager && !showPromptEditor}
                    <span class="text-[10px] text-(--ink-3) mono">
                        {activeKeyObj.label} · {$activeAiKey?.model ??
                            activeKeyObj.model}
                    </span>
                {/if}
            </div>
            <Btn
                on:click={() => {
                    showOptions = !showOptions;
                    showKeyManager = false;
                    showPromptEditor = false;
                }}
                class="px-2! py-0.5!"
                title="AI options"
            >
                <ConfigIcon />
            </Btn>
        </div>

        <!-- collapsible options tray -->
        {#if showOptions}
            <div
                class="px-3 pb-3 flex flex-col gap-2 border-t border-(--line) pt-2.5"
            >
                {#if $aiKeys.length > 0}
                    <!-- Key + model row -->
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs text-(--ink-3) shrink-0 w-10">
                            Key
                        </span>
                        <Select
                            value={$activeAiKey?.keyId ?? ""}
                            on:change={(e) => selectKey(e.target.value)}
                        >
                            {#each $aiKeys as k}
                                <option value={k.id}>{k.label}</option>
                            {/each}
                        </Select>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs text-(--ink-3) shrink-0 w-10">
                            Model
                        </span>
                        {#if availableModels.length > 0}
                            <Select
                                value={$activeAiKey?.model ?? ""}
                                on:change={onModelChange}
                            >
                                {#each availableModels as m}
                                    <option value={m}>{m}</option>
                                {/each}
                            </Select>
                        {:else if activeKeyObj}
                            <span class="text-[11px] text-(--ink-0) mono"
                                >{activeKeyObj.model}</span
                            >
                        {/if}
                    </div>
                {/if}
                <!-- Action buttons -->
                <div class="flex gap-1.5 pt-0.5">
                    <Btn
                        on:click={() => {
                            showOptions = false;
                            showKeyManager = true;
                        }}
                    >
                        <KeyIcon />
                        Manage keys
                    </Btn>
                    <Btn on:click={openPromptEditor}>
                        <EditIcon />
                        System prompt
                    </Btn>
                    <Btn
                        on:click={() => {
                            clearChat();
                            showOptions = false;
                        }}
                    >
                        <ClearIcon />
                        Clear chat
                    </Btn>
                </div>
            </div>
        {/if}
    </div>

    {#if showKeyManager}
        <div class="flex-1 overflow-hidden">
            <VaultGate>
                <AIKeyManager onClose={() => (showKeyManager = false)} />
            </VaultGate>
        </div>
    {:else if showPromptEditor}
        <div class="flex-1 flex flex-col overflow-hidden p-3 gap-2">
            <p class="text-[11px] text-(--ink-3) leading-snug">
                Customise the system prompt sent to the AI at the start of every
                conversation. Changes are saved in your browser and persist
                across sessions.
            </p>
            <CodeEditor bind:value={promptDraft} mode="markdown"></CodeEditor>
            {#if promptError}
                <p class="text-[11px] text-red-400">{promptError}</p>
            {/if}
            <div class="flex gap-1.5 justify-end">
                <button
                    on:click={resetPrompt}
                    disabled={promptSaving}
                    class="px-2.5 py-1 text-[11px] rounded-(--radius) border border-(--line) text-(--ink-3) hover:border-(--acc) hover:text-(--acc) transition-colors duration-80 disabled:opacity-40"
                >
                    Reset to default
                </button>
                <button
                    on:click={savePrompt}
                    disabled={promptSaving || !promptDraft.trim()}
                    class="px-2.5 py-1 text-[11px] rounded-(--radius) bg-(--acc)/10 border border-(--acc)/40 text-(--acc) hover:bg-(--acc)/20 transition-colors duration-80 disabled:opacity-40"
                >
                    {promptSaving ? "Saving…" : "Save"}
                </button>
            </div>
        </div>
    {:else if $aiKeys.length === 0}
        <div
            class="flex-1 flex flex-col items-center justify-center gap-3 p-4 text-center"
        >
            <span class="text-[28px] opacity-30">
                <KeyIcon />
            </span>
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
            {#each messages as msg}
                {#if msg.role === "assistant" && msg.isSuggestion}
                    <!-- Query suggestion bubble -->
                    {@const state =
                        suggestionStates[msg.suggestionId] ?? "idle"}
                    <div class="flex gap-1.75 items-start">
                        <div
                            class="w-5.5 h-5.5 rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-(--acc) flex items-center justify-center text-[11px] shrink-0 mt-0.5"
                        >
                            <AIIcon />
                        </div>
                        <div
                            class="query-suggestion min-w-0 max-w-[88%] rounded-lg border border-(--line) bg-(--bg-2) overflow-hidden text-[12px]"
                        >
                            <!-- Rationale -->
                            {#if msg.rationale}
                                <p
                                    class="px-2.75 pt-2 pb-1.5 text-(--ink-2) text-[11.5px] leading-snug"
                                >
                                    {msg.rationale}
                                </p>
                            {/if}
                            <!-- SQL block -->
                            <pre
                                class="sql-preview m-0 px-2.75 py-2 bg-(--bg-1) border-y border-(--line) overflow-x-auto text-[11px] leading-relaxed font-mono text-(--ink-1) {msg.rationale
                                    ? ''
                                    : 'rounded-t-lg'}">{msg.sql}</pre>
                            <!-- Action bar -->
                            <div
                                class="flex items-center justify-between gap-2 px-2.75 py-1.75"
                            >
                                {#if msg.database}
                                    <span
                                        class="text-[10px] text-(--ink-3) mono truncate"
                                    >
                                        db: {msg.database}
                                    </span>
                                {:else}
                                    <span></span>
                                {/if}
                                <div class="flex items-center gap-1.5 shrink-0">
                                    {#if state === "done"}
                                        <span
                                            class="text-[10.5px] text-emerald-400"
                                            >✓ ran</span
                                        >
                                    {:else if state === "error"}
                                        <span class="text-[10.5px] text-red-400"
                                            >✗ failed</span
                                        >
                                    {/if}
                                    <button
                                        class="copy-sql-btn text-[10px] px-1.5 py-0.5 bg-(--bg-3) border border-(--line) rounded-[3px] text-(--ink-3) hover:border-(--acc) hover:text-(--acc) transition-colors duration-80"
                                        on:click={(event) => {
                                            navigator.clipboard
                                                .writeText(msg.sql)
                                                .then(() => {
                                                    let btn = event.target;
                                                    btn.textContent = "Copied!";
                                                    setTimeout(() => {
                                                        btn.textContent =
                                                            "Copy";
                                                    }, 2000);
                                                });
                                        }}
                                    >
                                        Copy
                                    </button>
                                    <button
                                        class="run-btn flex gap-1 items-center text-[10.5px] px-2 py-0.5 rounded-[3px] border font-medium transition-colors duration-80
                                               {state === 'running'
                                            ? 'bg-(--bg-3) border-(--line) text-(--ink-3) cursor-wait'
                                            : state === 'done'
                                              ? 'bg-emerald-950/40 border-emerald-800/50 text-emerald-400 hover:bg-emerald-900/40'
                                              : 'bg-(--acc)/10 border-(--acc)/40 text-(--acc) hover:bg-(--acc)/20'}"
                                        disabled={state === "running"}
                                        on:click={() =>
                                            runSuggestion(
                                                msg.suggestionId,
                                                msg.database,
                                                msg.sql,
                                            )}
                                    >
                                        {#if state === "running"}
                                            Running…
                                        {:else if state === "done"}
                                            <RunIcon />
                                            Run again
                                        {:else}
                                            <RunIcon />
                                            Run
                                        {/if}
                                    </button>
                                </div>
                            </div>
                            <!-- Inline query result -->
                            {#if msg.queryResult}
                                <div
                                    class="prose-md max-h-100 px-2.75 py-2 border-t border-(--line) text-(--ink-1) overflow-x-auto text-[11.5px]"
                                >
                                    {@html parse(msg.queryResult)}
                                </div>
                            {:else if msg.queryError}
                                <p
                                    class="px-2.75 py-2 border-t border-(--line) text-[11px] text-red-400"
                                >
                                    Query failed: {msg.queryError}
                                </p>
                            {/if}
                        </div>
                    </div>
                {:else if msg.role !== "system"}
                    <!-- Normal message bubble -->
                    <div
                        class="flex gap-1.75 items-start {msg.role === 'user'
                            ? 'flex-row-reverse'
                            : ''}"
                    >
                        {#if msg.role === "assistant"}
                            <div
                                class="w-5.5 h-5.5 rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-(--acc) flex items-center justify-center text-[11px] shrink-0 mt-0.5"
                            >
                                <AIIcon />
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

            <!-- Streaming / loading indicator -->
            {#if busy}
                <div class="flex gap-1.75 items-start">
                    <div
                        class="w-5.5 h-5.5 rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-(--acc) flex items-center justify-center text-[11px] shrink-0 mt-0.5"
                    >
                        <AIIcon />
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
                class="cursor-pointer w-7.5 h-7.5 bg-(--acc) text-[#0a0c0a] border-0 rounded-(--radius) text-[15px] font-bold flex items-center justify-center shrink-0 transition-[background] duration-80 disabled:bg-(--bg-3) disabled:text-(--ink-3) enabled:hover:bg-(--acc-d)"
                on:click={send}
                disabled={!input.trim() || busy || !$activeAiKey}
                title="Send (Enter)"
            >
                <SendIcon />
            </button>
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

    /* ── Query suggestion SQL preview ── */
    .sql-preview {
        white-space: pre;
        tab-size: 2;
    }
</style>
