<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Identity\ThaiDProtocol;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ThaiDScaffoldTest extends FoundationTestCase
{
    private function configureProvider(): void
    {
        config(['identity.thaid.client_id' => 'synthetic-rp', 'identity.thaid.client_secret' => 'synthetic-secret',
            'identity.thaid.redirect_uri' => 'https://lms.example.org/auth/thaid/callback']);
        Http::preventStrayRequests();
    }

    public function test_registered_request_contract_and_one_time_state(): void
    {
        $this->configureProvider();
        $protocol = new ThaiDProtocol;
        $session = app('session')->driver();
        $url = $protocol->authorizationUrl($session);
        $this->assertStringStartsWith(ThaiDProtocol::AUTHORIZATION_ENDPOINT.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('pid openid', $query['scope']);
        $this->assertSame('https://lms.example.org/auth/thaid/callback', $query['redirect_uri']);
        $this->assertStringNotContainsString('synthetic-secret', $url);
        $this->assertNotSame($query['state'], $session->get('thaid.request.state_hash'));
        $protocol->consumeState($session, $query['state']);
        $this->assertNull($session->get('thaid.request'));
        $this->expectException(RuntimeException::class);
        $protocol->consumeState($session, $query['state']);
    }

    public function test_wrong_expired_future_and_array_state_are_rejected_without_network(): void
    {
        $this->configureProvider();
        foreach (['wrong', 'expired', 'future', 'array'] as $case) {
            $session = app('session')->driver();
            $protocol = new ThaiDProtocol;
            parse_str((string) parse_url($protocol->authorizationUrl($session), PHP_URL_QUERY), $query);
            if ($case === 'expired' || $case === 'future') {
                $stored = $session->get('thaid.request');
                $stored['issued_at'] = time() + ($case === 'expired' ? -301 : 60);
                $session->put('thaid.request', $stored);
            }
            try {
                $protocol->consumeState($session, match ($case) {
                    'wrong' => str_repeat('0', 64), 'array' => [$query['state']], default => $query['state']
                });
                $this->fail('Invalid state must be rejected.');
            } catch (RuntimeException) {
                $this->assertNull($session->get('thaid.request'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_token_exchange_uses_form_and_basic_auth_and_never_authenticates(): void
    {
        $this->configureProvider();
        Http::fake([ThaiDProtocol::TOKEN_ENDPOINT => Http::response(['access_token' => 'synthetic-token',
            'id_token' => 'unverified-jwt', 'token_type' => 'Bearer', 'expire_in' => time() + 900], 200)]);
        $payload = (new ThaiDProtocol)->exchangeCode('synthetic-code');
        $this->assertSame('unverified-jwt', $payload['id_token']);
        $this->assertGuest();
        $this->assertDatabaseCount('external_identities', 0);
        Http::assertSent(fn ($request): bool => $request->url() === ThaiDProtocol::TOKEN_ENDPOINT
            && $request->method() === 'POST' && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'synthetic-code' && $request['redirect_uri'] === config('identity.thaid.redirect_uri')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-rp:synthetic-secret'))
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
    }

    public function test_bad_or_redirected_token_response_does_not_leak_provider_body(): void
    {
        $this->configureProvider();
        foreach ([302, 401, 500, 200] as $status) {
            Http::fake([ThaiDProtocol::TOKEN_ENDPOINT => Http::response(['error_description' => 'PRIVATE_TOKEN synthetic-secret'], $status)]);
            try {
                (new ThaiDProtocol)->exchangeCode('synthetic-code');
                $this->fail('Invalid response must not be accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('PRIVATE_TOKEN', $error->getMessage());
                $this->assertStringNotContainsString('synthetic-secret', $error->getMessage());
            }
        }
    }

    public function test_missing_configuration_or_http_callback_is_rejected(): void
    {
        $this->configureProvider();
        config(['identity.thaid.redirect_uri' => 'http://127.0.0.1/auth/thaid/callback']);
        $this->expectException(RuntimeException::class);
        (new ThaiDProtocol)->authorizationUrl(app('session')->driver());
    }

    public function test_reserved_routes_stay_closed_even_with_enabled_flag_and_query_pid(): void
    {
        $this->configureProvider();
        config(['identity.thaid.enabled' => true]);
        $this->get('/auth/thaid')->assertNotFound();
        $this->get('/auth/thaid/callback?pid=0000000000001&sub=0000000000001&code=fake&state=fake')->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseCount('external_identities', 0);
        Http::assertNothingSent();
    }
}
