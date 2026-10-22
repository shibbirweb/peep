<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Mail;

use ShibbirWeb\PeepServer\Event\ServerEvent;

/**
 * The mail inbox, in memory. The oldest mail goes first when it holds too many
 * messages or too many bytes.
 */
final class MailStore
{
    /**
     * @var array<string, Mail> id => mail, oldest first
     */
    private array $mails = [];

    private int $bytes = 0;

    /**
     * @param positive-int $maxMails
     * @param positive-int $maxBytes
     */
    public function __construct(
        private readonly int $maxMails = 1000,
        private readonly int $maxBytes = 256 * 1024 * 1024,
    ) {}

    /**
     * @return list<ServerEvent>
     */
    public function add(Mail $mail): array
    {
        $this->mails[$mail->id] = $mail;
        $this->bytes += $mail->size();

        $events = [];
        while (count($this->mails) > 1 && (count($this->mails) > $this->maxMails || $this->bytes > $this->maxBytes)) {
            $oldest = array_shift($this->mails);
            $this->bytes -= $oldest->size();
            $events[] = new ServerEvent('mail.removed', ['id' => $oldest->id]);
        }

        $events[] = new ServerEvent('mail.received', $mail->summary());

        return $events;
    }

    public function find(string $id): ?Mail
    {
        return $this->mails[$id] ?? null;
    }

    /**
     * @return list<ServerEvent>
     */
    public function markRead(string $id, bool $read = true): array
    {
        $mail = $this->mails[$id] ?? null;
        if ($mail === null || $mail->read === $read) {
            return [];
        }

        $mail->read = $read;

        return [new ServerEvent('mail.updated', ['id' => $id, 'read' => $read])];
    }

    /**
     * @return list<ServerEvent>
     */
    public function delete(string $id): array
    {
        $mail = $this->mails[$id] ?? null;
        if ($mail === null) {
            return [];
        }

        unset($this->mails[$id]);
        $this->bytes -= $mail->size();

        return [new ServerEvent('mail.removed', ['id' => $id])];
    }

    /**
     * @return list<ServerEvent>
     */
    public function clear(?string $project = null): array
    {
        foreach ($this->mails as $id => $mail) {
            if ($project === null || $mail->project === $project) {
                unset($this->mails[$id]);
                $this->bytes -= $mail->size();
            }
        }

        return [new ServerEvent('mail.cleared', ['project' => $project])];
    }

    /**
     * Newest first.
     *
     * @return list<Mail>
     */
    public function all(): array
    {
        return array_reverse(array_values($this->mails));
    }
}
