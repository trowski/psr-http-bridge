<?php declare(strict_types=1);

namespace Trowski\PsrHttpBridge;

use Amp\ByteStream\IteratorStream;
use Amp\Http\Server\FormParser\BufferingParser;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Producer;
use Amp\Promise;
use Amp\Success;
use League\Uri\Components\Query;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Zend\Diactoros\ServerRequest as ZendRequest;
use function Amp\call;

final class ZendMessageFactory implements MessageFactory
{
    private const CHUNK_SIZE = 8192;

    public const DEFAULT_FIELD_COUNT_LIMIT = 1000;
    public const DEFAULT_BODY_SIZE_LIMIT = 2 ** 20; // 1MB

    /** @var BufferingParser */
    private $bodyParser;

    /** @var string */
    private $tmpFilePath;

    public function __construct(
        int $fieldCountLimit = self::DEFAULT_FIELD_COUNT_LIMIT,
        ?string $tmpFilePath = null
    ) {
        $this->bodyParser = new BufferingParser($fieldCountLimit);
        $this->tmpFilePath = $tmpFilePath ?? \sys_get_temp_dir();
    }

    /**
     * @param AmpRequest $request
     *
     * @return Promise<PsrRequest>
     */
    public function convertRequest(AmpRequest $request): Promise
    {
        return call(function () use ($request) {
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

                // @TODO Normalize uploaded files and write to tmp directory.
                // $files = $form->getFiles();
            }

            $uri = $request->getUri();
            $client = $request->getClient();
            $localAddress = $client->getLocalAddress();
            $remoteAddress = $client->getRemoteAddress();
            $query = new Query($uri->getQuery());

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

            $cookies = [];
            foreach ($request->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }

            return new ZendRequest(
                $server,
                $files ?? [],
                $uri,
                $request->getMethod(),
                new StreamAdapter($request->getBody()),
                $request->getHeaders(),
                $cookies,
                $query->getPairs(),
                $postValues ?? null,
                $request->getProtocolVersion()
            );
        });
    }

    /**
     * @param PsrResponse $response
     *
     * @return Promise<AmpResponse>
     */
    public function convertResponse(PsrResponse $response): Promise
    {
        $push = $response->getHeader('link');
        $response = $response->withoutHeader('link');

        $body = $response->getBody();

        $converted = new AmpResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            new IteratorStream(new Producer(function (callable $emit) use ($body): \Generator {
                while (!$body->eof()) {
                    yield $emit($body->read(self::CHUNK_SIZE));
                }
            }))
        );

        foreach ($push as $pushed) {
            if (\preg_match('/<([^>]+)>; rel=preload/i', $pushed, $matches)) {
                $converted->push($matches[1]);
            }
        }

        return new Success($converted);
    }
}
