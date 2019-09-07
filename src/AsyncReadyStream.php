<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\InputStream;
use Amp\Promise;
use Psr\Http\Message\StreamInterface as PsrStream;
use function Amp\call;

final class AsyncReadyStream implements PsrStream
{
    /** @var PsrStream */
    private $decoratedStream;

    /** @var callable */
    private $asyncFactory;

    public function __construct(PsrStream $stream, callable $asyncFactory)
    {
        $this->decoratedStream = $stream;
        $this->asyncFactory = $asyncFactory;
    }

    /**
     * @return Promise<InputStream>
     */
    public function createAsyncStream(): Promise
    {
        if ($this->asyncFactory === null) {
            throw new \RuntimeException('An async stream cannot be created');
        }

        $asyncFactory = $this->asyncFactory;
        $this->asyncFactory = null;
        return call($asyncFactory, $this->decoratedStream);
    }

    public function __toString(): string
    {
        $this->asyncFactory = null;
        return $this->decoratedStream->__toString();
    }

    public function close(): void
    {
        $this->decoratedStream->close();
        $this->asyncFactory = null;
    }

    public function detach()
    {
        $this->asyncFactory = null;
        return $this->decoratedStream->detach();
    }

    public function getSize(): ?int
    {
        return $this->decoratedStream->getSize();
    }

    public function tell(): int
    {
        return $this->decoratedStream->tell();
    }

    public function eof(): bool
    {
        return $this->decoratedStream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->decoratedStream->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->decoratedStream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->decoratedStream->rewind();
    }

    public function isWritable(): bool
    {
        return $this->decoratedStream->isWritable();
    }

    public function write($string): int
    {
        return $this->decoratedStream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->decoratedStream->isReadable();
    }

    public function read($length): string
    {
        return $this->decoratedStream->read($length);
    }

    public function getContents(): string
    {
        return $this->decoratedStream->getContents();
    }

    public function getMetadata($key = null)
    {
        return $this->decoratedStream->getMetadata($key);
    }
}
