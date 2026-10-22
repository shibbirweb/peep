<script>
  // MS-14 and MS-15: caught SMS, one thread per phone number.
  import { clearSms, deleteSms, markThreadRead } from '../lib/api.js';
  import { clock } from '../lib/format.js';
  import { describeSegments, threadsOf } from '../lib/inbox.js';
  import SendWebhook from './SendWebhook.svelte';

  /** @type {{ messages: Array<object>, project: string, onrequest: (requestId: string) => void }} */
  let { messages, project, onrequest } = $props();

  let selectedKey = $state(null);

  const threads = $derived(threadsOf(messages.filter((sms) => project === '' || sms.project === project)));
  const thread = $derived(threads.find((each) => each.key === selectedKey) ?? threads[0] ?? null);

  $effect(() => {
    if (thread !== null && thread.unread > 0) {
      markThreadRead(thread.project, thread.to);
    }
  });
</script>

<div class="inbox">
  <aside>
    <div class="list-tools">
      <span class="muted">{threads.length} {threads.length === 1 ? 'number' : 'numbers'}</span>
      <span class="spacer"></span>
      <button type="button" onclick={() => clearSms(project === '' ? null : project)} disabled={threads.length === 0}>Clear</button>
    </div>
    <ul>
      {#each threads as each (each.key)}
        <li>
          <button type="button" class="item" class:selected={thread?.key === each.key} onclick={() => (selectedKey = each.key)}>
            <span class="line">
              <span class="number">{each.to}</span>
              {#if each.unread > 0}<span class="badge">{each.unread}</span>{/if}
              <time>{clock(each.last.received_at)}</time>
            </span>
            <span class="preview">{each.last.body}</span>
            {#if project === ''}<span class="muted">{each.project}</span>{/if}
          </button>
        </li>
      {:else}
        <li class="empty">
          <p>No SMS yet.</p>
          <p class="hint">Point the app's Twilio or Vonage base URL at the viewer, post to <code>/_peep/sms</code>, call <code>peep()-&gt;sms()</code>, or send Laravel SMS notifications.</p>
        </li>
      {/each}
    </ul>
  </aside>

  <section class="thread">
    {#if thread === null}
      <p class="empty">Select a number.</p>
    {:else}
      <header><h2>{thread.to}</h2><span class="muted">{thread.messages.length} {thread.messages.length === 1 ? 'message' : 'messages'} · {thread.project}</span></header>
      {#each thread.messages as sms (sms.id)}
        <article class="bubble">
          <p class="text">{sms.body}</p>
          {#each sms.media as url (url)}<p class="media">Media: <a href={url} rel="noreferrer" target="_blank">{url}</a></p>{/each}
          <footer>
            <span>{clock(sms.received_at)}</span>
            {#if sms.from}<span>from {sms.from}</span>{/if}
            <span>{describeSegments(sms.segments)}</span>
            <span class="badge source">{sms.source}</span>
            <span title="Twilio-style message SID" class="sid">{sms.sid}</span>
            {#if sms.status_callback}<span title="Status callback the app asked for">callback {sms.status_callback}</span>{/if}
            {#if sms.request_id}<button type="button" class="link" onclick={() => onrequest(sms.request_id)}>Show request</button>{/if}
            <button type="button" class="link" onclick={() => deleteSms(sms.id)}>Delete</button>
          </footer>
          <div class="webhook"><SendWebhook kind="sms" id={sms.id} project={sms.project} /></div>
        </article>
      {/each}
    {/if}
  </section>
</div>

<style>
  .inbox {
    display: grid;
    grid-template-columns: minmax(240px, 320px) 1fr;
    gap: 12px;
    align-items: start;
  }

  aside,
  .thread {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
  }

  aside {
    position: sticky;
    top: 60px;
    max-height: calc(100vh - 130px);
    overflow: auto;
  }

  .list-tools {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
  }

  ul {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    text-align: left;
    border: 0;
    border-bottom: 1px solid var(--border);
    border-radius: 0;
    padding: 8px 10px;
    background: none;
  }

  .item.selected {
    background: var(--surface-2);
  }

  .line {
    display: flex;
    gap: 8px;
    align-items: baseline;
  }

  .number {
    font-family: var(--mono);
    font-weight: 600;
    flex: 1;
  }

  .preview {
    font-size: 12px;
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  time,
  .muted {
    font-size: 12px;
    color: var(--muted);
  }

  .badge {
    font-size: 11px;
    border-radius: 999px;
    padding: 0 7px;
    background: var(--accent);
    color: white;
  }

  .badge.source {
    background: var(--surface-2);
    color: var(--muted);
  }

  .spacer {
    flex: 1;
  }

  .thread {
    padding: 12px 16px 16px;
    min-height: 300px;
  }

  header {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 10px;
  }

  h2 {
    margin: 0;
    font-size: 17px;
    font-family: var(--mono);
  }

  .bubble {
    background: var(--surface-2);
    border-radius: 12px 12px 12px 4px;
    padding: 8px 12px;
    margin: 8px 0;
    max-width: 640px;
  }

  .text {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .media {
    margin: 4px 0 0;
    font-size: 12px;
    overflow-wrap: anywhere;
  }

  footer {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 10px;
    margin-top: 6px;
    font-size: 11.5px;
    color: var(--muted);
  }

  .webhook {
    margin-top: 6px;
  }

  .sid {
    font-family: var(--mono);
  }

  .link {
    border: 0;
    background: none;
    padding: 0;
    color: var(--accent);
    font-size: 11.5px;
  }

  .empty {
    padding: 24px 16px;
    color: var(--muted);
    text-align: center;
  }

  .hint {
    font-size: 12px;
  }

  button:disabled {
    opacity: 0.45;
    cursor: default;
  }

  @media (max-width: 720px) {
    .inbox {
      grid-template-columns: 1fr;
    }

    aside {
      position: static;
      max-height: 40vh;
    }
  }
</style>
