<?php
/**
 * Shared setup for the CRM admin pages: library, session login, helpers.
 * New admin page? Start it with:
 *   require __DIR__ . '/_init.php';
 *   require_login();
 *   layout_start('Customers', 'customers');   ...   layout_end();
 * and add it to the $nav list in _layout.php. Every POST form needs
 * csrf_field() inside the form and csrf_check() in the handler.
 */
require_once __DIR__ . '/../api/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'crm');
require_once __DIR__ . '/../api/src/Events.php';
require_once __DIR__ . '/../api/src/Customers.php';
require_once __DIR__ . '/../api/src/Tickets.php';
require_once __DIR__ . '/../api/src/Reports.php';
require_once __DIR__ . '/_layout.php';

session_name('kantina_crm_admin');
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

/** Flash message after a change that sends a webhook to Kantina. */
function flash_notified(string $saved, ?bool $notified): void
{
    if ($notified === null) {
        flash('ok', 'Nothing changed.');
    } elseif ($notified) {
        flash('ok', "$saved — Kantina has been notified.");
    } else {
        flash('error', "$saved, but Kantina could not be notified. Resend it from Webhooks to Kantina.");
    }
}

function tier_badge(string $tier): string
{
    $class = ['Gold' => 'warn', 'Silver' => 'gray', 'Bronze' => 'bronze'][$tier] ?? 'gray';
    return '<span class="badge ' . $class . '">' . h($tier) . '</span>';
}

function status_badge(string $status): string
{
    $class = ['open' => 'bad', 'in_progress' => 'warn', 'resolved' => 'ok'][$status] ?? 'gray';
    return '<span class="badge ' . $class . '">' . h(Tickets::LABELS[$status] ?? $status) . '</span>';
}

function stars(?int $rating): string
{
    return $rating ? '<span class="stars" title="' . $rating . ' of 5">' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . '</span>' : '<span class="muted">—</span>';
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
