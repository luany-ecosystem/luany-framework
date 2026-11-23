<?php

namespace Luany\Framework\Mail\Transport;

use Luany\Framework\Mail\Address;
use Luany\Framework\Mail\MailException;
use Luany\Framework\Mail\Message;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;

/**
 * SMTP delivery. symfony/mailer does the protocol work (STARTTLS, AUTH,
 * MIME, encoded headers); it is an implementation detail — nothing from
 * Symfony appears in Luany's API or exceptions.
 *
 * encryption: 'tls' (STARTTLS, required — default, port 587), 'ssl'
 * (implicit TLS, port 465) or 'none' (local relays and tests only).
 */
final class SmtpTransport implements Transport
{
    private ?EsmtpTransport $smtp = null;

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $username = '',
        #[\SensitiveParameter] private string $password = '',
        private string $encryption = 'tls',
        private float $timeout = 10.0,
    ) {
        if ($host === '') {
            throw new MailException('LUANY-MAIL-005', 'SMTP host is empty: set MAIL_HOST.');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new MailException('LUANY-MAIL-005', "Unknown SMTP encryption [{$encryption}]: use tls, ssl or none.");
        }
    }

    public function send(Message $message): void
    {
        $message->assertSendable();

        try {
            $this->transport()->send($this->toEmail($message));
        } catch (TransportExceptionInterface $e) {
            // Symfony's message may name the user, never the password; the
            // previous exception is not chained so no DSN reaches the logs.
            $reason = $this->password !== '' ? str_replace($this->password, '***', $e->getMessage()) : $e->getMessage();
            throw new MailException('LUANY-MAIL-003', "Could not send the email via SMTP ({$this->host}:{$this->port}): {$reason}");
        }
    }

    private function transport(): EsmtpTransport
    {
        if ($this->smtp === null) {
            $smtp = new EsmtpTransport($this->host, $this->port, $this->encryption === 'ssl');
            if ($this->encryption === 'tls') {
                $smtp->setRequireTls(true);
            } elseif ($this->encryption === 'none') {
                $smtp->setAutoTls(false);
            }
            $stream = $smtp->getStream();
            if (method_exists($stream, 'setTimeout')) {
                $stream->setTimeout($this->timeout);
            }
            if ($this->username !== '') {
                $smtp->setUsername($this->username);
                $smtp->setPassword($this->password);
            }
            $this->smtp = $smtp;
        }

        return $this->smtp;
    }

    private function toEmail(Message $message): Email
    {
        $convert = fn (Address $a): MimeAddress => new MimeAddress($a->email, $a->name);

        $email = (new Email())
            ->from($convert($message->from ?? throw new MailException('LUANY-MAIL-005', 'No sender.')))
            ->subject($message->subject);

        $email->to(...array_map($convert, $message->to));
        $email->cc(...array_map($convert, $message->cc));
        $email->bcc(...array_map($convert, $message->bcc));
        $email->replyTo(...array_map($convert, $message->replyTo));

        if ($message->html !== '') {
            $email->html($message->html, 'utf-8');
        }
        if ($message->text !== '') {
            $email->text($message->text, 'utf-8');
        }
        foreach ($message->attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['name']);
        }

        return $email;
    }
}
