import { describe, expect, test } from 'bun:test';
import { cardText, nodeText, searchText } from './text.js';

const str = (value) => ({ type: 'string', value, length: value.length });

describe('text forms', () => {
  test('a value tree becomes indented lines', () => {
    const node = {
      type: 'array',
      length: 2,
      children: [
        { key: 'name', node: str('Ada') },
        { key: 'roles', node: { type: 'array', length: 1, children: [{ key: 0, node: str('admin') }] } },
      ],
    };

    expect(nodeText(node)).toBe('array(2)\n  name: "Ada"\n  roles: array(1)\n    0: "admin"');
  });

  test('a card joins its label and payloads, skipping update payloads', () => {
    const card = {
      label: 'User',
      payloads: [
        { type: 'log', content: { values: [str('hello')] } },
        { type: 'color', content: { color: 'red' } },
        { type: 'query', content: { sql: 'select * from users' } },
      ],
    };

    expect(cardText(card)).toBe('User\nhello\nselect * from users');
  });

  test('search text is lower case and includes the origin and request', () => {
    const card = { payloads: [{ type: 'text', content: { text: 'Order PAID' } }], origin: { file: '/app/Checkout.php' }, request: { label: 'POST /pay' } };

    expect(searchText(card)).toContain('order paid');
    expect(searchText(card)).toContain('/app/checkout.php');
    expect(searchText(card)).toContain('post /pay');
  });

  test('unknown payload types are searched through every string inside', () => {
    expect(cardText({ payloads: [{ type: 'custom', content: { a: 'one', b: { c: 'two', d: 3 } } }] })).toBe('one\ntwo\n3');
  });
});
