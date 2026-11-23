<?php

namespace Luany\Framework\Mail\Transport;

use Luany\Framework\Mail\Message;
use Psr\Log\LoggerInterface;

/**
 * Writes emails to the log instead of sending them (the default in
 * development: nothing to configure, links are readable in storage/logs).
 */
final class LogTransport implements Transport
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function send(Message $message): void
    {
        $list = fn (array $addresses): string => implode(', ', array_map('strval', $addresses));

        $this->logger->info("Email \"{$message->subject}\" to " . $list([...$message->to, ...$message->cc, ...$message->bcc]), [
            'from'        => (string) $message->from,
            'to'          => $list($message->to),
            'cc'          => $list($message->cc),
            'bcc'         => $list($message->bcc),
            'subject'     => $message->subject,
            'text'        => $message->text,
            'attachments' => array_column($message->attachments, 'name'),
        ]);
    }
}
