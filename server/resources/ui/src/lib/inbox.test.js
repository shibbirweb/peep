import { describe, expect, test } from 'bun:test';
import { applyInboxEvent, describeSegments, mailMatches, threadsOf } from './inbox.js';

describe('inbox events', () => {
  test('received, updated, removed and cleared', () => {
    let list = applyInboxEvent([], 'mail.received', { id: 'a', project: 'shop', read: false });
    list = applyInboxEvent(list, 'mail.received', { id: 'b', project: 'blog', read: false });
    list = applyInboxEvent(list, 'mail.updated', { id: 'a', read: true });

    expect(list.map((mail) => mail.id)).toEqual(['b', 'a']);
    expect(list[1].read).toBe(true);
    expect(applyInboxEvent(list, 'mail.removed', { id: 'b' })).toHaveLength(1);
    expect(applyInboxEvent(list, 'mail.cleared', { project: 'shop' }).map((mail) => mail.id)).toEqual(['b']);
    expect(applyInboxEvent(list, 'mail.cleared', { project: null })).toEqual([]);
  });
});

test('SMS threads per number, oldest message first inside a thread', () => {
  const sms = (id, to, read = false, project = 'shop') => ({ id, to, read, project });
  const threads = threadsOf([sms('3', '+1'), sms('2', '+2', true), sms('1', '+1')]);

  expect(threads.map((thread) => thread.to)).toEqual(['+1', '+2']);
  expect(threads[0].messages.map((message) => message.id)).toEqual(['1', '3']);
  expect(threads[0].unread).toBe(2);
  expect(threads[0].last.id).toBe('3');
});

test('segment descriptions', () => {
  expect(describeSegments({ encoding: 'GSM-7', length: 161, segments: 2 })).toBe('2 parts · 161 characters · GSM-7');
  expect(describeSegments({ encoding: 'UCS-2', length: 5, segments: 1 })).toBe('1 part · 5 UTF-16 units · UCS-2');
});

test('mail search looks at subject, people and preview', () => {
  const mail = { subject: 'Invoice', from: 'shop@app.test', to: ['ada@example.test'], cc: [], preview: 'Total 42' };

  expect(mailMatches(mail, 'ADA@')).toBe(true);
  expect(mailMatches(mail, 'total 42')).toBe(true);
  expect(mailMatches(mail, 'refund')).toBe(false);
});
