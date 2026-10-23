<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Agent;

use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;

/**
 * The filters of the agent endpoints, read from the query string:
 *
 * project  one project (all when missing)
 * since    unix time (1792663321.5) or an age (90s, 5m, 2h): only what came or changed after it
 * request  start of a request id: the cards of one request
 * card     start of a card id: one card
 * search   text the card must contain (case-insensitive)
 * limit    how many of the newest to return (default 50, at most 1000)
 * base     project root; paths under it are shown relative to it
 */
final readonly class CardQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 1000;

    public function __construct(
        public ?string $project = null,
        public ?float $since = null,
        public ?string $request = null,
        public ?string $card = null,
        public ?string $search = null,
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $base = null,
    ) {}

    public static function fromQueryString(string $queryString, float $now): self
    {
        parse_str($queryString, $query);

        $limit = self::text($query, 'limit');

        return new self(
            project: self::text($query, 'project'),
            since: self::since(self::text($query, 'since'), $now),
            request: self::id(self::text($query, 'request')),
            card: self::id(self::text($query, 'card')),
            search: self::text($query, 'search'),
            limit: $limit !== null && ctype_digit($limit) ? max(1, min(self::MAX_LIMIT, (int) $limit)) : self::DEFAULT_LIMIT,
            base: self::text($query, 'base'),
        );
    }

    /**
     * Unix time, or an age such as 30s, 5m, 2h or 1d before now. Null when it is neither.
     */
    public static function since(?string $value, float $now): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(\d+)(s|m|h|d)$/', $value, $matches) !== 1) {
            return null;
        }

        return $now - (int) $matches[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$matches[2]];
    }

    /**
     * @param list<Card> $cards Oldest first.
     * @return list<Card> The newest that match, oldest first.
     */
    public function cards(array $cards, TextRenderer $renderer): array
    {
        $matching = array_values(array_filter($cards, function (Card $card) use ($renderer): bool {
            if ($this->since !== null && $card->updatedAt <= $this->since) {
                return false;
            }

            if ($this->request !== null && ! str_starts_with(self::compact($card->request['id']), $this->request)) {
                return false;
            }

            if ($this->card !== null && ! str_starts_with(self::compact($card->id), $this->card)) {
                return false;
            }

            return $this->search === null || mb_stripos($renderer->card($card), $this->search) !== false;
        }));

        return array_slice($matching, -$this->limit);
    }

    /**
     * @param list<Mail> $mails Newest first.
     * @return list<Mail> Newest first.
     */
    public function mails(array $mails): array
    {
        return array_slice(array_values(array_filter($mails, fn(Mail $mail): bool => $this->inbox($mail->project, $mail->receivedAt, $mail->requestId)
            && ($this->search === null || mb_stripos($mail->subject . "\n" . implode(', ', $mail->to) . "\n" . ($mail->text ?? $mail->html ?? ''), $this->search) !== false))), 0, $this->limit);
    }

    /**
     * @param list<Sms> $messages Newest first.
     * @return list<Sms> Newest first.
     */
    public function sms(array $messages): array
    {
        return array_slice(array_values(array_filter($messages, fn(Sms $sms): bool => $this->inbox($sms->project, $sms->receivedAt, $sms->requestId)
            && ($this->search === null || mb_stripos($sms->to . "\n" . $sms->body, $this->search) !== false))), 0, $this->limit);
    }

    private function inbox(string $project, float $receivedAt, ?string $requestId): bool
    {
        return ($this->project === null || $project === $this->project)
            && ($this->since === null || $receivedAt > $this->since)
            && ($this->request === null || ($requestId !== null && str_starts_with(self::compact($requestId), $this->request)));
    }

    private static function compact(string $id): string
    {
        return strtolower(str_replace('-', '', $id));
    }

    private static function id(?string $value): ?string
    {
        return $value === null ? null : self::compact($value);
    }

    /**
     * @param array<mixed> $query
     */
    private static function text(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
