<?php

namespace Luany\Framework\Mail;

/**
 * A validated email address with an optional display name.
 */
final class Address
{
    public readonly string $email;
    public readonly string $name;

    public function __construct(string $email, string $name = '')
    {
        self::assertHeaderSafe($email, 'address');
        self::assertHeaderSafe($name, 'name');

        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('LUANY-MAIL-001', "Invalid email address [{$email}].");
        }

        $this->email = $email;
        $this->name  = trim($name);
    }

    /** No CR/LF (or NUL) in anything that becomes a header. */
    public static function assertHeaderSafe(string $value, string $what): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new MailException('LUANY-MAIL-002', "Line breaks are not allowed in an email {$what}.");
        }
    }

    public function __toString(): string
    {
        return $this->name === '' ? $this->email : "{$this->name} <{$this->email}>";
    }
}
