import { describe, expect, test } from 'bun:test';
import { appendEntries, contextOf, firstLine, jsonNode, levelCounts, pathCrumbs, prependEntries, projectFromLogPath, visibleEntries } from './logs.js';

const entry = (offset, level = 'info', raw = `entry ${offset}`) => ({ offset, level, raw, message: raw });

describe('projectFromLogPath', () => {
  test('names the app that owns storage/logs or a logs folder', () => {
    expect(projectFromLogPath('/Users/me/Projects/shop/storage/logs')).toBe('shop');
    expect(projectFromLogPath('/Users/me/Projects/shop/storage/logs/')).toBe('shop');
    expect(projectFromLogPath('C:\\Sites\\shop\\storage\\logs')).toBe('shop');
    expect(projectFromLogPath('/var/www/blog/logs')).toBe('blog');
  });

  test('otherwise uses the folder name', () => {
    expect(projectFromLogPath('/tmp')).toBe('tmp');
    expect(projectFromLogPath('/srv/app-logs')).toBe('app-logs');
    expect(projectFromLogPath('  ')).toBe('');
    expect(projectFromLogPath('/')).toBe('');
  });
});

describe('pathCrumbs', () => {
  test('splits a path into folders to go back to', () => {
    expect(pathCrumbs('/Users/me/shop')).toEqual([
      { name: '/', path: '/' },
      { name: 'Users', path: '/Users' },
      { name: 'me', path: '/Users/me' },
      { name: 'shop', path: '/Users/me/shop' },
    ]);
    expect(pathCrumbs('/')).toEqual([{ name: '/', path: '/' }]);
  });

  test('keeps the drive of a Windows path', () => {
    expect(pathCrumbs('C:\\Sites\\shop')).toEqual([
      { name: 'C:\\', path: 'C:\\' },
      { name: 'Sites', path: 'C:\\Sites' },
      { name: 'shop', path: 'C:\\Sites\\shop' },
    ]);
  });
});

describe('jsonNode', () => {
  test('turns JSON into value tree nodes', () => {
    expect(jsonNode({ id: 18, total: 9.5, tags: ['a'], ok: true, none: null })).toEqual({
      type: 'array',
      length: 5,
      children: [
        { key: 'id', node: { type: 'int', value: 18 } },
        { key: 'total', node: { type: 'float', value: 9.5 } },
        { key: 'tags', node: { type: 'array', length: 1, children: [{ key: 0, node: { type: 'string', value: 'a', length: 1 } }] } },
        { key: 'ok', node: { type: 'bool', value: true } },
        { key: 'none', node: { type: 'null' } },
      ],
    });
  });
});

describe('contextOf', () => {
  test('leaves out the exception the entry shows on its own', () => {
    expect(contextOf({ exception: { class: 'E' }, context: { order: 18, exception: '[object] ...' } })).toEqual({ order: 18 });
    expect(contextOf({ exception: { class: 'E' }, context: { exception: '[object] ...' } })).toBeNull();
    expect(contextOf({ exception: null, context: [] })).toBeNull();
    expect(contextOf({ exception: null, context: { a: 1 } })).toEqual({ a: 1 });
  });
});

describe('keeping entries', () => {
  test('watching appends new entries once and drops the oldest past the limit', () => {
    const { entries, dropped } = appendEntries([entry(0), entry(10)], [entry(10), entry(20), entry(30)], 3);

    expect(entries.map((item) => item.offset)).toEqual([10, 20, 30]);
    expect(dropped).toBe(true);
  });

  test('older entries go first', () => {
    expect(prependEntries([entry(20)], [entry(0), entry(10), entry(20)]).map((item) => item.offset)).toEqual([0, 10, 20]);
  });
});

describe('visibleEntries', () => {
  const entries = [entry(0, 'debug', 'cache miss'), entry(10, 'error', 'Payment failed'), entry(20, 'info', 'Payment ok')];

  test('filters by level and text, in either order', () => {
    const levels = new Set(['error', 'info']);

    expect(visibleEntries(entries, { levels, search: 'payment', order: 'oldest' }).map((item) => item.offset)).toEqual([10, 20]);
    expect(visibleEntries(entries, { levels, search: '', order: 'newest' }).map((item) => item.offset)).toEqual([20, 10]);
    expect(entries.map((item) => item.offset)).toEqual([0, 10, 20]);
  });

  test('counts levels', () => {
    expect(levelCounts(entries)).toMatchObject({ debug: 1, error: 1, info: 1, warning: 0 });
  });
});

test('firstLine', () => {
  expect(firstLine('one\ntwo')).toBe('one …');
  expect(firstLine('one')).toBe('one');
});
