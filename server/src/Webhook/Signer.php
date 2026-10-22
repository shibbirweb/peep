<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

/**
 * Provider signatures, so the app's own verification passes (WH-05).
 */
final class Signer
{
    private function __construct() {}

    /**
     * Mailgun: computed before rendering and exposed as signature.timestamp, .token, .signature.
     *
     * @return array{timestamp: string, token: string, signature: string}
     */
    public static function mailgun(string $signingKey, ?int $timestamp = null, ?string $token = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $token ??= bin2hex(random_bytes(25));

        return [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp . $token, $signingKey),
        ];
    }

    /**
     * Twilio: HMAC-SHA1 of the full URL followed by the sorted POST fields, base64 encoded,
     * sent as X-Twilio-Signature. The URL must be exactly the one called.
     *
     * @param array<string, string> $fields
     */
    public static function twilio(string $authToken, string $url, array $fields): string
    {
        ksort($fields, SORT_STRING);

        $data = $url;
        foreach ($fields as $name => $value) {
            $data .= $name . $value;
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }
}
