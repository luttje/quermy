<script>
    import { onMount, onDestroy, createEventDispatcher } from "svelte";
    import {
        EditorView,
        keymap,
        lineNumbers,
        highlightActiveLine,
        highlightActiveLineGutter,
        placeholder as cmPlaceholder,
    } from "@codemirror/view";
    import { EditorState, Compartment } from "@codemirror/state";
    import {
        defaultKeymap,
        indentWithTab,
        history,
        historyKeymap,
    } from "@codemirror/commands";
    import { sql } from "@codemirror/lang-sql";
    import { markdown } from "@codemirror/lang-markdown";
    import { gruvboxDark } from "@uiw/codemirror-theme-gruvbox-dark";
    import { bracketMatching, indentOnInput } from "@codemirror/language";
    import {
        closeBrackets,
        closeBracketsKeymap,
    } from "@codemirror/autocomplete";

    export let value = "";
    export let mode = "markdown"; // "markdown" | "sql"
    export let placeholder = "";
    export let minHeight = "180px";

    const languageCompartment = new Compartment();

    function getLanguageExtension(m) {
        return m === "sql" ? sql() : markdown();
    }

    function getDefaultPlaceholder(m) {
        return m === "sql" ? "SELECT * FROM ..." : "Write markdown here...";
    }

    const dispatch = createEventDispatcher();

    let container;
    let view;
    let suppressChangeDispatch = false;

    const appTheme = EditorView.theme(
        {
            "&": {
                background: "var(--bg-1)",
                color: "var(--ink-0)",
                fontSize: "13.5px",
                minHeight,
                fontFamily: "var(--font-mono)",
            },
            ".cm-content": {
                padding: "16px",
                lineHeight: "1.6",
                caretColor: "var(--acc)",
            },
            ".cm-scroller": {
                fontFamily: "var(--font-mono)",
                overflow: "auto",
            },
            ".cm-focused": {
                outline: "none",
            },
            ".cm-gutters": {
                background: "var(--bg-2)",
                color: "var(--ink-3)",
                border: "none",
                borderRight: "1px solid var(--line)",
            },
            ".cm-lineNumbers .cm-gutterElement": {
                padding: "0 10px 0 8px",
                minWidth: "32px",
            },
            ".cm-activeLineGutter": {
                background: "rgba(200, 255, 90, 0.05)",
                color: "var(--ink-2)",
            },
            ".cm-activeLine": {
                background: "rgba(200, 255, 90, 0.04)",
            },
            ".cm-selectionBackground, ::selection": {
                background: "rgba(200, 255, 90, 0.15) !important",
            },
            ".cm-focused .cm-selectionBackground": {
                background: "rgba(200, 255, 90, 0.18) !important",
            },
            ".cm-cursor, .cm-dropCursor": {
                borderLeftColor: "var(--acc)",
            },
            ".cm-placeholder": {
                color: "var(--ink-3)",
                fontStyle: "italic",
            },
            ".cm-matchingBracket": {
                background: "rgba(200, 255, 90, 0.2)",
                outline: "1px solid rgba(200, 255, 90, 0.4)",
            },
        },
        { dark: true },
    );

    onMount(() => {
        const updateListener = EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                value = update.state.doc.toString();
                if (suppressChangeDispatch) {
                    suppressChangeDispatch = false;
                    return;
                }
                dispatch("change", value);
            }
        });

        const state = EditorState.create({
            doc: value,
            extensions: [
                history(),
                lineNumbers(),
                highlightActiveLine(),
                highlightActiveLineGutter(),
                indentOnInput(),
                bracketMatching(),
                closeBrackets(),
                languageCompartment.of(getLanguageExtension(mode)),
                gruvboxDark,
                appTheme,
                keymap.of([
                    ...closeBracketsKeymap,
                    ...defaultKeymap,
                    ...historyKeymap,
                    indentWithTab,
                ]),
                cmPlaceholder(placeholder || getDefaultPlaceholder(mode)),
                updateListener,
            ],
        });

        view = new EditorView({ state, parent: container });
    });

    onDestroy(() => {
        view?.destroy();
    });

    // Allow parent to set value programmatically
    export function setValue(newVal) {
        if (!view) return;
        const current = view.state.doc.toString();
        if (current === newVal) return;
        suppressChangeDispatch = true;
        view.dispatch({
            changes: { from: 0, to: current.length, insert: newVal },
        });
    }

    $: if (view) {
        const current = view.state.doc.toString();
        if (value !== current) {
            setValue(value);
        }
    }

    $: if (view && mode) {
        view.dispatch({
            effects: languageCompartment.reconfigure(
                getLanguageExtension(mode),
            ),
        });
    }
</script>

<div
    bind:this={container}
    class="flex-1 h-full min-h-0 overflow-hidden flex flex-col sql-editor"
></div>
