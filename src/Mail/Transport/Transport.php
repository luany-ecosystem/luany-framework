<?php

namespace Luany\Framework\Mail\Transport;

use Luany\Framework\Mail\Message;

/**
 * Delivers a Message. Implementations: LogTransport (default), SmtpTransport,
 * ArrayTransport (tests).
 */
interface Transport
{
    /** @throws \Luany\Framework\Mail\MailException */
    public function send(Message $message): void;
}
