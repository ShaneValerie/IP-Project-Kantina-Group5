<?php
/**
 * Router script for PHP's built-in server (used by dev.sh).
 * Does what the .htaccess files do on Apache: sends /api/... to api/index.php
 * and refuses to serve config and SQL files. Everything else is served normally.
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/config(\.local|\.sample)?\.php$|\.sql$|/lib/|/src/#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (preg_match('#^/api(/|$)#', $path) && !is_file($root . $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $root . '/api/index.php';
    return true;
}
return false;
