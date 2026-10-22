import { describe, expect, test } from 'bun:test';
import { formatBytes, formatXml, framedHtml, hasChildren, isSafeUrl, prettyJson, startsOpen, summary, tableOf, visibilityMarker } from './format.js';

describe('summary', () => {
  test('scalars and containers', () => {
    expect(summary({ type: 'null' })).toBe('null');
    expect(summary({ type: 'bool', value: false })).toBe('false');
    expect(summary({ type: 'int', value: '9007199254740993' })).toBe('9007199254740993');
    expect(summary({ type: 'float', value: 'NAN' })).toBe('NAN');
    expect(summary({ type: 'string', value: 'a"b' })).toBe('"a\\"b"');
    expect(summary({ type: 'string', value: 'abc', truncated: 7 })).toBe('"abc" … +7 chars');
    expect(summary({ type: 'array', length: 3 })).toBe('array(3)');
    expect(summary({ type: 'object', class: 'App\\User', id: 2 })).toBe('App\\User #2');
    expect(summary({ type: 'object', class: 'App\\User', id: 2, lazy: true })).toBe('App\\User #2 (lazy, not initialized)');
    expect(summary({ type: 'ref', id: 2 })).toBe('→ #2');
    expect(summary({ type: 'max_depth', length: 4 })).toBe('array(4) …');
    expect(summary({ type: 'binary', length: 10, value: 'AA==' })).toBe('binary(10 bytes)');
    expect(summary({ type: 'resource', value: 'closed' })).toBe('resource(closed)');
  });
});

test('hasChildren', () => {
  expect(hasChildren({ type: 'array', children: [{ key: 0, node: { type: 'null' } }] })).toBe(true);
  expect(hasChildren({ type: 'array', children: [] })).toBe(false);
  expect(hasChildren({ type: 'string', value: 'x' })).toBe(false);
});

test('visibilityMarker', () => {
  expect(visibilityMarker('public')).toBe('+');
  expect(visibilityMarker('protected')).toBe('#');
  expect(visibilityMarker('private')).toBe('-');
  expect(visibilityMarker(undefined)).toBe('');
});

test('prettyJson', () => {
  expect(prettyJson('{"a":1}')).toEqual({ ok: true, text: '{\n  "a": 1\n}' });
  expect(prettyJson('{oops')).toEqual({ ok: false, text: '{oops' });
});

describe('tableOf', () => {
  const scalar = (value) => ({ type: 'string', value });
  const row = (pairs) => ({
    type: 'array',
    children: Object.entries(pairs).map(([key, value]) => ({ key, node: scalar(value) })),
  });

  test('a flat array becomes key/value rows', () => {
    const table = tableOf({ type: 'array', children: [{ key: 'name', node: scalar('Ada') }] });

    expect(table.columns).toEqual(['Key', 'Value']);
    expect(table.rows).toEqual([{ key: 'name', cells: [scalar('Ada')] }]);
  });

  test('a list of rows becomes columns, missing cells are null', () => {
    const table = tableOf({
      type: 'array',
      children: [
        { key: 0, node: row({ id: '1', name: 'Ada' }) },
        { key: 1, node: row({ id: '2', role: 'editor' }) },
      ],
    });

    expect(table.columns).toEqual(['#', 'id', 'name', 'role']);
    expect(table.rows[1].cells).toEqual([scalar('2'), null, scalar('editor')]);
  });
});

describe('startsOpen', () => {
  test('first two levels by default', () => {
    expect(startsOpen(0, '', null)).toBe(true);
    expect(startsOpen(1, 'a', null)).toBe(true);
    expect(startsOpen(2, 'a.b', null)).toBe(false);
  });

  test('level, keys and all', () => {
    expect(startsOpen(3, 'a.b.c', { level: 4 })).toBe(true);
    expect(startsOpen(1, 'a', { level: 1 })).toBe(false);
    expect(startsOpen(3, 'user.roles', { level: null, keys: ['user.roles.admin'] })).toBe(true);
    expect(startsOpen(3, 'user.name', { level: null, keys: ['user.roles'] })).toBe(false);
    expect(startsOpen(9, 'x.y', { all: true })).toBe(true);
  });
});

test('formatBytes', () => {
  expect(formatBytes(512)).toBe('512 B');
  expect(formatBytes(2048)).toBe('2.0 KB');
  expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB');
});

test('formatXml indents nested tags and keeps text inline', () => {
  expect(formatXml('<?xml version="1.0"?><a><b>text</b><c/></a>')).toBe('<?xml version="1.0"?>\n<a>\n  <b>text</b>\n  <c/>\n</a>');
});

test('isSafeUrl', () => {
  expect(isSafeUrl('https://example.com')).toBe(true);
  expect(isSafeUrl('mailto:a@b.c')).toBe(true);
  expect(isSafeUrl('javascript:alert(1)')).toBe(false);
  expect(isSafeUrl('data:text/html,x')).toBe(false);
});

test('summary shows caster summaries for objects', () => {
  expect(summary({ type: 'object', class: 'DateTime', id: 1, summary: '2026-10-22' })).toBe('DateTime #1 · 2026-10-22');
});

test('framedHtml makes a light document around app HTML', () => {
  const framed = framedHtml('<pre>x</pre>');

  expect(framed).toContain('color-scheme" content="light"');
  expect(framed).toContain('<body><pre>x</pre></body>');
});
