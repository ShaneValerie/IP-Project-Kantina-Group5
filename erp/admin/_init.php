<?php
/**
 * Shared setup for the ERP admin pages: library, session login, helpers.
 */
require_once __DIR__ . '/../api/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'erp');
require_once __DIR__ . '/../api/src/Events.php';
require_once __DIR__ . '/../api/src/Inventory.php';
require_once __DIR__ . '/../api/src/Sales.php';
require_once __DIR__ . '/../api/src/Reports.php';
require_once __DIR__ . '/_layout.php';

session_name('kantina_erp_admin');
session_start();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    $amount = (float) $amount;
    return '₱' . number_format($amount, fmod($amount, 1.0) ? 2 : 0);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'] = [$type, $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Call at the top of every POST handler. */
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Go back and try again.');
    }
}

function require_login(): array
{
    if (empty($_SESSION['admin'])) {
        redirect('login.php');
    }
    return $_SESSION['admin'];
}

/** Run an action and turn API-style errors into a flash message. */
function attempt(callable $fn, string $success): void
{
    try {
        $fn();
        flash('ok', $success);
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        flash('error', Config::get('debug') ? $e->getMessage() : 'Something went wrong.');
    }
}

function stall_names(): array
{
    $names = [];
    foreach (Db::all('SELECT code, name FROM erp_stalls ORDER BY name') as $s) {
        $names[$s['code']] = $s['name'];
    }
    return $names;
}

function status_badge(string $status): string
{
    $class = ['paid' => 'ok', 'open' => 'warn', 'cancelled' => 'gray'][$status] ?? '';
    return '<span class="badge ' . $class . '">' . h($status) . '</span>';
}
