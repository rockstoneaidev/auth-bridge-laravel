<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

class AuthBridgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userEndpoint,
        private readonly HttpFactory $http,
        private readonly array $httpConfig = [],
    ) {
    }

    /**
     * Fetch the authenticated user payload from the Auth API.
     *
     * @param  string  $token
     * @param  array<string, string|null>  $headers
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function fetchUser(string $token, array $headers = []): array
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($token)
            ->acceptJson();

        $config = $this->httpConfig;

        if (isset($config['timeout'])) {
            $request->timeout((float) $config['timeout']);
        }

        if (isset($config['connect_timeout'])) {
            $request->connectTimeout((float) $config['connect_timeout']);
        }

        if (Arr::exists($config, 'allow_redirects')) {
            $request->withOptions(['allow_redirects' => (bool) $config['allow_redirects']]);
        }

        $request->withHeaders(array_filter($headers, static fn ($value): bool => filled($value)));

        /** @var Response $response */
        $response = $request->get($this->userEndpoint);

        $response->throw();

        $data = $response->json();

        if (! is_array($data)) {
            throw new RequestException($response);
        }

        return $data;
    }
}
