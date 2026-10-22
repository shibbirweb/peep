<script>
  import { releaseLock } from '../lib/api.js';
  import { viewer } from '../lib/stream.svelte.js';

  /** @type {{ name: string }} */
  let { name } = $props();

  const state = $derived(viewer.locks[name] ?? 'continued');
</script>

<div class="lock state-{state}">
  {#if state === 'active'}
    <span>⏸ The app is paused here.</span>
    <button type="button" class="continue" onclick={() => releaseLock(name, 'continue')}>Continue</button>
    <button type="button" onclick={() => releaseLock(name, 'stop')}>Stop</button>
  {:else if state === 'stopped'}
    <span>Stopped.</span>
  {:else}
    <span>Continued.</span>
  {/if}
</div>

<style>
  .lock {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 6px;
    background: var(--surface-2);
  }

  .state-active {
    background: color-mix(in srgb, var(--yellow) 25%, var(--surface));
  }

  .continue {
    border-color: var(--green);
  }
</style>
