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

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close(): void
    {
        $this->buffer = null;
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach(): void
    {
        $this->buffer = null;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize(): int
    {
        return \strlen($this->buffer);
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof(): bool
    {
        return $this->position >= \strlen($this->buffer);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable(): bool
    {
        return $this->isReadable();
    }

    /**
     * Seek to a position in the stream.
     *
     * @link http://www.php.net/manual/en/function.fseek.php
     *
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *                    based on the seek offset. Valid values are identical to the built-in
     *                    PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *                    offset bytes SEEK_CUR: Set position to current location plus offset
     *                    SEEK_END: Set position to end-of-stream plus offset.
     *
     * @throws \RuntimeException on failure.
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
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

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @throws \RuntimeException on failure.
     * @link http://www.php.net/manual/en/function.fseek.php
     * @see  seek()
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     *
     * @return int Returns the number of bytes written to the stream.
     * @throws \RuntimeException on failure.
     */
    public function write($string): int
    {
        throw new \RuntimeException("The stream is not writable");
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable(): bool
    {
        return $this->buffer !== null;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if underlying stream
     *                    call returns fewer bytes.
     *
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read($length): string
    {
        $result = (string) \substr($this->buffer, $this->position, $length);
        $this->position = \min(\strlen($this->buffer), $this->position + $length);
        return $result;
    }

    /**
     * Returns the remaining contents in a string.
     *
     * @return string
     * @throws \RuntimeException if unable to read or an error occurs while
     *     reading.
     */
    public function getContents(): string
    {
        $result = (string) \substr($this->buffer, $this->position);
        $this->position = \strlen($this->buffer);
        return $result;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     *
     * @param string $key Specific metadata to retrieve.
     *
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        return null;
    }
}
