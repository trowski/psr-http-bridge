<?php declare(strict_types=1);

require \dirname(__DIR__) . '/vendor/autoload.php';

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\Server;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Trowski\PsrHttpBridge\RequestHandlerBridge;
use Trowski\PsrHttpBridge\ZendMessageFactory;
use Zend\Expressive\Application;
use Zend\Expressive\MiddlewareFactory;

// This example assumes a project created by 'composer create-project zendframework/zend-expressive-skeleton'
// in the parent directory and for amphp/cluster and amphp/log to be installed.

Loop::run(function (): \Generator {
    /** @var \Psr\Container\ContainerInterface $container */
    $container = require \dirname(__DIR__) . '/config/container.php';

    /** @var \Zend\Expressive\Application $app */
    $app = $container->get(Application::class);
    $factory = $container->get(MiddlewareFactory::class);

    // Execute programmatic/declarative middleware pipeline and routing
    // configuration statements
    (require \dirname(__DIR__) . '/config/pipeline.php')($app, $factory, $container);
    (require \dirname(__DIR__) . '/config/routes.php')($app, $factory, $container);

    $requestHandler = new RequestHandlerBridge($app, new ZendMessageFactory);

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

    $tlsContext = (new ServerTlsContext)
        ->withDefaultCertificate(new Certificate(\dirname(__DIR__) . '/etc/certificate.pem'))
        ->withMinimumVersion(\STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
    $bindContext = (new BindContext)->withTlsContext($tlsContext);

    $sockets = yield [
        Cluster::listen('0.0.0.0:8080'), // Plaintext IPv4, HTTP/1.x only
        Cluster::listen('[::]:8080'), // Plaintext IPv6, HTTP/1.x only
        Cluster::listen('0.0.0.0:8443', $bindContext), // Encrypted IPv4, HTTP/1.x or HTTP/2
        Cluster::listen('[::]:8443', $bindContext), // Encrypted IPv6, HTTP/1.x or HTTP/2
    ];

    $server = new Server($sockets, $requestHandler, $logger);

    Cluster::onTerminate(function () use ($server): Promise {
        return $server->stop();
    });

    return yield $server->start();
});
