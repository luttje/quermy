<script>
    import { onMount } from "svelte";
    import { api } from "../lib/api.js";
    import * as vault from "../lib/vault.js";
    import { aiKeys, activeAiKey } from "../lib/store.js";

    export let onClose = () => {};

    // Provider metadata — kept in sync with ProviderRegistry on the backend
    let providers = [];
    let loadingProviders = false;

    // Add-key form state
    let showAddForm = false;
    let draftProvider = "";
    let draftLabel = "";
    let draftKey = "";
    let draftModel = "";
    let saving = false;
    let saveError = "";

    // Edit-in-place state
    let editingId = null;
    let editLabel = "";
    let editModel = "";
    let editKey = "";
    let editSaving = false;
    let editError = "";

    // Models currently shown in the "Add" dropdown (changes when draftProvider changes)
    $: addModels = providers.find((p) => p.id === draftProvider)?.models ?? [];
    $: if (draftProvider && addModels.length && !draftModel) {
        draftModel =
            providers.find((p) => p.id === draftProvider)?.defaultModel ?? "";
    }

    // Models for the currently edited key's provider
    $: editModels = (() => {
        const key = $aiKeys.find((k) => k.id === editingId);
        if (!key) return [];
        return providers.find((p) => p.id === key.provider)?.models ?? [];
    })();

    onMount(async () => {
        await loadProviders();
        await refreshKeys();
    });

    async function loadProviders() {
        loadingProviders = true;
        try {
            const res = await api.getAiProviders();
            providers = res.providers ?? [];
            if (providers.length && !draftProvider) {
                draftProvider = providers[0].id;
            }
        } catch {
            providers = [];
        } finally {
            loadingProviders = false;
        }
    }

    async function refreshKeys() {
        try {
            const keys = await vault.listAiKeys();
            aiKeys.set(keys);
        } catch {
            // leave unchanged
        }
    }

    async function addKey() {
        saveError = "";
        if (!draftLabel.trim()) {
            saveError = "Label is required.";
            return;
        }
        if (!draftKey.trim()) {
            saveError = "API key is required.";
            return;
        }
        if (!draftModel) {
            saveError = "Pick a model.";
            return;
        }
        saving = true;
        try {
            const entry = await vault.addAiKey(
                draftLabel.trim(),
                draftProvider,
                draftKey.trim(),
                draftModel,
            );
            aiKeys.update((keys) => [...keys, entry]);
            // Auto-select newly added key if none active
            if (!$activeAiKey) {
                activeAiKey.set({ keyId: entry.id, model: entry.model });
            }
            resetAddForm();
        } catch (err) {
            saveError = err.message;
        } finally {
            saving = false;
        }
    }

    function resetAddForm() {
        showAddForm = false;
        draftLabel = "";
        draftKey = "";
        draftModel =
            providers.find((p) => p.id === draftProvider)?.defaultModel ?? "";
        saveError = "";
    }

    function startEdit(key) {
        editingId = key.id;
        editLabel = key.label;
        editModel = key.model;
        editKey = "";
        editError = "";
    }

    async function saveEdit() {
        editError = "";
        if (!editLabel.trim()) {
            editError = "Label is required.";
            return;
        }
        editSaving = true;
        try {
            const changes = { label: editLabel.trim(), model: editModel };
            if (editKey.trim()) changes.apiKey = editKey.trim();
            const updated = await vault.updateAiKey(editingId, changes);
            if (!updated) throw new Error("Key not found.");
            aiKeys.update((keys) =>
                keys.map((k) => (k.id === editingId ? updated : k)),
            );
            // Keep activeAiKey model in sync if this was the active key
            activeAiKey.update((a) => {
                if (a?.keyId === editingId)
                    return { ...a, model: updated.model };
                return a;
            });
            editingId = null;
        } catch (err) {
            editError = err.message;
        } finally {
            editSaving = false;
        }
    }

    async function deleteKey(id) {
        if (!confirm("Delete this API key? This cannot be undone.")) return;
        try {
            await vault.deleteAiKey(id);
            aiKeys.update((keys) => keys.filter((k) => k.id !== id));
            // Clear active if deleted
            activeAiKey.update((a) => (a?.keyId === id ? null : a));
            // Auto-select first remaining
            activeAiKey.update((a) => {
                if (a) return a;
                const remaining = $aiKeys;
                return remaining.length
                    ? { keyId: remaining[0].id, model: remaining[0].model }
                    : null;
            });
        } catch (err) {
            alert(`Failed to delete: ${err.message}`);
        }
    }

    const PROVIDER_COLORS = {
        openai: "text-emerald-400 bg-emerald-950/40 border-emerald-800/50",
        anthropic: "text-orange-400 bg-orange-950/40 border-orange-800/50",
    };

    function providerLabel(id) {
        return providers.find((p) => p.id === id)?.label ?? id;
    }

    function providerColor(id) {
        return (
            PROVIDER_COLORS[id] ?? "text-(--ink-3) bg-(--bg-3) border-(--line)"
        );
    }
