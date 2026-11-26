<?php
/**
 * Request / response helpers for the JSON APIs.
 */

/** Throw this anywhere to stop and send a JSON error response. */
class HttpError extends Exception
{
    public int $status;
    public string $errorCode;
    public array $fields;

    public function __construct(int $status, string $code, string $message, array $fields = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
        $this->fields = $fields;
    }
}

final class Request
{
    public string $method;
    public string $path;          // route path after /api, e.g. /orders/KTN-90214
    public array $query;
    public array $params = [];    // filled by the router from {placeholders}
    public ?array $user = null;   // filled by the auth middleware
    public array $attributes = [];
    private ?string $raw = null;
    private ?array $json = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($this->method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $this->method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }
        $this->query = $_GET;
        $this->path = self::routePath();
    }

    /**
     * Everything after the "/api" segment of the URL. Works in a subfolder,
     * on its own subdomain, with .htaccess rewriting or as /api/index.php/...
     */
    private static function routePath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        $pos = strpos($path, '/api/');
        if ($pos === false) {
            $pos = substr($path, -4) === '/api' ? strlen($path) - 4 : false;
        }
        $route = $pos === false ? $path : substr($path, $pos + 4);
        $route = preg_replace('#^/index\.php#', '', $route);
        $route = '/' . trim($route, '/');
        return $route;
    }

    public function raw(): string
    {
        if ($this->raw === null) {
            $this->raw = (string) file_get_contents('php://input');
        }
        return $this->raw;
    }

    /** Decoded JSON body (or form fields). */
    public function body(): array
    {
        if ($this->json === null) {
            $raw = $this->raw();
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if ($raw !== '' && !is_array($decoded)) {
                if (!empty($_POST)) {
                    $decoded = $_POST;
                } else {
                    throw new HttpError(400, 'invalid_json', 'The request body is not valid JSON.');
                }
            }
            $this->json = $decoded ?? [];
        }
        return $this->json;
    }

    public function input(string $key, $default = null)
    {
        $body = $this->body();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return $v;
                }
            }
        }
        return null;
    }

    /** Bearer token from Authorization (some hosts strip it, so X-Auth-Token also works). */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization')
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if ($auth && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return $this->header('X-Auth-Token');
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

final class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function error(HttpError $e): void
    {
        $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        if ($e->fields) {
            $body['error']['fields'] = $e->fields;
        }
        self::json($body, $e->status);
    }

    /** CORS + no-cache headers shared by all API responses. */
    public static function commonHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token, X-Api-Key, X-Kantina-Signature, X-Kantina-Event');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}
