<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Promise;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\ResponseInterface as PsrResponse;

interface MessageFactory
{
    /**
     * @param AmpRequest $request
     *
     * @return Promise<PsrRequest>
     */
    public function convertRequest(AmpRequest $request): Promise;

    /**
     * @param PsrResponse $response
     *
     * @return Promise<AmpResponse>
     */
    public function convertResponse(PsrResponse $response): Promise;
}
