<?php

namespace Luany\Framework\Support;

/**
 * Dates in the application's locale.
 *
 *   format_date($post->created_at);              // pt: 23/11/2026      en: 2026-11-23
 *   format_date($post->created_at, 'long');      // pt: 23 de novembro de 2026
 *   format_date($post->created_at, 'datetime');  // pt: 23/11/2026 14:05
 *
 * Styles: short, medium, long, full, time, datetime. Portuguese and English
 * use built-in tables, so the result is the same on every server; other
 * locales use ext-intl when it is installed, otherwise English.
 */
final class DateFormatter
{
    public const STYLES = ['short', 'medium', 'long', 'full', 'time', 'datetime'];

    private const MONTHS_PT = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const DAYS_PT   = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    public function __construct(private string $locale = 'en', private ?\DateTimeZone $timezone = null)
    {
    }

    /** '' for null / '' input; throws on an unknown style or an unreadable date. */
    public function format(\DateTimeInterface|string|int|null $value, string $style = 'short'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException("Unknown date style [{$style}]: use " . implode(', ', self::STYLES) . '.');
        }

        $date = $this->toDate($value);
        $language = strtolower((string) preg_split('/[_-]/', $this->locale)[0]);

        return match ($language) {
            'pt'    => $this->portuguese($date, $style),
            'en'    => $this->english($date, $style),
            default => $this->intl($date, $style) ?? $this->english($date, $style),
        };
    }

    private function portuguese(\DateTimeImmutable $d, string $style): string
    {
        $month = self::MONTHS_PT[(int) $d->format('n') - 1];

        return match ($style) {
            'short'    => $d->format('d/m/Y'),
            'medium'   => $d->format('j') . ' ' . mb_substr($month, 0, 3) . ' ' . $d->format('Y'),
            'long'     => $d->format('j') . " de {$month} de " . $d->format('Y'),
            'full'     => self::DAYS_PT[(int) $d->format('w')] . ', ' . $d->format('j') . " de {$month} de " . $d->format('Y'),
            'time'     => $d->format('H:i'),
            default    => $d->format('d/m/Y H:i'),
        };
    }

    private function english(\DateTimeImmutable $d, string $style): string
    {
        return $d->format(match ($style) {
            'short'    => 'Y-m-d',
            'medium'   => 'M j, Y',
            'long'     => 'F j, Y',
            'full'     => 'l, F j, Y',
            'time'     => 'H:i',
            default    => 'Y-m-d H:i',
        });
    }

    private function intl(\DateTimeImmutable $d, string $style): ?string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }
        [$date, $time] = match ($style) {
            'short'    => [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE],
            'medium'   => [\IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE],
            'long'     => [\IntlDateFormatter::LONG, \IntlDateFormatter::NONE],
            'full'     => [\IntlDateFormatter::FULL, \IntlDateFormatter::NONE],
            'time'     => [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT],
            default    => [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT],
        };
        $result = (new \IntlDateFormatter($this->locale, $date, $time, $d->getTimezone()))->format($d);

        return is_string($result) ? $result : null;
    }

    private function toDate(\DateTimeInterface|string|int $value): \DateTimeImmutable
    {
        $timezone = $this->timezone ?? new \DateTimeZone(date_default_timezone_get());

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }
        if (is_int($value)) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone($timezone);
        }

        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unreadable date [{$value}].");
        }
    }
}
