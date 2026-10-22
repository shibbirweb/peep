import { describe, expect, test } from 'bun:test';
import { applyWebhookEvent, eventsOf, exportRules, parseImport, ruleFromPreset, rulesFor } from './webhooks.js';

const rule = (id, trigger, project = '*', steps = [{ event: 'delivered', delay_ms: 0 }]) => ({ id, trigger, project, steps, signature: { type: 'none' } });

describe('webhook state', () => {
  test('deliveries go on top and rules are replaced', () => {
    let state = { rules: [], deliveries: [] };
    state = applyWebhookEvent(state, 'webhook.delivered', { id: 'a' });
    state = applyWebhookEvent(state, 'webhook.delivered', { id: 'b' });
    state = applyWebhookEvent(state, 'webhooks.changed', { rules: [rule('r', 'manual')] });

    expect(state.deliveries.map((delivery) => delivery.id)).toEqual(['b', 'a']);
    expect(state.rules).toHaveLength(1);
    expect(applyWebhookEvent(state, 'webhook.cleared', {}).deliveries).toEqual([]);
  });
});

test('rules offered for a mail: mail and manual triggers of its project', () => {
  const rules = [rule('a', 'mail.received'), rule('b', 'sms.received'), rule('c', 'manual', 'shop'), rule('d', 'manual', 'blog')];

  expect(rulesFor(rules, 'mail', 'shop').map((each) => each.id)).toEqual(['a', 'c']);
});

test('events come from the steps and the matching preset', () => {
  const twilio = { ...rule('t', 'sms.received', '*', [{ event: 'sent', delay_ms: 0 }]), signature: { type: 'twilio' } };
  const presets = [{ rule: { trigger: 'sms.received', signature: { type: 'twilio' } }, events: ['queued', 'sent', 'failed'] }];

  expect(eventsOf(twilio, presets)).toEqual(['sent', 'queued', 'failed']);
});

test('presets become editable rules', () => {
  const made = ruleFromPreset({ rule: { name: 'Hook', trigger: 'manual', steps: [] } }, 'shop');

  expect(made.project).toBe('shop');
  expect(made.name).toBe('Hook');
  expect(made.retry).toEqual({ enabled: false, max: 3 });
});

test('export and import round trip', () => {
  const rules = [rule('a', 'manual')];

  expect(parseImport(exportRules(rules))).toEqual(rules);
  expect(parseImport(JSON.stringify(rules))).toEqual(rules);
  expect(() => parseImport('{}')).toThrow();
});
