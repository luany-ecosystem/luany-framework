<?php

namespace Luany\Framework\Mail;

/**
 * Email failures, with a stable code (LUANY-MAIL-NNN):
 *   001 invalid address · 002 header injection (line break) · 003 transport
 *   failure · 004 missing subject or recipient · 005 configuration · 006 attachment
 *
 * Messages never contain passwords or DSNs.
 */
final class MailException extends \RuntimeException
{
    public function __construct(public readonly string $luanyCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct("[{$luanyCode}] {$message}", 0, $previous);
    }
}
