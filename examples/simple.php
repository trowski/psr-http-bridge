<?php

require \dirname(__DIR__) . '/vendor/autoload.php';

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\HttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequest;
use Psr\Http\Message\StreamFactoryInterface as PsrStreamFactory;
use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandler;
use Trowski\PsrHttpBridge\AsyncReadyStreamFactory;
use Trowski\PsrHttpBridge\PsrFactoryMessageConverter;
use Trowski\PsrHttpBridge\RequestHandlerBridge;

// This examples requires amphp/cluster, amphp/log, and laminas/diactoros to be installed.

Loop::run(function (): \Generator {
    // Wrapping the PSR-17 stream factory in AsyncReadyStreamFactory allows responses to be stream asynchronously.
    $streamFactory = new AsyncReadyStreamFactory(new StreamFactory);

    // A very simple implementation of a PSR-15 request handler.
    // This would be replaced by your top-level request handler, likely a router.
    $psrRequestHandler = new class($streamFactory) implements PsrRequestHandler {
        /** @var AsyncReadyStreamFactory */
        private $streamFactory;

        public function __construct(PsrStreamFactory $streamFactory)
        {
            $this->streamFactory = $streamFactory;
        }

        public function handle(PsrServerRequest $request): PsrResponse
        {
            return new Response($this->streamFactory->createStream("Hello, world!"), 200);
        }
    };

    // Generic converter from PSR-7 messages to Amp's HTTP Server messages.
    $messageConverter = new PsrFactoryMessageConverter(new ServerRequestFactory);

    // Request handler compatible with Amp's HTTP server request handler to bridge to PSR-15 request handler.
    $requestHandlerBridge = new RequestHandlerBridge($psrRequestHandler, $messageConverter);

    if (Cluster::isWorker()) {
        $formatter = new LineFormatter;
        $handler = Cluster::createLogHandler();
    } else {
        $formatter = new ConsoleFormatter;
        $handler = new StreamHandler(ByteStream\getStdout());
    }

    $handler->setFormatter($formatter);

    $logger = new Logger('http-server');
    $logger->pushHandler($handler);

    // TLS context for HTTPS connections.
    $tlsContext = (new ServerTlsContext)
        ->withDefaultCertificate(new Certificate(__DIR__ . '/certificate.pem'))
        ->withMinimumVersion(\STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
    $bindContext = (new BindContext)->withTlsContext($tlsContext);

    // Define the network interfaces where the server will be listening for requests.
    $sockets = yield [
        Cluster::listen('0.0.0.0:8080'), // Plaintext IPv4, HTTP/1.x only
        Cluster::listen('[::]:8080'), // Plaintext IPv6, HTTP/1.x only
        Cluster::listen('0.0.0.0:8443', $bindContext), // Encrypted IPv4, HTTP/1.x or HTTP/2
        Cluster::listen('[::]:8443', $bindContext), // Encrypted IPv6, HTTP/1.x or HTTP/2
    ];

    $server = new HttpServer($sockets, $requestHandlerBridge, $logger);

    Cluster::onTerminate(function () use ($logger, $server): Promise {
        $logger->info('Received termination request');
        return $server->stop();
    });

    yield $server->start();
});
