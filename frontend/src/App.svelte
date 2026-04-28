<script>
    import { onMount } from "svelte";
    import { api } from "./lib/api.js";
    import { view, session, toast } from "./lib/store.js";

    import ConnectView from "./views/ConnectView.svelte";
    import DatabasesView from "./views/DatabasesView.svelte";
    import TablesView from "./views/TablesView.svelte";
    import BrowseView from "./views/BrowseView.svelte";
    import QueryView from "./views/QueryView.svelte";
    import Toaster from "./components/Toaster.svelte";

    let bootstrapping = true;

    onMount(async () => {
        try {
            const r = await api.getSession();
            if (r.active) {
                session.set(r.active);
                view.set({ name: "databases" });
            } else {
                view.set({ name: "connect" });
            }
        } catch (e) {
            view.set({ name: "connect" });
        } finally {
            bootstrapping = false;
        }
    });

    async function disconnect() {
        try {
            await api.disconnect();
            session.set(null);
            view.set({ name: "connect" });
            toast("Disconnected");
        } catch (e) {
            toast(e.message, "error");
        }
    }
</script>

<Toaster />

{#if bootstrapping}
    <div class="boot mono">initializing…</div>
{:else}
    <!-- Topbar — only shown when connected -->
    {#if $session && $view.name !== "connect"}
        <header class="topbar">
            <button
                class="brand"
                on:click={() => view.set({ name: "databases" })}
            >
                <span class="brand-mark">Q</span>
                <span class="brand-name">Quermy</span>
            </button>

            <div class="conn-pill">
                <span class="conn-dot"></span>
                <span class="conn-text mono">
                    {$session.username}@{$session.host}{$session.port !== 3306
                        ? ":" + $session.port
                        : ""}
                </span>
                <span class="conn-engine mono">{$session.engine}</span>
            </div>

            <div class="spacer"></div>

            <nav class="topnav">
                <button
                    class="nav-btn"
                    class:active={$view.name === "databases" ||
                        $view.name === "tables" ||
                        $view.name === "browse"}
                    on:click={() => view.set({ name: "databases" })}
                    >Databases</button
                >
                <button
                    class="nav-btn"
                    class:active={$view.name === "query"}
                    on:click={() =>
                        view.set({
                            name: "query",
                            database: $view.database || "",
                        })}>Query</button
                >
            </nav>

            <button class="btn btn-ghost disconnect" on:click={disconnect}>
                Disconnect
            </button>
        </header>
    {/if}

    <main>
        {#if $view.name === "connect"}
            <ConnectView />
        {:else if $view.name === "databases"}
            <DatabasesView />
        {:else if $view.name === "tables"}
            {#key $view.database}
                <TablesView database={$view.database} />
            {/key}
        {:else if $view.name === "browse"}
            {#key $view.database + ":" + $view.table}
                <BrowseView database={$view.database} table={$view.table} />
            {/key}
        {:else if $view.name === "query"}
            {#key $view.name}
                <QueryView database={$view.database || ""} />
            {/key}
        {/if}
    </main>
{/if}

<style>
    .boot {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--ink-3);
    }

    .topbar {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px 24px;
        background: rgba(10, 12, 10, 0.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--line);
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .brand {
        background: transparent;
        border: 0;
        display: flex;
        gap: 10px;
        align-items: center;
        padding: 4px 8px 4px 4px;
        border-radius: 6px;
        transition: background 80ms ease;
    }
    .brand:hover {
        background: var(--bg-2);
    }
    .brand-mark {
        width: 26px;
        height: 26px;
        border-radius: 5px;
        background: var(--acc);
        color: #0a0c0a;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-family: var(--font-display);
        font-weight: 600;
        font-size: 16px;
        line-height: 1;
        box-shadow: 0 0 12px var(--acc-glow);
    }
    .brand-name {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 18px;
        letter-spacing: -0.01em;
        color: var(--ink-0);
    }

    .conn-pill {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 5px 12px 5px 10px;
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: 999px;
        font-size: 12px;
    }
    .conn-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--ok);
        box-shadow: 0 0 8px rgba(127, 217, 127, 0.5);
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%,
        100% {
            opacity: 1;
        }
        50% {
            opacity: 0.55;
        }
    }
    .conn-text {
        color: var(--ink-1);
    }
    .conn-engine {
        color: var(--ink-3);
        text-transform: uppercase;
        font-size: 10px;
        letter-spacing: 0.06em;
        border-left: 1px solid var(--line-strong);
        padding-left: 10px;
        font-weight: 600;
    }

    .spacer {
        flex: 1;
    }

    .topnav {
        display: flex;
        gap: 4px;
        padding: 3px;
        background: var(--bg-2);
        border-radius: 7px;
        border: 1px solid var(--line);
    }
    .nav-btn {
        background: transparent;
        border: 0;
        color: var(--ink-2);
        padding: 6px 14px;
        border-radius: 5px;
        font-size: 13px;
        font-weight: 500;
        transition:
            background 80ms ease,
            color 80ms ease;
    }
    .nav-btn:hover {
        color: var(--ink-0);
    }
    .nav-btn.active {
        background: var(--bg-0);
        color: var(--ink-0);
        box-shadow: inset 0 0 0 1px var(--line-strong);
    }

    .disconnect {
        color: var(--ink-2);
        font-size: 12.5px;
    }
    .disconnect:hover {
        color: var(--danger);
    }

    main {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    @media (max-width: 720px) {
        .conn-pill {
            display: none;
        }
        .topbar {
            padding: 12px 16px;
            gap: 10px;
        }
    }
</style>
