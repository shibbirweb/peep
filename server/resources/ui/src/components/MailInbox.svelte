<script>
  // MS-05 to MS-08: caught mail. List on the left, the open mail on the right.
  import { clearMail, deleteMail, loadMail, loadMailSource, markMailRead } from '../lib/api.js';
  import { clock, formatBytes } from '../lib/format.js';
  import { mailMatches } from '../lib/inbox.js';
  import HtmlFrame from './HtmlFrame.svelte';

  /** @type {{ mails: Array<object>, project: string, smtpPort: number, onrequest: (requestId: string) => void }} */
  let { mails, project, smtpPort, onrequest } = $props();

  let search = $state('');
  let selectedId = $state(null);
  let detail = $state(null);
  let source = $state('');
  let view = $state('html');
  let width = $state('desktop');
  let error = $state('');

  const visible = $derived(mails.filter((mail) => (project === '' || mail.project === project) && mailMatches(mail, search)));
  const selected = $derived(mails.find((mail) => mail.id === selectedId) ?? null);
  const attachments = $derived((detail?.attachments ?? []).filter((attachment) => !attachment.inline));

  async function open(mail) {
    selectedId = mail.id;
    detail = null;
    source = '';
    error = '';

    try {
      detail = await loadMail(mail.id);
      view = detail.html ? 'html' : 'text';

      if (!mail.read) {
        markMailRead(mail.id);
      }
    } catch (exception) {
      error = exception.message;
    }
  }

  async function show(next) {
    view = next;

    if (next === 'source' && source === '' && selectedId !== null) {
      source = await loadMailSource(selectedId);
    }
  }

  async function remove() {
    if (selectedId !== null) {
      await deleteMail(selectedId);
      selectedId = null;
      detail = null;
    }
  }
</script>

