<?php

namespace Luany\Framework\Mail;

use Luany\Framework\Mail\Transport\Transport;

/**
 * Sends emails written as LTE views.
 *
 *   app('mailer')->to($email, $nome)
 *       ->subject('Bem-vindo')
 *       ->send('emails.bem-vindo', ['nome' => $nome, 'url' => $url]);
 *
 * views/emails/bem-vindo.lte is the HTML body; views/emails/bem-vindo_text.lte,
 * when it exists, is the plain-text body (otherwise the text is taken from
 * the HTML). Variables are escaped by LTE as in any view; English or
 * Portuguese directives both work.
 *
 * Transports: log (default — nothing to configure), smtp, array (tests).
 */
class Mailer
{
    /** @var \Closure(string, array<string, mixed>): string */
    private \Closure $render;

    /** @var \Closure(string): bool */
    private \Closure $exists;

    /**
     * @param callable(string, array<string, mixed>): string $render
     * @param callable(string): bool                         $exists
     */
    public function __construct(
        private Transport $transport,
        private ?Address $from = null,
        ?callable $render = null,
        ?callable $exists = null,
    ) {
        $this->render = $render !== null ? \Closure::fromCallable($render)
            : static fn (string $view): string => throw new MailException('LUANY-MAIL-005', "No view renderer to render [{$view}].");
        $this->exists = $exists !== null ? \Closure::fromCallable($exists) : static fn (): bool => false;
    }

    public function to(string $email, string $name = ''): PendingMail
    {
        return (new PendingMail($this))->to($email, $name);
    }

    /** Send a ready Message (the default sender is used when it has none). */
    public function sendMessage(Message $message): void
    {
        if ($message->from === null && $this->from !== null) {
            $message->from = $this->from;
        }
        $message->assertSendable();
        $this->transport->send($message);
    }

    /**
     * Render a view into the message bodies and send it.
     *
     * @param array<string, mixed> $data
     */
    public function sendView(Message $message, string $view, array $data = []): void
    {
        $data += ['assunto' => $message->subject, 'subject' => $message->subject];

        $message->html(($this->render)($view, $data));
        $message->text(($this->exists)($view . '_text')
            ? ($this->render)($view . '_text', $data)
            : self::textFromHtml($message->html));

        $this->sendMessage($message);
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /** Readable plain text from an HTML body (links keep their URL). */
    public static function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', '$3 ($2)', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }
}
