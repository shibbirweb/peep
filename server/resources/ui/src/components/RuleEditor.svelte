<script>
  // WH-01 to WH-06, WH-09, WH-10: one rule's form.
  import { untrack } from 'svelte';
  import { deleteRule, saveRule, testRule } from '../lib/api.js';
  import { VARIABLES } from '../lib/webhooks.js';

  /** @type {{ rule: object, onsaved: (rule: object) => void, ondeleted: () => void }} */
  let { rule, onsaved, ondeleted } = $props();

  const toRows = (map) => Object.entries(map ?? {}).map(([name, value]) => ({ name, value }));
  const toMap = (rows) => Object.fromEntries(rows.filter((row) => row.name.trim() !== '').map((row) => [row.name.trim(), row.value]));

  let form = $state(null);
  let headers = $state([]);
  let vars = $state([]);
  let error = $state('');
  let notice = $state('');
  let testEvent = $state('');
  let showHelp = $state(false);
  // A save hands the saved rule back; its notice must outlive the form reset that follows.
  let keepMessages = false;

  // Reset the form when another rule is opened. Only the rule prop is a dependency: the
  // form itself is written here, so reading it would make the effect run again.
  $effect(() => {
    const copy = structuredClone($state.snapshot(rule));

    untrack(() => {
      form = copy;
      headers = toRows(copy.headers);
      vars = toRows(copy.vars);
      testEvent = copy.steps[0]?.event ?? 'delivered';

      if (!keepMessages) {
        error = '';
        notice = '';
      }

      keepMessages = false;
    });
  });

  function payload() {
    return { ...$state.snapshot(form), headers: toMap(headers), vars: toMap(vars), steps: form.steps.map((step) => ({ event: step.event, delay_ms: Number(step.delay_ms) || 0 })), retry: { ...form.retry, max: Number(form.retry.max) || 3 } };
  }

  async function save() {
    error = '';
    notice = '';

    try {
      const saved = await saveRule(payload());
      notice = 'Saved.';
      keepMessages = true;
      onsaved(saved.rule);
    } catch (exception) {
      error = exception.message;
    }
  }

  async function test() {
    error = '';
    notice = '';

    try {
      const saved = await saveRule(payload());
      keepMessages = true;
      onsaved(saved.rule);
      const delivery = await testRule(saved.rule.id, testEvent);
      notice = delivery.error ? `Test failed: ${delivery.error}` : `Test sent: ${delivery.status} in ${delivery.duration_ms} ms. See the delivery log.`;
    } catch (exception) {
      error = exception.message;
    }
  }

  async function remove() {
    if (form.id !== '') {
      await deleteRule(form.id);
    }

    ondeleted();
  }
</script>

