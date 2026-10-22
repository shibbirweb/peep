// Pure functions for the mail and SMS inboxes (newest first, like the viewer sends them).

export const MAIL_EVENTS = ['mail.received', 'mail.updated', 'mail.removed', 'mail.cleared'];
export const SMS_EVENTS = ['sms.received', 'sms.updated', 'sms.removed', 'sms.cleared'];

/**
 * @param {Array<object>} list Mails or SMS, newest first.
 * @param {string} name Event name, e.g. "mail.received".
 * @param {object} data
 * @returns {Array<object>}
 */
export function applyInboxEvent(list, name, data) {
  switch (name.split('.')[1]) {
    case 'received':
      return [data, ...list.filter((item) => item.id !== data.id)];
    case 'updated':
      return list.map((item) => (item.id === data.id ? { ...item, ...data } : item));
    case 'removed':
      return list.filter((item) => item.id !== data.id);
    case 'cleared':
      return data.project === null ? [] : list.filter((item) => item.project !== data.project);
    default:
      return list;
  }
}

/**
 * SMS grouped into one thread per project and phone number, newest thread first.
 *
 * @param {Array<object>} messages Newest first.
 * @returns {Array<{key: string, project: string, to: string, messages: Array<object>, last: object, unread: number}>}
 */
export function threadsOf(messages) {
  const threads = new Map();

  for (const sms of messages) {
    const key = `${sms.project}\u0000${sms.to}`;
    let thread = threads.get(key);

    if (thread === undefined) {
      thread = { key, project: sms.project, to: sms.to, messages: [], last: sms, unread: 0 };
      threads.set(key, thread);
    }

    thread.messages.unshift(sms);
    thread.unread += sms.read ? 0 : 1;
  }

  return [...threads.values()];
}

/**
 * @param {{encoding: string, length: number, segments: number}} segments
 * @returns {string} e.g. "2 parts · 161 characters · GSM-7"
 */
export function describeSegments(segments) {
  const parts = segments.segments === 1 ? '1 part' : `${segments.segments} parts`;

  return `${parts} · ${segments.length} ${segments.encoding === 'UCS-2' ? 'UTF-16 units' : 'characters'} · ${segments.encoding}`;
}

/**
 * @param {object} mail Summary.
 * @param {string} search
 * @returns {boolean}
 */
export function mailMatches(mail, search) {
  const needle = search.trim().toLowerCase();

  if (needle === '') {
    return true;
  }

  return [mail.subject, mail.from, ...(mail.to ?? []), ...(mail.cc ?? []), mail.preview].join('\n').toLowerCase().includes(needle);
}
