<?php
/**
 * JSON HTTP client (cURL) used for calls between Kantina, the ERP, the CMS
 * and external services. Never throws on HTTP errors — check ['ok'].
 */
final class HttpClient
{
    /**
     * @return array{ok: bool, status: int, body: mixed, raw: string, error: ?string, ms: int}
     */
    public static function request(string $method, string $url, $body = null, array $headers = [], int $timeout = 5): array
    {
        $ch = curl_init($url);
        $sendHeaders = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $sendHeaders[] = is_int($k) ? $v : "$k: $v";
        }
        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sendHeaders[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $sendHeaders,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $start = microtime(true);
        $raw = curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => $error ?: 'Connection failed', 'ms' => $ms];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);
        $ok = $status >= 200 && $status < 300;
        $error = null;
        if (!$ok) {
            $error = is_array($decoded) && isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : "HTTP $status";
        }
        return ['ok' => $ok, 'status' => $status, 'body' => $decoded, 'raw' => $raw, 'error' => $error, 'ms' => $ms];
    }
}