{#if form !== null}
  <form class="editor" onsubmit={(event) => { event.preventDefault(); save(); }}>
    <div class="grid">
      <label><span>Name</span><input bind:value={form.name} required /></label>
      <label><span>Project</span><input bind:value={form.project} placeholder="* for every project" /></label>
      <label>
        <span>When</span>
        <select bind:value={form.trigger}>
          <option value="mail.received">A mail is caught</option>
          <option value="sms.received">An SMS is caught</option>
          <option value="manual">Only when sent by hand</option>
        </select>
      </label>
      <label><span>To contains</span><input bind:value={form.filter.to_contains} placeholder="any recipient" /></label>
    </div>

    <div class="request">
      <select bind:value={form.method} aria-label="Method">
        {#each ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'] as method (method)}<option>{method}</option>{/each}
      </select>
      <input class="url" bind:value={form.url} placeholder="http://my-app.test/webhooks/mailgun" aria-label="URL" required />
    </div>
    {#if form.trigger !== 'mail.received'}
      <label class="check"><input type="checkbox" bind:checked={form.use_status_callback} /><span>Send to the SMS's status callback when the app gave one (local hosts only: localhost, 127.0.0.1, *.test, *.localhost)</span></label>
    {/if}

    <fieldset>
      <legend>Headers</legend>
      {#each headers as header, index (index)}
        <div class="row"><input bind:value={header.name} placeholder="X-Header" /><input bind:value={header.value} placeholder={'value, may use {{ }}'} /><button type="button" onclick={() => headers.splice(index, 1)} aria-label="Remove header">×</button></div>
      {/each}
      <button type="button" class="small" onclick={() => headers.push({ name: '', value: '' })}>Add header</button>
    </fieldset>

    <fieldset>
      <legend>
        Body
        <select bind:value={form.format} aria-label="Body format">
          <option value="json">JSON</option>
          <option value="form">Form (JSON object of fields)</option>
          <option value="xml">XML</option>
          <option value="raw">Raw text</option>
        </select>
        <button type="button" class="small" onclick={() => (showHelp = !showHelp)}>{showHelp ? 'Hide' : 'Show'} variables</button>
      </legend>
      {#if showHelp}
        <table class="help">
          <tbody>{#each VARIABLES as [name, meaning] (name)}<tr><td><code>{'{{ ' + name + ' }}'}</code></td><td>{meaning}</td></tr>{/each}</tbody>
        </table>
      {/if}
      <textarea bind:value={form.body} rows="12" spellcheck="false" aria-label="Body template"></textarea>
      <p class="hint">In JSON, a value that is only one placeholder keeps its type: <code>{'"{{ now.unix_float }}"'}</code> becomes a number. Values are escaped for the format.</p>
    </fieldset>

    <fieldset>
      <legend>Signature</legend>
      <div class="row">
        <select bind:value={form.signature.type} aria-label="Signature">
          <option value="none">None</option>
          <option value="mailgun">Mailgun (webhook signing key)</option>
          <option value="twilio">Twilio X-Twilio-Signature (auth token)</option>
          <option value="custom">Custom header</option>
        </select>
        {#if form.signature.type !== 'none'}<input bind:value={form.signature.secret} placeholder="secret, key or token" aria-label="Secret" />{/if}
      </div>
      {#if form.signature.type === 'custom'}
        <div class="row"><input bind:value={form.signature.header} placeholder="X-Signature" aria-label="Header" /><input bind:value={form.signature.value} placeholder={'{{ hmac_sha256(vars.secret, body) }}'} aria-label="Value" /></div>
      {/if}
    </fieldset>

    <fieldset>
      <legend>Steps</legend>
      {#each form.steps as step, index (index)}
        <div class="row"><input bind:value={step.event} placeholder="delivered" aria-label="Event" /><label class="inline">after <input type="number" min="0" max="600000" step="100" bind:value={step.delay_ms} aria-label="Delay" /> ms</label><button type="button" onclick={() => form.steps.splice(index, 1)} disabled={form.steps.length === 1} aria-label="Remove step">×</button></div>
      {/each}
      <button type="button" class="small" onclick={() => form.steps.push({ event: '', delay_ms: (form.steps.at(-1)?.delay_ms ?? 0) + 1000 })}>Add step</button>
      <label class="check"><input type="checkbox" bind:checked={form.retry.enabled} /><span>Retry on network errors and 5xx answers, up to <input type="number" min="1" max="10" bind:value={form.retry.max} aria-label="Retries" /> attempts</span></label>
    </fieldset>

    <fieldset>
      <legend>Variables</legend>
      {#each vars as variable, index (index)}
        <div class="row"><input bind:value={variable.name} placeholder="name" /><input bind:value={variable.value} placeholder="value" /><button type="button" onclick={() => vars.splice(index, 1)} aria-label="Remove variable">×</button></div>
      {/each}
      <button type="button" class="small" onclick={() => vars.push({ name: '', value: '' })}>Add variable</button>
    </fieldset>

    {#if error}<p class="error" role="alert">{error}</p>{/if}
    {#if notice}<p class="notice">{notice}</p>{/if}

    <footer>
      <label class="check"><input type="checkbox" bind:checked={form.enabled} /><span>On</span></label>
      <span class="spacer"></span>
      <button type="button" onclick={remove}>Delete</button>
      <label class="inline">
        <input class="event" bind:value={testEvent} aria-label="Test event" />
        <button type="button" onclick={test} title="Save, then send with sample data">Test</button>
      </label>
      <button type="submit" class="primary">Save</button>
    </footer>
  </form>
{/if}

<style>
  .editor {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 8px;
  }

  label > span {
    display: block;
    font-size: 12px;
    color: var(--muted);
  }

  input:not([type='checkbox']),
  textarea {
    font: inherit;
    color: inherit;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px 8px;
    min-width: 0;
  }

  .grid input,
  .grid select {
    width: 100%;
  }

  textarea {
    width: 100%;
    font-family: var(--mono);
    font-size: 12.5px;
    resize: vertical;
  }

  .request,
  .row {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-top: 4px;
  }

  .row input {
    flex: 1;
  }

  .url {
    flex: 1;
    font-family: var(--mono);
  }

  fieldset {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px 10px;
    margin: 0;
  }

  legend {
    font-weight: 600;
    padding: 0 4px;
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .check {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    margin-top: 6px;
  }

  .check span {
    display: inline;
    color: inherit;
    font-size: 13px;
  }

  .inline {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
  }

  input[type='number'] {
    width: 90px;
  }

  .check input[type='number'] {
    width: 52px;
  }

  .event {
    width: 100px;
  }

  .small {
    font-size: 12px;
    padding: 2px 8px;
    margin-top: 6px;
  }

  .help {
    font-size: 12px;
    border-collapse: collapse;
    margin: 4px 0 8px;
  }

  .help td {
    padding: 2px 8px 2px 0;
    vertical-align: top;
  }

  .hint {
    font-size: 12px;
    color: var(--muted);
    margin: 4px 0 0;
  }

  .error {
    color: var(--danger);
    margin: 0;
  }

  .notice {
    background: var(--surface-2);
    border-radius: 6px;
    padding: 6px 10px;
    margin: 0;
  }

  footer {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .spacer {
    flex: 1;
  }

  .primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
  }
</style>
