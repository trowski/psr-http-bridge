<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\File;
use Amp\File\File as FileStream;
use Psr\Http\Message\StreamFactoryInterface as PsrStreamFactory;
use Psr\Http\Message\StreamInterface as PsrStream;

final class AsyncReadyStreamFactory implements PsrStreamFactory
{
    private const CHUNK_SIZE = 8192;

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
            static function (PsrStream $stream): InputStream {
                return new InMemoryStream($stream->getContents());
            }
        );
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): PsrStream
    {
        return new AsyncReadyStream(
            $this->factory->createStreamFromFile($filename, $mode),
            static function (PsrStream $stream) use ($filename, $mode): \Generator {
                $position = $stream->tell();

                $stream->close();

                /** @var FileStream $file */
                $file = yield File\open($filename, $mode);

                if ($position) {
                    yield $file->seek($position);
                }

                return $file;
            }
        );
    }

    public function createStreamFromResource($resource): PsrStream
    {
        return new AsyncReadyStream(
            $this->factory->createStreamFromResource($resource),
            static function (PsrStream $stream): \Generator {
                $type = \strtolower((string) $stream->getMetadata('stream_type'));

                if ($type === 'stdio') {
                    $type = \strtolower((string) $stream->getMetadata('wrapper_type'));
                }

                switch ($type) {
                    case 'temp':
                    case 'memory':
                        return new InMemoryStream($stream->getContents());

                    case 'plainfile':
                        $filename = (string) $stream->getMetadata('uri');
                        $mode = (string) $stream->getMetadata('mode');

                        $position = $stream->tell();

                        $stream->close();

                        /** @var FileStream $file */
                        $file = yield File\open($filename, $mode);

                        if ($position) {
                            yield $file->seek($position);
                        }

                        return $file;

                    default:
                        return new ResourceInputStream($stream->detach());
                }
            }
        );
    }
}
