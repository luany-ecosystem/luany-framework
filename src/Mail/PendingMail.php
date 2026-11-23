<?php

namespace Luany\Framework\Mail;

/**
 * Fluent builder returned by app('mailer')->to(...).
 */
final class PendingMail
{
    private Message $message;

    public function __construct(private Mailer $mailer)
    {
        $this->message = new Message();
    }

    public function to(string $email, string $name = ''): self
    {
        $this->message->to($email, $name);
        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->message->cc($email, $name);
        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->message->bcc($email, $name);
        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->message->replyTo($email, $name);
        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        $this->message->from($email, $name);
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->message->subject($subject);
        return $this;
    }

    public function attach(string $path, string $name = ''): self
    {
        $this->message->attach($path, $name);
        return $this;
    }

    /**
     * Render views/{view}.lte (and {view}_text.lte when present) and send.
     *
     * @param array<string, mixed> $data
     */
    public function send(string $view, array $data = []): void
    {
        $this->mailer->sendView($this->message, $view, $data);
    }

    /** Send without a view. */
    public function sendText(string $text, string $html = ''): void
    {
        $this->message->text($text)->html($html);
        $this->mailer->sendMessage($this->message);
    }
}
