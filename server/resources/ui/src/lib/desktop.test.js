import { afterEach, expect, test } from 'bun:test';
import { appCommand, isDesktop, nativeNotify, setUnread } from './desktop.js';

afterEach(() => {
  delete globalThis.window;
});

test('outside the desktop app the commands do nothing', async () => {
  globalThis.window = {};

  expect(isDesktop()).toBe(false);
  expect(await setUnread(3)).toBeNull();
});

test('in the desktop app they call its commands', async () => {
  const calls = [];
  globalThis.window = { __TAURI__: { core: { invoke: async (command, args) => calls.push([command, args]) } } };

  await setUnread(2);
  await nativeNotify('Title', 'Body');
  await appCommand('hide_app');
  await appCommand('unknown');

  expect(isDesktop()).toBe(true);
  expect(calls).toEqual([
    ['set_unread', { count: 2 }],
    ['notify', { title: 'Title', body: 'Body' }],
    ['hide_window', {}],
  ]);
});
