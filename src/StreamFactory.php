<?php

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\ResourceInputStream;
use Psr\Http\Message\StreamFactoryInterface as PsrStreamFactory;
use Psr\Http\Message\StreamInterface as PsrStream;

final class StreamFactory implements PsrStreamFactory
{
    public function createStream(string $content = ''): PsrStream
    {
        return new AdaptedAsyncStream(new InMemoryStream($content));
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): PsrStream
    {
        return new AdaptedAsyncStream(new DelayedOpenFileStream($filename, $mode));
    }

    public function createStreamFromResource($resource): PsrStream
    {
        return new AdaptedAsyncStream(new ResourceInputStream($resource));
    }
}
