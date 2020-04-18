<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\IteratorStream;
use Amp\Http\Server\FormParser\BufferingParser;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Producer;
use Amp\Promise;
use Kelunik\LinkHeaderRfc5988;
use League\Uri\Components\Query;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\ServerRequestFactoryInterface as PsrServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequest;
use function Amp\call;

final class PsrFactoryMessageConverter implements MessageConverter
{
    private const CHUNK_SIZE = 8192;

    public const DEFAULT_FIELD_COUNT_LIMIT = 1000;
    public const DEFAULT_BODY_SIZE_LIMIT = 2 ** 20; // 1MB

    /** @var PsrServerRequestFactory */
    private $requestFactory;

    /** @var BufferingParser */
    private $bodyParser;

    /** @var int */
    private $bodySizeLimit;

    public function __construct(
        PsrServerRequestFactory $requestFactory,
        int $fieldCountLimit = self::DEFAULT_FIELD_COUNT_LIMIT,
        int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT
    ) {
        $this->requestFactory = $requestFactory;
        $this->bodyParser = new BufferingParser($fieldCountLimit);
        $this->bodySizeLimit = $bodySizeLimit;
    }

    /**
     * @param AmpRequest $request
     *
     * @return Promise<PsrServerRequest>
     */
    public function convertRequest(AmpRequest $request): Promise
    {
        return call(function () use ($request) {
            $uri = $request->getUri();
            $client = $request->getClient();
            $localAddress = $client->getLocalAddress();
            $remoteAddress = $client->getRemoteAddress();

            $request->getBody()->increaseSizeLimit($this->bodySizeLimit);

            $server = [
                'HTTPS' => $client->isEncrypted(),
                'QUERY_STRING' => $uri->getQuery(),
                'REMOTE_ADDR' => $remoteAddress->getHost(),
                'REQUEST_METHOD' => $request->getMethod(),
                'REMOTE_USER' => $uri->getUserInfo(),
                'REMOTE_PORT' => $remoteAddress->getPort(),
                'REQUEST_TIME' => \time(),
                'REQUEST_TIME_FLOAT' => \microtime(true),
                'REQUEST_URI' => $uri->getPath(),
                'SERVER_ADDR' => $localAddress->getHost(),
                'SERVER_PORT' => $localAddress->getPort(),
                'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
                'SERVER_SOFTWARE' => 'Amp HTTP Server'
            ];

            if ($request->hasHeader('accept')) {
                $server['HTTP_ACCEPT'] = $request->getHeader('accept');
            }

            if ($request->hasHeader('accept-charset')) {
                $server['HTTP_ACCEPT_CHARSET'] = $request->getHeader('accept-charset');
            }

            if ($request->hasHeader('accept-encoding')) {
                $server['HTTP_ACCEPT_ENCODING'] = $request->getHeader('accept-encoding');
            }

            if ($request->hasHeader('connection')) {
                $server['HTTP_CONNECTION'] = $request->getHeader('connection');
            }

            if ($request->hasHeader('referer')) {
                $server['HTTP_REFERER'] = $request->getHeader('referer');
            }

            if ($request->hasHeader('user-agent')) {
                $server['HTTP_USER_AGENT'] = $request->getHeader('user-agent');
            }

            if ($request->hasHeader('host')) {
                $server['HTTP_HOST'] = $request->getHeader('host');
            }

            $converted = $this->requestFactory->createServerRequest($request->getMethod(), $uri, $server);

            foreach ($request->getHeaders() as $field => $values) {
                $converted = $converted->withHeader($field, $values);
            }

            $cookies = [];
            foreach ($request->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }

            $converted = $converted->withCookieParams($cookies);
            $converted = $converted->withQueryParams((new Query($uri->getQuery()))->getPairs());
            $converted = $converted->withProtocolVersion($request->getProtocolVersion());

            $type = $request->getHeader('content-type');

            if ($type !== null
                && (
                    \strncmp($type, "application/x-www-form-urlencoded", \strlen("application/x-www-form-urlencoded")) === 0
                    || \preg_match('#^\s*multipart/(?:form-data|mixed)(?:\s*;\s*boundary\s*=\s*("?)([^"]*)\1)?$#', $type)
                )
            ) {
                $form = yield $this->bodyParser->parseForm($request);
                \assert($form instanceof Form);
                $postValues = $form->getValues();
                foreach ($postValues as $key => $value) {
                    if (\count($value) === 1) {
                        $postValues[$key] = $value[0];
                    }
                }

                $converted = $converted->withParsedBody($postValues);

                $files = $form->getFiles();
                $uploadedFiles = [];
                foreach ($files as $fileset) {
                    foreach ($fileset as $file) {
                        $uploadedFiles[] = new BufferedUploadedFile($file);
                    }
                }

                $converted->withUploadedFiles($uploadedFiles);
            } else {
                $converted = $converted->withBody(new BufferedBody(yield $request->getBody()->buffer()));
            }

            return $converted;
        });
    }

    /**
     * @param PsrResponse $response
     *
     * @return Promise<AmpResponse>
     */
    public function convertResponse(PsrResponse $response): Promise
    {
        return call(function () use ($response) {
            $body = $response->getBody();

            if ($body instanceof AsyncReadyStream) {
                $stream = yield $body->createAsyncStream();
            } else {
                $stream = new IteratorStream(new Producer(function (callable $emit) use ($body): \Generator {
                    while (!$body->eof()) {
                        yield $emit($body->read(self::CHUNK_SIZE));
                    }
                }));
            }

            $converted = new AmpResponse(
                $response->getStatusCode(),
                $response->getHeaders(),
                $stream
            );

            $links = LinkHeaderRfc5988\parseLinks($response->getHeaderLine('link'));
            foreach ($links->getAllByRel('preload') as $link) {
                $converted->push($link->getUri());
            }

            return $converted;
        });
    }
}
