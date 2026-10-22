// The viewer's UI actions. Every POST carries X-Peep-UI, which the viewer requires.

const UI_HEADERS = {
  'Content-Type': 'application/json',
  'X-Peep-UI': '1',
};

async function post(path, body = {}) {
  const response = await fetch(path, {
    method: 'POST',
    headers: UI_HEADERS,
    body: JSON.stringify(body),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(data.message ?? `The viewer answered ${response.status}.`);
    error.setting = data.setting ?? null;
    throw error;
  }

  return data;
}

/**
 * @param {string|null} project Null clears every project.
 */
export function clearCards(project) {
  return post('/_peep/ui/clear', { project });
}

/**
 * @param {string} name
 * @param {'continue'|'stop'} action
 */
export function releaseLock(name, action) {
  return post(`/_peep/ui/locks/${encodeURIComponent(name)}/${action}`);
}

/**
 * @param {string} project
 * @param {object} switches e.g. { queries: 'on' }
 */
export function setSwitches(project, switches) {
  return post('/_peep/ui/switches', { project, switches });
}

/**
 * @param {boolean} paused
 */
export function setReceiving(paused) {
  return post('/_peep/ui/receiving', { paused });
}

/**
 * @param {string} project
 */
export function newScreen(project) {
  return post('/_peep/ui/screens', { project });
}

/**
 * @param {string} id
 */
export async function loadScreen(id) {
  const response = await fetch(`/_peep/ui/screens/${encodeURIComponent(id)}`);

  if (!response.ok) {
    throw new Error('This screen is no longer kept.');
  }

  return response.json();
}

export async function loadSettings() {
  const response = await fetch('/_peep/ui/settings');

  return response.json();
}

/**
 * @param {object} changes
 */
export function saveSettings(changes) {
  return post('/_peep/ui/settings', { changes });
}

/**
 * @param {string} id
 */
export async function loadMail(id) {
  const response = await fetch(`/_peep/ui/mail/${encodeURIComponent(id)}`);

  if (!response.ok) {
    throw new Error('This mail is no longer in the inbox.');
  }

  return response.json();
}

/**
 * @param {string} id
 */
export async function loadMailSource(id) {
  const response = await fetch(`/_peep/ui/mail/${encodeURIComponent(id)}/source`);

  return response.text();
}

/**
 * @param {string} id
 * @param {boolean} read
 */
export function markMailRead(id, read = true) {
  return post(`/_peep/ui/mail/${encodeURIComponent(id)}/read`, { read });
}

/**
 * @param {string} id
 */
export function deleteMail(id) {
  return post(`/_peep/ui/mail/${encodeURIComponent(id)}/delete`);
}

/**
 * @param {string|null} project Null clears every project.
 */
export function clearMail(project) {
  return post('/_peep/ui/mail/clear', { project });
}

/**
 * @param {string} project
 * @param {string} to
 */
export function markThreadRead(project, to) {
  return post('/_peep/ui/sms/read', { project, to });
}

/**
 * @param {string} id
 */
export function deleteSms(id) {
  return post(`/_peep/ui/sms/${encodeURIComponent(id)}/delete`);
}

/**
 * @param {string|null} project Null clears every project.
 */
export function clearSms(project) {
  return post('/_peep/ui/sms/clear', { project });
}
