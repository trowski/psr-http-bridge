<?php

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\PendingReadError;
use Amp\File;
use Amp\File\File as FileStream;
use Amp\LazyPromise;
use Amp\Promise;
use function Amp\call;

final class DelayedOpenFileStream implements InputStream, OutputStream
{
    /** @var Promise<File\File>|null */
    private $promise;

    public function __construct(string $filename, string $mode = 'r')
    {
        $this->promise = new LazyPromise(static function () use ($filename, $mode): Promise {
            return File\open($filename, $mode);
        });
    }

    /**
     * Reads data from the stream.
     *
     * @param int $length
     *
     * @return Promise Resolves with a string when new data is available or `null` if the stream has closed.
     *
     * @throws PendingReadError Thrown if another read operation is still pending.
     */
    public function read(int $length = File\File::DEFAULT_READ_LENGTH): Promise
    {
        return call(function () use ($length) {
            $file = yield $this->promise;
            \assert($file instanceof FileStream);

            return yield $file->read($length);
        });
    }

    public function write(string $data): Promise
    {
        return call(function () use ($data) {
            $file = yield $this->promise;
            \assert($file instanceof FileStream);

            return yield $file->write($data);
        });
    }

    public function end(string $data = ''): Promise
    {
        return call(function () use ($data) {
            $file = yield $this->promise;
            \assert($file instanceof FileStream);

            return yield $file->end($data);
        });
    }
}
