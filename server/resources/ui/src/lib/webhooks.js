// Pure helpers for the webhook simulator page.

export const MAX_DELIVERIES = 200;

export const VARIABLES = [
  ['event', 'The step\'s event, e.g. delivered'],
  ['project', 'Project of the mail or SMS'],
  ['now', 'ISO 8601 time; also now.unix, now.unix_float, now.ms'],
  ['mail.message_id', 'Message-ID without < >'],
  ['mail.recipient', 'First To address'],
  ['mail.to, mail.from, mail.subject, mail.text', 'Mail fields'],
  ['mail.headers.<name>', 'Any header, lower case'],
  ['sms.sid, sms.message_id', 'Twilio SID; Vonage-style id'],
  ['sms.to, sms.from, sms.body, sms.segments', 'SMS fields'],
  ['signature.timestamp, .token, .signature', 'Mailgun signature (Mailgun signing)'],
  ['vars.<name>', 'Your own variables'],
  ['uuid(), random_hex(n), random_int(a, b)', 'Random values'],
  ['hmac_sha256(key, data), sha1(x), base64(x)', 'Hashes and encodings; also hmac_sha1, sha256, md5, url_encode'],
  ["date('Y-m-d'), upper(x), lower(x), a ~ b", 'Dates, case, joining text'],
];

/**
 * @param {{rules: Array<object>, deliveries: Array<object>}} state
 * @param {string} name
 * @param {object} data
 * @returns {{rules: Array<object>, deliveries: Array<object>}}
 */
export function applyWebhookEvent(state, name, data) {
  switch (name) {
    case 'webhooks.changed':
      return { ...state, rules: data.rules };
    case 'webhook.delivered':
      return { ...state, deliveries: [data, ...state.deliveries.filter((delivery) => delivery.id !== data.id)].slice(0, MAX_DELIVERIES) };
    case 'webhook.cleared':
      // One project (an agent's `peep clear --project=...`) or every project.
      return { ...state, deliveries: data.project ? state.deliveries.filter((delivery) => delivery.project !== data.project) : [] };
    default:
      return state;
  }
}

/**
 * Rules that can be sent by hand for a caught mail or SMS: their own trigger or manual.
 *
 * @param {Array<object>} rules
 * @param {'mail'|'sms'} kind
 * @param {string} project
 * @returns {Array<object>}
 */
export function rulesFor(rules, kind, project) {
  return rules.filter((rule) => (rule.trigger === `${kind}.received` || rule.trigger === 'manual') && (rule.project === '*' || rule.project === project));
}

/**
 * @param {object} rule
 * @param {Array<object>} presets
 * @returns {Array<string>} Events to offer for a manual send.
 */
export function eventsOf(rule, presets = []) {
  const fromPreset = presets.find((preset) => preset.rule.signature?.type === rule.signature?.type && preset.rule.trigger === rule.trigger)?.events ?? [];

  return [...new Set([...rule.steps.map((step) => step.event), ...fromPreset])];
}

/**
 * A blank rule from a preset, ready for the editor.
 *
 * @param {object} preset
 * @param {string} project
 * @returns {object}
 */
export function ruleFromPreset(preset, project) {
  return {
    id: '',
    project: project || '*',
    filter: { to_contains: '' },
    use_status_callback: false,
    headers: {},
    vars: {},
    signature: { type: 'none', secret: '', header: '', value: '' },
    retry: { enabled: false, max: 3 },
    enabled: true,
    // A JSON copy: presets live in reactive state, whose proxies structuredClone() cannot copy.
    ...JSON.parse(JSON.stringify(preset.rule)),
  };
}

/**
 * @param {Array<object>} rules
 * @returns {string} Pretty JSON for export.
 */
export function exportRules(rules) {
  return JSON.stringify({ peep_webhooks: 1, rules }, null, 2);
}

/**
 * @param {string} text An export, or a plain list of rules.
 * @returns {Array<object>}
 */
export function parseImport(text) {
  const data = JSON.parse(text);
  const rules = Array.isArray(data) ? data : data?.rules;

  if (!Array.isArray(rules)) {
    throw new Error('The file has no "rules" list.');
  }

  return rules;
}
