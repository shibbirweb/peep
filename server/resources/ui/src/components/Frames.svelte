<script>
  import { ideUrl, shortPath } from '../lib/ide.js';

  /** @type {{ frames: Array<object>, ide: string }} */
  let { frames, ide } = $props();
</script>

<ol class="frames">
  {#each frames as frame, index (index)}
    {@const link = frame.file ? ideUrl(ide, frame.file, frame.line ?? 1) : null}
    <li>
      <span class="call">{frame.class ? `${frame.class}::` : ''}{frame.function ?? '(main)'}()</span>
      {#if frame.file}
        {#if link}
          <a href={link} title="{frame.file}:{frame.line}">{shortPath(frame.file)}:{frame.line}</a>
        {:else}
          <span class="file" title={frame.file}>{shortPath(frame.file)}:{frame.line}</span>
        {/if}
      {/if}
    </li>
  {/each}
</ol>

<style>
  .frames {
    margin: 0;
    padding-left: 2em;
    font-family: var(--mono);
    font-size: 12px;
  }

  li {
    padding: 1px 0;
  }

  .call {
    color: var(--text);
  }

  a,
  .file {
    margin-left: 8px;
    color: var(--muted);
  }
</style>
