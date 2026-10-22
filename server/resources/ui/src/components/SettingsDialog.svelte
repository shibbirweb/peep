<script>
  // VF-15 to VF-20: the settings page. The viewer validates and saves settings.json.
  import { onMount } from 'svelte';
  import { loadSettings, saveSettings } from '../lib/api.js';
  import { IDES } from '../lib/ide.js';
  import { askPermission } from '../lib/notify.js';
  import { applySettings } from '../lib/stream.svelte.js';

  /** @type {{ onclose: () => void }} */
  let { onclose } = $props();

  let dialog;
  let form = $state(null);
  let page = $state(null);
  let error = $state('');
  let saved = $state(false);
  let showToken = $state(false);

  onMount(() => {
    dialog.showModal();
    load();
  });

  async function load() {
    page = await loadSettings();
    form = { ...page.settings, path_mappings: page.settings.path_mappings.map((mapping) => ({ ...mapping })), token: page.settings.token ?? '' };
  }

  async function save(event) {
    event.preventDefault();
    error = '';
    saved = false;

    if (form.notifications && !(await askPermission())) {
      form.notifications = false;
      error = 'The browser does not allow notifications for this page.';

      return;
    }

    const changes = {
      ...form,
      port: Number.parseInt(String(form.port), 10),
      max_cards: Number.parseInt(String(form.max_cards), 10),
      token: form.token.trim() === '' ? null : form.token.trim(),
      path_mappings: form.path_mappings.filter((mapping) => mapping.from.trim() !== '' || mapping.to.trim() !== ''),
    };
    delete changes.switches;

    try {
      page = await saveSettings(changes);
      const { token, switches, ...rest } = page.settings;
      applySettings({ ...rest, token_set: token !== null });
      saved = true;
    } catch (exception) {
      error = exception.message;
    }
  }

  function generateToken() {
    const bytes = crypto.getRandomValues(new Uint8Array(24));
    form.token = btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '');
    showToken = true;
  }
</script>

<dialog bind:this={dialog} onclose={onclose} aria-labelledby="settings-title">
  <form method="dialog" onsubmit={save}>
    <header>
      <h2 id="settings-title">Settings</h2>
      <button type="button" class="close" onclick={() => dialog.close()} aria-label="Close">×</button>
    </header>

    {#if form === null}
      <p class="muted">Loading…</p>
    {:else}
      <fieldset>
        <legend>Display</legend>
        <label class="row">
          <span>Open the viewer in</span>
          <select bind:value={form.display_mode}>
            <option value="browser">Browser</option>
            <option value="window">Desktop window</option>
          </select>
        </label>
        {#if form.display_mode === 'window'}<p class="hint">Needs the Peep desktop app. Until it is installed, <code>serve</code> opens the browser.</p>{/if}
        <label class="row">
          <span>Theme</span>
          <select bind:value={form.theme}>
            <option value="system">Follow system</option>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
          </select>
        </label>
        <label class="row check">
          <input type="checkbox" bind:checked={form.notifications} />
          <span>Desktop notifications for <code>notify()</code> and exceptions while the viewer is in the background</span>
        </label>
      </fieldset>

      <fieldset>
        <legend>Editor</legend>
        <label class="row">
          <span>Open files in</span>
          <select bind:value={form.ide}>
            {#each IDES as option (option.id)}<option value={option.id}>{option.name}</option>{/each}
          </select>
        </label>
        <p class="hint">Path mappings turn paths from the app (for example inside Docker) into paths on this machine.</p>
        {#each form.path_mappings as mapping, index (index)}
          <div class="mapping">
            <input placeholder="/var/www/html" bind:value={mapping.from} aria-label="Path in the app" />
            <span>→</span>
            <input placeholder="/Users/you/code/shop" bind:value={mapping.to} aria-label="Path on this machine" />
            <button type="button" onclick={() => form.path_mappings.splice(index, 1)} aria-label="Remove mapping">×</button>
          </div>
        {/each}
        <button type="button" class="small" onclick={() => form.path_mappings.push({ from: '', to: '' })}>Add mapping</button>
      </fieldset>

      <fieldset>
        <legend>Server</legend>
        <div class="pair">
          <label class="row"><span>Host</span><input bind:value={form.host} /></label>
          <label class="row"><span>Port</span><input type="number" min="1" max="65535" bind:value={form.port} /></label>
        </div>
        <label class="row">
          <span>Token</span>
          <input type={showToken ? 'text' : 'password'} bind:value={form.token} placeholder="none" autocomplete="off" spellcheck="false" />
          <button type="button" class="small" onclick={() => (showToken = !showToken)}>{showToken ? 'Hide' : 'Show'}</button>
          <button type="button" class="small" onclick={generateToken}>Generate</button>
        </label>
        <p class="hint">
          With a token, apps on other machines (or in Docker on Linux) can send to this viewer: set <code>PEEP_TOKEN</code> in the app, and listen beyond
          this machine with a host such as <code>0.0.0.0</code>. The viewer page itself is only served to this machine.
        </p>
        <div class="pair">
          <label class="row"><span>Cards per project</span><input type="number" min="100" max="50000" step="100" bind:value={form.max_cards} /></label>
          <label class="row check"><input type="checkbox" bind:checked={form.history} /><span>Keep cards across restarts</span></label>
        </div>
        <p class="hint">History: {page.storage}. Settings file: <code>{page.file ?? 'not saved'}</code></p>
      </fieldset>

      {#if page.restart_required.length > 0}
        <p class="notice">Restart the viewer to apply: {page.restart_required.join(', ').replaceAll('_', ' ')}.</p>
      {/if}
      {#if error}<p class="error" role="alert">{error}</p>{/if}

      <footer>
        {#if saved && !error}<span class="muted">Saved.</span>{/if}
        <button type="button" onclick={() => dialog.close()}>Close</button>
        <button type="submit" class="primary">Save</button>
      </footer>
    {/if}
  </form>
</dialog>

<style>
  dialog {
    width: min(640px, calc(100vw - 32px));
    max-height: calc(100vh - 48px);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 0;
    background: var(--surface);
    color: var(--text);
  }

  dialog::backdrop {
    background: rgb(0 0 0 / 40%);
  }

  form {
    padding: 16px 20px;
  }

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  h2 {
    margin: 0;
    font-size: 17px;
  }

  .close {
    border: 0;
    font-size: 20px;
    line-height: 1;
  }

  fieldset {
    border: 1px solid var(--border);
    border-radius: 8px;
    margin: 14px 0 0;
    padding: 8px 12px 12px;
  }

  legend {
    font-weight: 600;
    padding: 0 4px;
  }

  .row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
  }

  .row > span:first-child {
    min-width: 120px;
  }

  .check > span:first-child {
    min-width: 0;
  }

  .pair {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
  }

  input:not([type='checkbox']) {
    font: inherit;
    color: inherit;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px 8px;
    min-width: 0;
  }

  input[type='number'] {
    width: 100px;
  }

  .mapping {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
  }

  .mapping input {
    flex: 1;
  }

  .small {
    font-size: 12px;
    padding: 2px 8px;
    margin-top: 6px;
  }

  .hint {
    font-size: 12px;
    color: var(--muted);
    margin: 6px 0 0;
  }

  .notice {
    background: var(--surface-2);
    border-radius: 6px;
    padding: 6px 10px;
  }

  .error {
    color: var(--danger);
  }

  .muted {
    color: var(--muted);
  }

  footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
  }

  .primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
  }
</style>
