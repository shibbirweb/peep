<?php

namespace ShibbirWeb\Peep\Laravel\Mail;

use Illuminate\Mail\Transport\Transport;
use ShibbirWeb\Peep\PeepFactory;
use Swift_Mime_SimpleMessage;

/**
 * The "peep" mail transport for Laravel 5.5 to 8 (SwiftMailer).
 */
final class PeepSwiftTransport extends Transport
{
    /**
     * @var PeepFactory
     */
    private $factory;

    public function __construct(PeepFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param string[]|null $failedRecipients
     * @return int
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        MailCatcher::send($this->factory, $message->toString());

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }
}
