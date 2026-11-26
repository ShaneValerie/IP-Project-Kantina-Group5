<?php
/**
 * Copies the shared library from kantina/api/lib to erp/api/lib and cms/api/lib.
 * Each app keeps its own copy so it can be deployed on its own server.
 *
 *   php tools/sync-lib.php          copy
 *   php tools/sync-lib.php --check  only report differences (exit 1 if any)
 */
$root = dirname(__DIR__);
$source = "$root/kantina/api/lib";
$check = in_array('--check', $argv, true);
$different = 0;

foreach (['erp', 'cms'] as $app) {
    $target = "$root/$app/api/lib";
    if (!is_dir($target) && !$check) {
        mkdir($target, 0777, true);
    }
    $files = glob("$source/*.php");
    if (is_file("$source/.htaccess")) {
        $files[] = "$source/.htaccess";
    }
    foreach ($files as $file) {
        $dest = "$target/" . basename($file);
        $same = is_file($dest) && sha1_file($dest) === sha1_file($file);
        if ($same) {
            continue;
        }
        $different++;
        if ($check) {
            echo "differs: $app/api/lib/" . basename($file) . "\n";
        } else {
            copy($file, $dest);
            echo "copied:  $app/api/lib/" . basename($file) . "\n";
        }
    }
}
echo $different === 0 ? "Library is in sync.\n" : ($check ? "$different file(s) out of sync.\n" : "Done.\n");
exit($check && $different ? 1 : 0);
