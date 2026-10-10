<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Transport scaffold only. A token response is NOT a verified local identity. */
class ThaiDProtocol
{
    public const AUTHORIZATION_ENDPOINT = 'https://imauth.bora.dopa.go.th/api/v2/oauth2/auth/';

    public const TOKEN_ENDPOINT = 'https://imauth.bora.dopa.go.th/api/v2/oauth2/token/';

    public const DISCOVERY_ENDPOINT = 'https://imauth.bora.dopa.go.th/.well-known/openid-configuration';

    public function authorizationUrl(Session $session): string
    {
        $this->requireConfiguration();
        $state = bin2hex(random_bytes(32));
        $session->put('thaid.request', ['state_hash' => hash('sha256', $state), 'issued_at' => time()]);

        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('identity.thaid.client_id'),
            'redirect_uri' => config('identity.thaid.redirect_uri'), 'scope' => 'pid openid', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Validate before any provider request. Consume even a rejected callback. */
    public function consumeState(Session $session, mixed $state): void
    {
        $request = $session->pull('thaid.request');
        if (! is_array($request) || ! is_string($state) || strlen($state) !== 64
            || ! isset($request['state_hash'], $request['issued_at'])
            || ! is_string($request['state_hash']) || ! is_int($request['issued_at'])
            || time() - $request['issued_at'] > 300 || $request['issued_at'] > time()
            || ! hash_equals($request['state_hash'], hash('sha256', $state))) {
            throw new RuntimeException('ThaiD callback state is invalid or expired.');
        }
    }

    /** @return array<string, mixed> Untrusted until an OIDC verifier validates it. */
    public function exchangeCode(string $code): array
    {
        $this->requireConfiguration();
        if ($code === '' || strlen($code) > 2048) {
            throw new RuntimeException('ThaiD authorization code is invalid.');
        }
        try {
            // No redirects/retries: never forward client credentials or replay a code.
            $response = Http::asForm()->acceptJson()->withBasicAuth(
                (string) config('identity.thaid.client_id'), (string) config('identity.thaid.client_secret'),
            )->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->post(self::TOKEN_ENDPOINT, ['grant_type' => 'authorization_code', 'code' => $code,
                    'redirect_uri' => config('identity.thaid.redirect_uri')]);
            $payload = $response->json();
        } catch (\Throwable) {
            // Do not attach provider exceptions, request bodies or tokens to logs.
            throw new RuntimeException('ThaiD token service is unavailable.');
        }
        if (! $response->successful() || ! is_array($payload)
            || ($payload['token_type'] ?? null) !== 'Bearer'
            || ! is_string($payload['access_token'] ?? null) || ! is_string($payload['id_token'] ?? null)
            || ! is_int($payload['expire_in'] ?? null) || $payload['expire_in'] <= time()) {
            throw new RuntimeException('ThaiD token response is invalid.');
        }

        return $payload;
    }

    private function requireConfiguration(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (! is_string(config('identity.thaid.'.$key)) || trim(config('identity.thaid.'.$key)) === '') {
                throw new RuntimeException('ThaiD registered RP configuration is incomplete.');
            }
        }
        $uri = (string) config('identity.thaid.redirect_uri');
        if (filter_var($uri, FILTER_VALIDATE_URL) === false || parse_url($uri, PHP_URL_SCHEME) !== 'https'
            || parse_url($uri, PHP_URL_PATH) !== '/auth/thaid/callback'
            || parse_url($uri, PHP_URL_USER) !== null || parse_url($uri, PHP_URL_QUERY) !== null
            || parse_url($uri, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('ThaiD requires the registered HTTPS callback URI.');
        }
    }
}
