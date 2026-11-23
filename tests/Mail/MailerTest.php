<?php

namespace Luany\Framework\Tests\Mail;

use Luany\Framework\Mail\Address;
use Luany\Framework\Mail\MailException;
use Luany\Framework\Mail\Mailer;
use Luany\Framework\Mail\Message;
use Luany\Framework\Mail\Transport\ArrayTransport;
use Luany\Framework\Mail\Transport\LogTransport;
use Luany\Framework\Mail\Transport\SmtpTransport;
use Luany\Lte\Engine;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class MailerTest extends TestCase
{
    private string $dir;
    private ArrayTransport $transport;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/luany-mail-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/views/emails', 0777, true);
        $this->transport = new ArrayTransport();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function mailer(string $syntax = 'en', ?Address $from = null): Mailer
    {
        $engine = (new Engine($this->dir . '/views', $this->dir . '/cache'))->setSyntax($syntax);

        return new Mailer(
            $this->transport,
            $from ?? new Address('ola@luany.dev', 'Luany'),
            fn (string $view, array $data): string => $engine->render($view, $data),
            fn (string $view): bool => $engine->findView($view) !== null,
        );
    }

    private function last(): Message
    {
        $this->assertNotEmpty($this->transport->messages);

        return $this->transport->messages[array_key_last($this->transport->messages)];
    }

    public function test_sends_a_view_with_escaped_data_and_a_text_alternative(): void
    {
        file_put_contents($this->dir . '/views/emails/welcome.lte', "<h1>Hello {{ \$name }}</h1>\n<p><a href=\"{{ \$url }}\">Confirm</a></p>");

        $this->mailer()->to('ana@example.com', 'Ana')->subject('Welcome')->send('emails.welcome', [
            'name' => '<script>x</script>',
            'url'  => 'https://luany.dev/c?t=1',
        ]);

        $m = $this->last();
        $this->assertSame('Welcome', $m->subject);
        $this->assertSame('Ana <ana@example.com>', (string) $m->to[0]);
        $this->assertSame('Luany <ola@luany.dev>', (string) $m->from);
        $this->assertStringContainsString('&lt;script&gt;', $m->html);
        $this->assertStringContainsString('Confirm (https://luany.dev/c?t=1)', $m->text);
        $this->assertStringNotContainsString('<h1>', $m->text, 'template tags removed; data stays literal in plain text');
    }

    public function test_text_view_wins_and_portuguese_directives_work(): void
    {
        file_put_contents($this->dir . '/views/emails/boas-vindas.lte', "@se(\$nome)<p>Olá {{ \$nome }}</p>@fimse");
        file_put_contents($this->dir . '/views/emails/boas-vindas_text.lte', "Olá {{ \$nome }} — {{ \$assunto }}");

        $this->mailer('pt')->to('ana@example.com')->subject('Bem-vinda')->send('emails.boas-vindas', ['nome' => 'Ana']);

        $this->assertSame('<p>Olá Ana</p>', trim($this->last()->html));
        $this->assertSame('Olá Ana — Bem-vinda', trim($this->last()->text));
    }

    public function test_text_fallback_keeps_links_and_drops_style_and_script(): void
    {
        $text = Mailer::textFromHtml(
            "<html><head><title>T</title><style>p{color:red}</style></head><body>"
            . "<script>alert(1)</script><p>Redefina a palavra-passe:</p>"
            . "<p><a class=\"btn\" href=\"https://app.test/reset?token=abc&amp;email=a%40b.co\">Redefinir</a></p></body></html>"
        );

        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringNotContainsString('alert(1)', $text);
        $this->assertStringContainsString('Redefinir (https://app.test/reset?token=abc&email=a%40b.co)', $text);
    }

    /** @return array<string, array{\Closure(): mixed, string}> */
    public static function invalid(): array
    {
        return [
            'bad address'            => [fn () => new Address('not-an-email'), 'LUANY-MAIL-001'],
            'CRLF in address'        => [fn () => new Address("a@b.co\r\nBcc: x@y.z"), 'LUANY-MAIL-002'],
            'CRLF in name'           => [fn () => new Address('a@b.co', "Ana\nBcc: x@y.z"), 'LUANY-MAIL-002'],
            'CRLF in subject'        => [fn () => (new Message())->subject("Oi\r\nBcc: x@y.z"), 'LUANY-MAIL-002'],
            'missing attachment'     => [fn () => (new Message())->attach('/nope/file.pdf'), 'LUANY-MAIL-006'],
            'unknown encryption'     => [fn () => new SmtpTransport('smtp.example.com', 587, '', '', 'starttls'), 'LUANY-MAIL-005'],
            'empty SMTP host'        => [fn () => new SmtpTransport(''), 'LUANY-MAIL-005'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function test_invalid_input_has_stable_codes(\Closure $action, string $code): void
    {
        try {
            $action();
            $this->fail('no exception');
        } catch (MailException $e) {
            $this->assertSame($code, $e->luanyCode);
        }
    }

    public function test_unsendable_messages(): void
    {
        file_put_contents($this->dir . '/views/emails/x.lte', 'x');

        foreach ([
            fn () => $this->mailer()->to('a@example.com')->send('emails.x'),                             // no subject
            fn () => (new Mailer($this->transport))->to('a@example.com')->subject('s')->sendText('t'),   // no sender
        ] as $i => $action) {
            try {
                $action();
                $this->fail("case {$i}: no exception");
            } catch (MailException $e) {
                $this->assertContains($e->luanyCode, ['LUANY-MAIL-004', 'LUANY-MAIL-005'], (string) $i);
            }
        }
        $this->assertSame([], $this->transport->messages);
    }

    public function test_log_transport_records_the_email(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        $message = (new Message())->from('ola@luany.dev')->to('ana@example.com')->subject('Teste')->text('Link: https://x');
        (new LogTransport($logger))->send($message);

        $this->assertSame('Email "Teste" to ana@example.com', $logger->records[0][0]);
        $this->assertSame('Link: https://x', $logger->records[0][1]['text']);
    }

    public function test_smtp_failure_never_shows_the_password(): void
    {
        $transport = new SmtpTransport('127.0.0.1', 1, 'user', 'S3cr3t-p4ss', 'none', 2.0);
        $message = (new Message())->from('ola@luany.dev')->to('ana@example.com')->subject('x')->text('x');

        try {
            $transport->send($message);
            $this->fail('no exception');
        } catch (MailException $e) {
            $this->assertSame('LUANY-MAIL-003', $e->luanyCode);
            $this->assertStringNotContainsString('S3cr3t-p4ss', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_smtp_delivers_a_mime_message(): void
    {
        $portFile = $this->dir . '/port';
        $outFile  = $this->dir . '/out';
        $server   = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/smtp-server.php', $portFile, $outFile], [], $pipes);
        $this->assertIsResource($server);

        for ($i = 0; $i < 50 && !is_file($portFile); $i++) {
            usleep(100_000);
        }
        $port = (int) file_get_contents($portFile);

        file_put_contents($this->dir . '/views/emails/ola.lte', '<p>Olá {{ $nome }}</p>');
        $engine = new Engine($this->dir . '/views', $this->dir . '/cache');
        $mailer = new Mailer(
            new SmtpTransport('127.0.0.1', $port, 'ana', 'segredo', 'none', 5.0),
            new Address('ola@luany.dev', 'Luany'),
            fn (string $v, array $d): string => $engine->render($v, $d),
            fn (string $v): bool => $engine->findView($v) !== null,
        );
        $mailer->to('ana@example.com', 'Ana Gola')->subject('Olá, ção ✓')->send('emails.ola', ['nome' => 'Ana']);

        unset($mailer); // the transport sends QUIT when it is destroyed
        proc_close($server);
        $sent = (string) file_get_contents($outFile);

        $this->assertStringContainsString('AUTH', $sent);
        $this->assertStringContainsString('To: Ana Gola <ana@example.com>', $sent);
        $this->assertMatchesRegularExpression('/^Subject: =\?utf-8\?/mi', $sent, 'UTF-8 subject is encoded');
        $this->assertStringContainsString('multipart/alternative', $sent);
        $this->assertStringContainsString('text/html', $sent);
    }
}
