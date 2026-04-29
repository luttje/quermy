<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import { view, session, toast } from "../lib/store.js";

    let connections = [];
    let engines = ["mysql"];
    let loading = true;

    // form state
    let form = {
        engine: "mysql",
        name: "",
        host: "127.0.0.1",
        port: 3306,
        username: "root",
        password: "",
        database: "",
        save: true,
    };
    let busy = false;

    onMount(async () => {
        try {
            const [connsRes, enginesRes] = await Promise.all([
                api.listConnections(),
                api.getSession().catch(() => ({ engines: ["mysql"] })),
            ]);
            connections = connsRes.connections || [];
            try {
                const e = await fetch("/api/engines", {
                    credentials: "include",
                }).then((r) => r.json());
                if (e.engines) engines = e.engines;
            } catch (_) {}
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loading = false;
        }
    });

    async function connect() {
        busy = true;
        try {
            const payload = { ...form };
            if (!payload.name) payload.name = `${payload.host}:${payload.port}`;
            await api.connect(payload);
            const s = await api.getSession();
            session.set(s.active);
            toast("Connected", "success");
            view.set({ name: "databases" });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            busy = false;
        }
    }

    async function connectSaved(c) {
        busy = true;
        try {
            await api.connectSaved(c.id);
            const s = await api.getSession();
            session.set(s.active);
            toast(`Connected to ${c.name}`, "success");
            view.set({ name: "databases" });
        } catch (e) {
            toast(e.message, "error");
        } finally {
            busy = false;
        }
    }

    async function deleteSaved(c, ev) {
        ev.stopPropagation();
        if (!confirm(`Delete saved connection "${c.name}"?`)) return;
        try {
            await api.deleteConnection(c.id);
            connections = connections.filter((x) => x.id !== c.id);
            toast("Connection removed");
        } catch (e) {
            toast(e.message, "error");
        }
    }
</script>

