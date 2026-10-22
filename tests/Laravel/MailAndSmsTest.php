<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use ShibbirWeb\Peep\Tests\Laravel\Fixture\VerificationCode;

final class MailAndSmsTest extends LaravelTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // MAIL_MAILER=peep (Laravel 7+) and MAIL_DRIVER=peep (5.5 to 6).
        $app['config']->set('mail.default', 'peep');
        $app['config']->set('mail.driver', 'peep');
    }

    /**
     * @return array<string, mixed>
     */
    protected function peepConfig(): array
    {
        return ['block_real_sms' => true];
    }

    public function testThePeepMailerSendsTheWholeMessageToTheViewer(): void
    {
        Mail::raw('Your invoice is ready.', function ($message) {
            $message->to('ada@example.com')->subject('Invoice');
        });

        $posts = $this->postsTo('/_peep/mail');
        $this->assertCount(1, $posts);

        // PHPUnit 6 (Laravel 5.5) has no assertIsString() or assertStringContainsString().
        $raw = (string) base64_decode((string) $posts[0]['raw_base64'], true);
        $this->assertNotSame('', $raw);
        $this->assertTrue(strpos($raw, 'Subject: Invoice') !== false);
        $this->assertTrue(strpos($raw, 'ada@example.com') !== false);
        $this->assertTrue(strpos($raw, 'X-Peep-Project: Peep Laravel Tests') !== false, 'Tagged by the mail watcher.');
        $this->assertTrue(strpos($raw, 'X-Peep-Request-Id: ') !== false);
    }

    public function testSmsNotificationsGoToTheViewerAndTheRealSendIsBlocked(): void
    {
        Notification::route('vonage', '+15550001')->notify(new VerificationCode());

        $posts = $this->postsTo('/_peep/sms');
        $this->assertCount(1, $posts);
        $this->assertSame('+15550001', $posts[0]['to']);
        $this->assertSame('Your code is 123456', $posts[0]['body']);
        $this->assertSame('Shop', $posts[0]['from']);
        $this->assertSame('laravel', $posts[0]['source']);
        $this->assertSame('vonage', $posts[0]['channel']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postsTo(string $path): array
    {
        $bodies = [];
        foreach ($this->transport->requests as $request) {
            if ($request['path'] === $path) {
                $bodies[] = $request['body'];
            }
        }

        return $bodies;
    }
}
