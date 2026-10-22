<script>
  import { ideUrl, shortPath } from '../lib/ide.js';
  import ExceptionView from './ExceptionView.svelte';
  import Frames from './Frames.svelte';

  /** @type {{ exception: object, ide: string, nested?: boolean }} */
  let { exception, ide, nested = false } = $props();

  let showFrames = $state(false);
  const link = $derived(ideUrl(ide, exception.file, exception.line));
</script>

<div class="exception" class:nested>
  <div class="title"><span class="class">{exception.class}</span>{#if exception.code}<span class="code">code {exception.code}</span>{/if}</div>
  <div class="message">{exception.message}</div>
  <div class="where">
    {#if link}<a href={link}>{shortPath(exception.file)}:{exception.line}</a>{:else}{shortPath(exception.file)}:{exception.line}{/if}
  </div>

  {#if exception.snippet}
    <pre class="snippet">{#each exception.snippet.lines as line, index (index)}<span class:highlight={exception.snippet.start + index === exception.snippet.highlight}><span class="number">{exception.snippet.start + index}</span>{line}
</span>{/each}</pre>
  {/if}

  {#if exception.frames?.length}
    <button type="button" class="toggle" onclick={() => (showFrames = !showFrames)}>{showFrames ? 'Hide' : 'Show'} trace ({exception.frames.length})</button>
    {#if showFrames}<Frames frames={exception.frames} {ide} />{/if}
  {/if}

  {#each exception.previous ?? [] as previous, index (index)}
    <div class="previous-label">Caused by</div>
    <ExceptionView exception={previous} {ide} nested={true} />
  {/each}
</div>

<style>
  .exception.nested {
    margin-top: 4px;
    padding-left: 10px;
    border-left: 2px solid var(--border);
  }

  .class {
    font-weight: 600;
    color: var(--danger);
  }

  .code {
    margin-left: 8px;
    color: var(--muted);
    font-size: 12px;
  }

  .message {
    margin: 2px 0;
  }

  .where {
    font-family: var(--mono);
    font-size: 12px;
  }

  .snippet {
    margin: 6px 0;
    padding: 6px 0;
    background: var(--surface-2);
    border-radius: 6px;
    overflow-x: auto;
    white-space: pre;
  }

  .snippet > span {
    display: block;
    padding: 0 8px;
  }

  .highlight {
    background: color-mix(in srgb, var(--danger) 18%, transparent);
  }

  .number {
    display: inline-block;
    width: 3.5em;
    color: var(--muted);
    user-select: none;
  }

  .toggle {
    font-size: 12px;
    padding: 2px 8px;
    margin: 2px 0 4px;
  }

  .previous-label {
    margin-top: 8px;
    font-size: 12px;
    color: var(--muted);
  }
</style>
