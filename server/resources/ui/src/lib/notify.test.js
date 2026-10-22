import { expect, test } from 'bun:test';
import { notificationFor } from './notify.js';

test('notify() and exceptions make notifications; other cards do not', () => {
  expect(notificationFor({ project: 'shop', payloads: [{ type: 'notify', content: { message: 'Import done' } }] })).toEqual({ title: 'Peep · shop', body: 'Import done' });
  expect(notificationFor({ project: 'shop', payloads: [{ type: 'exception', content: { class: 'RuntimeException', message: 'Boom' } }] })).toEqual({ title: 'RuntimeException · shop', body: 'Boom' });
  expect(notificationFor({ project: 'shop', payloads: [{ type: 'log', content: {} }] })).toBeNull();
});
