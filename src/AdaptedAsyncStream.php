<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\File;
use Amp\File\File as FileStream;
use Amp\Promise;
use Psr\Http\Message\StreamInterface as PsrStream;

final class AdaptedAsyncStream implements PsrStream
{
    /** @var InputStream */
    private $stream;

    /** @var string|null */
    private $buffer;

    /** @var bool */
    private $eof = false;

    public function __construct(InputStream $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Returns the wrapped stream and marks this stream unusable, similar to detach().
     *
     * @return InputStream
     */
    public function extractAsyncStream(): InputStream
    {
        $this->eof = true;
        return $this->stream;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }


    public function close(): void
    {
        $this->eof = true;
        if (\method_exists($this->stream, 'close')) {
            $this->stream->close();
        }
    }

    public function detach() /* : ?resource */
    {
        $this->eof = true;
        if (\method_exists($this->stream, 'getResource')) {
            return $this->stream->getResource();
        }
        return null;
    }

    public function getSize(): ?int
    {
        if (!$this->stream instanceof FileStream) {
            return null;
        }

        try {
            $size = Promise\wait(File\size($this->stream->path()));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('An error occurred while retrieving the file size', 0, $exception);
        }

        return $size;
    }

    public function tell(): int
    {
        if (!$this->stream instanceof FileStream) {
            throw new \RuntimeException('Cannot seek non-file Amp streams');
        }

        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function isSeekable(): bool
    {
        return $this->stream instanceof FileStream && !$this->eof();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->stream instanceof FileStream) {
            throw new \RuntimeException('Cannot seek non-file Amp streams');
        }

        try {
            Promise\wait($this->stream->seek($offset, $whence));
        } catch (\Throwable $exception) {
            $this->eof = true;
            throw new \RuntimeException('An error occurred when seeking on the file stream', 0, $exception);
        }
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Cannot rewind Amp streams');
    }

    public function isWritable(): bool
    {
        return $this->stream instanceof OutputStream && !$this->eof();
    }

    public function write($string): int
    {
        if (!$this->stream instanceof OutputStream) {
            throw new \RuntimeException('The stream is not writable');
        }

        if ($this->eof()) {
            throw new \RuntimeException('The stream has closed and is no longer writable');
        }

        try {
            $length = Promise\wait($this->stream->write($string));
        } catch (\Throwable $exception) {
            $this->eof = true;
            throw new \RuntimeException('An error occurred when writing to the stream', 0, $exception);
        }

        return $length;
    }

    public function isReadable(): bool
    {
        return !$this->eof();
    }

    public function read($length): string
    {
        if ($this->eof()) {
            throw new \RuntimeException('The stream has closed and is no longer readable');
        }

        if ($this->buffer === null) {
            try {
                $result = Promise\wait($this->stream->read());
            } catch (\Throwable $exception) {
                $this->eof = true;
                throw new \RuntimeException('An error occurred while reading from the stream', 0, $exception);
            }

            if ($result === null) {
                $this->eof = true;
            }
        } else {
            $result = $this->buffer;
            $this->buffer = null;
        }

        $result = (string) $result;

        if (\strlen($result) > $length) {
            $this->buffer = \substr($result, $length);
            $result = \substr($result, 0, $length);
        }

        return $result;
    }

    public function getContents(): string
    {
        try {
            return Promise\wait(ByteStream\buffer($this->stream));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('An error occurred while reading from the stream', 0, $exception);
        } finally {
            $this->eof = true;
        }
    }

    public function getMetadata($key = null)
    {
        if (!\method_exists($this->stream, 'getResource')) {
            return null;
        }

        $resource = $this->stream->getResource();
        $data = \stream_get_meta_data($resource);

        if ($key !== null) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
