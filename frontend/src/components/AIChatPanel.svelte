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

<div class="chat-panel">
    <div class="chat-header">
        <div class="chat-title">
            <span class="ai-icon">✦</span>
            <span>AI Assistant</span>
        </div>
        <span class="mock-badge mono">mock</span>
    </div>

    <div class="chat-messages" bind:this={messagesEl}>
        {#each messages as msg}
            <div class="msg" class:user={msg.role === "user"}>
                {#if msg.role === "assistant"}
                    <div class="msg-avatar">✦</div>
                {/if}
                <div class="msg-bubble">{msg.content}</div>
            </div>
        {/each}
    </div>

    <div class="chat-footer">
        <textarea
            class="chat-input"
            placeholder="Ask about your data…"
            bind:value={input}
            on:keydown={onKeydown}
            rows="3"
        ></textarea>
        <button
            class="send-btn"
            on:click={send}
            disabled={!input.trim()}
            title="Send (Enter)"
        >
            ↑
        </button>
    </div>
</div>

<style>
    .chat-panel {
        height: 100%;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .chat-header {
        padding: 9px 14px;
        border-bottom: 1px solid var(--line);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        background: var(--bg-2);
    }

    .chat-title {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--ink-1);
    }

    .ai-icon {
        color: var(--acc);
        font-size: 13px;
    }

    .mock-badge {
        font-size: 9px;
        padding: 2px 6px;
        background: var(--bg-3);
        border: 1px solid var(--line-strong);
        border-radius: 3px;
        color: var(--ink-3);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .chat-messages {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 12px 10px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .msg {
        display: flex;
        gap: 7px;
        align-items: flex-start;
    }

    .msg.user {
        flex-direction: row-reverse;
    }

    .msg-avatar {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: rgba(200, 255, 90, 0.08);
        border: 1px solid rgba(200, 255, 90, 0.2);
        color: var(--acc);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .msg-bubble {
        max-width: 88%;
        padding: 7px 11px;
        border-radius: 8px;
        font-size: 12px;
        line-height: 1.5;
        color: var(--ink-1);
        background: var(--bg-2);
        border: 1px solid var(--line);
        word-break: break-word;
    }

    .msg.user .msg-bubble {
        background: rgba(200, 255, 90, 0.07);
        border-color: rgba(200, 255, 90, 0.15);
        color: var(--ink-0);
    }

    .chat-footer {
        border-top: 1px solid var(--line);
        padding: 8px;
        display: flex;
        gap: 6px;
        align-items: flex-end;
        flex-shrink: 0;
        background: var(--bg-1);
    }

    .chat-input {
        flex: 1;
        background: var(--bg-input);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 7px 10px;
        font-size: 12px;
        color: var(--ink-0);
        resize: none;
        line-height: 1.45;
        min-height: 0;
    }

    .chat-input:focus {
        outline: none;
        border-color: var(--acc);
        box-shadow: 0 0 0 2px var(--acc-glow);
    }

    .send-btn {
        width: 30px;
        height: 30px;
        background: var(--acc);
        color: #0a0c0a;
        border: 0;
        border-radius: var(--radius);
        font-size: 15px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background 80ms;
    }

    .send-btn:disabled {
        background: var(--bg-3);
        color: var(--ink-3);
    }

    .send-btn:not(:disabled):hover {
        background: var(--acc-d);
    }
</style>
