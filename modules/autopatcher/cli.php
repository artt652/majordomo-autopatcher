<?php
/**
 * AutoPatcher command line
 *
 *   php modules/autopatcher/cli.php status   - show state of patches and modules (no changes)
 *   php modules/autopatcher/cli.php apply    - apply pending patches / install missing modules now
 *
 * Exit code: 0 - ok, 1 - there are conflicts or errors, 2 - usage error
 */

if (PHP_SAPI !== 'cli') {
    // this file lives in a web-accessible folder: never run from HTTP
    header('HTTP/1.0 403 Forbidden');
    exit;
}

chdir(dirname(__FILE__) . '/../../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./load_settings.php");
include_once(DIR_MODULES . "autopatcher/autopatcher.class.php");

$command = isset($argv[1]) ? $argv[1] : 'status';
if (!in_array($command, array('status', 'apply'))) {
    echo "Usage: php " . $argv[0] . " status|apply" . PHP_EOL;
    exit(2);
}

$ap = new autopatcher();
$engine = $ap->getEngine();
$failed = 0;

if ($command == 'apply') {
    $summary = $ap->autoRun('cli', true);
    echo "Applied: " . (count($summary['applied']) ? implode(', ', $summary['applied']) : '-') . PHP_EOL;
    echo "Modules installed: " . (count($summary['packages']) ? implode(', ', $summary['packages']) : '-') . PHP_EOL;
    if ($summary['restart']) {
        echo "Service restart requested." . PHP_EOL;
    }
}

$ap->syncPatches();
echo PHP_EOL . "Patches (" . $engine->patchesDir() . "):" . PHP_EOL;
$rows = $ap->sortPatchRows(SQLSelect("SELECT * FROM autopatcher_patches"));
if (!count($rows)) {
    echo "  none" . PHP_EOL;
}
foreach ($rows as $rec) {
    $res = $engine->evaluate($engine->loadPatch($rec['FILENAME']));
    $status = $res['status'];
    if (!$rec['ENABLED']) {
        $status .= ' (disabled)';
    } elseif (in_array($res['status'], array(AutopatcherEngine::ST_CONFLICT, AutopatcherEngine::ST_ERROR))) {
        $failed++;
    }
    printf("  %-10s %s%s" . PHP_EOL, $status, $rec['FILENAME'], $rec['TITLE'] != $rec['FILENAME'] ? ' - ' . $rec['TITLE'] : '');
    if ($res['message'] != '') {
        echo "             " . str_replace("\n", "\n             ", $res['message']) . PHP_EOL;
    }
}

echo PHP_EOL . "Modules:" . PHP_EOL;
$packages = SQLSelect("SELECT * FROM autopatcher_packages ORDER BY NAME");
if (!count($packages)) {
    echo "  none" . PHP_EOL;
}
foreach ($packages as $rec) {
    $present = is_file(ROOT . $ap->packageCheckPath($rec));
    $cache = is_file($ap->packageArchive($rec['NAME']));
    printf("  %-10s %s  [%s, archive: %s]%s" . PHP_EOL,
        $present ? 'present' : 'MISSING', $rec['NAME'], $rec['STATUS'] != '' ? $rec['STATUS'] : '-',
        $cache ? 'yes' : 'no', $rec['ENABLED'] ? '' : ' (disabled)');
}

exit($failed ? 1 : 0);
