<?php
/**
 * Loads the shared library and runs an API request with consistent
 * JSON responses and error handling.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/Signature.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/WebhookSender.php';

date_default_timezone_set('Asia/Manila');

/**
 * Dispatch the current request through $router and send the response.
 * $after(Request $req, int $status, int $ms) runs after the response (logging).
 */
function api_run(Router $router, ?callable $after = null): void
{
    $start = microtime(true);
    Response::commonHeaders();
    $request = new Request();
    $status = 500;
    try {
        [$data, $status] = $router->dispatch($request);
        if ($status === 204) {
            http_response_code(204);
        } else {
            Response::json($data, $status);
        }
    } catch (HttpError $e) {
        $status = $e->status;
        Response::error($e);
    } catch (Throwable $e) {
        $status = 500;
        error_log('[' . Config::appName() . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $message = Config::get('debug') ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Something went wrong on the server.';
        Response::json(['error' => ['code' => 'server_error', 'message' => $message]], 500);
    }
    if ($after) {
        try {
            $after($request, $status, (int) round((microtime(true) - $start) * 1000));
        } catch (Throwable $e) {
            error_log('[' . Config::appName() . '] after-hook failed: ' . $e->getMessage());
        }
    }
}

/** Format a DECIMAL from MySQL as a number (85.00 -> 85, 85.50 -> 85.5). */
function money($value): float
{
    return round((float) $value, 2);
}

/** "13:05:00" -> "13:05" */
function hm(?string $time): ?string
{
    return $time === null ? null : substr($time, 0, 5);
}

/** ISO-8601 timestamp with the +08:00 offset, or null. */
function iso(?string $datetime): ?string
{
    return $datetime ? date('c', strtotime($datetime)) : null;
}
