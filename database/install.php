<?php
/**
 * Kantina installer — creates the three databases and imports the SQL files.
 * Works on Windows and Mac (XAMPP). Run it from the project folder:
 *
 *   php database/install.php
 *   php database/install.php --port=3307 --password=secret
 *   php database/install.php --single-db=kantina     (host allows only one database)
 *   php database/install.php --only=erp              (re-import just one app)
 *
 * Options: --host (127.0.0.1) --port (3306) --user (root) --password ('')
 *          --kantina-db (kantina) --erp-db (kantina_erp) --crm-db (kantina_crm)
 *          --single-db=<name>  --only=kantina,erp,crm  --yes (skip the question)
 *
 * WARNING: re-importing drops and recreates the Kantina tables (only ours —
 * other databases on your MySQL server are not touched).
 *
 * On the school server without a terminal, import the three .sql files with
 * phpMyAdmin instead (see README → Deployment).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php database/install.php\n");
}

$opts = getopt('', ['host:', 'port:', 'user:', 'password::', 'kantina-db:', 'erp-db:', 'crm-db:', 'single-db:', 'only:', 'yes', 'help']);
if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1200);
    exit(0);
}

$host = $opts['host'] ?? '127.0.0.1';
$port = (int) ($opts['port'] ?? 3306);
$user = $opts['user'] ?? 'root';
$pass = isset($opts['password']) && $opts['password'] !== false ? (string) $opts['password'] : '';
$single = $opts['single-db'] ?? null;
$dbs = [
    'kantina' => $single ?? ($opts['kantina-db'] ?? 'kantina'),
    'erp'     => $single ?? ($opts['erp-db'] ?? 'kantina_erp'),
    'crm'     => $single ?? ($opts['crm-db'] ?? 'kantina_crm'),
];
$files = [
    'kantina' => __DIR__ . '/kantina.sql',
    'erp'     => __DIR__ . '/kantina_erp.sql',
    'crm'     => __DIR__ . '/kantina_crm.sql',
];
$only = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : array_keys($files);

echo "Kantina installer\n";
echo "MySQL: {$user}@{$host}:{$port}\n";
foreach ($only as $app) {
    if (!isset($files[$app])) {
        exit("Unknown app '$app' (use kantina, erp or crm)\n");
    }
    echo "  - {$app}: database `{$dbs[$app]}`\n";
}
if (!isset($opts['yes'])) {
    echo "This will (re)create the Kantina tables in these databases. Continue? [y/N] ";
    $answer = strtolower(trim((string) fgets(STDIN)));
    if ($answer !== 'y' && $answer !== 'yes') {
        exit("Cancelled.\n");
    }
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // Import with MySQL 8's strict defaults so the files are known to work on any server.
    $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\n");
    fwrite(STDERR, "Is MySQL running in the XAMPP control panel? Wrong port? Try --port=3307\n");
    exit(1);
}

foreach ($only as $app) {
    $db = $dbs[$app];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        exit("Invalid database name: $db\n");
    }
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");
    $sql = file_get_contents($files[$app]);
    // Remove full-line comments, then split into statements at ";" + newline.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)), 'strlen');
    $count = 0;
    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
            $count++;
        } catch (PDOException $e) {
            fwrite(STDERR, "\nError in {$app} SQL: {$e->getMessage()}\nStatement starts with: " . substr($statement, 0, 160) . "\n");
            exit(1);
        }
    }
    echo "✓ {$app}: {$count} statements imported into `{$db}`\n";
}

echo "\nDone. Demo logins (password for all: kantina123)\n";
echo "  Student app ........ 21-0942\n";
echo "  Store console ...... stall-01 (Lola's Silog) … stall-04 (Grill Station)\n";
echo "  ERP / CRM admin .... admin\n";
if ($port !== 3306 || $user !== 'root' || $pass !== '' || $single) {
    echo "\nYour MySQL settings differ from the defaults — put them in a config.local.php in each app\n";
    echo "(copy kantina/config.sample.php, erp/config.sample.php, crm/config.sample.php).\n";
}
