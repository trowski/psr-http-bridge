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
    /** @var PsrStreamFactory */
    private $decoratedFactory;

    /**
     * @param PsrStreamFactory $factory Factory used to create decorated streams.
     */
    public function __construct(PsrStreamFactory $factory)
    {
        $this->decoratedFactory = $factory;
    }

    public function createStream(string $content = ''): PsrStream
    {
        return new AsyncReadyStream(
            $this->decoratedFactory->createStream($content),
            static function (PsrStream $stream): InputStream {
                $content = $stream->getContents();
                $stream->detach();
                return new InMemoryStream($content);
            }
        );
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): PsrStream
    {
        return new AsyncReadyStream(
            $this->decoratedFactory->createStreamFromFile($filename, $mode),
            static function (PsrStream $stream) use ($filename, $mode): \Generator {
                $resource = $stream->detach();

                if (!\is_resource($resource)) {
                    throw new \RuntimeException('Stream resource in unusable state');
                }

                $position = \ftell($resource);
                \fclose($resource);

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
            $this->decoratedFactory->createStreamFromResource($resource),
            static function (PsrStream $stream): \Generator {
                $resource = $stream->detach();

                if (!\is_resource($resource)) {
                    throw new \RuntimeException('Stream resource in unusable state');
                }

                $metadata = \stream_get_meta_data($resource);

                $type = \strtolower($metadata['stream_type']);

                if ($type === 'stdio') {
                    $type = \strtolower($metadata['wrapper_type']);
                }

                switch ($type) {
                    case 'temp':
                    case 'memory':
                        $content = \stream_get_contents($resource);
                        \fclose($resource);
                        return new InMemoryStream($content);

                    case 'plainfile':
                        $filename = $metadata['uri'];
                        $mode = $metadata['mode'];

                        $position = \ftell($resource);
                        \fclose($resource);

                        /** @var FileStream $file */
                        $file = yield File\open($filename, $mode);

                        if ($position) {
                            yield $file->seek($position);
                        }

                        return $file;

                    default:
                        return new ResourceInputStream($resource);
                }
            }
        );
    }
}
