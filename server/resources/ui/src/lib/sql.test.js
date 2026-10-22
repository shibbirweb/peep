import { expect, test } from 'bun:test';
import { inlineBindings, quoteBinding } from './sql.js';

test('quoteBinding', () => {
  expect(quoteBinding(null)).toBe('NULL');
  expect(quoteBinding(42)).toBe('42');
  expect(quoteBinding(true)).toBe('1');
  expect(quoteBinding("O'Brien")).toBe("'O''Brien'");
});

test('inlineBindings replaces placeholders outside quotes only', () => {
  expect(inlineBindings('select * from users where id = ? and name = ?', [7, 'Ada'])).toBe("select * from users where id = 7 and name = 'Ada'");
  expect(inlineBindings("select '?' as q, ? as v", [1])).toBe("select '?' as q, 1 as v");
  expect(inlineBindings('select ?', [])).toBe('select ?');
  expect(inlineBindings('select ?, ?', [1])).toBe('select 1, ?');
});
