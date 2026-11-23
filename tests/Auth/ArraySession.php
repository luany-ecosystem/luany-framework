<?php

namespace Luany\Framework\Tests\Auth;

use Luany\Framework\Contracts\SessionInterface;

class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    public int $regenerated = 0;

    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function has(string $key): bool { return isset($this->data[$key]); }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function flash(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function regenerate(): void { $this->regenerated++; }
    public function save(): void {}
    public function getId(): string { return 'test'; }
    public function all(): array { return $this->data; }
    public function destroy(): void { $this->data = []; }
}
