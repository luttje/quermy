<script>
    let messages = [
        {
            role: "assistant",
            content:
                "Ask me anything about your data — I can help write queries, explain results, or analyse your schema.",
        },
    ];
    let input = "";
    let messagesEl;

    function scrollToBottom() {
        if (messagesEl) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    function send() {
        const text = input.trim();
        if (!text) return;
        messages = [...messages, { role: "user", content: text }];
        input = "";
        scrollToBottom();

        // Mock response
        setTimeout(() => {
            messages = [
                ...messages,
                {
                    role: "assistant",
                    content:
                        "AI integration is not yet implemented. This panel is a placeholder — come back soon!",
                },
            ];
            scrollToBottom();
        }, 600);
    }

    function onKeydown(e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            send();
        }
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
        <span
            class="mono text-[9px] px-1.5 py-0.5 bg-(--bg-3) border border-(--line-strong) rounded-[3px] text-(--ink-3) tracking-[0.08em] uppercase"
            >mock</span
        >
    </div>

    <!-- messages -->
    <div
        class="flex-1 min-h-0 overflow-y-auto px-2.5 py-3 flex flex-col gap-2.5"
        bind:this={messagesEl}
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
                    class="max-w-[88%] px-2.75 py-1.75 rounded-lg text-[12px] leading-normal wrap-break-word {msg.role ===
                    'user'
                        ? 'bg-[rgba(200,255,90,0.07)] border border-[rgba(200,255,90,0.15)] text-(--ink-0)'
                        : 'bg-(--bg-2) border border-(--line) text-(--ink-1)'}"
                >
                    {msg.content}
                </div>
            </div>
        {/each}
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
            disabled={!input.trim()}
            title="Send (Enter)">↑</button
        >
    </div>
</div>
