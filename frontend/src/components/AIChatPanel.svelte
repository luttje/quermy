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
        class="px-[14px] py-[9px] border-b border-[var(--line)] flex items-center justify-between shrink-0 bg-[var(--bg-2)]"
    >
        <div
            class="flex items-center gap-[7px] text-[12.5px] font-medium text-[var(--ink-1)]"
        >
            <span class="text-[var(--acc)] text-[13px]">✦</span>
            <span>AI Assistant</span>
        </div>
        <span
            class="mono text-[9px] px-[6px] py-[2px] bg-[var(--bg-3)] border border-[var(--line-strong)] rounded-[3px] text-[var(--ink-3)] tracking-[0.08em] uppercase"
            >mock</span
        >
    </div>

    <!-- messages -->
    <div
        class="flex-1 min-h-0 overflow-y-auto px-[10px] py-3 flex flex-col gap-[10px]"
        bind:this={messagesEl}
    >
        {#each messages as msg}
            <div
                class="flex gap-[7px] items-start {msg.role === 'user'
                    ? 'flex-row-reverse'
                    : ''}"
            >
                {#if msg.role === "assistant"}
                    <div
                        class="w-[22px] h-[22px] rounded-full bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] text-[var(--acc)] flex items-center justify-center text-[11px] shrink-0 mt-[2px]"
                    >
                        ✦
                    </div>
                {/if}
                <div
                    class="max-w-[88%] px-[11px] py-[7px] rounded-lg text-[12px] leading-[1.5] break-words {msg.role ===
                    'user'
                        ? 'bg-[rgba(200,255,90,0.07)] border border-[rgba(200,255,90,0.15)] text-[var(--ink-0)]'
                        : 'bg-[var(--bg-2)] border border-[var(--line)] text-[var(--ink-1)]'}"
                >
                    {msg.content}
                </div>
            </div>
        {/each}
    </div>

    <!-- footer -->
    <div
        class="border-t border-[var(--line)] p-2 flex gap-[6px] items-end shrink-0 bg-[var(--bg-1)]"
    >
        <textarea
            class="flex-1 bg-[var(--bg-input)] border border-[var(--line)] rounded-[var(--radius)] px-[10px] py-[7px] text-[12px] text-[var(--ink-0)] resize-none leading-[1.45] min-h-0 focus:outline-none focus:border-[var(--acc)] focus:shadow-[0_0_0_2px_var(--acc-glow)] placeholder:text-[var(--ink-3)]"
            placeholder="Ask about your data…"
            bind:value={input}
            on:keydown={onKeydown}
            rows="3"
        ></textarea>
        <button
            class="w-[30px] h-[30px] bg-[var(--acc)] text-[#0a0c0a] border-0 rounded-[var(--radius)] text-[15px] font-bold flex items-center justify-center shrink-0 transition-[background] duration-[80ms] disabled:bg-[var(--bg-3)] disabled:text-[var(--ink-3)] enabled:hover:bg-[var(--acc-d)]"
            on:click={send}
            disabled={!input.trim()}
            title="Send (Enter)">↑</button
        >
    </div>
</div>
