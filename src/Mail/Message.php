<?php

namespace Luany\Framework\Mail;

/**
 * An email: recipients, subject, HTML and plain-text bodies, attachments.
 * Every address and header value is validated as it is added.
 */
final class Message
{
    /** @var list<Address> */
    public array $to = [];
    /** @var list<Address> */
    public array $cc = [];
    /** @var list<Address> */
    public array $bcc = [];
    /** @var list<Address> */
    public array $replyTo = [];

    public ?Address $from = null;
    public string $subject = '';
    public string $html = '';
    public string $text = '';

    /** @var list<array{path: string, name: string}> */
    public array $attachments = [];

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);
        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);
        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);
        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo[] = new Address($email, $name);
        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);
        return $this;
    }

    public function subject(string $subject): self
    {
        Address::assertHeaderSafe($subject, 'subject');
        $this->subject = trim($subject);
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function attach(string $path, string $name = ''): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MailException('LUANY-MAIL-006', 'Attachment not found or not readable: ' . basename($path) . '.');
        }
        Address::assertHeaderSafe($name, 'attachment name');
        $this->attachments[] = ['path' => $path, 'name' => $name !== '' ? $name : basename($path)];
        return $this;
    }

    /** Throws when the message cannot be sent as it is. */
    public function assertSendable(): void
    {
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new MailException('LUANY-MAIL-004', 'The email has no recipient.');
        }
        if ($this->subject === '') {
            throw new MailException('LUANY-MAIL-004', 'The email has no subject.');
        }
        if ($this->from === null) {
            throw new MailException('LUANY-MAIL-005', 'No sender: set MAIL_FROM_ADDRESS (config/mail.php → from).');
        }
        if ($this->html === '' && $this->text === '') {
            throw new MailException('LUANY-MAIL-004', 'The email has no body.');
        }
    }
}
