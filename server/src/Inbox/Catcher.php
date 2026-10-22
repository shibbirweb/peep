<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Inbox;

use Closure;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Mail\MailStore;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Sms\SmsStore;

/**
 * Where caught mail and SMS go, however they arrived: into the inbox, to the UI, and
 * to whoever listens (the webhook simulator).
 */
final class Catcher
{
    /**
     * @var list<Closure(Mail): void>
     */
    private array $mailListeners = [];

    /**
     * @var list<Closure(Sms): void>
     */
    private array $smsListeners = [];

    public function __construct(
        public readonly MailStore $mails,
        public readonly SmsStore $sms,
        private readonly EventHub $hub,
    ) {}

    /**
     * @param Closure(Mail): void $listener
     */
    public function onMail(Closure $listener): void
    {
        $this->mailListeners[] = $listener;
    }

    /**
     * @param Closure(Sms): void $listener
     */
    public function onSms(Closure $listener): void
    {
        $this->smsListeners[] = $listener;
    }

    public function mail(Mail $mail): void
    {
        $this->hub->publish($this->mails->add($mail));

        foreach ($this->mailListeners as $listener) {
            $listener($mail);
        }
    }

    public function sms(Sms $sms): void
    {
        $this->hub->publish($this->sms->add($sms));

        foreach ($this->smsListeners as $listener) {
            $listener($sms);
        }
    }
}
