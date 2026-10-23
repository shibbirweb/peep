// The Peep desktop app shows this page in its window and exposes a few commands to it
// (window.__TAURI__, allowed by the app's "viewer" capability). In a browser they do nothing.

function tauri() {
  return typeof window !== 'undefined' ? window.__TAURI__ : undefined;
}

export function isDesktop() {
  return typeof tauri()?.core?.invoke === 'function';
}

function invoke(command, args = {}) {
  if (!isDesktop()) {
    return Promise.resolve(null);
  }

  return tauri().core.invoke(command, args).catch(() => null);
}

/**
 * @param {number} count Unread mail and SMS, shown on the tray and the dock.
 */
export function setUnread(count) {
  return invoke('set_unread', { count });
}

/**
 * A native notification; the app shows it only while its window is in the background.
 *
 * @param {string} title
 * @param {string} body
 */
export function nativeNotify(title, body) {
  return invoke('notify', { title, body });
}

/**
 * Checks GitHub for a newer desktop app now, on the channel in the settings; the app says what it found.
 */
export function checkUpdates() {
  return invoke('check_updates');
}

/**
 * A native folder picker (the Logs tab's "Browse"). Null when cancelled, or outside the app.
 *
 * @param {string} start The folder to open it at; ignored when it does not exist.
 * @returns {Promise<string|null>}
 */
export function pickFolder(start) {
  return invoke('pick_folder', { start: start.trim() !== '' ? start.trim() : null });
}

/**
 * peep()->showApp() and peep()->hideApp().
 *
 * @param {string} command "show_app" or "hide_app"
 */
export function appCommand(command) {
  if (command === 'show_app') {
    return invoke('show_window');
  }

  if (command === 'hide_app') {
    return invoke('hide_window');
  }

  return Promise.resolve(null);
}