<div class="inbox">
  <aside>
    <div class="list-tools">
      <input type="search" placeholder="Search mail" bind:value={search} aria-label="Search mail" />
      <button type="button" onclick={() => clearMail(project === '' ? null : project)} disabled={visible.length === 0}>Clear</button>
    </div>
    <ul>
      {#each visible as mail (mail.id)}
        <li>
          <button type="button" class="item" class:selected={mail.id === selectedId} class:unread={!mail.read} onclick={() => open(mail)}>
            <span class="line"><span class="from">{mail.from || '(no sender)'}</span><time>{clock(mail.received_at)}</time></span>
            <span class="subject">{mail.subject || '(no subject)'}</span>
            <span class="preview">{#if mail.attachment_count > 0}📎 {/if}{mail.preview}</span>
            {#if project === ''}<span class="project">{mail.project}</span>{/if}
          </button>
        </li>
      {:else}
        <li class="empty">
          {#if mails.length === 0}
            <p>No mail yet.</p>
            <p class="hint">Send through SMTP on port {smtpPort}, set <code>MAIL_MAILER=peep</code> in Laravel, or post to <code>/_peep/mail</code>.</p>
          {:else}
            <p>No mail matches.</p>
          {/if}
        </li>
      {/each}
    </ul>
  </aside>

  <section class="detail">
    {#if selected === null}
      <p class="placeholder">Select a mail.</p>
    {:else if error}
      <p class="error">{error}</p>
    {:else if detail === null}
      <p class="placeholder">Loading…</p>
    {:else}
      <header>
        <h2>{detail.subject || '(no subject)'}</h2>
        <dl>
          <dt>From</dt><dd>{detail.from}</dd>
          <dt>To</dt><dd>{detail.to.join(', ')}</dd>
          {#if detail.cc.length}<dt>Cc</dt><dd>{detail.cc.join(', ')}</dd>{/if}
          {#if detail.bcc.length}<dt>Bcc</dt><dd>{detail.bcc.join(', ')}</dd>{/if}
          <dt>Received</dt><dd>{clock(detail.received_at)} · {formatBytes(detail.size)} · via {detail.source} · {detail.project}</dd>
        </dl>
        <div class="actions">
          {#if detail.request_id}<button type="button" onclick={() => onrequest(detail.request_id)} title="Show the cards of the request or job that sent this mail">Show request</button>{/if}
          <button type="button" onclick={() => markMailRead(detail.id, false)}>Mark unread</button>
          <button type="button" onclick={remove}>Delete</button>
        </div>
      </header>

      <nav class="tabs" aria-label="Mail views">
        {#each [['html', 'HTML', !!detail.html], ['text', 'Text', !!detail.text], ['source', 'Source', true], ['headers', 'Headers', true], ['attachments', `Attachments (${attachments.length})`, attachments.length > 0]] as [id, name, available] (id)}
          <button type="button" class:active={view === id} disabled={!available} onclick={() => show(id)}>{name}</button>
        {/each}
        {#if view === 'html'}
          <span class="spacer"></span>
          <button type="button" class:active={width === 'desktop'} onclick={() => (width = 'desktop')}>Desktop</button>
          <button type="button" class:active={width === 'mobile'} onclick={() => (width = 'mobile')}>Mobile</button>
        {/if}
      </nav>

      <div class="body">
        {#if view === 'html'}
          <HtmlFrame html={detail.html ?? ''} title="Mail body" document height="60vh" width={width === 'mobile' ? '375px' : '100%'} />
        {:else if view === 'text'}
          <pre>{detail.text}</pre>
        {:else if view === 'source'}
          <pre>{source || 'Loading…'}</pre>
        {:else if view === 'headers'}
          <table>
            <tbody>
              {#each detail.headers as header, index (index)}<tr><th>{header.name}</th><td>{header.value}</td></tr>{/each}
            </tbody>
          </table>
        {:else}
          <ul class="attachments">
            {#each attachments as attachment (attachment.index)}
              <li>
                <a href="/_peep/ui/mail/{detail.id}/attachments/{attachment.index}" download={attachment.filename}>{attachment.filename}</a>
                <span class="muted">{attachment.content_type} · {formatBytes(attachment.size)}</span>
              </li>
            {/each}
          </ul>
        {/if}
      </div>
    {/if}
  </section>
</div>

<style>
  .inbox {
    display: grid;
    grid-template-columns: minmax(260px, 360px) 1fr;
    gap: 12px;
    align-items: start;
  }

  aside,
  .detail {
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
    gap: 6px;
    padding: 8px;
    border-bottom: 1px solid var(--border);
  }

  .list-tools input {
    flex: 1;
    min-width: 0;
    font: inherit;
    color: inherit;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px 8px;
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

  .item.unread .subject,
  .item.unread .from {
    font-weight: 700;
  }

  .item.unread {
    box-shadow: inset 3px 0 0 var(--accent);
  }

  .line {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 12px;
    color: var(--muted);
  }

  .from,
  .subject,
  .preview {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .preview,
  .project {
    font-size: 12px;
    color: var(--muted);
  }

  .empty,
  .placeholder {
    padding: 24px 16px;
    color: var(--muted);
    text-align: center;
  }

  .hint {
    font-size: 12px;
  }

  .detail {
    padding: 12px 16px 16px;
    min-height: 300px;
  }

  h2 {
    margin: 0 0 8px;
    font-size: 17px;
  }

  dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 10px;
    margin: 0;
    font-size: 13px;
  }

  dt {
    color: var(--muted);
  }

  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }

  .actions,
  .tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
  }

  .tabs button.active {
    background: var(--surface-2);
    font-weight: 600;
  }

  .spacer {
    flex: 1;
  }

  .body {
    margin-top: 10px;
  }

  pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    background: var(--surface-2);
    border-radius: 6px;
    padding: 10px;
    max-height: 70vh;
    overflow: auto;
  }

  table {
    border-collapse: collapse;
    font-size: 12.5px;
    width: 100%;
  }

  th,
  td {
    text-align: left;
    vertical-align: top;
    padding: 3px 8px;
    border-bottom: 1px solid var(--border);
    overflow-wrap: anywhere;
  }

  th {
    white-space: nowrap;
    color: var(--muted);
    font-weight: 600;
  }

  .attachments li {
    display: flex;
    gap: 10px;
    padding: 4px 0;
  }

  .muted {
    color: var(--muted);
    font-size: 12px;
  }

  .error {
    color: var(--danger);
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
