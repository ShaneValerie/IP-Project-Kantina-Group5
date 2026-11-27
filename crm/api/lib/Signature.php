<?php
/**
 * HMAC-SHA256 signatures for webhooks between the apps.
 *
 * Header:  X-Kantina-Signature: t=<unix time>,v1=<hex hmac of "<t>.<raw body>">
 * The receiver recomputes the HMAC with the shared secret and rejects the
 * request if it doesn't match or is older than 5 minutes (replay protection).
 */
final class Signature
{
    public static function sign(string $secret, string $body, ?int $time = null): string
    {
        $t = $time ?? time();
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    public static function verify(string $secret, ?string $header, string $body, int $tolerance = 300): bool
    {
        if (!$header || !preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $m[1] . '.' . $body, $secret);
        return hash_equals($expected, $m[2]);
    }
}
