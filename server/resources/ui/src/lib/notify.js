// Desktop notifications for notify() and exceptions, while the viewer is in the background.

/**
 * @param {object} card A new card.
 * @returns {{title: string, body: string}|null}
 */
export function notificationFor(card) {
  for (const payload of card.payloads ?? []) {
    if (payload.type === 'notify') {
      return { title: `Peep · ${card.project}`, body: String(payload.content?.message ?? payload.content?.text ?? '') };
    }

    if (payload.type === 'exception') {
      return { title: `${payload.content?.class ?? 'Exception'} · ${card.project}`, body: String(payload.content?.message ?? '') };
    }
  }

  return null;
}

/**
 * Shows the notification when the user allowed it and is not looking at the viewer.
 *
 * @param {object} card
 */
export function maybeNotify(card) {
  const notification = notificationFor(card);

  if (notification === null || typeof Notification === 'undefined' || Notification.permission !== 'granted' || !document.hidden) {
    return;
  }

  new Notification(notification.title, { body: notification.body, tag: `peep-${card.project}-${card.id}` });
}

/**
 * @returns {Promise<boolean>} Whether notifications may be shown.
 */
export async function askPermission() {
  if (typeof Notification === 'undefined') {
    return false;
  }

  if (Notification.permission === 'granted') {
    return true;
  }

  return (await Notification.requestPermission()) === 'granted';
}
