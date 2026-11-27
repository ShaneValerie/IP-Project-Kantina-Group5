<?php
/**
 * JSON Web Tokens (HS256) — used by Kantina for login sessions.
 * A token is three base64url parts: header.payload.signature
 */
final class Jwt
{
    public static function encode(array $payload, string $secret, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge(['iat' => $now, 'exp' => $now + $ttlSeconds], $payload);
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$sig";
    }

    /** Returns the payload, or null if the token is invalid or expired. */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $head = json_decode(self::unb64($header), true);
        if (($head['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
