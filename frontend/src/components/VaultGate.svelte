<script>
    /**
     * VaultGate — wraps any content that requires vault access.
     *
     * Shows a setup dialog on first run, an unlock prompt when the vault is
     * protected and locked, and the slot content once the vault is ready.
     */
    import { onMount } from "svelte";
    import {
        getVaultMode,
        isUnlocked,
        setupVault,
        unlockVault,
    } from "../lib/vault.js";
    import Btn from "./ui/Btn.svelte";
    import Input from "./ui/Input.svelte";

    // 'loading' | 'setup' | 'unlock' | 'ready'
    let state = "loading";

    let passwordInput = "";
    let confirmInput = "";
    let wantProtected = false;
    let error = "";
    let busy = false;

    onMount(async () => {
        const mode = await getVaultMode();
        if (mode === "uninitialized") {
            state = "setup";
        } else if (await isUnlocked()) {
            state = "ready";
        } else {
            state = "unlock";
        }
    });

    async function handleSetup() {
        error = "";
        if (wantProtected) {
            if (!passwordInput) {
                error = "Enter a master password.";
                return;
            }
            if (passwordInput.length < 8) {
                error = "Password must be at least 8 characters.";
                return;
            }
            if (passwordInput !== confirmInput) {
                error = "Passwords do not match.";
                return;
            }
        }
        busy = true;
        try {
            await setupVault(wantProtected ? passwordInput : null);
            state = "ready";
        } catch (e) {
            error = e.message;
        } finally {
            busy = false;
            passwordInput = "";
            confirmInput = "";
        }
    }

    async function handleUnlock() {
        error = "";
        if (!passwordInput) {
            error = "Enter your master password.";
            return;
        }
        busy = true;
        try {
            const ok = await unlockVault(passwordInput);
            if (ok) {
                state = "ready";
            } else {
                error = "Incorrect master password.";
            }
        } catch (e) {
            error = e.message;
        } finally {
            busy = false;
            passwordInput = "";
        }
    }

    function handleKeydown(e) {
        if (e.key === "Enter") {
            if (state === "setup") handleSetup();
            if (state === "unlock") handleUnlock();
        }
    }
</script>

{#if state === "loading"}
    <!-- nothing — avoids flash before we know the vault mode -->
{:else if state === "setup"}
    <div class="flex flex-col items-center justify-center h-full gap-6 px-4">
        <div class="w-full max-w-sm flex flex-col gap-4">
            <div class="text-center">
                <h2 class="text-(--ink-0) text-base font-semibold mb-1">
                    Set up your credential vault
                </h2>
                <p class="text-(--ink-2) text-sm">
                    Saved connections and API keys are stored in your browser.
                    You can optionally protect them with a master password.
                </p>
            </div>

            <label class="flex items-center gap-3 cursor-pointer select-none">
                <input
                    type="checkbox"
                    bind:checked={wantProtected}
                    class="w-4 h-4 rounded accent-(--acc)"
                />
                <span class="text-(--ink-1) text-sm">
                    Encrypt with a master password
                    <span class="text-(--ink-3) text-xs block">
                        You'll need to enter it once per browser session.
                    </span>
                </span>
            </label>

            {#if wantProtected}
                <div class="flex flex-col gap-2">
                    <Input
                        type="password"
                        bind:value={passwordInput}
                        on:keydown={handleKeydown}
                        placeholder="Master password"
                    />
                    <Input
                        type="password"
                        bind:value={confirmInput}
                        on:keydown={handleKeydown}
                        placeholder="Confirm master password"
                    />
                </div>
            {/if}

            {#if error}
                <p class="text-(--danger) text-sm">{error}</p>
            {/if}

            <Btn
                variant="primary"
                disabled={busy}
                on:click={handleSetup}
                class="w-full justify-center"
            >
                {wantProtected
                    ? "Set up encrypted vault"
                    : "Continue without encryption"}
            </Btn>
        </div>
    </div>
{:else if state === "unlock"}
    <div class="flex flex-col items-center justify-center h-full gap-6 px-4">
        <div class="w-full max-w-sm flex flex-col gap-4">
            <div class="text-center">
                <h2 class="text-(--ink-0) text-base font-semibold mb-1">
                    Unlock vault
                </h2>
                <p class="muted text-sm">
                    Enter your master password to access saved connections and
                    API keys.
                </p>
            </div>

            <Input
                type="password"
                bind:value={passwordInput}
                on:keydown={handleKeydown}
                placeholder="Master password"
                autofocus
            />

            {#if error}
                <p class="text-(--danger) text-sm">{error}</p>
            {/if}

            <Btn
                variant="primary"
                disabled={busy}
                on:click={handleUnlock}
                class="w-full justify-center"
            >
                Unlock
            </Btn>
        </div>
    </div>
{:else if state === "ready"}
    <slot />
{/if}
