<?php

declare(strict_types=1);

namespace App\Contracts;

interface ThaiDIdentityVerifier
{
    /**
     * Verify discovery, signature, issuer, audience, expiry, iat and at_hash.
     * Never implement by decoding Base64 or trusting callback pid/sub.
     *
     * @param  array<string, mixed>  $tokenResponse
     * @return array{issuer: string, subject: string, pid: string}
     */
    public function verify(array $tokenResponse): array;
}
