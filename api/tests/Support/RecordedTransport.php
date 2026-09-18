<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 transport that answers from a script instead of the network.
 *
 * Exists so the one request this application makes to a model can be asserted
 * on — both halves of it. What we send matters as much as what we do with the
 * reply: the model id, the token ceiling, the effort level and the cache
 * breakpoint are all things that fail silently and expensively in production,
 * and none of them were covered by anything before this.
 *
 * Responses are consumed in order. Running out is an error rather than a repeat
 * of the last one, so a test that accidentally makes two calls says so.
 */
class RecordedTransport implements ClientInterface
{
    /** @var list<array{status: int, body: string}> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param  array<string, mixed>|string  $body */
    public function queue(array|string $body, int $status = 200): self
    {
        $this->queue[] = [
            'status' => $status,
            'body'   => is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR),
        ];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Rewound because the SDK has already read it to send it, and a test
        // asserting on the body would otherwise get an empty string.
        $request->getBody()->rewind();

        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \RuntimeException(sprintf(
                'RecordedTransport received an unscripted request to %s. Queue one response per expected call.',
                (string) $request->getUri(),
            ));
        }

        return new Response(
            $next['status'],
            ['Content-Type' => 'application/json', 'request-id' => 'req_test'],
            $next['body'],
        );
    }

    /** The decoded body of the nth request, for asserting on what we sent. */
    public function sentBody(int $index = 0): array
    {
        $request = $this->requests[$index] ?? throw new \RuntimeException("No request was made at index {$index}.");

        $request->getBody()->rewind();

        return json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
