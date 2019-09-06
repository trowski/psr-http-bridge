<?php

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\File;
use Amp\Promise;
use Psr\Http\Message\StreamFactoryInterface as PsrStreamFactory;
use Psr\Http\Message\StreamInterface as PsrStream;

final class StreamFactory implements PsrStreamFactory
{
    /** @var PsrStreamFactory */
    private $factory;

    /**
     * @param PsrStreamFactory $factory Factory used to create streams to be wrapped.
     */
    public function __construct(PsrStreamFactory $factory)
    {
        $this->factory = $factory;
    }

    public function createStream(string $content = ''): PsrStream
    {
        return new AsyncReadyStream(
            $this->factory->createStream($content),
            static function () use ($content): InputStream {
                return new InMemoryStream($content);
            }
        );
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): PsrStream
    {
        return new AsyncReadyStream(
            $this->factory->createStreamFromFile($filename, $mode),
            static function () use ($filename, $mode): Promise {
                return File\open($filename, $mode);
            }
        );
    }

    public function createStreamFromResource($resource): PsrStream
    {
        return new AsyncReadyStream(
            $this->factory->createStreamFromFile($resource),
            static function () use ($resource): InputStream {
                return new ResourceInputStream($resource);
            }
        );
    }
}
