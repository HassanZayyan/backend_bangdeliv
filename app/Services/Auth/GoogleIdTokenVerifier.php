<?php

namespace App\Services\Auth;

use Google\Auth\AccessToken;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class GoogleIdTokenVerifier
{
    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string, picture: string|null}
     */
    public function verify(string $idToken): array
    {
        $clientId = trim((string) config('services.google.client_id', ''));
        if ($clientId === '') {
            throw new RuntimeException('Google client ID belum dikonfigurasi.');
        }

        $payload = (new AccessToken())->verify($idToken, [
            'audience' => $clientId,
            'throwException' => true,
        ]);

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Token Google tidak valid.');
        }

        $issuer = (string) ($payload['iss'] ?? '');
        if (! in_array($issuer, [AccessToken::OAUTH2_ISSUER, AccessToken::OAUTH2_ISSUER_HTTPS], true)) {
            throw new UnexpectedValueException('Issuer token Google tidak valid.');
        }

        $sub = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if ($sub === '' || $email === '') {
            throw new InvalidArgumentException('Token Google tidak memiliki identitas yang lengkap.');
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => trim((string) ($payload['name'] ?? '')),
            'picture' => $this->nullableString($payload['picture'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
