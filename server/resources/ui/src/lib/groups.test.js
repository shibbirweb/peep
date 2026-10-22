import { expect, test } from 'bun:test';
import { groupRows } from './groups.js';

const card = (id, request, project = 'shop') => ({ id, project, request: { id: request, kind: 'http', label: `GET /${request}` }, payloads: [] });

test('cards in a row from one request share a header; single cards stay alone', () => {
  const cards = [card('c3', 'r2'), card('c2', 'r1'), card('c1', 'r1'), card('c0', 'r0')];

  const rows = groupRows(cards, new Set());

  expect(rows.map((row) => (row.kind === 'group' ? `group ${row.count}` : row.card.id))).toEqual(['c3', 'group 2', 'c2', 'c1', 'c0']);
  expect(rows[1].key).toBe('group:shop:c1');
});

test('a collapsed group shows only its header', () => {
  const rows = groupRows([card('b', 'r1'), card('a', 'r1')], new Set(['r1']));

  expect(rows).toHaveLength(1);
  expect(rows[0].collapsed).toBe(true);
});

test('the same request id in two projects is not one group', () => {
  expect(groupRows([card('b', 'r1', 'shop'), card('a', 'r1', 'blog')], new Set())).toHaveLength(2);
});
