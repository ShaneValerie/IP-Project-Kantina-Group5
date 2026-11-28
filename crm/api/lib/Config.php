<?php
/**
 * Settings loader.
 *
 * Order (later wins):
 *   1. defaults in <app>/config.php
 *   2. <app>/config.local.php   (not in git — passwords, secrets, live URLs)
 *   3. environment variables    (used by dev.sh)
 *
 * NOTE: api/lib/ is identical in kantina/, erp/ and crm/ so each app can be
 * deployed on its own. Edit it in kantina/api/lib and run `php tools/sync-lib.php`.
 */
final class Config
{
    private static array $data = [];
    private static string $appDir = '';
    private static string $appName = '';

    public static function load(string $appDir, string $appName): void
    {
        self::$appDir = rtrim($appDir, '/\\');
        self::$appName = $appName;
        $config = require self::$appDir . '/config.php';
        $local = self::$appDir . '/config.local.php';
        if (is_file($local)) {
            $config = self::merge($config, require $local);
        }
        $env = [
            'DB_HOST' => 'db.host', 'DB_PORT' => 'db.port', 'DB_USER' => 'db.user', 'DB_PASS' => 'db.pass',
            strtoupper($appName) . '_DB' => 'db.name',
            'KANTINA_URL' => 'urls.kantina', 'ERP_URL' => 'urls.erp', 'CRM_URL' => 'urls.crm',
            'BREVO_API_KEY' => 'email.brevo_api_key',
        ];
        foreach ($env as $var => $path) {
            $value = getenv($var);
            if ($value !== false && $value !== '') {
                self::setPath($config, $path, $value);
            }
        }
        self::$data = $config;
    }

    /** Get a value by dotted path, e.g. Config::get('db.host'). */
    public static function get(string $path, $default = null)
    {
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public static function appDir(): string
    {
        return self::$appDir;
    }

    public static function appName(): string
    {
        return self::$appName;
    }

    /**
     * Public base URL of an app ('kantina', 'erp' or 'crm'), without trailing slash.
     * Uses urls.<app> from the config; if empty, assumes the apps are sibling
     * folders on the same host (the default XAMPP htdocs layout).
     */
    public static function url(string $app): string
    {
        $configured = self::get("urls.$app");
        if ($configured) {
            return rtrim($configured, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Every PHP entry point is one folder below its app (api/index.php, admin/*.php), so
        // /<root>/<app>/api/index.php -> <root> is the folder that holds kantina/, erp/ and crm/.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && substr_count($script, '/') >= 3) {
            $root = str_replace('\\', '/', dirname(dirname(dirname($script))));
        } else {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $pos = strpos($path, '/' . self::$appName . '/');
            $root = $pos === false ? '' : substr($path, 0, $pos);
        }
        $root = rtrim($root, '/.');
        return "$scheme://$host$root/$app";
    }

    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && array_keys($value) !== range(0, count($value) - 1)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function setPath(array &$config, string $path, $value): void
    {
        $node = &$config;
        foreach (explode('.', $path) as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = $node[$key] ?? [];
            }
            $node = &$node[$key];
        }
        $node = $value;
    }
}
