<?php

namespace ShibbirWeb\Peep\Tests\Unit\Api;

final class SmsTest extends ApiTestCase
{
    public function testSmsGoesToTheViewersSmsInboxWithProjectAndRequest(): void
    {
        $this->peep()->sms('+15550001', 'Your code is 123456', 'Shop');

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $this->assertSame('/_peep/sms', $request['path']);
        $this->assertSame('+15550001', $request['body']['to']);
        $this->assertSame('Your code is 123456', $request['body']['body']);
        $this->assertSame('Shop', $request['body']['from']);
        $this->assertSame('api', $request['body']['project']);
        $this->assertIsString($request['body']['request_id']);
    }

    public function testNothingIsSentWhenDisabled(): void
    {
        $this->peep()->disable();
        $this->peep()->sms('+15550001', 'Hi');

        $this->assertCount(0, $this->transport->requests);
    }
}