</script>

<div class="h-full flex flex-col overflow-hidden">
    <!-- Header -->
    <div
        class="px-3.5 py-2.25 border-b border-(--line) flex items-center justify-between shrink-0 bg-(--bg-2)"
    >
        <div
            class="flex items-center gap-1.75 text-[12.5px] font-medium text-(--ink-1)"
        >
            <span class="text-(--acc) text-[13px]">⚿</span>
            <span>API Keys</span>
        </div>
        <div class="flex items-center gap-1.5">
            {#if !showAddForm}
                <button
                    on:click={() => (showAddForm = true)}
                    class="mono text-[9px] px-1.5 py-0.5 bg-(--bg-3) border border-(--line-strong) text-(--ink-3) hover:border-(--acc) hover:text-(--acc) rounded-[3px] tracking-[0.08em] uppercase transition-colors duration-80"
                >
                    + Add Key
                </button>
            {/if}
            <button
                on:click={onClose}
                class="w-5 h-5 flex items-center justify-center text-(--ink-3) hover:text-(--ink-1) rounded transition-colors duration-80"
                title="Back to chat"
            >
                ✕
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto flex flex-col gap-0 bg-(--bg-1)">
        <!-- Add key form -->
        {#if showAddForm}
            <div
                class="p-3.5 border-b border-(--line) bg-(--bg-2) flex flex-col gap-2.5"
            >
                <p class="text-[11px] text-(--ink-3) leading-relaxed">
                    Your key is encrypted (AES-256-GCM) on the server and never
                    returned to the browser.
                </p>

                <!-- Provider -->
                <label class="flex flex-col gap-1">
                    <span
                        class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                        >Provider</span
                    >
                    <select
                        bind:value={draftProvider}
                        on:change={() => {
                            draftModel =
                                providers.find((p) => p.id === draftProvider)
                                    ?.defaultModel ?? "";
                        }}
                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.5 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc)"
                    >
                        {#each providers as p}
                            <option value={p.id}>{p.label}</option>
                        {/each}
                    </select>
                </label>

                <!-- Label -->
                <label class="flex flex-col gap-1">
                    <span
                        class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                        >Label</span
                    >
                    <input
                        type="text"
                        placeholder="e.g. Work OpenAI"
                        bind:value={draftLabel}
                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.5 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_2px_var(--acc-glow)] placeholder:text-(--ink-3)"
                    />
                </label>

                <!-- API Key -->
                <label class="flex flex-col gap-1">
                    <span
                        class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                        >API Key</span
                    >
                    <input
                        type="password"
                        autocomplete="off"
                        placeholder={draftProvider === "anthropic"
                            ? "sk-ant-…"
                            : "sk-…"}
                        bind:value={draftKey}
                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.5 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc) focus:shadow-[0_0_0_2px_var(--acc-glow)] placeholder:text-(--ink-3)"
                    />
                </label>

                <!-- Default Model -->
                <label class="flex flex-col gap-1">
                    <div class="flex items-baseline gap-1.5">
                        <span
                            class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                            >Default Model</span
                        >
                        <span
                            class="text-[10px] text-(--ink-3) normal-case tracking-normal"
                            >(can be changed per chat)</span
                        >
                    </div>
                    <select
                        bind:value={draftModel}
                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2.5 py-1.5 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc)"
                    >
                        {#each addModels as m}
                            <option value={m}>{m}</option>
                        {/each}
                    </select>
                </label>

                {#if saveError}
                    <p class="text-[11px] text-red-400">{saveError}</p>
                {/if}

                <div class="flex gap-2">
                    <button
                        on:click={addKey}
                        disabled={saving}
                        class="flex-1 py-1.5 rounded-(--radius) text-[12px] font-medium bg-(--acc) text-[#0a0c0a] border-0 disabled:opacity-40 enabled:hover:bg-(--acc-d) transition-colors duration-80 flex items-center justify-center gap-1.5"
                    >
                        {#if saving}
                            <span
                                class="w-3 h-3 rounded-full border-2 border-[#0a0c0a]/30 border-t-[#0a0c0a] animate-spin"
                            ></span>
                            Saving…
                        {:else}
                            Save
                        {/if}
                    </button>
                    <button
                        on:click={resetAddForm}
                        class="px-3 py-1.5 rounded-(--radius) text-[12px] bg-(--bg-3) border border-(--line) muted hover:border-(--line-strong) transition-colors duration-80"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        {/if}

        <!-- Keys list -->
        {#if $aiKeys.length === 0 && !showAddForm}
            <div
                class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center"
            >
                <span class="text-[28px] opacity-20">⚿</span>
                <p class="text-[12px] text-(--ink-3) leading-relaxed max-w-48">
                    No API keys yet. Add one to start chatting with an AI
                    provider.
                </p>
                <button
                    on:click={() => (showAddForm = true)}
                    class="px-3 py-1.5 rounded-(--radius) text-[11.5px] bg-(--bg-3) border border-(--line-strong) muted hover:border-(--acc) hover:text-(--acc) transition-colors duration-80"
                >
                    Add your first key
                </button>
            </div>
        {:else}
            <ul class="flex flex-col divide-y divide-(--line)">
                {#each $aiKeys as key (key.id)}
                    <li
                        class="px-3.5 py-3 flex flex-col gap-2 hover:bg-(--bg-2) transition-colors duration-80"
                    >
                        {#if editingId === key.id}
                            <!-- Inline edit form -->
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center px-1.5 py-0.25 rounded text-[9.5px] font-medium border {providerColor(
                                            key.provider,
                                        )}"
                                    >
                                        {providerLabel(key.provider)}
                                    </span>
                                </div>
                                <label class="flex flex-col gap-1">
                                    <span
                                        class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                                        >Label</span
                                    >
                                    <input
                                        type="text"
                                        bind:value={editLabel}
                                        placeholder="Label"
                                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2 py-1 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc)"
                                    />
                                </label>
                                <label class="flex flex-col gap-1">
                                    <div class="flex items-baseline gap-1.5">
                                        <span
                                            class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                                            >Default Model</span
                                        >
                                        <span
                                            class="text-[10px] text-(--ink-3) normal-case tracking-normal"
                                            >(can be changed per chat)</span
                                        >
                                    </div>
                                    <select
                                        bind:value={editModel}
                                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2 py-1 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc)"
                                    >
                                        {#each editModels as m}
                                            <option value={m}>{m}</option>
                                        {/each}
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span
                                        class="text-[10.5px] text-(--ink-3) uppercase tracking-wider"
                                        >API Key</span
                                    >
                                    <input
                                        type="password"
                                        autocomplete="off"
                                        bind:value={editKey}
                                        placeholder="Leave blank to keep current"
                                        class="bg-(--bg-input) border border-(--line) rounded-(--radius) px-2 py-1 text-[12px] text-(--ink-0) focus:outline-none focus:border-(--acc) placeholder:text-(--ink-3)"
                                    />
                                </label>
                                {#if editError}
                                    <p class="text-[11px] text-red-400">
                                        {editError}
                                    </p>
                                {/if}
                                <div class="flex gap-1.5">
                                    <button
                                        on:click={saveEdit}
                                        disabled={editSaving}
                                        class="flex-1 py-1 rounded-(--radius) text-[11px] font-medium bg-(--acc) text-[#0a0c0a] border-0 disabled:opacity-40 enabled:hover:bg-(--acc-d) transition-colors duration-80"
                                    >
                                        {editSaving ? "Saving…" : "Save"}
                                    </button>
                                    <button
                                        on:click={() => (editingId = null)}
                                        class="px-2.5 py-1 rounded-(--radius) text-[11px] bg-(--bg-3) border border-(--line) muted hover:border-(--line-strong) transition-colors duration-80"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        {:else}
                            <!-- Normal row -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex flex-col gap-1 min-w-0">
                                    <div
                                        class="flex items-center gap-1.5 flex-wrap"
                                    >
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.25 rounded text-[9.5px] font-medium border {providerColor(
                                                key.provider,
                                            )}"
                                        >
                                            {providerLabel(key.provider)}
                                        </span>
                                        <span
                                            class="text-[12px] font-medium text-(--ink-0) truncate"
                                            >{key.label}</span
                                        >
                                    </div>
                                    <span
                                        class="text-[10.5px] text-(--ink-3) mono"
                                        >{key.model}</span
                                    >
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        on:click={() => startEdit(key)}
                                        class="px-1.5 py-0.5 text-[10px] text-(--ink-3) hover:text-(--ink-1) border border-transparent hover:border-(--line) rounded transition-colors duration-80"
                                        title="Edit"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        on:click={() => deleteKey(key.id)}
                                        class="px-1.5 py-0.5 text-[10px] text-red-500 hover:text-red-400 border border-transparent hover:border-red-900/50 rounded transition-colors duration-80"
                                        title="Delete"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        {/if}
                    </li>
                {/each}
            </ul>
        {/if}
    </div>
</div>
