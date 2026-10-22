<?php

namespace ShibbirWeb\Peep\Laravel\Mail;

use ShibbirWeb\Peep\PeepFactory;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The "peep" mail transport for Laravel 9 and newer (Symfony Mailer).
 */
final class PeepSymfonyTransport extends AbstractTransport
{
    /**
     * @var PeepFactory
     */
    private $factory;

    public function __construct(PeepFactory $factory)
    {
        parent::__construct();

        $this->factory = $factory;
    }

    protected function doSend(SentMessage $message): void
    {
        MailCatcher::send($this->factory, $message->toString());
    }

    public function __toString(): string
    {
        return 'peep';
    }
}
