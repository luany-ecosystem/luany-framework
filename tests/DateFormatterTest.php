<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\DateFormatter;
use PHPUnit\Framework\TestCase;

class DateFormatterTest extends TestCase
{
    private function pt(string $locale = 'pt_AO'): DateFormatter
    {
        return new DateFormatter($locale, new \DateTimeZone('Africa/Luanda'));
    }

    public function test_portuguese_styles(): void
    {
        $date = new \DateTimeImmutable('2026-11-23 14:05:00', new \DateTimeZone('Africa/Luanda'));

        $this->assertSame('23/11/2026', $this->pt()->format($date));
        $this->assertSame('23 nov 2026', $this->pt()->format($date, 'medium'));
        $this->assertSame('23 de novembro de 2026', $this->pt()->format($date, 'long'));
        $this->assertSame('segunda-feira, 23 de novembro de 2026', $this->pt()->format($date, 'full'));
        $this->assertSame('14:05', $this->pt()->format($date, 'time'));
        $this->assertSame('23/11/2026 14:05', $this->pt('pt_BR')->format($date, 'datetime'));
        $this->assertSame('1 de março de 2026', $this->pt('pt')->format('2026-03-01', 'long'));
    }

    public function test_english_styles(): void
    {
        $en = new DateFormatter('en', new \DateTimeZone('UTC'));

        $this->assertSame('2026-11-23', $en->format('2026-11-23 10:00'));
        $this->assertSame('November 23, 2026', $en->format('2026-11-23', 'long'));
        $this->assertSame('Monday, November 23, 2026', $en->format('2026-11-23', 'full'));
    }

    public function test_inputs_and_timezone(): void
    {
        $this->assertSame('', $this->pt()->format(null));
        $this->assertSame('', $this->pt()->format(''));
        // 23:30 UTC is already the next day in Luanda (UTC+1).
        $this->assertSame('24/11/2026', $this->pt()->format(new \DateTimeImmutable('2026-11-23 23:30', new \DateTimeZone('UTC'))));
        $this->assertSame('01/01/1970 01:00', $this->pt()->format(0, 'datetime'));
    }

    public function test_bad_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pt()->format('ontem à tarde');
    }

    public function test_unknown_style(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pt()->format('2026-01-01', 'curto');
    }
}
