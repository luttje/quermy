<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import * as vault from "../lib/vault.js";
    import { session, toast } from "../lib/store.js";
    import Btn from "../components/ui/Btn.svelte";
    import Input from "../components/ui/Input.svelte";
    import Select from "../components/ui/Select.svelte";
    import FormField from "../components/ui/FormField.svelte";

    let connections = [];
    let engines = [];
    let loading = true;

    /**
     * When the server admin has configured a server_connection in config.php,
     * this will be set to { engine, host, port, database } and the UI switches
     * to "hosted mode" — visitors only enter username + password.
     * null means standard local/dev mode with full connection form.
     */
    let serverConnection = null;

    // form state
    let form = {
        engine: "",
        name: "",
        host: "127.0.0.1",
        port: 3306,
        username: "root",
        password: "",
        database: "",
        save: true,
    };
    let busy = false;

    // Derived from selected engine meta (only used in local mode)
    $: selectedEngine = engines.find((e) => e.id === form.engine) ?? null;
    $: isFileConnection = selectedEngine?.connectionType === "file";

    // True when the server config has pre-selected a database (so users can't change it)
    $: serverPinsDatabase =
        serverConnection?.database != null && serverConnection.database !== "";

    // Auto-fill port and username when the engine selection changes (local mode only)
    let _prevEngineId = "";
    $: if (form.engine !== _prevEngineId && engines.length > 0) {
        _prevEngineId = form.engine;
        if (selectedEngine) {
            form.port = selectedEngine.defaultPort;
            form.username = selectedEngine.defaultUsername;
        }
    }

    onMount(async () => {
        try {
            const [conns, engRes, cfgRes] = await Promise.all([
                vault.listConnections(),
                api.getEngines(),
                api.getServerConfig(),
            ]);
            connections = conns;
            engines = engRes.engines || [];
            serverConnection = cfgRes.serverConnection ?? null;

            if (serverConnection) {
                // Hosted mode: pre-fill the fields the server controls.
                form.engine = serverConnection.engine;
                form.host = serverConnection.host ?? form.host;
                form.port = serverConnection.port ?? form.port;
                form.database = serverConnection.database ?? "";
                form.username = "";
            } else if (engines.length > 0) {
                form.engine = engines[0].id;
            }
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
            if (!payload.name) {
                payload.name = serverConnection
                    ? `${payload.username}@${serverConnection.host ?? serverConnection.engine}`
                    : isFileConnection
                      ? payload.database || "file-db"
                      : `${payload.host}:${payload.port}`;
            }
            // Always connect as adhoc; saving is handled client-side.
            await api.connect(payload);
            if (form.save) {
                await vault.saveConnection(payload);
                connections = await vault.listConnections();
            }
            const s = await api.getSession();
            session.set(s.active);
            toast("Connected", "success");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            busy = false;
        }
    }

    async function connectSaved(c) {
        busy = true;
        try {
            // Load full credentials from the local vault, then connect adhoc.
            const creds = await vault.loadConnection(c.id);
            if (!creds)
                throw new Error("Saved connection not found in local vault.");
            await api.connect(creds);
            const s = await api.getSession();
            session.set(s.active);
            toast(`Connected to ${c.name}`, "success");
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
            await vault.deleteConnection(c.id);
            connections = connections.filter((x) => x.id !== c.id);
            toast("Connection removed");
        } catch (e) {
            toast(e.message, "error");
        }
    }
</script>

<div class="animate-in max-w-270 mx-auto px-8 py-16 w-full">
    <header class="mb-14 max-w-160">
        <div class="flex items-baseline gap-3.5 flex-wrap">
            <h1 class="wordmark">Quermy</h1>
            <span class="mono text-(--acc) text-[13px]"
                >// modern database administration</span
            >
        </div>
        <p class="text-(--ink-1) text-[16px] leading-[1.55] mt-4 max-w-130">
            A keyboard-first relational client that lives in your stack. Connect
            once, and your databases are a few keystrokes away — for as long as
            you keep the project around.
        </p>
    </header>

    {#if loading}
        <div class="muted mono text-center py-14">loading…</div>
    {:else if serverConnection}
        <!-- ---------------------------------------------------------------- -->
        <!-- HOSTED MODE: server admin has pre-configured the connection.      -->
        <!-- Visitors only enter username + password.                          -->
        <!-- ---------------------------------------------------------------- -->
        <div class="max-w-100 mx-auto">
            <section
                class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden flex flex-col"
            >
                <div class="px-5 py-4 border-b border-(--line)">
                    <h2
                        class="font-(--font-display) text-[22px] tracking-[-0.02em] m-0"
                    >
                        Log in
                    </h2>
                    <div
                        class="mono text-(--ink-3) text-[12px] mt-1.5 flex items-center gap-1.5"
                    >
                        <span
                            class="mono text-[10px] uppercase tracking-[0.08em] text-(--acc) bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] px-2 py-0.5 rounded-sm font-semibold"
                            >{serverConnection.engine}</span
                        >
                        {#if serverConnection.host}
                            <span
                                >{serverConnection.host}{serverConnection.port
                                    ? `:${serverConnection.port}`
                                    : ""}</span
                            >
                        {/if}
                        {#if serverConnection.database}
                            <span>· {serverConnection.database}</span>
                        {/if}
                    </div>
                </div>

                <form
                    on:submit|preventDefault={connect}
                    class="p-5 flex flex-col gap-3.5"
                >
                    <FormField label="Username">
                        <Input
                            type="text"
                            bind:value={form.username}
                            required
                            autofocus
                        />
                    </FormField>

                    <FormField label="Password">
                        <Input
                            type="password"
                            bind:value={form.password}
                            placeholder="••••••"
                        />
                    </FormField>

                    {#if !serverPinsDatabase}
                        <FormField label="Database" optional>
                            <Input
                                type="text"
                                bind:value={form.database}
                                placeholder="leave empty to choose later"
                            />
                        </FormField>
                    {/if}

                    <label
                        class="flex items-center gap-2.5 text-(--ink-1) text-[13px] cursor-pointer select-none py-1"
                    >
                        <input
                            type="checkbox"
                            class="accent-(--acc) w-3.5 h-3.5"
                            bind:checked={form.save}
                        />
                        <span>Remember credentials (encrypted in browser)</span>
                    </label>

                    <Btn
                        variant="primary"
                        type="submit"
                        disabled={busy}
                        class="mt-1.5 justify-center py-2.75"
                    >
                        {busy ? "Connecting…" : "Log in →"}
                    </Btn>
                </form>
            </section>
        </div>
    {:else}
        <!-- ---------------------------------------------------------------- -->
        <!-- LOCAL / DEV MODE: full connection form + saved connections.       -->
        <!-- ---------------------------------------------------------------- -->
        <div class="grid grid-cols-2 gap-7 max-[880px]:grid-cols-1">
            <!-- saved connections -->
            <section
                class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden flex flex-col"
            >
                <div
                    class="px-5 py-4 border-b border-(--line) flex justify-between items-baseline"
                >
                    <h2
                        class="font-(--font-display) text-[22px] tracking-[-0.02em] m-0"
                    >
                        Saved connections
                    </h2>
                    <span class="mono text-(--ink-3) text-[12px]"
                        >{connections.length}</span
                    >
                </div>

                {#if connections.length === 0}
                    <div class="py-14 px-6 text-center muted">
                        <div class="text-[32px] text-(--ink-3) mb-3">⌁</div>
                        <div>No saved connections yet.</div>
                        <div class="muted text-[12px] mt-1">
                            Save your first one on the right.
                        </div>
                    </div>
                {:else}
                    <ul class="list-none m-0 p-1.5 flex flex-col gap-0.5">
                        {#each connections as c (c.id)}
                            <li>
                                <div class="relative group">
                                    <button
                                        type="button"
                                        class="w-full flex gap-3.5 items-center bg-transparent border border-transparent rounded-(--radius) px-3.5 py-3 text-left cursor-pointer transition-[background,border-color] duration-80 hover:bg-(--bg-2) hover:border-(--line)"
                                        on:click={() => connectSaved(c)}
                                        disabled={busy}
                                    >
                                        <div
                                            class="mono text-[10px] uppercase tracking-[0.08em] text-(--acc) bg-[rgba(200,255,90,0.08)] border border-[rgba(200,255,90,0.2)] px-2 py-1 rounded-sm font-semibold shrink-0"
                                        >
                                            {c.engine}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div
                                                class="font-medium text-(--ink-0) text-[14px] mb-0.5 whitespace-nowrap overflow-hidden text-ellipsis"
                                            >
                                                {c.name}
                                            </div>
                                            <div
                                                class="mono muted text-[11.5px] whitespace-nowrap overflow-hidden text-ellipsis"
                                            >
                                                {c.username}@{c.host}:{c.port}{c.database
                                                    ? ` · ${c.database}`
                                                    : ""}
                                            </div>
                                        </div>
                                        <span
                                            class="mr-8 text-(--ink-3) text-[16px] transition-[color,transform] duration-100 group-hover:text-(--acc) group-hover:translate-x-0.5"
                                            >↩</span
                                        >
                                    </button>

                                    <button
                                        type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 bg-transparent border border-transparent text-(--ink-3) w-6 h-6 rounded-sm inline-flex items-center justify-center text-[16px] leading-none hover:bg-[rgba(255,115,103,0.1)] hover:text-(--danger) hover:border-[rgba(255,115,103,0.2)] cursor-pointer"
                                        title="Delete"
                                        on:click={(ev) => deleteSaved(c, ev)}
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="4"
                                            stroke="currentColor"
                                            class="size-3"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M6 18 18 6M6 6l12 12"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </section>

            <!-- new connection -->
            <section
                class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden flex flex-col"
            >
                <div class="px-5 py-4 border-b border-(--line)">
                    <h2
                        class="font-(--font-display) text-[22px] tracking-[-0.02em] m-0"
                    >
                        New connection
                    </h2>
                </div>

                <form
                    on:submit|preventDefault={connect}
                    class="p-5 flex flex-col gap-3.5"
                >
                    <FormField label="Engine">
                        <Select bind:value={form.engine}>
                            {#each engines as e}<option value={e.id}
                                    >{e.label}</option
                                >{/each}
                        </Select>
                    </FormField>

                    <FormField label="Display name">
                        <Input
                            type="text"
                            bind:value={form.name}
                            placeholder="e.g. staging-db"
                        />
                    </FormField>

                    {#if !isFileConnection}
                        <div class="grid grid-cols-[3fr_1fr] gap-3">
                            <FormField label="Host">
                                <Input
                                    type="text"
                                    bind:value={form.host}
                                    required
                                />
                            </FormField>
                            <FormField label="Port">
                                <Input
                                    type="number"
                                    bind:value={form.port}
                                    required
                                />
                            </FormField>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <FormField label="Username">
                                <Input
                                    type="text"
                                    bind:value={form.username}
                                    required
                                />
                            </FormField>
                            <FormField label="Password">
                                <Input
                                    type="password"
                                    bind:value={form.password}
                                    placeholder="••••••"
                                />
                            </FormField>
                        </div>
                    {/if}

                    {#if isFileConnection}
                        <FormField label="File path">
                            <Input
                                type="text"
                                bind:value={form.database}
                                placeholder="/path/to/database.sqlite"
                                required
                            />
                        </FormField>
                    {:else}
                        <FormField label="Default database" optional>
                            <Input
                                type="text"
                                bind:value={form.database}
                                placeholder="leave empty to choose later"
                            />
                        </FormField>
                    {/if}

                    <label
                        class="flex items-center gap-2.5 text-(--ink-1) text-[13px] cursor-pointer select-none py-1"
                    >
                        <input
                            type="checkbox"
                            class="accent-(--acc) w-3.5 h-3.5"
                            bind:checked={form.save}
                        />
                        <span
                            >Save this connection (password encrypted at rest)</span
                        >
                    </label>

                    <Btn
                        variant="primary"
                        type="submit"
                        disabled={busy}
                        class="mt-1.5 justify-center py-2.75"
                    >
                        {busy ? "Connecting…" : "Connect →"}
                    </Btn>
                </form>
            </section>
        </div>
    {/if}
</div>