<div class="connect-page animate-in">
    <header class="hero">
        <div class="word-wrap">
            <h1 class="wordmark">Quermy</h1>
            <span class="tag mono">// modern database administration</span>
        </div>
        <p class="lead">
            A keyboard-first relational client that lives in your stack. Connect
            once, and your databases are a few keystrokes away — for as long as
            you keep the project around.
        </p>
    </header>

    <div class="grid">
        <!-- saved connections -->
        <section class="panel">
            <div class="panel-head">
                <h2>Saved connections</h2>
                <span class="count mono">{connections.length}</span>
            </div>

            {#if loading}
                <div class="placeholder mono">loading…</div>
            {:else if connections.length === 0}
                <div class="placeholder">
                    <div class="placeholder-mark">⌁</div>
                    <div>No saved connections yet.</div>
                    <div
                        class="muted"
                        style="font-size: 12px; margin-top: 4px;"
                    >
                        Save your first one on the right.
                    </div>
                </div>
            {:else}
                <ul class="conn-list">
                    {#each connections as c (c.id)}
                        <li>
                            <a
                                class="conn-row"
                                on:click={() => connectSaved(c)}
                                disabled={busy}
                            >
                                <div class="conn-engine mono">{c.engine}</div>
                                <div class="conn-body">
                                    <div class="conn-name">{c.name}</div>
                                    <div class="conn-meta mono">
                                        {c.username}@{c.host}:{c.port}{c.database
                                            ? ` · ${c.database}`
                                            : ""}
                                    </div>
                                </div>
                                <div class="conn-actions">
                                    <span class="conn-arrow">↩</span>
                                    <button
                                        class="btn-icon"
                                        title="Delete"
                                        on:click={(e) => deleteSaved(c, e)}
                                        >×</button
                                    >
                                </div>
                            </a>
                        </li>
                    {/each}
                </ul>
            {/if}
        </section>

        <!-- new connection -->
        <section class="panel">
            <div class="panel-head">
                <h2>New connection</h2>
            </div>

            <form on:submit|preventDefault={connect} class="form">
                <label class="field">
                    <span class="lbl">Engine</span>
                    <select class="select" bind:value={form.engine}>
                        {#each engines as e}<option value={e}>{e}</option
                            >{/each}
                    </select>
                </label>

                <label class="field">
                    <span class="lbl">Display name</span>
                    <input
                        class="input"
                        type="text"
                        bind:value={form.name}
                        placeholder="e.g. staging-db"
                    />
                </label>

                <div class="row">
                    <label class="field flex-3">
                        <span class="lbl">Host</span>
                        <input
                            class="input"
                            type="text"
                            bind:value={form.host}
                            required
                        />
                    </label>
                    <label class="field flex-1">
                        <span class="lbl">Port</span>
                        <input
                            class="input"
                            type="number"
                            bind:value={form.port}
                            required
                        />
                    </label>
                </div>

                <div class="row">
                    <label class="field flex-1">
                        <span class="lbl">Username</span>
                        <input
                            class="input"
                            type="text"
                            bind:value={form.username}
                            required
                        />
                    </label>
                    <label class="field flex-1">
                        <span class="lbl">Password</span>
                        <input
                            class="input"
                            type="password"
                            bind:value={form.password}
                            placeholder="••••••"
                        />
                    </label>
                </div>

                <label class="field">
                    <span class="lbl"
                        >Default database <span class="optional">optional</span
                        ></span
                    >
                    <input
                        class="input"
                        type="text"
                        bind:value={form.database}
                        placeholder="leave empty to choose later"
                    />
                </label>

                <label class="checkbox">
                    <input type="checkbox" bind:checked={form.save} />
                    <span
                        >Save this connection (password encrypted at rest)</span
                    >
                </label>

                <button class="btn btn-primary" type="submit" disabled={busy}>
                    {busy ? "Connecting…" : "Connect →"}
                </button>
            </form>
        </section>
    </div>
</div>

<style>
    .connect-page {
        max-width: 1080px;
        margin: 0 auto;
        padding: 64px 32px;
        width: 100%;
    }

    .hero {
        margin-bottom: 56px;
        max-width: 640px;
    }

    .word-wrap {
        display: flex;
        align-items: baseline;
        gap: 14px;
        flex-wrap: wrap;
    }

    .wordmark {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 72px;
        line-height: 0.9;
        letter-spacing: -0.04em;
        margin: 0;
        font-variation-settings:
            "opsz" 144,
            "SOFT" 50;
        background: linear-gradient(180deg, var(--ink-0) 0%, var(--ink-1) 100%);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .tag {
        color: var(--acc);
        font-size: 13px;
    }

    .lead {
        color: var(--ink-1);
        font-size: 16px;
        line-height: 1.55;
        margin-top: 16px;
        max-width: 520px;
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 28px;
    }
    @media (max-width: 880px) {
        .grid {
            grid-template-columns: 1fr;
        }
        .wordmark {
            font-size: 56px;
        }
    }

    .panel {
        background: var(--bg-1);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .panel-head {
        padding: 16px 20px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: baseline;
    }
    .panel-head h2 {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 22px;
        margin: 0;
        letter-spacing: -0.02em;
    }
    .count {
        color: var(--ink-3);
        font-size: 12px;
    }

    .placeholder {
        padding: 56px 24px;
        text-align: center;
        color: var(--ink-2);
    }
    .placeholder-mark {
        font-size: 32px;
        color: var(--ink-3);
        margin-bottom: 12px;
    }

    .conn-list {
        list-style: none;
        margin: 0;
        padding: 6px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .conn-row {
        cursor: pointer;
        width: 100%;
        display: flex;
        gap: 14px;
        align-items: center;
        background: transparent;
        border: 1px solid transparent;
        border-radius: var(--radius);
        padding: 12px 14px;
        text-align: left;
        transition:
            background 80ms ease,
            border-color 80ms ease;
    }
    .conn-row:hover {
        background: var(--bg-2);
        border-color: var(--line);
    }
    .conn-row:hover .conn-arrow {
        color: var(--acc);
        transform: translateX(2px);
    }

    .conn-engine {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--acc);
        background: rgba(200, 255, 90, 0.08);
        border: 1px solid rgba(200, 255, 90, 0.2);
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 600;
    }

    .conn-body {
        flex: 1;
        min-width: 0;
    }
    .conn-name {
        font-weight: 500;
        color: var(--ink-0);
        font-size: 14px;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .conn-meta {
        color: var(--ink-2);
        font-size: 11.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .conn-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .conn-arrow {
        color: var(--ink-3);
        font-size: 16px;
        transition:
            color 100ms ease,
            transform 100ms ease;
    }
    .btn-icon {
        background: transparent;
        border: 1px solid transparent;
        color: var(--ink-3);
        width: 24px;
        height: 24px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        line-height: 1;
    }
    .btn-icon:hover {
        background: rgba(255, 115, 103, 0.1);
        color: var(--danger);
        border-color: rgba(255, 115, 103, 0.2);
    }

    .form {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .row {
        display: flex;
        gap: 12px;
    }
    .flex-1 {
        flex: 1;
    }
    .flex-3 {
        flex: 3;
    }

    .optional {
        color: var(--ink-3);
        font-weight: 400;
        text-transform: none;
        letter-spacing: 0;
        font-size: 11px;
        margin-left: 4px;
    }

    .checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--ink-1);
        font-size: 13px;
        cursor: pointer;
        user-select: none;
        padding: 4px 0;
    }
    .checkbox input {
        accent-color: var(--acc);
        width: 14px;
        height: 14px;
    }

    .btn-primary {
        margin-top: 6px;
        justify-content: center;
        padding: 11px 14px;
    }
</style>
