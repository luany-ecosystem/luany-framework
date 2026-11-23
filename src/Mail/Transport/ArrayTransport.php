<?php

namespace Luany\Framework\Mail\Transport;

use Luany\Framework\Mail\Message;

/**
 * Keeps sent messages in memory — for tests:
 *   $this->assertCount(1, app('mailer')->transport()->messages);
 */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    public array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
