<?php

namespace App\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Adapter PSR-7 minimal yang membaca byte sumber secara berurutan lalu
 * berhenti setelah $length byte. Dipakai untuk memotong stream Google Drive
 * (alt=media, tanpa Range) menjadi potongan 206 tanpa menulis logika
 * offset/limit ke StreamPump.
 */
final class ClosureStream implements StreamInterface
{
    private int $position = 0;

    private bool $eof = false;

    /**
     * @param  \Closure(): (?string)  $read  pembaca chunk (null/'' = habis)
     */
    public function __construct(
        private readonly \Closure $read,
        private readonly int $length,
    ) {}

    public function read(int $length): string
    {
        if ($this->eof) {
            return '';
        }

        $remaining = $this->length - $this->position;
        if ($remaining <= 0) {
            $this->eof = true;

            return '';
        }

        $chunk = ($this->read)(min($length, $remaining));
        if ($chunk === null || $chunk === '') {
            $this->eof = true;

            return '';
        }

        $this->position += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->eof || $this->position >= $this->length;
    }

    public function getContents(): string
    {
        $out = '';
        while (! $this->eof()) {
            $out .= $this->read(StreamPump::CHUNK_BYTES);
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->eof = true;
    }

    public function detach()
    {
        $this->eof = true;

        return null;
    }

    public function getSize(): ?int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('ClosureStream tidak bisa di-seek.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('ClosureStream tidak bisa di-rewind.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('ClosureStream tidak bisa ditulis.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
