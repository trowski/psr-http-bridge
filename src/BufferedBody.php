<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Psr\Http\Message\StreamInterface as PsrStream;

final class BufferedBody implements PsrStream
{
    /** @var string */
    private $buffer;

    /** @var int */
    private $position = 0;

    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->buffer = null;
    }

    public function detach()
    {
        $this->buffer = null;
        return null;
    }

    public function getSize(): ?int
    {
        return $this->buffer !== null ? \strlen($this->buffer) : null;
    }

    public function tell(): int
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('The stream is no longer seekable');
        }

        return $this->position;
    }

    public function eof(): bool
    {
        return $this->buffer === null ||  $this->position >= \strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return $this->isReadable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('The stream is no longer seekable');
        }

        $length = \strlen($this->buffer);

        switch ($whence) {
            case \SEEK_SET:
                $this->position = \min($offset, $length);
                break;

            case \SEEK_CUR:
                $this->position = \min($this->position + $offset, $length);
                break;

            case \SEEK_END:
                $this->position = \min($length + $offset, $length);
                break;

            default:
                throw new \RuntimeException('Invalid value for $whence');
        }

        $this->position = \max(0, $this->position);
    }

    public function rewind(): void
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('The stream is no longer seekable');
        }

        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException("The stream is not writable");
    }

    public function isReadable(): bool
    {
        return $this->buffer !== null;
    }

    public function read($length): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException("The stream is no longer readable");
        }

        $result = (string) \substr($this->buffer, $this->position, $length);
        $this->position = \min(\strlen($this->buffer), $this->position + $length);
        return $result;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException("The stream is no longer readable");
        }

        $result = (string) \substr($this->buffer, $this->position);
        $this->position = \strlen($this->buffer);
        return $result;
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}
