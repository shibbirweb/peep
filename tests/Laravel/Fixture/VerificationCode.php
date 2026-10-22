<?php

namespace ShibbirWeb\Peep\Tests\Laravel\Fixture;

use Illuminate\Notifications\Notification;

/**
 * An SMS notification on the Vonage channel. The message is a plain object with the
 * same "content" and "from" properties as Laravel's VonageMessage, which needs a package.
 */
final class VerificationCode extends Notification
{
    /**
     * @param mixed $notifiable
     * @return list<string>
     */
    public function via($notifiable)
    {
        return ['vonage'];
    }

    /**
     * @param mixed $notifiable
     * @return object
     */
    public function toVonage($notifiable)
    {
        $message = new \stdClass();
        $message->content = 'Your code is 123456';
        $message->from = 'Shop';

        return $message;
    }
}
