<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler as AmpRequestHandler;
use Amp\Promise;
use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandler;
use function Amp\call;

final class RequestHandlerBridge implements AmpRequestHandler
{
    /** @var PsrRequestHandler */
    private $requestHandler;

    /** @var MessageFactory */
    private $messageFactory;

    public function __construct(PsrRequestHandler $handler, MessageFactory $factory)
    {
        $this->requestHandler = $handler;
        $this->messageFactory = $factory;
    }

    public function handleRequest(Request $request): Promise
    {
        return call(function () use ($request): \Generator {
            $request = yield $this->messageFactory->convertRequest($request);
            $response = $this->requestHandler->handle($request);
            return yield $this->messageFactory->convertResponse($response);
        });
    }
}
