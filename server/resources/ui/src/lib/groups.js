// Groups the card list by request: cards in a row from the same HTTP request, command or
// job get one collapsible header. Single cards stay on their own.

/**
 * @param {Array<object>} cards Newest first.
 * @param {Set<string>} collapsed Request ids whose groups are collapsed.
 * @returns {Array<{kind: 'group', key: string, request: object, count: number, collapsed: boolean, project: string}|{kind: 'card', key: string, card: object}>}
 */
export function groupRows(cards, collapsed) {
  const rows = [];
  let index = 0;

  while (index < cards.length) {
    const first = cards[index];
    let end = index + 1;

    while (end < cards.length && sameRequest(cards[end], first)) {
      end++;
    }

    const run = cards.slice(index, end);

    if (run.length > 1) {
      const isCollapsed = collapsed.has(first.request.id);

      // Keyed by the oldest card of the run, which stays the same while new cards arrive.
      rows.push({
        kind: 'group',
        key: `group:${first.project}:${run[run.length - 1].id}`,
        request: first.request,
        project: first.project,
        count: run.length,
        collapsed: isCollapsed,
      });

      if (!isCollapsed) {
        for (const card of run) {
          rows.push({ kind: 'card', key: `card:${card.project}:${card.id}`, card });
        }
      }
    } else {
      rows.push({ kind: 'card', key: `card:${first.project}:${first.id}`, card: first });
    }

    index = end;
  }

  return rows;
}

function sameRequest(a, b) {
  return a.project === b.project && a.request?.id !== undefined && a.request.id === b.request?.id;
}
