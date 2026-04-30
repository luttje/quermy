<script>
    import { api } from "../lib/api.js";
    import { toast } from "../lib/store.js";
    import Input from "../components/ui/Input.svelte";
    import Btn from "../components/ui/Btn.svelte";
    import Select from "../components/ui/Select.svelte";

    export let db = "";
    export let capabilities = {};

    let loadingViews = true;
    let loadingDefinition = false;
    let saving = false;
    let dropping = false;

    let views = [];
    let selectedView = "";
    let viewName = "";
    let definition = "";
    let dropConfirm = "";
    let lastLoadedDb = null;

    $: if (db && db !== lastLoadedDb) {
        lastLoadedDb = db;
        selectedView = "";
        viewName = "";
        definition = "";
        dropConfirm = "";
        loadViews();
    }

    function quoteIdent(name) {
        const ioOpen = capabilities?.identifierOpen ?? "`";

        if (ioOpen === '"') return '"' + name.replace(/"/g, '""') + '"';
        if (ioOpen === "[") return "[" + name.replace(/]/g, "]]") + "]";
        return "`" + name.replace(/`/g, "``") + "`";
    }

    async function loadViews() {
        if (!db) return;

        loadingViews = true;
        try {
            const r = await api.listViews(db);
            views = r.views || [];

            if (selectedView && !views.includes(selectedView)) {
                selectedView = "";
            }
            if (viewName && !views.includes(viewName) && selectedView) {
                viewName = selectedView;
            }
        } catch (e) {
            toast(e.message, "error");
            views = [];
        } finally {
            loadingViews = false;
        }
    }

    async function loadSelectedDefinition() {
        if (!db || !selectedView) return;

        loadingDefinition = true;
        dropConfirm = "";
        try {
            const r = await api.getViewDefinition(db, selectedView);
            definition = r.definition ?? "";
            viewName = selectedView;
        } catch (e) {
            toast(e.message, "error");
        } finally {
            loadingDefinition = false;
        }
    }

    function handleSelectChange() {
        if (!selectedView) {
            viewName = "";
            definition = "";
            dropConfirm = "";
            return;
        }
        loadSelectedDefinition();
    }

    function startTemplate() {
        definition = `SELECT *\nFROM ${quoteIdent("your_table")};`;
        selectedView = "";
        dropConfirm = "";
    }

    async function saveDefinition() {
        if (!db || !definition.trim()) return;
        const targetView = viewName.trim();
        if (!targetView) {
            toast("Target view name is required", "error");
            return;
        }

        saving = true;
        try {
            await api.saveViewDefinition(db, targetView, definition);
            await loadViews();
            selectedView = targetView;
            await loadSelectedDefinition();

            toast("View definition saved", "success");
        } catch (e) {
            toast(e.message, "error");
        } finally {
            saving = false;
        }
    }

    async function dropSelectedView() {
        if (!db || !selectedView) return;
        if (dropConfirm !== selectedView) return;

        dropping = true;
        try {
            await api.dropView(db, selectedView);
            toast(`Dropped view "${selectedView}"`, "success");
            selectedView = "";
            viewName = "";
            definition = "";
            dropConfirm = "";
            await loadViews();
        } catch (e) {
            toast(e.message, "error");
        } finally {
            dropping = false;
        }
    }
</script>

<section class="bg-(--bg-1) border border-(--line) rounded-lg overflow-hidden">
    <header
        class="flex items-center justify-between gap-2 px-3.5 py-2.5 border-b border-(--line) bg-(--bg-2)"
    >
        <div class="flex flex-col">
            <h2 class="mono text-(--ink-0) text-[12.5px]">Views · {db}</h2>
            <p class="mono text-(--ink-3) text-[10.5px]">
                Create, update, and drop SQL views.
            </p>
        </div>
        <Btn
            variant="ghost"
            class="text-[11px] px-2.5 py-1!"
            disabled={loadingViews}
            on:click={loadViews}
        >
            {loadingViews ? "Refreshing..." : "Refresh"}
        </Btn>
    </header>

    <div class="p-3.5 flex flex-col gap-3">
        <div class="flex flex-wrap gap-2 items-end">
            <label class="flex-1 min-w-56 flex flex-col gap-1">
                <span
                    class="mono text-[9.5px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Existing Views</span
                >
                <Select
                    class="w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2 text-(--ink-0) mono text-[12px] focus:outline-none focus:border-(--acc)"
                    disabled={loadingViews || views.length === 0}
                    bind:value={selectedView}
                    on:change={handleSelectChange}
                >
                    <option value="">
                        {loadingViews
                            ? "Loading views..."
                            : views.length === 0
                              ? "No views found"
                              : "Select a view"}
                    </option>
                    {#each views as name}
                        <option value={name}>{name}</option>
                    {/each}
                </Select>
            </label>

            <label class="flex-1 min-w-56 flex flex-col gap-1">
                <span
                    class="mono text-[9.5px] uppercase tracking-[0.08em] text-(--ink-3)"
                    >Target View Name</span
                >
                <Input
                    placeholder="e.g. active_users"
                    bind:value={viewName}
                    class="text-[12px] py-2!"
                />
            </label>

            <Btn class="text-[11.5px] px-3 py-2!" on:click={startTemplate}>
                New Template
            </Btn>
        </div>

        <label class="flex flex-col gap-1">
            <span
                class="mono text-[9.5px] uppercase tracking-[0.08em] text-(--ink-3)"
                >View SQL</span
            >
            <textarea
                class="min-h-72 resize-y w-full bg-(--bg-input) border border-(--line) rounded-(--radius) px-3 py-2.5 text-(--ink-0) mono text-[12px] leading-relaxed focus:outline-none focus:border-(--acc)"
                bind:value={definition}
                placeholder="SELECT ... FROM ..."
                spellcheck="false"
                disabled={loadingDefinition}
            ></textarea>
        </label>

        <div class="flex flex-wrap justify-between items-end gap-2">
            <div class="flex items-end gap-2">
                <label class="flex flex-col gap-1 min-w-56">
                    <span
                        class="mono text-[9.5px] uppercase tracking-[0.08em] text-(--ink-3)"
                        >Drop Confirmation</span
                    >
                    <Input
                        placeholder={selectedView
                            ? `Type ${selectedView}`
                            : "Select a view first"}
                        bind:value={dropConfirm}
                        class="text-[12px] py-2!"
                        disabled={!selectedView}
                    />
                </label>
                <Btn
                    variant="danger"
                    class="text-[11.5px] px-3 py-2!"
                    disabled={!selectedView || dropConfirm !== selectedView || dropping}
                    on:click={dropSelectedView}
                >
                    {dropping ? "Dropping…" : "Drop View"}
                </Btn>
            </div>

            <Btn
                variant="primary"
                class="text-[11.5px] px-3 py-2!"
                disabled={!definition.trim() || saving || loadingDefinition}
                on:click={saveDefinition}
            >
                {saving ? "Saving…" : "Save SQL"}
            </Btn>
        </div>
    </div>
</section>
