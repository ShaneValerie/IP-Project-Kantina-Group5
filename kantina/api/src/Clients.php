<?php
/**
 * HTTP clients for the integrated systems (subclass ServiceClient for each one).
 * They authenticate with an API key sent in the X-Api-Key header.
 */
abstract class ServiceClient
{
    abstract protected static function app(): string;      // 'erp' | 'crm'
    abstract protected static function label(): string;    // 'Kantina ERP'

    public static function baseUrl(): string
    {
        return Config::url(static::app()) . '/api/v1';
    }

    /** Raw call: never throws, returns HttpClient's result array. */
    public static function call(string $method, string $path, $body = null, int $timeout = 6): array
    {
        return HttpClient::request($method, static::baseUrl() . $path, $body, [
            'X-Api-Key' => (string) Config::get(static::app() . '.api_key'),
        ], $timeout);
    }

    /**
     * Call and return the decoded body, or stop the request:
     *   - the other system rejected the data (4xx) -> same status + its message
     *   - it is down / errored (0, 5xx)            -> 502 "<system> is not responding"
     */
    public static function must(string $method, string $path, $body = null, int $timeout = 6): array
    {
        $res = static::call($method, $path, $body, $timeout);
        if ($res['ok']) {
            return is_array($res['body']) ? $res['body'] : [];
        }
        if ($res['status'] >= 400 && $res['status'] < 500 && is_array($res['body'])) {
            $err = $res['body']['error'] ?? [];
            throw new HttpError($res['status'] === 401 ? 502 : $res['status'], $err['code'] ?? 'rejected', static::label() . ': ' . ($err['message'] ?? 'request rejected'), $err['fields'] ?? []);
        }
        throw new HttpError(502, static::app() . '_unavailable', static::label() . ' is not responding right now (' . $res['error'] . '). Please try again in a moment.');
    }

    /** Health check for the integrations status page. */
    public static function health(): array
    {
        $res = static::call('GET', '/health', null, 3);
        return [
            'name' => static::label(),
            'url' => static::baseUrl(),
            'online' => $res['ok'],
            'ms' => $res['ms'],
            'error' => $res['ok'] ? null : $res['error'],
        ];
    }
}

final class ErpClient extends ServiceClient
{
    protected static function app(): string { return 'erp'; }
    protected static function label(): string { return 'Kantina ERP'; }
}

final class CrmClient extends ServiceClient
{
    protected static function app(): string { return 'crm'; }
    protected static function label(): string { return 'Kantina CRM'; }
}
