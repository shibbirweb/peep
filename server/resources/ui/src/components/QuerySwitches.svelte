<script>
  // Query debugging for one project, applied to the running app within a few seconds.
  import { setSwitches } from '../lib/api.js';

  /** @type {{ project: string, switches: object|undefined }} */
  let { project, switches } = $props();

  const STATES = [
    { value: 'default', name: 'App default' },
    { value: 'on', name: 'On' },
    { value: 'off', name: 'Off' },
  ];

  const current = $derived({ queries: 'default', slow_queries: 'default', duplicate_queries: 'default', slow_query_ms: null, ...switches });
  let error = $state('');

  async function change(name, value) {
    error = '';

    try {
      await setSwitches(project, { [name]: value });
    } catch (exception) {
      error = exception.message;
    }
  }

  function changeThreshold(event) {
    const text = event.currentTarget.value.trim();
    const value = text === '' ? null : Number.parseInt(text, 10);

    change('slow_query_ms', Number.isInteger(value) && value >= 0 ? value : null);
  }
</script>

<div class="switches" role="group" aria-label="Query debugging for {project}">
  <span class="title" class:active={current.queries === 'on'} class:stopped={current.queries === 'off'}>Queries</span>
  {#each [['queries', 'All'], ['slow_queries', 'Slow'], ['duplicate_queries', 'Duplicates']] as [name, label] (name)}
    <label>
      <span>{label}</span>
      <select value={current[name]} onchange={(event) => change(name, event.currentTarget.value)}>
        {#each STATES as state (state.value)}<option value={state.value}>{state.name}</option>{/each}
      </select>
    </label>
  {/each}
  <label title="Slow query threshold; empty uses the app's setting">
    <span>over</span>
    <input type="number" min="0" step="10" placeholder="app" value={current.slow_query_ms ?? ''} onchange={changeThreshold} />
    <span>ms</span>
  </label>
  {#if error}<span class="error">{error}</span>{/if}
</div>

<style>
  .switches {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--muted);
  }

  .title {
    font-weight: 600;
    color: var(--text);
  }

  .title::before {
    content: '';
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 5px;
    background: var(--gray);
  }

  .title.active::before {
    background: var(--green);
  }

  .title.stopped::before {
    background: var(--danger);
  }

  label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  select,
  input {
    font-size: 12px;
    padding: 1px 4px;
  }

  input {
    width: 64px;
    font: inherit;
    color: inherit;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
  }

  .error {
    color: var(--danger);
  }
</style>
