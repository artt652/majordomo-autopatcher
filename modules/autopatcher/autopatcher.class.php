<?php
/**
 * AutoPatcher
 *
 * Re-applies local patches to system files and (re)installs custom modules
 * after a MajorDoMo system update.
 *
 * Hook: cycle.php removes all cms/modules_installed/*.installed markers on every start
 * and control_modules re-runs install() of every module BEFORE cycle_*.php threads are
 * started. AutoPatcher does its work in install(), so no core file has to be modified.
 *
 * Data (survives system and module updates): cms/autopatcher/
 *   patches/   *.diff | *.patch | *.replace
 *   packages/  <name>.tgz  local archive cache (boot never goes to the network)
 *   backup/    originals of every changed file
 *
 * @package MajorDoMo
 * @version 1.0
 */

class autopatcher extends module
{
    public $id;
    public $engine = null;
    public $is_boot = false;
    public $runStage = '';
    // GitHub endpoints (properties so a test stand can point them to a mock server)
    public $githubApi = 'https://api.github.com';
    public $githubWeb = 'https://github.com';
    public $shutdownRegistered = false;

    function __construct()
    {
        $this->name = "autopatcher";
        $this->title = "AutoPatcher";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
    }

    function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        $out['VIEW_MODE'] = isset($this->view_mode) ? $this->view_mode : '';
        $out['MODE'] = isset($this->mode) ? $this->mode : '';
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    function usual(&$out)
    {
        // no frontend
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function dataDir()
    {
        return ROOT . 'cms/autopatcher/';
    }

    function getEngine()
    {
        if (!$this->engine) {
            include_once(DIR_MODULES . $this->name . '/autopatcher_engine.php');
            $this->engine = new AutopatcherEngine(ROOT, $this->dataDir(), function ($msg) {
                DebMes($msg, 'autopatcher');
                if ($this->is_boot) {
                    echo "AutoPatcher: " . $msg . PHP_EOL;
                }
            });
            if (defined('PATH_TO_PHP') && PATH_TO_PHP != '') {
                $this->engine->phpBinary = PATH_TO_PHP;
            }
            $this->engine->ensureDirs();
        }
        return $this->engine;
    }

    function loadConfig()
    {
        $this->getConfig();
        if (!is_array($this->config)) {
            $this->config = array();
        }
        return $this->config;
    }

    function cfg($key, $default)
    {
        if (!isset($this->config) || !is_array($this->config)) {
            $this->loadConfig();
        }
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    function esc($text)
    {
        return str_replace(array('[', ']', '#'), array('&#91;', '&#93;', '&#35;'), htmlspecialchars((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    function systemUpdateSignature()
    {
        return trim(getGlobal('LatestUpdateId') . '|' . getGlobal('LatestUpdateTimestamp'), '|');
    }

    function isWindows()
    {
        return function_exists('IsWindowsOS') ? IsWindowsOS() : (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    }

    // ------------------------------------------------------------------
    // Patches
    // ------------------------------------------------------------------

    /**
     * Keep DB rows in sync with files in cms/autopatcher/patches/
     */
    function syncPatches()
    {
        $engine = $this->getEngine();
        $files = $engine->listPatchFiles();
        $rows = SQLSelect("SELECT * FROM autopatcher_patches");
        $known = array();
        foreach ($rows as $row) {
            if (!in_array($row['FILENAME'], $files)) {
                SQLExec("DELETE FROM autopatcher_patches WHERE ID=" . (int)$row['ID']);
            } else {
                $known[$row['FILENAME']] = 1;
            }
        }
        foreach ($files as $file) {
            if (!isset($known[$file])) {
                $patch = $engine->loadPatch($file);
                $rec = array('FILENAME' => $file, 'TITLE' => mb_substr($patch['title'], 0, 255), 'ENABLED' => 1, 'STATUS' => '');
                SQLInsert('autopatcher_patches', $rec);
            }
        }
        return $files;
    }

    /**
     * Patches are applied in natural file name order (5-x, 10-y, 100-z)
     */
    function sortPatchRows($rows)
    {
        if (!is_array($rows)) {
            return array();
        }
        usort($rows, function ($a, $b) {
            return strnatcasecmp($a['FILENAME'], $b['FILENAME']);
        });
        return $rows;
    }

    /**
     * Order number of a patch = digits at the start of its file name ('' if none)
     */
    static function patchNumber($filename)
    {
        return preg_match('/^(\d+)/', $filename, $m) ? $m[1] : '';
    }

    /**
     * File name with another order number: 80-fix.diff -> 15-fix.diff, fix.diff -> 15-fix.diff
     */
    static function withNumber($filename, $num)
    {
        $rest = preg_replace('/^\d+[-_.]?/', '', $filename);
        return sprintf('%02d', (int)$num) . '-' . $rest;
    }

    /**
     * Rename a patch file and its DB row together: state, enabled flag and the
     * remembered checksums stay with the patch (a manual rename would lose them)
     * @return string '' or error
     */
    function renamePatch($rec, $new_name)
    {
        $engine = $this->getEngine();
        if ($new_name === $rec['FILENAME']) {
            return '';
        }
        if (!AutopatcherEngine::isSafeId($new_name)) {
            return LANG_AUTOPATCHER_BAD_FILENAME;
        }
        foreach ($engine->listPatchFiles() as $f) {
            if ($f !== $rec['FILENAME'] && strcasecmp($f, $new_name) == 0) {
                return LANG_AUTOPATCHER_FILE_EXISTS . ': ' . $f;
            }
        }
        $dir = $engine->patchesDir();
        $tmp = $rec['FILENAME'] . '.renaming';
        // two steps: also works for a case-only rename on case-insensitive file systems
        if (!@rename($dir . $rec['FILENAME'], $dir . $tmp) || !@rename($dir . $tmp, $dir . $new_name)) {
            @rename($dir . $tmp, $dir . $rec['FILENAME']);
            return 'Cannot rename ' . $rec['FILENAME'];
        }
        SQLExec("UPDATE autopatcher_patches SET FILENAME='" . DBSafe($new_name) . "' WHERE ID=" . (int)$rec['ID']);
        DebMes("Patch renamed: " . $rec['FILENAME'] . " -> " . $new_name, 'autopatcher');
        return '';
    }

    /**
     * Renumber all patches 10, 20, 30 ... in the given order
     * @param array $rows patch rows in the wanted order
     */
    function renumberPatches($rows)
    {
        $width = count($rows) * 10 >= 100 ? 3 : 2;
        // first move everything to temporary names, so new names never collide with old ones
        $plan = array();
        foreach (array_values($rows) as $i => $row) {
            $rest = preg_replace('/^\d+[-_.]?/', '', $row['FILENAME']);
            $plan[] = array('rec' => $row, 'final' => sprintf('%0' . $width . 'd', ($i + 1) * 10) . '-' . $rest);
        }
        foreach ($plan as &$item) {
            if ($item['final'] === $item['rec']['FILENAME']) {
                continue;
            }
            $tmp = '_tmp' . $item['rec']['ID'] . '-' . $item['final'];
            if ($this->renamePatch($item['rec'], $tmp) === '') {
                $item['rec']['FILENAME'] = $tmp;
            }
        }
        unset($item);
        foreach ($plan as $item) {
            $this->renamePatch($item['rec'], $item['final']);
        }
    }

    /**
     * Swap a patch with its neighbour in the apply order
     * @param int $dir -1 up, +1 down
     */
    function movePatch($id, $dir)
    {
        $rows = $this->sortPatchRows(SQLSelect("SELECT ID, FILENAME FROM autopatcher_patches"));
        $pos = -1;
        foreach ($rows as $i => $row) {
            if ((int)$row['ID'] == (int)$id) {
                $pos = $i;
            }
        }
        $other = $pos + $dir;
        if ($pos < 0 || $other < 0 || $other >= count($rows)) {
            return;
        }
        $a = $rows[$pos];
        $b = $rows[$other];
        $na = self::patchNumber($a['FILENAME']);
        $nb = self::patchNumber($b['FILENAME']);
        if ($na !== '' && $nb !== '' && (int)$na != (int)$nb) {
            // swap the numbers via a temporary name
            $tmp = '_tmp' . $a['ID'] . '-' . $a['FILENAME'];
            if ($this->renamePatch($a, $tmp) === '') {
                $a['FILENAME'] = $tmp;
                $this->renamePatch($b, self::withNumber($b['FILENAME'], $na));
                $this->renamePatch($a, self::withNumber(preg_replace('/^_tmp\d+-/', '', $tmp), $nb));
            }
            return;
        }
        // equal or missing numbers: renumber the whole list with the two swapped
        $rows[$pos] = $b;
        $rows[$other] = $a;
        $this->renumberPatches($rows);
    }

    /**
     * Evaluate / apply one patch and store the result
     */
    function processPatch($rec, $action = 'check')
    {
        $engine = $this->getEngine();
        $patch = $engine->loadPatch($rec['FILENAME']);
        $before = array();
        if ($action == 'apply' || $action == 'revert') {
            foreach (array_keys($patch['files']) as $path) {
                $before[$path] = is_file(ROOT . $path) ? md5_file(ROOT . $path) : '';
            }
        }
        if ($action == 'apply') {
            $res = $engine->apply($patch);
        } elseif ($action == 'revert') {
            $res = $engine->apply($patch, true);
            if ($res['status'] == 'reverted' || $res['status'] == AutopatcherEngine::ST_APPLIED) {
                // re-evaluate in forward direction to show the real state
                $res['status'] = $engine->evaluate($patch)['status'];
            }
        } else {
            $res = $engine->apply($patch, false, true);
        }

        $old_status = $rec['STATUS'];
        $rec['TITLE'] = mb_substr($patch['title'], 0, 255);
        $rec['STATUS'] = $res['status'];
        $rec['MESSAGE'] = $res['message'];
        $rec['CHECKED'] = date('Y-m-d H:i:s');
        if ($action == 'apply' && count($res['changed'])) {
            $rec['APPLIED'] = date('Y-m-d H:i:s');
        }
        if (count($res['changed'])) {
            $after = array();
            foreach ($res['changed'] as $path) {
                $after[$path] = is_file(ROOT . $path) ? md5_file(ROOT . $path) : '';
            }
            // other patches applied by AutoPatcher to the same files keep their ownership
            $this->moveAppliedHashes((int)$rec['ID'], $before, $after);
            if ($action == 'apply') {
                // remember what AutoPatcher itself wrote: a later delete may revert it safely
                $rec['APPLIED_HASH'] = json_encode($after);
            } else {
                $rec['APPLIED_HASH'] = '';
            }
        }
        SQLUpdate('autopatcher_patches', $rec);

        if (in_array($rec['STATUS'], array(AutopatcherEngine::ST_CONFLICT, AutopatcherEngine::ST_ERROR)) && $old_status != $rec['STATUS']) {
            DebMes("Patch " . $rec['FILENAME'] . " " . $rec['STATUS'] . ": " . $rec['MESSAGE'], 'autopatcher');
            if (function_exists('registerError')) {
                registerError('autopatcher', "Patch " . $rec['FILENAME'] . " " . $rec['STATUS'] . ":\n" . $rec['MESSAGE']);
            }
        }
        return $res;
    }

    /**
     * A file changed by AutoPatcher: patches whose remembered checksum matched the old
     * content get the new one (their change is still there, made by us)
     */
    function moveAppliedHashes($except_id, $before, $after)
    {
        $rows = SQLSelect("SELECT ID, APPLIED_HASH FROM autopatcher_patches WHERE APPLIED_HASH!='' AND ID!=" . (int)$except_id);
        foreach ($rows as $row) {
            $h = json_decode($row['APPLIED_HASH'], true);
            if (!is_array($h)) {
                continue;
            }
            $changed = false;
            foreach ($after as $path => $md5) {
                if (isset($h[$path]) && isset($before[$path]) && $h[$path] === $before[$path]) {
                    $h[$path] = $md5;
                    $changed = true;
                }
            }
            if ($changed) {
                SQLExec("UPDATE autopatcher_patches SET APPLIED_HASH='" . DBSafe(json_encode($h)) . "' WHERE ID=" . (int)$row['ID']);
            }
        }
    }

    /**
     * True only if every file of the patch is exactly as AutoPatcher left it after applying
     * this patch. Otherwise the "applied" state may come from upstream (the fix was merged)
     * or the file was replaced by an update / module, and reverting could cut native code.
     */
    function appliedByUs($rec, $patch)
    {
        $h = !empty($rec['APPLIED_HASH']) ? json_decode($rec['APPLIED_HASH'], true) : null;
        if (!is_array($h) || !count($h)) {
            return false;
        }
        foreach (array_keys($patch['files']) as $path) {
            if (!isset($h[$path]) || !is_file(ROOT . $path) || md5_file(ROOT . $path) !== $h[$path]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Patches that are applied now and would stop being applied if $patch is applied/reverted:
     * they change the same lines (e.g. a cumulative git diff that already contains them).
     * @return array FILENAME => ID
     */
    function affectedPatches($patch, $reverse)
    {
        $engine = $this->getEngine();
        $eval = $engine->evaluate($patch, $reverse);
        if ($eval['status'] != AutopatcherEngine::ST_PENDING) {
            return array();
        }
        $overrides = array();
        foreach ($eval['files'] as $path => $f) {
            if ($f['status'] == AutopatcherEngine::ST_PENDING) {
                $overrides[$path] = $f['new'];
            }
        }
        $affected = array();
        $rows = SQLSelect("SELECT ID, FILENAME FROM autopatcher_patches WHERE ENABLED=1 AND FILENAME!='" . DBSafe($patch['id']) . "' ORDER BY FILENAME");
        foreach ($rows as $row) {
            $other = $engine->loadPatch($row['FILENAME']);
            if ($other['error'] != '' || !count(array_intersect(array_keys($other['files']), array_keys($overrides)))) {
                continue;
            }
            if ($engine->evaluate($other)['status'] != AutopatcherEngine::ST_APPLIED) {
                continue;
            }
            if ($engine->evaluate($other, false, $overrides)['status'] != AutopatcherEngine::ST_APPLIED) {
                $affected[$row['FILENAME']] = (int)$row['ID'];
            }
        }
        return $affected;
    }

    // ------------------------------------------------------------------
    // Packages (custom modules)
    // ------------------------------------------------------------------

    function packageArchive($name)
    {
        return $this->dataDir() . 'packages/' . $name . '.tgz';
    }

    function packageCheckPath($rec)
    {
        $path = trim($rec['CHECK_PATH']);
        if ($path == '') {
            $path = 'modules/' . $rec['NAME'] . '/' . $rec['NAME'] . '.class.php';
        }
        return $path;
    }

    function packageUrl($rec)
    {
        $url = trim($rec['URL']);
        if ($url == '') {
            $plugin = SQLSelectOne("SELECT REPOSITORY_URL FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($rec['NAME']) . "'");
            if (!empty($plugin['REPOSITORY_URL'])) {
                $url = $plugin['REPOSITORY_URL'];
            }
        }
        return $url;
    }

    function setPackageStatus(&$rec, $status, $message = '')
    {
        $rec['STATUS'] = $status;
        $rec['MESSAGE'] = $message;
        SQLUpdate('autopatcher_packages', $rec);
    }

    /**
     * Download package archive into local cache (manual action only)
     */
    /**
     * Download $url into $target (.tar.gz only).
     * @param bool $quick short timeouts (system start): an unreachable or stalled server
     *                    must not delay the boot for minutes
     * @return string '' on success, error text otherwise
     */
    function downloadFile($url, $target, $quick = false)
    {
        $tmp = $target . '.download';
        @unlink($tmp);
        if (preg_match('/\?op=download/', $url)) {
            if ($quick) {
                // Connect downloader has no short timeouts (and connect.majordomohome.com may hang)
                return 'MajorDoMo Connect archive: download it manually (button Download)';
            }
            // MajorDoMo Connect market: reuse market downloader (adds Connect credentials)
            include_once(DIR_MODULES . 'market/market.class.php');
            $mkt = new market();
            $mkt->downloadPlugin($url, $tmp, 0);
        } else {
            $f = @fopen($tmp, 'wb');
            if (!$f) {
                return 'Cannot write ' . str_replace(ROOT, '', $tmp);
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $quick ? 5 : 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, $quick ? 120 : 300);
            // abort a stalled transfer (TLS hang behind a blocked CDN) instead of waiting for TIMEOUT
            curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 512);
            curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, $quick ? 10 : 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FILE, $f);
            if (defined('USE_PROXY') && USE_PROXY != '') {
                curl_setopt($ch, CURLOPT_PROXY, USE_PROXY);
                if (defined('USE_PROXY_AUTH') && USE_PROXY_AUTH != '') {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, USE_PROXY_AUTH);
                }
            }
            curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($f);
            if ($err != '' || $code >= 400) {
                @unlink($tmp);
                return 'Download failed: ' . ($err != '' ? $err : 'HTTP ' . $code);
            }
        }
        if (!is_file($tmp) || filesize($tmp) == 0 || file_get_contents($tmp, false, null, 0, 2) !== "\x1f\x8b") {
            @unlink($tmp);
            return 'Download failed or not a .tar.gz archive: ' . $url;
        }
        @unlink($target);
        rename($tmp, $target);
        return '';
    }

    /**
     * Recognize GitHub links:
     *   github.com/O/R(.git)                       -> default branch
     *   github.com/O/R/tree/BRANCH                 -> branch
     *   github.com/O/R/archive/refs/heads/B.tar.gz -> branch
     *   github.com/O/R/archive/refs/tags/T.tar.gz  -> tag (updates = latest release)
     *   github.com/O/R/archive/REF.tar.gz|.zip     -> branch, tag or commit
     *   codeload.github.com/O/R/tar.gz/[refs/heads/|refs/tags/]REF
     * @return array|false ['owner','repo','ref','kind' => default|branch|tag|ref]
     */
    function parseGithub($url)
    {
        $url = trim($url);
        $o = '([A-Za-z0-9_.\-]+)';
        if (preg_match('~^https?://(?:www\.)?github\.com/' . $o . '/' . $o . '/archive/refs/(heads|tags)/(.+?)\.(?:tar\.gz|tgz|zip)$~i', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2], 'ref' => $m[4], 'kind' => $m[3] == 'tags' ? 'tag' : 'branch');
        }
        if (preg_match('~^https?://(?:www\.)?github\.com/' . $o . '/' . $o . '/archive/(.+?)\.(?:tar\.gz|tgz|zip)$~i', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2], 'ref' => $m[3], 'kind' => 'ref');
        }
        if (preg_match('~^https?://codeload\.github\.com/' . $o . '/' . $o . '/(?:tar\.gz|zip|legacy\.tar\.gz)/(?:refs/(heads|tags)/)?(.+)$~i', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2], 'ref' => $m[4], 'kind' => $m[3] == 'tags' ? 'tag' : ($m[3] == 'heads' ? 'branch' : 'ref'));
        }
        if (preg_match('~^https?://(?:www\.)?github\.com/' . $o . '/' . $o . '/tree/(.+?)/?$~i', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2], 'ref' => $m[3], 'kind' => 'branch');
        }
        if (preg_match('~^https?://(?:www\.)?github\.com/' . $o . '/' . $o . '/?$~i', $url, $m)) {
            return array('owner' => $m[1], 'repo' => preg_replace('/\.git$/i', '', $m[2]), 'ref' => 'HEAD', 'kind' => 'default');
        }
        return false;
    }

    function githubArchiveUrl($gh)
    {
        $base = $this->githubWeb . '/' . $gh['owner'] . '/' . $gh['repo'] . '/archive/';
        if ($gh['kind'] == 'tag') {
            return $base . 'refs/tags/' . $gh['ref'] . '.tar.gz';
        }
        if ($gh['kind'] == 'branch') {
            return $base . 'refs/heads/' . $gh['ref'] . '.tar.gz';
        }
        return $base . $gh['ref'] . '.tar.gz';
    }

    /**
     * Small HTTP request with short timeouts (update checks run in a background request)
     * @return array [code, body, headers(lowercase name => value)]
     */
    function httpQuick($url, $headers = array(), $head = false)
    {
        $resp_headers = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 64);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('User-Agent: MajorDoMo-AutoPatcher'), $headers));
        if ($head) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$resp_headers) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $resp_headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        });
        if (defined('USE_PROXY') && USE_PROXY != '') {
            curl_setopt($ch, CURLOPT_PROXY, USE_PROXY);
            if (defined('USE_PROXY_AUTH') && USE_PROXY_AUTH != '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, USE_PROXY_AUTH);
            }
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return array($err != '' ? 0 : $code, (string)$body, $resp_headers, $err);
    }

    /**
     * Latest available version of the package and the URL of exactly that version.
     * GitHub branch: commit SHA (archive pinned to the commit); GitHub tag: latest release tag;
     * other servers: ETag / Last-Modified.
     * @return array ['version' => '', 'url' => '', 'label' => '', 'error' => '']
     */
    function remoteVersion($url)
    {
        $res = array('version' => '', 'url' => $url, 'label' => '', 'error' => '');
        $gh = $this->parseGithub($url);
        if ($gh) {
            $api = $this->githubApi . '/repos/' . $gh['owner'] . '/' . $gh['repo'];
            if ($gh['kind'] == 'tag') {
                list($code, $body) = $this->httpQuick($api . '/releases/latest', array('Accept: application/vnd.github+json'));
                $data = json_decode($body, true);
                if ($code == 200 && !empty($data['tag_name'])) {
                    $tag = $data['tag_name'];
                } elseif ($code == 404) {
                    $tag = $gh['ref']; // no releases: stay on the given tag
                } else {
                    $res['error'] = 'GitHub API: ' . ($code ? 'HTTP ' . $code : 'no connection') . (isset($data['message']) ? ' ' . $data['message'] : '');
                    return $res;
                }
                $res['version'] = 'tag:' . $tag;
                $res['label'] = $tag;
                $res['url'] = $this->githubWeb . '/' . $gh['owner'] . '/' . $gh['repo'] . '/archive/refs/tags/' . $tag . '.tar.gz';
                return $res;
            }
            $ref = $gh['ref'];
            list($code, $body) = $this->httpQuick($api . '/commits/' . rawurlencode($ref), array('Accept: application/vnd.github.sha'));
            $sha = trim($body);
            if ($gh['kind'] == 'default' && !($code == 200 && preg_match('/^[0-9a-f]{40}$/', $sha))) {
                // fallback: ask for the default branch name
                list($c2, $b2) = $this->httpQuick($api, array('Accept: application/vnd.github+json'));
                $repo = json_decode($b2, true);
                if ($c2 == 200 && !empty($repo['default_branch'])) {
                    $ref = $repo['default_branch'];
                    list($code, $body) = $this->httpQuick($api . '/commits/' . rawurlencode($ref), array('Accept: application/vnd.github.sha'));
                    $sha = trim($body);
                }
            }
            if ($code == 200 && preg_match('/^[0-9a-f]{40}$/', $sha)) {
                $res['version'] = $sha;
                $res['label'] = substr($sha, 0, 7) . ($ref != 'HEAD' && !preg_match('/^[0-9a-f]{7,40}$/', $ref) ? ' (' . $ref . ')' : '');
                $res['url'] = $this->githubWeb . '/' . $gh['owner'] . '/' . $gh['repo'] . '/archive/' . $sha . '.tar.gz';
                return $res;
            }
            $data = json_decode($body, true);
            $res['error'] = 'GitHub API: ' . ($code ? 'HTTP ' . $code : 'no connection') . (isset($data['message']) ? ' ' . $data['message'] : '');
            // still downloadable without the API
            $res['url'] = $this->githubArchiveUrl($gh);
            return $res;
        }
        if (preg_match('/\?op=download/', $url)) {
            $res['error'] = 'MajorDoMo Connect: updates are checked by Market';
            return $res;
        }
        list($code, $body, $h, $err) = $this->httpQuick($url, array(), true);
        if ($code >= 200 && $code < 400) {
            if (!empty($h['etag'])) {
                $res['version'] = 'etag:' . trim($h['etag'], 'W/"');
            } elseif (!empty($h['last-modified'])) {
                $res['version'] = 'lm:' . $h['last-modified'];
            }
            $res['label'] = substr(preg_replace('/^(etag|lm):/', '', $res['version']), 0, 25);
            if ($res['version'] == '') {
                $res['error'] = 'Server does not report a version (no ETag / Last-Modified)';
            }
        } else {
            $res['error'] = $code ? 'HTTP ' . $code : ('No connection' . ($err != '' ? ': ' . $err : ''));
        }
        return $res;
    }

    function versionLabel($version)
    {
        if ($version == '') {
            return '';
        }
        if (preg_match('/^[0-9a-f]{40}$/', $version)) {
            return substr($version, 0, 7);
        }
        return substr(preg_replace('/^(tag|etag|lm):/', '', $version), 0, 25);
    }

    /**
     * Download package archive into local cache
     */
    function downloadPackage(&$rec, $quick = false)
    {
        $url = $this->packageUrl($rec);
        if ($url == '') {
            $this->setPackageStatus($rec, 'error', 'No URL');
            return false;
        }
        $target = $this->packageArchive($rec['NAME']);
        // at system start: no extra API request, plain download
        $rv = $quick ? array('version' => '', 'url' => $url, 'label' => '', 'error' => '') : $this->remoteVersion($url);
        $dl = $rv['url'];
        if ($quick && ($gh = $this->parseGithub($url))) {
            $dl = $this->githubArchiveUrl($gh);
        }
        $err = $this->downloadFile($dl, $target, $quick);
        if ($err != '') {
            $this->setPackageStatus($rec, (strpos($err, 'Connect') !== false) ? 'no_cache' : 'error', $err);
            return false;
        }
        $rec['CACHED_SHA'] = $rv['version'];
        if (!empty($rv['version']) && $rv['version'] != (isset($rec['LATEST_SHA']) ? $rec['LATEST_SHA'] : '')) {
            $rec['LATEST_SHA'] = $rv['version'];
        }
        $rec['CACHED'] = date('Y-m-d H:i:s');
        $this->setPackageStatus($rec, 'cached', basename($target) . ', ' . round(filesize($target) / 1024) . ' KB');
        return $this->finalizeAutoName($rec);
    }

    /**
     * Temporary record name from a URL: github.com/user/majordomo-tinyfm -> tinyfm
     */
    function guessNameFromUrl($url)
    {
        $gh = $this->parseGithub($url);
        if ($gh) {
            $name = $gh['repo'];
        } else {
            $path = parse_url($url, PHP_URL_PATH);
            $name = preg_replace('/\.(tar\.gz|tgz|zip)$/i', '', basename((string)$path));
        }
        $name = strtolower(preg_replace('/^(majordomo|mdm)[-_]/i', '', $name));
        $name = trim(preg_replace('/[^a-z0-9_]+/', '_', $name), '_');
        return $name != '' ? $name : 'module';
    }

    function uniqueName($name, $except_id = 0)
    {
        $base = $name;
        for ($i = 2; ; $i++) {
            $dup = SQLSelectOne("SELECT ID FROM autopatcher_packages WHERE NAME='" . DBSafe($name) . "'" . ($except_id ? " AND ID!=" . (int)$except_id : ''));
            if (empty($dup['ID']) && $name != $this->name) {
                return $name;
            }
            $name = $base . '_' . $i;
        }
    }

    /**
     * Name was not typed by the user: take the real one from the downloaded archive
     * @return bool false if the name could not be confirmed
     */
    function finalizeAutoName(&$rec)
    {
        if (empty($rec['NAME_AUTO'])) {
            return true;
        }
        $archive = $this->packageArchive($rec['NAME']);
        if (!is_file($archive)) {
            return false;
        }
        $det = $this->detectPackage($archive);
        if ($det['name'] == '') {
            $rec['NAME_AUTO'] = 0;
            $this->setPackageStatus($rec, 'error', 'Module name not detected' . ($det['error'] != '' ? ': ' . $det['error'] : '') . ' - set it in the module settings');
            return false;
        }
        if ($det['name'] != $rec['NAME']) {
            $dup = SQLSelectOne("SELECT ID FROM autopatcher_packages WHERE NAME='" . DBSafe($det['name']) . "' AND ID!=" . (int)$rec['ID']);
            if (!empty($dup['ID']) || $det['name'] == $this->name) {
                $rec['NAME_AUTO'] = 0;
                $this->setPackageStatus($rec, 'error', 'Module ' . $det['name'] . ' is already in the list');
                return false;
            }
            $new_archive = $this->packageArchive($det['name']);
            @unlink($new_archive);
            @rename($archive, $new_archive);
            DebMes("Package " . $rec['NAME'] . " renamed to " . $det['name'] . " (from archive)", 'autopatcher');
            $rec['NAME'] = $det['name'];
            if ($rec['STATUS'] == 'cached' && is_file($new_archive)) {
                $rec['MESSAGE'] = basename($new_archive) . ', ' . round(filesize($new_archive) / 1024) . ' KB';
            }
        }
        if ($rec['TITLE'] == '' && $det['title'] != '') {
            $rec['TITLE'] = $det['title'];
        }
        $rec['NAME_AUTO'] = 0;
        SQLUpdate('autopatcher_packages', $rec);
        return true;
    }

    /**
     * Download in a background HTTP request (same mechanism as HOURLY/DAILY events),
     * so the form is saved immediately
     */
    function startBackgroundDownload(&$rec)
    {
        $rec['DL_STARTED'] = date('Y-m-d H:i:s');
        $this->setPackageStatus($rec, 'downloading', '');
        if (function_exists('processSubscriptionsSafe')) {
            processSubscriptionsSafe('AUTOPATCHER_DOWNLOAD', array('id' => (int)$rec['ID']));
        }
    }

    function backgroundDownload($id)
    {
        $rec = SQLSelectOne("SELECT * FROM autopatcher_packages WHERE ID=" . (int)$id);
        if (empty($rec['ID'])) {
            return;
        }
        DebMes("Background download of " . $rec['NAME'] . " started", 'autopatcher');
        if ($this->downloadPackage($rec)) {
            DebMes("Background download of " . $rec['NAME'] . " done", 'autopatcher');
        } else {
            DebMes("Background download of " . $rec['NAME'] . " failed: " . $rec['MESSAGE'], 'autopatcher');
        }
    }

    /**
     * Find the module inside an archive: modules/<name>/<name>.class.php
     * @return array ['name' => '', 'title' => '', 'error' => '']
     */
    function detectPackage($archive)
    {
        $res = array('name' => '', 'title' => '', 'error' => '');
        $engine = $this->getEngine();
        $tmp = $this->dataDir() . 'tmp/_detect_' . getmypid();
        $engine->removeDir($tmp);
        @umask(0);
        @mkdir($tmp, 0777, true);
        if (!$this->extractArchive($archive, $tmp)) {
            $engine->removeDir($tmp);
            $res['error'] = 'Unpack failed';
            return $res;
        }
        $root = $tmp;
        $entries = array_values(array_diff(scandir($tmp), array('.', '..')));
        if (count($entries) == 1 && is_dir($tmp . '/' . $entries[0])) {
            $root = $tmp . '/' . $entries[0];
        }
        $found = array();
        if (is_dir($root . '/modules')) {
            foreach (scandir($root . '/modules') as $dir) {
                if ($dir != '.' && $dir != '..' && preg_match('/^[a-z0-9_]+$/i', $dir) && is_file($root . '/modules/' . $dir . '/' . $dir . '.class.php')) {
                    $found[] = $dir;
                }
            }
        }
        if (count($found) == 1) {
            $res['name'] = strtolower($found[0]);
            $code = (string)file_get_contents($root . '/modules/' . $found[0] . '/' . $found[0] . '.class.php');
            if (preg_match('/\$this->title\s*=\s*([\'"])(.*?)\1\s*;/', $code, $m) && strpos($m[2], '<#') === false && strpos($m[2], '$') === false) {
                $res['title'] = $m[2];
            }
        } elseif (count($found) > 1) {
            $res['error'] = 'Several modules in archive: ' . implode(', ', $found);
        } else {
            $res['error'] = 'No modules/<name>/<name>.class.php in archive';
        }
        $engine->removeDir($tmp);
        return $res;
    }

    function extractArchive($archive, $dir)
    {
        $ok = false;
        if (!$this->isWindows() && function_exists('exec')) {
            $output = array();
            $code = 1;
            @exec('tar xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $output, $code);
            $ok = ($code === 0);
        }
        if (!$ok && class_exists('PharData')) {
            try {
                $phar = new PharData($archive);
                $ok = $phar->extractTo($dir, null, true);
            } catch (\Throwable $e) {
                DebMes("Extract failed: " . $e->getMessage(), 'autopatcher');
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Install package from local cache, same way market does it
     */
    function packageLogFile($name)
    {
        return $this->dataDir() . 'packages/' . $name . '.log';
    }

    /**
     * One installation step: kept for the install log page, the debug log and the boot console
     */
    function packageStep(&$steps, $name, $message)
    {
        $line = date('H:i:s') . ' ' . $message;
        $steps[] = $line;
        // written step by step: if install crashes halfway, the log shows where
        @file_put_contents($this->packageLogFile($name), $line . "\n", FILE_APPEND);
        DebMes("[$name] " . $message, 'autopatcher');
        if ($this->is_boot) {
            echo "AutoPatcher [$name]: " . $message . PHP_EOL;
        }
    }

    function finishPackage(&$rec, $status, $message, $steps)
    {
        $this->setPackageStatus($rec, $status, $message);
    }

    /**
     * Install package from local cache, same way market does it
     * @param string $reason missing|system_updated|manual
     */
    function installPackage(&$rec, $reason = 'manual')
    {
        $name = $rec['NAME'];
        $steps = array();
        $rec['LAST_REASON'] = $reason;
        $rec['FILES'] = 0;
        @file_put_contents($this->packageLogFile($name), date('Y-m-d H:i:s') . ' (' . ($this->is_boot ? 'boot' : PHP_SAPI) . ")\n");
        $this->packageStep($steps, $name, "Install started, reason: $reason"
            . (!empty($rec['CHECK_DETAILS']) ? ' (' . $rec['CHECK_DETAILS'] . ')' : ''));
        unset($rec['CHECK_DETAILS']);

        $archive = $this->packageArchive($name);
        if (!is_file($archive)) {
            $url = $this->packageUrl($rec);
            if ($url == '') {
                $this->packageStep($steps, $name, "No local archive " . str_replace(ROOT, '', $archive) . " and no URL - upload the archive or set the URL");
                $this->finishPackage($rec, 'no_cache', 'No local archive and no URL', $steps);
                return false;
            }
            $this->packageStep($steps, $name, "No local archive, downloading " . $url . ($this->is_boot ? ' (short timeouts at system start)' : ''));
            if (!$this->downloadPackage($rec, $this->is_boot)) {
                $this->packageStep($steps, $name, $rec['MESSAGE']);
                $this->finishPackage($rec, $rec['STATUS'] == 'no_cache' ? 'no_cache' : 'error', $rec['MESSAGE'], $steps);
                return false;
            }
            $this->packageStep($steps, $name, "Downloaded");
            if ($rec['NAME'] != $name) {
                $this->packageStep($steps, $name, "Module name from archive: " . $rec['NAME']);
                $name = $rec['NAME'];
                $archive = $this->packageArchive($name);
                @file_put_contents($this->packageLogFile($name), implode("\n", $steps) . "\n");
            }
        } elseif (!empty($rec['NAME_AUTO']) && !$this->finalizeAutoName($rec)) {
            $this->packageStep($steps, $name, $rec['MESSAGE']);
            return false;
        } elseif ($rec['NAME'] != $name) {
            $this->packageStep($steps, $name, "Module name from archive: " . $rec['NAME']);
            $name = $rec['NAME'];
            $archive = $this->packageArchive($name);
        }
        $this->packageStep($steps, $name, "Archive: " . basename($archive) . ', ' . round(filesize($archive) / 1024) . ' KB, ' . date('Y-m-d H:i', filemtime($archive)));

        $engine = $this->getEngine();
        $tmp = $this->dataDir() . 'tmp/' . $name;
        $engine->removeDir($tmp);
        @umask(0);
        @mkdir($tmp, 0777, true);
        if (!$this->extractArchive($archive, $tmp)) {
            $engine->removeDir($tmp);
            $this->packageStep($steps, $name, "Unpack failed");
            $this->finishPackage($rec, 'error', 'Unpack failed: ' . basename($archive), $steps);
            return false;
        }
        $folder = $tmp;
        $entries = array_values(array_diff(scandir($tmp), array('.', '..')));
        if (count($entries) == 1 && is_dir($tmp . '/' . $entries[0])) {
            $folder = $tmp . '/' . $entries[0];
        }
        $this->packageStep($steps, $name, "Unpacked, package root: " . ($folder == $tmp ? '/' : $entries[0] . '/'));
        @unlink($folder . '/config.php');
        @unlink($folder . '/README.md');

        include_once(DIR_MODULES . 'market/market.class.php');
        $mkt = new market();
        $cwd = getcwd();
        $files_list = $mkt->installUnpacketPlugin($folder, $name);
        if ($cwd) {
            @chdir($cwd);
        }
        $engine->removeDir($tmp);
        $files = array_values(array_filter(array_map('trim', explode("\n", (string)$files_list))));
        $rec['FILES'] = count($files);
        if ($files_list != '') {
            SaveFile(ROOT . 'cms/modules_installed/' . $name . '.files', $files_list);
        }
        $this->packageStep($steps, $name, "Files copied: " . count($files));
        $list = '';
        foreach ($files as $file) {
            $list .= '    ' . str_replace(ROOT, '', $file) . "\n";
        }
        @file_put_contents($this->packageLogFile($name), $list, FILE_APPEND);
        @unlink(ROOT . 'cms/modules_installed/' . $name . '.installed');
        @unlink(ROOT . 'cms/modules_installed/control_modules.installed');

        // run module install() now if its class is not loaded in this process yet
        $class_file = DIR_MODULES . $name . '/' . $name . '.class.php';
        if (is_file($class_file) && preg_match('/^[a-z0-9_]+$/i', $name)) {
            if (class_exists($name, false)) {
                $this->packageStep($steps, $name, "Module class already loaded in this process, install() will run on next start");
            } else {
                try {
                    include_once($class_file);
                    if (class_exists($name, false)) {
                        new $name();
                        $this->packageStep($steps, $name, "Module install() done");
                    }
                } catch (\Throwable $e) {
                    $this->packageStep($steps, $name, "Module install() failed: " . $e->getMessage());
                }
            }
        }
        if (!$this->is_boot) {
            $mkt->checkIfCycleRestartRequired($name);
        }

        $rec['INSTALLED'] = date('Y-m-d H:i:s');
        $rec['INSTALLED_SHA'] = isset($rec['CACHED_SHA']) ? $rec['CACHED_SHA'] : '';
        if (!is_file(ROOT . $this->packageCheckPath($rec))) {
            // otherwise the module would be reinstalled on every start
            $msg = 'Installed marker not found after install: ' . $this->packageCheckPath($rec);
            $this->packageStep($steps, $name, $msg . " - fix the marker path in module settings");
            $this->finishPackage($rec, 'check_missing', $msg, $steps);
            return false;
        }
        $this->packageStep($steps, $name, "Checksums saved: " . $this->savePackageBaseline($name, $files) . " files (used to detect overwritten files on next starts)");
        $this->packageStep($steps, $name, "Installed");
        $this->finishPackage($rec, 'installed', '', $steps);
        if (!$this->is_boot && function_exists('logAction') && isset($_SERVER['REQUEST_URI'])) {
            logAction('autopatcher_install', $name . ' (' . $reason . ', ' . count($files) . ' files)');
        }
        return true;
    }

    function packageBaselineFile($name)
    {
        return $this->dataDir() . 'packages/' . $name . '.baseline.json';
    }

    /**
     * Remember checksums of installed files right after installation
     * @param array $files absolute paths returned by market::installUnpacketPlugin()
     */
    function savePackageBaseline($name, $files)
    {
        $data = array('time' => date('Y-m-d H:i:s'), 'files' => array());
        foreach ($files as $file) {
            $rel = str_replace(str_replace('\\', '/', ROOT), '', str_replace('\\', '/', $file));
            if (is_file(ROOT . $rel)) {
                $data['files'][$rel] = md5_file(ROOT . $rel);
            }
        }
        @file_put_contents($this->packageBaselineFile($name), json_encode($data));
        return count($data['files']);
    }

    /**
     * @return array|false list of changed/missing files, false if there is no baseline yet
     */
    function packageChangedFiles($name)
    {
        $file = $this->packageBaselineFile($name);
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return false;
        }
        $changed = array();
        foreach ($data['files'] as $rel => $md5) {
            if (!is_file(ROOT . $rel)) {
                $changed[] = $rel . ' (missing)';
            } elseif (md5_file(ROOT . $rel) !== $md5) {
                $changed[] = $rel;
            }
        }
        return $changed;
    }

    /**
     * Decide whether the package must be (re)installed now.
     * Works like mdm_loaders: compares the real state of files, so it does not matter
     * how the system was updated (saverestore, git pull, market "update all", manual copy).
     * @return string '' or reason: missing | files_changed | no_baseline | system_updated
     */
    function packageNeedsInstall(&$rec, $system_updated)
    {
        $reason = '';
        $details = '';
        if (!is_file(ROOT . $this->packageCheckPath($rec))) {
            $reason = 'missing';
            $details = $this->packageCheckPath($rec);
        } elseif ((int)$rec['MODE'] == 1) {
            $changed = $this->packageChangedFiles($rec['NAME']);
            if ($changed === false) {
                // installed before checksums were kept (or by market): one sync install
                $reason = is_file($this->packageArchive($rec['NAME'])) ? 'no_baseline' : '';
                if ($reason == '' && $system_updated) {
                    $reason = 'system_updated';
                }
            } elseif (count($changed)) {
                $reason = 'files_changed';
                $details = count($changed) . ': ' . implode(', ', array_slice($changed, 0, 5)) . (count($changed) > 5 ? ', ...' : '');
            }
        }
        $rec['LAST_CHECK'] = $reason == '' ? 'ok' : $reason;
        $rec['CHECKED'] = date('Y-m-d H:i:s');
        SQLUpdate('autopatcher_packages', $rec);
        DebMes("Check " . $rec['NAME'] . ": " . $rec['LAST_CHECK'] . ($details != '' ? " ($details)" : ''), 'autopatcher');
        $rec['CHECK_DETAILS'] = $details;
        return $reason;
    }

    /**
     * Check one package for an update; install it when UPDATE_MODE = 2 (or $install)
     * @return string '' | available | updated | error
     */
    function checkPackageUpdate(&$rec, $install = false)
    {
        $url = $this->packageUrl($rec);
        $rec['UPDATE_CHECKED'] = date('Y-m-d H:i:s');
        if ($url == '') {
            $rec['UPDATE_MESSAGE'] = 'No URL';
            SQLUpdate('autopatcher_packages', $rec);
            return 'error';
        }
        $rv = $this->remoteVersion($url);
        if ($rv['version'] == '') {
            $rec['UPDATE_MESSAGE'] = $rv['error'];
            SQLUpdate('autopatcher_packages', $rec);
            DebMes("Update check " . $rec['NAME'] . ": " . $rv['error'], 'autopatcher');
            return 'error';
        }
        $rec['LATEST_SHA'] = $rv['version'];
        $rec['UPDATE_MESSAGE'] = '';
        SQLUpdate('autopatcher_packages', $rec);
        $installed = isset($rec['INSTALLED_SHA']) ? $rec['INSTALLED_SHA'] : '';
        if ($installed == $rv['version']) {
            return '';
        }
        DebMes("Update available for " . $rec['NAME'] . ": " . ($installed != '' ? $this->versionLabel($installed) : 'unknown') . " -> " . $rv['label'], 'autopatcher');
        if (!$install && (int)$rec['UPDATE_MODE'] != 2) {
            return 'available';
        }
        if (!$this->downloadPackage($rec)) {
            return 'error';
        }
        if ($this->installPackage($rec, 'update')) {
            if ((int)$this->cfg('RESTART', 1)) {
                setRebootRequired('autopatcher');
            }
            return 'updated';
        }
        return 'error';
    }

    /**
     * Check all packages with UPDATE_MODE > 0
     * @param bool $force ignore the interval
     */
    function checkUpdates($force = false)
    {
        $this->loadConfig();
        $interval = max(1, (int)$this->cfg('UPDATE_INTERVAL', 24));
        $last = (int)$this->cfg('LAST_UPDATE_CHECK', 0);
        if (!$force && $last && time() - $last < $interval * 3600 - 300) {
            return array();
        }
        $this->config['LAST_UPDATE_CHECK'] = time();
        $this->saveConfig();
        $engine = $this->getEngine();
        if (!$engine->lock()) {
            return array();
        }
        $result = array();
        try {
            $rows = SQLSelect("SELECT * FROM autopatcher_packages WHERE ENABLED=1 AND UPDATE_MODE>0 ORDER BY NAME");
            foreach ($rows as $rec) {
                if (!is_file(ROOT . $this->packageCheckPath($rec))) {
                    continue; // not installed: handled by the normal install path
                }
                if ($this->packageUrl($rec) == '') {
                    continue; // uploaded archive only: nothing to compare with
                }
                $result[$rec['NAME']] = $this->checkPackageUpdate($rec);
            }
        } catch (\Throwable $e) {
            DebMes("Update check failed: " . $e->getMessage(), 'autopatcher');
        }
        $engine->unlock();
        return $result;
    }

    /**
     * HOURLY event (cycle_main -> processSubscriptionsSafe: a background HTTP request,
     * so network checks never delay system start or cycles)
     */
    function processSubscription($event, $details = '')
    {
        if ($event == 'HOURLY') {
            $this->checkUpdates(false);
        } elseif ($event == 'AUTOPATCHER_DOWNLOAD' && is_array($details) && !empty($details['id'])) {
            // the caller (postURLBackground) disconnects after 300 ms: keep working
            ignore_user_abort(true);
            set_time_limit(0);
            // processSubscriptionsSafe() passes the user's session id: release the session lock,
            // otherwise every page of this user waits until the download is finished
            if (function_exists('session_status') && session_status() == PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $this->backgroundDownload((int)$details['id']);
        }
    }

    // ------------------------------------------------------------------
    // Automatic run (boot / after update)
    // ------------------------------------------------------------------

    function runPatches($auto_patches, &$summary)
    {
        $this->syncPatches();
        $rows = $this->sortPatchRows(SQLSelect("SELECT * FROM autopatcher_patches WHERE ENABLED=1"));
        foreach ($rows as $rec) {
            $this->runStage = 'patch ' . $rec['FILENAME'];
            $res = $this->processPatch($rec, $auto_patches ? 'apply' : 'check');
            if (count($res['changed'])) {
                $summary['applied'][] = $rec['FILENAME'];
                $summary['changed'] = array_merge($summary['changed'], $res['changed']);
            }
            if ($res['status'] == AutopatcherEngine::ST_CONFLICT) {
                $summary['conflict'][] = $rec['FILENAME'];
            } elseif ($res['status'] == AutopatcherEngine::ST_ERROR) {
                $summary['error'][] = $rec['FILENAME'];
            }
            if ($this->is_boot) {
                echo "AutoPatcher: " . $rec['FILENAME'] . ": " . $res['status'] . PHP_EOL;
            }
        }
        $summary['applied'] = array_values(array_unique($summary['applied']));
        $summary['conflict'] = array_values(array_unique($summary['conflict']));
        $summary['error'] = array_values(array_unique($summary['error']));
    }

    /**
     * If the process dies inside autoRun (exit() in a module installer, fatal error),
     * leave a trace: debug log + "last run" line in the admin page
     */
    function shutdownCheck()
    {
        if ($this->runStage === '') {
            return;
        }
        $err = error_get_last();
        $msg = 'AutoPatcher run interrupted at stage: ' . $this->runStage
            . ($err ? ' (' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] . ')' : ' (exit() called)');
        DebMes($msg, 'autopatcher');
        if ($this->is_boot) {
            echo $msg . PHP_EOL;
        }
        try {
            if (preg_match('/^module ([a-z0-9_]+)$/', $this->runStage, $m)) {
                // do not try this module automatically again (it would break every start),
                // and do not let control_modules skip AutoPatcher for an hour
                SQLExec("UPDATE autopatcher_packages SET STATUS='crashed', MESSAGE='" . DBSafe($msg) . "' WHERE NAME='" . DBSafe($m[1]) . "'");
                @unlink(ROOT . 'cms/modules_installed/' . $this->name . '.error');
            }
        } catch (\Throwable $e) {
        }
        try {
            $this->loadConfig();
            $this->config['LAST_RUN'] = date('Y-m-d H:i:s') . ' (' . $msg . ')';
            $this->saveConfig();
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param string $trigger install|manual|cli
     * @param bool $force apply even if automatic mode is off
     * @return array summary
     */
    function autoRun($trigger = 'install', $force = false)
    {
        $summary = array('applied' => array(), 'conflict' => array(), 'error' => array(), 'packages' => array(), 'changed' => array(), 'restart' => false);
        $engine = $this->getEngine();
        if (!$engine->lock()) {
            DebMes("Another AutoPatcher run in progress, skipping ($trigger)", 'autopatcher');
            return $summary;
        }
        $this->runStage = 'start';
        if (!$this->shutdownRegistered) {
            register_shutdown_function(array($this, 'shutdownCheck'));
            $this->shutdownRegistered = true;
        }
        try {
            $this->loadConfig();
            $auto_patches = $force || (int)$this->cfg('AUTO_APPLY', 1);
            $auto_packages = $force || (int)$this->cfg('AUTO_PACKAGES', 1);

            // system update detection
            // (first run only remembers the current version)
            $signature = $this->systemUpdateSignature();
            $first_run = !array_key_exists('LAST_SYSTEM_UPDATE', $this->config);
            $known = $first_run ? '' : (string)$this->config['LAST_SYSTEM_UPDATE'];
            $system_updated = (!$first_run && $signature != '' && $signature != $known);
            if ($first_run || $signature != $known) {
                $this->config['LAST_SYSTEM_UPDATE'] = $signature;
                if ($system_updated) {
                    $this->config['LAST_SYSTEM_UPDATE_DETECTED'] = date('Y-m-d H:i:s');
                    DebMes("System update detected: $known -> $signature", 'autopatcher');
                }
                $this->saveConfig();
            }

            // 1. patches first: a crash while installing a module must not leave the system unpatched
            $this->runStage = 'patches';
            $this->runPatches($auto_patches, $summary);

            // 2. modules
            $this->runStage = 'modules';
            $packages = SQLSelect("SELECT * FROM autopatcher_packages WHERE ENABLED=1 ORDER BY ID");
            foreach ($packages as $rec) {
                if (!empty($rec['NAME_AUTO']) && !is_file($this->packageArchive($rec['NAME']))) {
                    // name is not known yet (background download pending): install() would use a guessed name
                    continue;
                }
                if ($rec['STATUS'] == 'crashed') {
                    // installer killed the process last time: manual install only
                    continue;
                }
                $reason = $this->packageNeedsInstall($rec, $system_updated);
                if ($reason == '') {
                    continue;
                }
                if ($this->is_boot) {
                    echo "AutoPatcher [" . $rec['NAME'] . "]: needs install: " . $reason
                        . ($rec['CHECK_DETAILS'] != '' ? ' (' . $rec['CHECK_DETAILS'] . ')' : '') . PHP_EOL;
                }
                if (!$auto_packages) {
                    unset($rec['CHECK_DETAILS']);
                    $this->setPackageStatus($rec, 'pending', $reason);
                    continue;
                }
                $this->runStage = 'module ' . $rec['NAME'];
                if ($this->installPackage($rec, $reason)) {
                    $summary['packages'][] = $rec['NAME'];
                }
            }

            // 3. a module may have overwritten patched files: second pass
            if (count($summary['packages'])) {
                $this->runStage = 'patches (after modules)';
                $this->runPatches($auto_patches, $summary);
            }

            $this->config['LAST_RUN'] = date('Y-m-d H:i:s') . ' (' . $trigger . ')';
            $this->saveConfig();

            // 3. restart services so running cycles pick up changed PHP code.
            //    During boot the cycles are not started yet - no restart needed.
            if (!$this->is_boot && (int)$this->cfg('RESTART', 1) && (count($summary['packages']) || count(preg_grep('/\.php$/i', $summary['changed'])))) {
                setRebootRequired('autopatcher');
                $summary['restart'] = true;
            }
            if (count($summary['applied']) || count($summary['packages']) || count($summary['conflict']) || count($summary['error'])) {
                DebMes("Run ($trigger): applied=" . implode(',', $summary['applied'])
                    . " packages=" . implode(',', $summary['packages'])
                    . " conflict=" . implode(',', $summary['conflict'])
                    . " error=" . implode(',', $summary['error']), 'autopatcher');
            }
        } catch (\Throwable $e) {
            DebMes("AutoPatcher failed: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 'autopatcher');
            $summary['error'][] = $e->getMessage();
        }
        $this->runStage = '';
        $engine->unlock();
        return $summary;
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    function admin(&$out)
    {
        $engine = $this->getEngine();
        $this->loadConfig();
        $ok_msg = gr('ok_msg');
        $err_msg = gr('err_msg');

        if ($this->view_mode == 'help') {
            $lang = defined('SETTINGS_SITE_LANGUAGE') ? strtolower(SETTINGS_SITE_LANGUAGE) : 'en';
            $file = DIR_TEMPLATES . $this->name . '/help_' . ($lang == 'ru' || $lang == 'ua' || $lang == 'by' ? 'ru' : 'en') . '.html';
            $out['HELP'] = is_file($file) ? LoadFile($file) : '';
            $out['HELP_SECTION'] = preg_replace('/[^a-z]/', '', strtolower((string)gr('section')));
            return;
        }

        // settings
        if ($this->view_mode == 'update_settings') {
            $this->config['AUTO_APPLY'] = gr('auto_apply', 'int');
            $this->config['AUTO_PACKAGES'] = gr('auto_packages', 'int');
            $this->config['RESTART'] = gr('restart', 'int');
            $this->config['UPDATE_INTERVAL'] = max(1, gr('update_interval', 'int'));
            $this->saveConfig();
            $this->redirect("?ok_msg=" . urlencode(LANG_DATA_SAVED));
        }

        if ($this->view_mode == 'run_all') {
            $summary = $this->autoRun('manual', true);
            $msg = LANG_AUTOPATCHER_APPLIED . ': ' . count($summary['applied']) . ', ' . LANG_AUTOPATCHER_PACKAGES . ': ' . count($summary['packages'])
                . ', ' . LANG_AUTOPATCHER_ST_CONFLICT . ': ' . count($summary['conflict']) . ', ' . LANG_AUTOPATCHER_ST_ERROR . ': ' . count($summary['error']);
            if ($summary['restart']) {
                $msg .= '. ' . LANG_AUTOPATCHER_RESTART_REQUESTED;
            }
            $this->redirect("?ok_msg=" . urlencode($msg));
        }

        $this->syncPatches();

        // patch actions
        $id = (int)$this->id;
        if ($id && in_array($this->view_mode, array('patch_up', 'patch_down', 'patch_number'))) {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_patches WHERE ID=" . $id);
            if (!empty($rec['ID'])) {
                if (!$engine->lock()) {
                    $this->redirect("?err_msg=" . urlencode(LANG_AUTOPATCHER_BUSY));
                }
                $err = '';
                if ($this->view_mode == 'patch_number') {
                    $num = gr('number', 'int');
                    if ($num < 0 || $num > 9999) {
                        $err = LANG_AUTOPATCHER_BAD_NUMBER;
                    } else {
                        $err = $this->renamePatch($rec, self::withNumber($rec['FILENAME'], $num));
                    }
                } else {
                    $this->movePatch($rec['ID'], $this->view_mode == 'patch_up' ? -1 : 1);
                }
                $engine->unlock();
                if ($this->view_mode == 'patch_number') {
                    $this->redirect("?view_mode=view_patch&id=" . $rec['ID'] . ($err != '' ? "&err_msg=" . urlencode($err) : "&ok_msg=" . urlencode(LANG_AUTOPATCHER_ORDER_SAVED)));
                }
                $this->redirect("?" . ($err != '' ? "err_msg=" . urlencode($err) : ''));
            }
        }
        if ($id && $this->view_mode == 'refresh_patch') {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_patches WHERE ID=" . $id);
            if (!empty($rec['ID'])) {
                set_time_limit(0);
                $patch = $engine->loadPatch($rec['FILENAME']);
                $text = str_replace("\r\n", "\n", (string)LoadFile($engine->patchesDir() . $rec['FILENAME']));
                $headers = array();
                foreach (explode("\n", $text) as $l) {
                    if (preg_match('/^(diff --git |--- )/', $l)) {
                        break;
                    }
                    if (preg_match('/^#/', $l)) {
                        $headers[] = $l;
                    }
                }
                $imp = $this->importPatch($patch['source'], $patch['root'], $headers);
                $back = "?view_mode=view_patch&id=" . $rec['ID'];
                if ($imp['error'] != '') {
                    $this->redirect($back . "&err_msg=" . urlencode($imp['error']));
                }
                $state = $imp['state'] != '' ? ' (PR: ' . $imp['state'] . ')' : '';
                if ($imp['content'] === $text) {
                    $this->redirect($back . "&ok_msg=" . urlencode(LANG_AUTOPATCHER_SOURCE_SAME . $state));
                }
                if ($engine->evaluate($patch)['status'] == AutopatcherEngine::ST_APPLIED && $engine->parseText($imp['content'], 'diff')['files'] != $patch['files']) {
                    // changed while applied: the old version must be reverted first
                    $this->redirect($back . "&err_msg=" . urlencode(LANG_AUTOPATCHER_SOURCE_CHANGED_APPLIED . $state));
                }
                SaveFile($engine->patchesDir() . $rec['FILENAME'], $imp['content']);
                $this->processPatch($rec, 'check');
                $this->redirect($back . "&ok_msg=" . urlencode(LANG_AUTOPATCHER_SOURCE_UPDATED . $state));
            }
        }
        if ($id && in_array($this->view_mode, array('check_patch', 'apply_patch', 'revert_patch', 'toggle_patch', 'delete_patch'))) {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_patches WHERE ID=" . $id);
            if (!empty($rec['ID'])) {
                if ($this->view_mode == 'toggle_patch') {
                    $rec['ENABLED'] = $rec['ENABLED'] ? 0 : 1;
                    SQLUpdate('autopatcher_patches', $rec);
                    $this->redirect("?");
                }
                if ($this->view_mode == 'delete_patch') {
                    $this->deletePatch($rec);
                }
                if (!$engine->lock()) {
                    $this->redirect("?err_msg=" . urlencode(LANG_AUTOPATCHER_BUSY));
                }
                $action = str_replace('_patch', '', $this->view_mode);
                $disabled = array();
                $affected = array();
                if ($action == 'revert') {
                    $affected = $this->affectedPatches($engine->loadPatch($rec['FILENAME']), true);
                    if (count($affected) && !gr('force', 'int')) {
                        $engine->unlock();
                        $this->redirect("?view_mode=view_patch&id=" . $rec['ID'] . "&confirm_revert=1&affected=" . urlencode(implode(', ', array_keys($affected))));
                    }
                }
                if ($action == 'revert' && count($affected)) {
                    $grp = $this->revertWithAffected($rec, $affected);
                    if (!$grp['ok']) {
                        $engine->unlock();
                        $this->redirect("?view_mode=view_patch&id=" . $rec['ID'] . "&err_msg=" . urlencode($rec['FILENAME'] . ': ' . $grp['message']));
                    }
                    $res = array('status' => $engine->evaluate($engine->loadPatch($rec['FILENAME']))['status'], 'changed' => $grp['changed'], 'message' => '');
                } else {
                    $res = $this->processPatch($rec, $action);
                }
                if ($action == 'revert' && in_array($res['status'], array(AutopatcherEngine::ST_PENDING, AutopatcherEngine::ST_APPLIED))
                    && $engine->evaluate($engine->loadPatch($rec['FILENAME']))['status'] != AutopatcherEngine::ST_APPLIED) {
                    // otherwise automatic mode applies it again on the next system start
                    SQLExec("UPDATE autopatcher_patches SET ENABLED=0 WHERE ID=" . (int)$rec['ID']);
                    $disabled[] = $rec['FILENAME'];
                    foreach ($affected as $aname => $aid) {
                        $disabled[] = $aname; // reverted and disabled by revertWithAffected()
                    }
                }
                $engine->unlock();
                $restart = false;
                if (count($res['changed']) && (int)$this->cfg('RESTART', 1) && count(preg_grep('/\.php$/i', $res['changed']))) {
                    setRebootRequired('autopatcher');
                    $restart = true;
                }
                $msg = $rec['FILENAME'] . ': ' . (count($disabled) ? LANG_AUTOPATCHER_ST_REVERTED : $this->statusTitle($res['status'])) . ($restart ? '. ' . LANG_AUTOPATCHER_RESTART_REQUESTED : '');
                if (count($disabled)) {
                    $msg .= '. ' . LANG_AUTOPATCHER_REVERTED_DISABLED . ': ' . implode(', ', $disabled);
                }
                if (in_array($res['status'], array(AutopatcherEngine::ST_CONFLICT, AutopatcherEngine::ST_ERROR))) {
                    $this->redirect("?view_mode=view_patch&id=" . $rec['ID'] . "&err_msg=" . urlencode($msg));
                }
                $this->redirect("?ok_msg=" . urlencode($msg));
            }
        }

        // add / edit patch text
        if ($this->view_mode == 'edit_patch' || $this->view_mode == 'view_patch') {
            $this->editPatch($out, $id);
            $out['OK_MSG'] = $this->esc($ok_msg);
            // keep the validation error set by editPatch()
            $out['ERR_MSG'] = $this->esc(!empty($out['ERR_MSG']) ? $out['ERR_MSG'] : $err_msg);
            return;
        }

        // packages
        if ($this->view_mode == 'edit_package') {
            $this->editPackage($out, $id);
            $out['ERR_MSG'] = $this->esc(!empty($out['ERR_MSG']) ? $out['ERR_MSG'] : $err_msg);
            return;
        }
        if ($this->view_mode == 'view_package') {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_packages WHERE ID=" . (int)$id);
            if (!empty($rec['ID'])) {
                $out['ID'] = (int)$rec['ID'];
                $out['NAME'] = $this->esc($rec['NAME']);
                $out['STATUS_TITLE'] = $this->statusTitle($rec['STATUS']);
                $out['STATUS_CLASS'] = $this->statusClass($rec['STATUS']);
                $out['CACHE_EXISTS'] = is_file($this->packageArchive($rec['NAME'])) ? 1 : 0;
                $out['CAN_INSTALL'] = ($out['CACHE_EXISTS'] || $this->packageUrl($rec) != '') ? 1 : 0;
                $log = $this->packageLogFile($rec['NAME']);
                $out['INSTALL_LOG'] = is_file($log) ? $this->esc(LoadFile($log)) : '';
            }
            $out['OK_MSG'] = $this->esc($ok_msg);
            $out['ERR_MSG'] = $this->esc($err_msg);
            return;
        }
        if ($this->view_mode == 'check_updates') {
            set_time_limit(0);
            $res = $this->checkUpdates(true);
            $avail = array_keys(array_filter($res, function ($v) {
                return $v == 'available';
            }));
            $upd = array_keys(array_filter($res, function ($v) {
                return $v == 'updated';
            }));
            $errs = array_keys(array_filter($res, function ($v) {
                return $v == 'error';
            }));
            $msg = LANG_AUTOPATCHER_UPDATES_CHECKED . ': ' . count($res)
                . (count($avail) ? '. ' . LANG_AUTOPATCHER_UPDATE_AVAILABLE . ': ' . implode(', ', $avail) : '')
                . (count($upd) ? '. ' . LANG_AUTOPATCHER_UPDATED . ': ' . implode(', ', $upd) : '')
                . (count($errs) ? '. ' . LANG_AUTOPATCHER_ST_ERROR . ': ' . implode(', ', $errs) : '');
            $this->redirect('?' . (count($errs) && !count($avail) && !count($upd) ? 'err_msg=' : 'ok_msg=') . urlencode($msg));
        }
        if ($id && $this->view_mode == 'update_package') {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_packages WHERE ID=" . (int)$id);
            if (!empty($rec['ID'])) {
                set_time_limit(0);
                if (!$engine->lock()) {
                    $this->redirect("?err_msg=" . urlencode(LANG_AUTOPATCHER_BUSY));
                }
                $r = $this->checkPackageUpdate($rec, true);
                $engine->unlock();
                if ($r == 'updated') {
                    $this->redirect('?view_mode=view_package&id=' . $rec['ID'] . '&ok_msg=' . urlencode($rec['NAME'] . ': ' . LANG_AUTOPATCHER_UPDATED));
                }
                if ($r == '') {
                    $this->redirect('?ok_msg=' . urlencode($rec['NAME'] . ': ' . LANG_AUTOPATCHER_UP_TO_DATE));
                }
                $this->redirect('?err_msg=' . urlencode($rec['NAME'] . ': ' . ($rec['UPDATE_MESSAGE'] != '' ? $rec['UPDATE_MESSAGE'] : $rec['MESSAGE'])));
            }
        }
        if ($id && in_array($this->view_mode, array('download_package', 'install_package', 'toggle_package', 'delete_package'))) {
            $rec = SQLSelectOne("SELECT * FROM autopatcher_packages WHERE ID=" . $id);
            if (!empty($rec['ID'])) {
                if ($this->view_mode == 'toggle_package') {
                    $rec['ENABLED'] = $rec['ENABLED'] ? 0 : 1;
                    SQLUpdate('autopatcher_packages', $rec);
                    $this->redirect("?");
                }
                if ($this->view_mode == 'delete_package') {
                    // the installed module stays; only the rule and the cached archive are removed
                    @unlink($this->packageArchive($rec['NAME']));
                    @unlink($this->packageBaselineFile($rec['NAME']));
                    @unlink($this->packageLogFile($rec['NAME']));
                    SQLExec("DELETE FROM autopatcher_packages WHERE ID=" . (int)$rec['ID']);
                    $this->redirect("?");
                }
                set_time_limit(0);
                if ($this->view_mode == 'download_package') {
                    $ok = $this->downloadPackage($rec);
                } else {
                    $ok = $this->installPackage($rec);
                    if ($ok && (int)$this->cfg('RESTART', 1)) {
                        setRebootRequired('autopatcher');
                    }
                }
                $msg = $rec['NAME'] . ': ' . ($rec['MESSAGE'] != '' ? $rec['MESSAGE'] : $this->statusTitle($rec['STATUS']));
                if ($this->view_mode == 'install_package') {
                    $this->redirect('?view_mode=view_package&id=' . $rec['ID'] . '&' . ($ok ? 'ok_msg=' : 'err_msg=') . urlencode($msg));
                }
                $this->redirect('?' . ($ok ? 'ok_msg=' : 'err_msg=') . urlencode($msg));
            }
        }

        // main list
        $out['OK_MSG'] = $this->esc($ok_msg);
        $out['ERR_MSG'] = $this->esc($err_msg);
        $out['AUTO_APPLY'] = (int)$this->cfg('AUTO_APPLY', 1);
        $out['AUTO_PACKAGES'] = (int)$this->cfg('AUTO_PACKAGES', 1);
        $out['RESTART'] = (int)$this->cfg('RESTART', 1);
        $out['UPDATE_INTERVAL'] = max(1, (int)$this->cfg('UPDATE_INTERVAL', 24));
        $last_check = (int)$this->cfg('LAST_UPDATE_CHECK', 0);
        $out['LAST_UPDATE_CHECK'] = $last_check ? date('Y-m-d H:i', $last_check) : '';
        $out['LAST_RUN'] = $this->esc($this->cfg('LAST_RUN', ''));
        $out['LAST_SYSTEM_UPDATE'] = $this->esc($this->cfg('LAST_SYSTEM_UPDATE_DETECTED', ''));
        $out['SYSTEM_VERSION'] = $this->esc($this->systemUpdateSignature());
        $out['DATA_DIR'] = $this->esc(str_replace(ROOT, '', $this->dataDir()));

        // live state: the table must show what is in the files now, not the result of the last run
        $pending = 0;
        $live = $this->sortPatchRows(SQLSelect("SELECT * FROM autopatcher_patches"));
        foreach ($live as $rec) {
            $res = $this->processPatch($rec, 'check');
            if ($rec['ENABLED'] && $res['status'] == AutopatcherEngine::ST_PENDING) {
                $pending++;
            }
        }
        $out['PENDING_COUNT'] = $pending;

        $patches = $this->sortPatchRows(SQLSelect("SELECT * FROM autopatcher_patches"));
        $last_index = count($patches) - 1;
        foreach ($patches as $pi => &$p) {
            $p['IS_FIRST'] = ($pi == 0) ? 1 : 0;
            $p['IS_LAST'] = ($pi == $last_index) ? 1 : 0;
            $p['POSITION'] = $pi + 1;
            $p['STATUS_TITLE'] = $this->statusTitle($p['STATUS']);
            $p['STATUS_CLASS'] = $this->statusClass($p['STATUS']);
            $p['TITLE'] = $this->esc($p['TITLE']);
            $p['FILENAME'] = $this->esc($p['FILENAME']);
            $p['MESSAGE'] = nl2br($this->esc($p['MESSAGE']));
            $p['APPLIED'] = $this->esc($p['APPLIED']);
            $p['CAN_APPLY'] = ($p['STATUS'] == AutopatcherEngine::ST_PENDING) ? 1 : 0;
            $p['CAN_REVERT'] = ($p['STATUS'] == AutopatcherEngine::ST_APPLIED) ? 1 : 0;
        }
        unset($p);
        $out['PATCHES'] = $patches;
        $out['TOTAL_PATCHES'] = count($patches);

        $packages = SQLSelect("SELECT * FROM autopatcher_packages ORDER BY NAME");
        $updates_available = 0;
        $downloading = 0;
        foreach ($packages as &$p) {
            $p['STATUS_TITLE'] = $this->statusTitle($p['STATUS']);
            $p['STATUS_CLASS'] = $this->statusClass($p['STATUS']);
            $p['CACHE_EXISTS'] = is_file($this->packageArchive($p['NAME'])) ? 1 : 0;
            $p['CAN_INSTALL'] = ($p['CACHE_EXISTS'] || $this->packageUrl($p) != '') ? 1 : 0;
            if ($p['STATUS'] == 'downloading') {
                if (!empty($p['DL_STARTED']) && time() - strtotime($p['DL_STARTED']) > 600) {
                    // background request never finished (server restart, BASE_URL unreachable)
                    $p['STATUS'] = 'error';
                    $p['MESSAGE'] = LANG_AUTOPATCHER_DL_STALE;
                    SQLExec("UPDATE autopatcher_packages SET STATUS='error', MESSAGE='" . DBSafe($p['MESSAGE']) . "' WHERE ID=" . (int)$p['ID']);
                } else {
                    $downloading++;
                }
            }
            $p['NAME_PENDING'] = !empty($p['NAME_AUTO']) ? 1 : 0;
            $p['IS_PRESENT'] = is_file(ROOT . $this->packageCheckPath($p)) ? 1 : 0;
            $p['CHECK_PATH'] = $this->esc($this->packageCheckPath($p));
            $p['REASON_TITLE'] = $this->reasonTitle(isset($p['LAST_REASON']) ? $p['LAST_REASON'] : '');
            $p['INSTALLED'] = $this->esc($p['INSTALLED']);
            $p['FILES'] = (int)(isset($p['FILES']) ? $p['FILES'] : 0);
            $p['HAS_LOG'] = is_file($this->packageLogFile($p['NAME'])) ? 1 : 0;
            $p['INSTALLED_VERSION'] = $this->esc($this->versionLabel(isset($p['INSTALLED_SHA']) ? $p['INSTALLED_SHA'] : ''));
            $p['LATEST_VERSION'] = $this->esc($this->versionLabel(isset($p['LATEST_SHA']) ? $p['LATEST_SHA'] : ''));
            $p['UPDATE_AVAILABLE'] = (!empty($p['LATEST_SHA']) && $p['IS_PRESENT'] && $p['LATEST_SHA'] != $p['INSTALLED_SHA']) ? 1 : 0;
            $p['UPDATE_CHECKED'] = $this->esc(isset($p['UPDATE_CHECKED']) ? $p['UPDATE_CHECKED'] : '');
            $p['UPDATE_MESSAGE'] = $this->esc(isset($p['UPDATE_MESSAGE']) ? $p['UPDATE_MESSAGE'] : '');
            if ($p['UPDATE_AVAILABLE']) {
                $updates_available++;
            }
            $p['CHECK_TITLE'] = (isset($p['LAST_CHECK']) && $p['LAST_CHECK'] == 'ok') ? LANG_AUTOPATCHER_CHECK_OK : $this->reasonTitle(isset($p['LAST_CHECK']) ? $p['LAST_CHECK'] : '');
            $p['CHECKED'] = $this->esc(isset($p['CHECKED']) ? $p['CHECKED'] : '');
            foreach (array('NAME', 'TITLE', 'URL', 'MESSAGE') as $k) {
                $p[$k] = $this->esc($p[$k]);
            }
        }
        unset($p);
        $out['PACKAGES'] = $packages;
        $out['UPDATES_AVAILABLE'] = $updates_available;
        $out['DOWNLOADING'] = $downloading;
        $out['TOTAL_PACKAGES'] = count($packages);
    }

    /**
     * Revert $rec together with the patches that depend on the same lines ($affected: FILENAME => ID).
     * Two orders are simulated in memory first: dependants first (a patch built on top of this one),
     * or this one first (a cumulative diff that already contains the others). The first order that
     * reverts everything without a conflict is executed; if none does, nothing is changed.
     * @return array ['ok' => bool, 'changed' => [paths], 'message' => '']
     */
    function revertWithAffected($rec, $affected)
    {
        $engine = $this->getEngine();
        $names = array_keys($affected);
        rsort($names, SORT_STRING); // later patches are usually built on earlier ones
        $orders = array(array_merge($names, array($rec['FILENAME'])), array_merge(array($rec['FILENAME']), $names));
        $chosen = null;
        foreach ($orders as $order) {
            $overrides = array();
            $ok = true;
            foreach ($order as $fname) {
                $p = $engine->loadPatch($fname);
                $ev = $engine->evaluate($p, true, $overrides);
                if ($ev['status'] == AutopatcherEngine::ST_PENDING) {
                    foreach ($ev['files'] as $path => $f) {
                        if ($f['status'] == AutopatcherEngine::ST_PENDING) {
                            $overrides[$path] = $f['new'];
                        }
                    }
                } elseif ($ev['status'] != AutopatcherEngine::ST_APPLIED) {
                    $ok = false; // conflict: this order would leave pieces behind
                    break;
                }
            }
            if ($ok) {
                // every patch of the group must end up not applied
                foreach ($order as $fname) {
                    if ($engine->evaluate($engine->loadPatch($fname), false, $overrides)['status'] == AutopatcherEngine::ST_APPLIED) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $chosen = $order;
                break;
            }
        }
        if ($chosen === null) {
            return array('ok' => false, 'changed' => array(), 'message' => LANG_AUTOPATCHER_REVERT_GROUP_FAILED);
        }
        $changed = array();
        foreach ($chosen as $fname) {
            $r = SQLSelectOne("SELECT * FROM autopatcher_patches WHERE FILENAME='" . DBSafe($fname) . "'");
            if (empty($r['ID'])) {
                continue;
            }
            $res = $this->processPatch($r, 'revert');
            $changed = array_merge($changed, $res['changed']);
            if (in_array($res['status'], array(AutopatcherEngine::ST_CONFLICT, AutopatcherEngine::ST_ERROR))) {
                return array('ok' => false, 'changed' => $changed, 'message' => $fname . ': ' . $res['message']);
            }
        }
        foreach ($affected as $aid) {
            SQLExec("UPDATE autopatcher_patches SET ENABLED=0 WHERE ID=" . (int)$aid);
        }
        return array('ok' => true, 'changed' => array_values(array_unique($changed)), 'message' => '');
    }

    /**
     * Delete = revert (if applied) + move the file to cms/autopatcher/deleted/.
     *   how=''      revert automatically when the files are exactly as AutoPatcher left them,
     *               otherwise ask (the change may be native upstream code now)
     *   how=revert  revert and delete
     *   how=keep    delete without reverting
     */
    function deletePatch($rec)
    {
        $engine = $this->getEngine();
        $patch = $engine->loadPatch($rec['FILENAME']);
        $how = gr('how');
        $back = "?view_mode=view_patch&id=" . $rec['ID'];
        $reverted = false;
        $disabled = array();
        $restart = false;

        if ($patch['error'] == '' && $engine->evaluate($patch)['status'] == AutopatcherEngine::ST_APPLIED && $how != 'keep') {
            if ($how != 'revert' && !$this->appliedByUs($rec, $patch)) {
                $this->redirect($back . "&confirm_delete=1");
            }
            $affected = $this->affectedPatches($patch, true);
            if (count($affected) && !gr('force', 'int')) {
                $this->redirect($back . "&confirm_revert=1&for_delete=1&affected=" . urlencode(implode(', ', array_keys($affected))));
            }
            if (!$engine->lock()) {
                $this->redirect($back . "&err_msg=" . urlencode(LANG_AUTOPATCHER_BUSY));
            }
            if (count($affected)) {
                $grp = $this->revertWithAffected($rec, $affected);
                $res = array('changed' => $grp['changed'], 'message' => $grp['message'], 'status' => '');
            } else {
                $res = $this->processPatch($rec, 'revert');
            }
            $engine->unlock();
            if ($engine->evaluate($engine->loadPatch($rec['FILENAME']))['status'] == AutopatcherEngine::ST_APPLIED
                || (count($affected) && !$grp['ok'])) {
                // could not revert: keep the patch so nothing is lost
                $this->redirect($back . "&err_msg=" . urlencode($rec['FILENAME'] . ': ' . LANG_AUTOPATCHER_DELETE_REVERT_FAILED
                    . ($res['message'] != '' ? ' (' . $res['message'] . ')' : '')));
            }
            $reverted = true;
            foreach ($affected as $aname => $aid) {
                $disabled[] = $aname; // reverted and disabled by revertWithAffected()
            }
            if (count($res['changed']) && (int)$this->cfg('RESTART', 1) && count(preg_grep('/\.php$/i', $res['changed']))) {
                setRebootRequired('autopatcher');
                $restart = true;
            }
        }

        // the file is kept, just moved out of the active set
        @mkdir($this->dataDir() . 'deleted', 0777, true);
        @rename($engine->patchesDir() . $rec['FILENAME'], $this->dataDir() . 'deleted/' . date('Ymd-His') . '_' . $rec['FILENAME']);
        SQLExec("DELETE FROM autopatcher_patches WHERE ID=" . (int)$rec['ID']);
        $msg = $rec['FILENAME'] . ': ' . ($reverted ? LANG_AUTOPATCHER_DELETED_REVERTED : LANG_AUTOPATCHER_DELETED)
            . ($restart ? '. ' . LANG_AUTOPATCHER_RESTART_REQUESTED : '')
            . (count($disabled) ? '. ' . LANG_AUTOPATCHER_REVERTED_DISABLED . ': ' . implode(', ', $disabled) : '');
        $this->redirect("?ok_msg=" . urlencode($msg));
    }

    function statusTitle($status)
    {
        $map = array(
            'applied' => 'LANG_AUTOPATCHER_ST_APPLIED',
            'pending' => 'LANG_AUTOPATCHER_ST_PENDING',
            'conflict' => 'LANG_AUTOPATCHER_ST_CONFLICT',
            'error' => 'LANG_AUTOPATCHER_ST_ERROR',
            'installed' => 'LANG_AUTOPATCHER_ST_INSTALLED',
            'cached' => 'LANG_AUTOPATCHER_ST_CACHED',
            'no_cache' => 'LANG_AUTOPATCHER_ST_NO_CACHE',
            'check_missing' => 'LANG_AUTOPATCHER_ST_CHECK_MISSING',
            'crashed' => 'LANG_AUTOPATCHER_ST_CRASHED',
            'downloading' => 'LANG_AUTOPATCHER_ST_DOWNLOADING',
        );
        if (isset($map[$status]) && defined($map[$status])) {
            return constant($map[$status]);
        }
        return $status == '' ? '—' : $status;
    }

    function reasonTitle($reason)
    {
        $map = array('missing' => 'LANG_AUTOPATCHER_REASON_MISSING', 'system_updated' => 'LANG_AUTOPATCHER_REASON_UPDATED',
            'manual' => 'LANG_AUTOPATCHER_REASON_MANUAL', 'files_changed' => 'LANG_AUTOPATCHER_REASON_FILES_CHANGED',
            'no_baseline' => 'LANG_AUTOPATCHER_REASON_NO_BASELINE', 'update' => 'LANG_AUTOPATCHER_REASON_UPDATE');
        return (isset($map[$reason]) && defined($map[$reason])) ? constant($map[$reason]) : $this->esc($reason);
    }

    function statusClass($status)
    {
        $map = array('applied' => 'success', 'installed' => 'success', 'cached' => 'info', 'pending' => 'warning',
            'no_cache' => 'warning', 'conflict' => 'danger', 'error' => 'danger', 'check_missing' => 'danger', 'crashed' => 'danger', 'downloading' => 'primary');
        return isset($map[$status]) ? $map[$status] : 'default';
    }

    /**
     * GitHub pull request / commit / compare links -> .diff URL
     *   github.com/O/R/pull/N[/files|/commits]      -> pull/N.diff
     *   github.com/O/R/pull/N/commits/SHA           -> commit/SHA.diff
     *   github.com/O/R/commit/SHA                   -> commit/SHA.diff
     *   github.com/O/R/compare/BASE...HEAD          -> compare/BASE...HEAD.diff
     *   any URL ending with .diff / .patch          -> as is
     * @return array|false ['kind' => pr|commit|compare|raw, 'owner','repo','id','diff']
     */
    function parsePatchSource($url)
    {
        $url = trim($url);
        $o = '([A-Za-z0-9_.\-]+)';
        $base = '~^https?://(?:www\.)?github\.com/' . $o . '/' . $o;
        if (preg_match($base . '/pull/(\d+)/commits/([0-9a-f]{7,40})~i', $url, $m)) {
            return array('kind' => 'commit', 'owner' => $m[1], 'repo' => $m[2], 'id' => $m[4], 'diff' => $this->githubWeb . "/{$m[1]}/{$m[2]}/commit/{$m[4]}.diff");
        }
        if (preg_match($base . '/pull/(\d+)~i', $url, $m)) {
            return array('kind' => 'pr', 'owner' => $m[1], 'repo' => $m[2], 'id' => $m[3], 'diff' => $this->githubWeb . "/{$m[1]}/{$m[2]}/pull/{$m[3]}.diff");
        }
        if (preg_match($base . '/commit/([0-9a-f]{7,40})~i', $url, $m)) {
            return array('kind' => 'commit', 'owner' => $m[1], 'repo' => $m[2], 'id' => $m[3], 'diff' => $this->githubWeb . "/{$m[1]}/{$m[2]}/commit/{$m[3]}.diff");
        }
        if (preg_match($base . '/compare/([^?#]+?)(?:\.diff|\.patch)?(?:[?#].*)?$~i', $url, $m)) {
            return array('kind' => 'compare', 'owner' => $m[1], 'repo' => $m[2], 'id' => $m[3], 'diff' => $this->githubWeb . "/{$m[1]}/{$m[2]}/compare/{$m[3]}.diff");
        }
        if (preg_match('~^https?://\S+\.(diff|patch)(?:\?.*)?$~i', $url)) {
            return array('kind' => 'raw', 'owner' => '', 'repo' => '', 'id' => '', 'diff' => $url);
        }
        return false;
    }

    /**
     * Download a diff from GitHub (or a raw .diff URL) and build patch text with headers:
     * title from the PR / commit, source link, root, and automatic "# exclude:" lines for
     * files that are not in this installation, service folders (.github ...) and changes
     * the engine does not support (deletion, rename, binary).
     * @param array $keep_headers header lines to keep (refresh of an existing patch)
     * @return array ['content','title','error','state','excluded' => [path => reason]]
     */
    function importPatch($url, $root = '', $keep_headers = array())
    {
        $res = array('content' => '', 'title' => '', 'error' => '', 'state' => '', 'excluded' => array());
        $src = $this->parsePatchSource($url);
        if (!$src) {
            $res['error'] = LANG_AUTOPATCHER_SOURCE_UNKNOWN;
            return $res;
        }
        list($code, $body, , $err) = $this->httpQuick($src['diff'], array('Accept: text/plain'));
        $body = str_replace("\r\n", "\n", (string)$body);
        if ($code != 200 || !preg_match('/^(diff --git |--- )/m', $body)) {
            $res['error'] = 'Diff download failed: ' . ($code ? 'HTTP ' . $code : 'no connection' . ($err != '' ? ' (' . $err . ')' : '')) . ', ' . $src['diff'];
            return $res;
        }

        // title (and PR state) from the API; the diff is usable without it
        $api = $this->githubApi . '/repos/' . $src['owner'] . '/' . $src['repo'];
        if ($src['kind'] == 'pr') {
            list($c, $b) = $this->httpQuick($api . '/pulls/' . $src['id'], array('Accept: application/vnd.github+json'));
            $d = json_decode($b, true);
            $res['title'] = 'PR #' . $src['id'] . ($c == 200 && !empty($d['title']) ? ': ' . $d['title'] : ' ' . $src['owner'] . '/' . $src['repo']);
            if ($c == 200) {
                $res['state'] = !empty($d['merged']) || !empty($d['merged_at']) ? 'merged' : (isset($d['state']) ? $d['state'] : '');
            }
        } elseif ($src['kind'] == 'commit') {
            list($c, $b) = $this->httpQuick($api . '/commits/' . $src['id'], array('Accept: application/vnd.github+json'));
            $d = json_decode($b, true);
            $msg = ($c == 200 && !empty($d['commit']['message'])) ? strtok($d['commit']['message'], "\n") : $src['owner'] . '/' . $src['repo'];
            $res['title'] = trim($msg) . ' (' . substr($src['id'], 0, 7) . ')';
        } elseif ($src['kind'] == 'compare') {
            $res['title'] = $src['owner'] . '/' . $src['repo'] . ' ' . $src['id'];
        } else {
            $res['title'] = preg_replace('/\.(diff|patch)$/i', '', basename((string)parse_url($url, PHP_URL_PATH)));
        }

        // what would be excluded
        $root = trim(str_replace('\\', '/', $root), '/');
        $engine = $this->getEngine();
        $parsed = $engine->parseText($body, 'diff');
        $exclude = array();
        foreach ($parsed['unsupported'] as $path => $reason) {
            $exclude[$path] = $reason;
        }
        foreach ($parsed['files'] as $path => $spec) {
            if (preg_match('~(^|/)\.[^/]+~', $path)) {
                $exclude[$path] = 'service file';
            } elseif (empty($spec['create']) && !is_file(ROOT . ($root !== '' ? $root . '/' : '') . $path)) {
                $exclude[$path] = 'not in this installation';
            }
        }
        // a whole .github/ folder as one line
        $lines = array();
        foreach ($exclude as $path => $reason) {
            if (preg_match('~^(\.[^/]+)/~', $path, $m)) {
                $lines[$m[1]] = $m[1];
            } else {
                $lines[$path] = $path;
            }
        }
        $res['excluded'] = $exclude;

        $head = array();
        $head[] = '# title: ' . $res['title'];
        $head[] = '# source: ' . trim($url);
        if ($root !== '') {
            $head[] = '# root: ' . $root;
        }
        foreach ($keep_headers as $h) {
            if (!preg_match('/^#\s*(title|source|root):/i', $h)) {
                $head[] = $h; // user's own exclude / description lines
            }
        }
        foreach ($lines as $l) {
            $line = '# exclude: ' . $l;
            if (!in_array($line, $head)) {
                $head[] = $line;
            }
        }
        $res['content'] = implode("\n", $head) . "\n" . $body;
        return $res;
    }

    /**
     * File name for a pasted patch: <next number>-<slug>.<diff|replace>
     * slug from "# title:", otherwise from the first changed file
     */
    function suggestPatchName($content)
    {
        $content = str_replace("\r\n", "\n", $content);
        if (preg_match('/^<<<<<<< SEARCH\s*$/m', $content)) {
            $ext = 'replace';
        } elseif (preg_match('/^\+\+\+ /m', $content) && preg_match('/^@@ /m', $content)) {
            $ext = 'diff';
        } else {
            return ''; // unknown format: the form asks for a name
        }
        $slug = '';
        if (preg_match('/^#\s*title:\s*(.+)$/mi', $content, $m)) {
            $slug = $m[1];
        } elseif (preg_match('/^(?:FILE:\s*|\+\+\+ (?:b\/)?)(\S+)/m', $content, $m)) {
            $slug = preg_replace('/\.[a-z0-9]+$/i', '', basename($m[1]));
        }
        $tr = array('а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
            'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya');
        $slug = strtr(mb_strtolower($slug, 'UTF-8'), $tr);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = trim(substr($slug, 0, 40), '-');
        if ($slug == '') {
            $slug = 'patch';
        }
        $engine = $this->getEngine();
        $files = $engine->listPatchFiles();
        $max = 0;
        foreach ($files as $f) {
            if (preg_match('/^(\d+)/', $f, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        $num = (int)(floor($max / 10) * 10) + 10;
        $base = sprintf('%02d', $num) . '-' . $slug;
        $name = $base . '.' . $ext;
        for ($i = 2; is_file($engine->patchesDir() . $name); $i++) {
            $name = $base . '-' . $i . '.' . $ext;
        }
        return $name;
    }

    function editPatch(&$out, $id)
    {
        $engine = $this->getEngine();
        $rec = $id ? SQLSelectOne("SELECT * FROM autopatcher_patches WHERE ID=" . (int)$id) : array();
        $filename = !empty($rec['ID']) ? $rec['FILENAME'] : '';

        if ($this->view_mode == 'edit_patch' && $this->mode == 'update') {
            $new_name = trim(gr('filename'));
            $content = isset($_REQUEST['content']) ? (string)$_REQUEST['content'] : '';
            if (!empty($_FILES['patch_file']['tmp_name']) && is_uploaded_file($_FILES['patch_file']['tmp_name'])) {
                $content = (string)file_get_contents($_FILES['patch_file']['tmp_name']);
                if ($new_name == '') {
                    $new_name = basename($_FILES['patch_file']['name']);
                }
            }
            $source_url = trim(gr('source_url'));
            if ($source_url != '' && trim($content) == '') {
                // step 1: download and show for review, nothing is saved yet
                set_time_limit(0);
                $imp = $this->importPatch($source_url, trim(gr('root')));
                $out['SOURCE_URL'] = $this->esc($source_url);
                $out['ROOT'] = $this->esc(trim(gr('root')));
                $out['FILENAME'] = $this->esc($new_name);
                $out['ID'] = !empty($rec['ID']) ? (int)$rec['ID'] : '';
                if ($imp['error'] != '') {
                    $out['ERR_MSG'] = $imp['error'];
                    return;
                }
                $out['CONTENT'] = $this->esc($imp['content']);
                $out['IMPORTED'] = 1;
                $out['IMPORT_STATE'] = $this->esc($imp['state']);
                $excl = array();
                foreach ($imp['excluded'] as $path => $reason) {
                    $excl[] = array('PATH' => $this->esc($path), 'REASON' => $this->esc($reason));
                }
                $out['IMPORT_EXCLUDED'] = $excl;
                $out['IMPORT_EXCLUDED_COUNT'] = count($excl);
                $out['IMPORT_MERGED'] = ($imp['state'] == 'merged') ? 1 : 0;
                $out['IMPORT_STATE_CLASS'] = ($imp['state'] == 'merged') ? 'success' : 'default';
                return;
            }
            if ($filename != '') {
                $new_name = $filename; // renaming is done on disk
            } elseif ($new_name == '' && trim($content) != '') {
                // repeated submit of an auto-named patch: open the one already saved
                $norm = str_replace("\r\n", "\n", $content);
                foreach ($engine->listPatchFiles() as $f) {
                    if (str_replace("\r\n", "\n", (string)LoadFile($engine->patchesDir() . $f)) === $norm) {
                        $this->syncPatches();
                        $erec = SQLSelectOne("SELECT ID FROM autopatcher_patches WHERE FILENAME='" . DBSafe($f) . "'");
                        if (!empty($erec['ID'])) {
                            $this->redirect("?view_mode=view_patch&id=" . $erec['ID']);
                        }
                    }
                }
                $new_name = $this->suggestPatchName($content);
            }
            $existing = '';
            if ($filename == '' && AutopatcherEngine::isSafeId($new_name)) {
                // case-insensitive: Windows / some Android storages do not distinguish case
                foreach ($engine->listPatchFiles() as $f) {
                    if (strcasecmp($f, $new_name) == 0) {
                        $existing = $f;
                        break;
                    }
                }
                if ($existing == '' && is_file($engine->patchesDir() . $new_name)) {
                    $existing = $new_name;
                }
            }
            if ($existing != '') {
                $this->syncPatches();
                $erec = SQLSelectOne("SELECT ID FROM autopatcher_patches WHERE FILENAME='" . DBSafe($existing) . "'");
                $same = (str_replace("\r\n", "\n", (string)LoadFile($engine->patchesDir() . $existing)) === str_replace("\r\n", "\n", $content));
                if ($same && !empty($erec['ID'])) {
                    // repeated submit of the same form (double tap, Back + resend): already saved
                    $this->redirect("?view_mode=view_patch&id=" . $erec['ID']);
                }
                $out['EXISTING_ID'] = !empty($erec['ID']) ? (int)$erec['ID'] : '';
            }
            if (!AutopatcherEngine::isSafeId($new_name)) {
                $out['ERR_MSG'] = LANG_AUTOPATCHER_BAD_FILENAME;
            } elseif ($existing != '') {
                $out['ERR_MSG'] = LANG_AUTOPATCHER_FILE_EXISTS . ': ' . str_replace(ROOT, '', $engine->patchesDir()) . $existing;
            } else {
                $content = str_replace("\r\n", "\n", $content);
                SaveFile($engine->patchesDir() . $new_name, $content);
                $this->syncPatches();
                $rec = SQLSelectOne("SELECT * FROM autopatcher_patches WHERE FILENAME='" . DBSafe($new_name) . "'");
                if (!empty($rec['ID'])) {
                    $rec['STATUS'] = '';
                    SQLUpdate('autopatcher_patches', $rec);
                    $this->processPatch($rec, 'check');
                    $this->redirect("?view_mode=view_patch&id=" . $rec['ID']);
                }
                $this->redirect("?");
            }
            $out['FILENAME'] = $this->esc($new_name);
            $out['CONTENT'] = $this->esc($content);
            $out['ID'] = !empty($rec['ID']) ? (int)$rec['ID'] : '';
            return;
        }

        if ($filename != '') {
            $out['ID'] = (int)$rec['ID'];
            $out['FILENAME'] = $this->esc($filename);
            $out['TITLE'] = $this->esc($rec['TITLE']);
            $out['CONTENT'] = $this->esc(LoadFile($engine->patchesDir() . $filename));
            $out['STATUS_TITLE'] = $this->statusTitle($rec['STATUS']);
            $out['STATUS_CLASS'] = $this->statusClass($rec['STATUS']);
            // detailed per-file state
            $patch = $engine->loadPatch($filename);
            $eval = $engine->evaluate($patch);
            $files = array();
            foreach ($eval['files'] as $path => $f) {
                $files[] = array(
                    'PATH' => $this->esc($path),
                    'STATUS_TITLE' => $this->statusTitle($f['status']),
                    'STATUS_CLASS' => $this->statusClass($f['status']),
                    'DETAILS' => nl2br($this->esc(implode("\n", $f['details']))),
                );
            }
            $out['FILES'] = $files;
            $out['EVAL_MESSAGE'] = nl2br($this->esc($eval['status'] == AutopatcherEngine::ST_ERROR ? $eval['message'] : ''));
            $out['SOURCE'] = $this->esc($patch['source']);
            $out['NUMBER'] = (int)self::patchNumber($filename);
            $all = $this->sortPatchRows(SQLSelect("SELECT ID, FILENAME FROM autopatcher_patches"));
            foreach ($all as $i => $row) {
                if ((int)$row['ID'] == (int)$rec['ID']) {
                    $out['POSITION'] = $i + 1;
                }
            }
            $out['TOTAL'] = count($all);
            if (!empty($patch['excluded'])) {
                $out['EXCLUDED_LIST'] = $this->esc(implode(', ', $patch['excluded']));
            }
            if (gr('confirm_revert', 'int')) {
                $out['CONFIRM_REVERT'] = 1;
                $out['AFFECTED'] = $this->esc(gr('affected'));
                $out['FOR_DELETE'] = gr('for_delete', 'int') ? 1 : 0;
            }
            if (gr('confirm_delete', 'int')) {
                $out['CONFIRM_DELETE'] = 1;
            }
        }
    }

    function editPackage(&$out, $id)
    {
        $rec = $id ? SQLSelectOne("SELECT * FROM autopatcher_packages WHERE ID=" . (int)$id) : array();
        if ($this->mode == 'update') {
            $ok = 1;
            $old_name = isset($rec['NAME']) ? $rec['NAME'] : '';
            $old_url = isset($rec['URL']) ? $rec['URL'] : '';
            $rec['NAME'] = strtolower(trim(gr('name')));
            $rec['TITLE'] = trim(gr('title'));
            $rec['URL'] = trim(gr('url'));
            $rec['CHECK_PATH'] = trim(gr('check_path'));
            $rec['MODE'] = gr('package_mode', 'int');
            $rec['UPDATE_MODE'] = gr('update_mode', 'int');
            if ($rec['NAME'] == '' && $old_name != '') {
                $rec['NAME'] = $old_name;
            } elseif ($rec['NAME'] != '' && $rec['NAME'] != $old_name) {
                $rec['NAME_AUTO'] = 0;
            }

            // archive: uploaded file, or (to detect the name) downloaded from URL
            $staged = '';
            $staged_version = '';
            if (!empty($_FILES['archive']['tmp_name']) && is_uploaded_file($_FILES['archive']['tmp_name'])) {
                $staged = $this->dataDir() . 'tmp/upload_' . getmypid() . '.tgz';
                if (file_get_contents($_FILES['archive']['tmp_name'], false, null, 0, 2) !== "\x1f\x8b"
                    || !move_uploaded_file($_FILES['archive']['tmp_name'], $staged)) {
                    @unlink($staged);
                    $staged = '';
                    $out['ERR_MSG'] = LANG_AUTOPATCHER_NOT_TGZ;
                    $ok = 0;
                }
            } elseif ($ok && $rec['NAME'] == '' && $rec['URL'] != '') {
                // name will be taken from the archive after the background download;
                // until then the record gets a temporary name from the URL
                $rec['NAME'] = $this->uniqueName($this->guessNameFromUrl($rec['URL']), !empty($rec['ID']) ? (int)$rec['ID'] : 0);
                $rec['NAME_AUTO'] = 1;
            }
            if ($ok && $staged != '' && ($rec['NAME'] == '' || $rec['TITLE'] == '')) {
                $det = $this->detectPackage($staged);
                if ($rec['NAME'] == '') {
                    if ($det['name'] == '') {
                        $out['ERR_MSG'] = LANG_AUTOPATCHER_NAME_NOT_DETECTED . ($det['error'] != '' ? ' (' . $det['error'] . ')' : '');
                        $ok = 0;
                    } else {
                        $rec['NAME'] = $det['name'];
                    }
                }
                if ($rec['TITLE'] == '' && $det['title'] != '') {
                    $rec['TITLE'] = $det['title'];
                }
            }
            if ($ok && $rec['NAME'] == '') {
                $out['ERR_MSG'] = LANG_AUTOPATCHER_NAME_REQUIRED;
                $ok = 0;
            }
            if ($ok && (!preg_match('/^[a-z0-9_]+$/', $rec['NAME']) || $rec['NAME'] == $this->name)) {
                $ok = 0;
            }
            if ($ok) {
                $dup = SQLSelectOne("SELECT ID FROM autopatcher_packages WHERE NAME='" . DBSafe($rec['NAME']) . "'"
                    . (!empty($rec['ID']) ? " AND ID!=" . (int)$rec['ID'] : ''));
                if (!empty($dup['ID'])) {
                    $out['ERR_MSG'] = LANG_AUTOPATCHER_NAME_EXISTS . ': ' . $rec['NAME'];
                    $ok = 0;
                }
            }
            if (!$ok && empty($out['ERR_MSG'])) {
                $out['ERR_NAME'] = 1;
            }
            if ($rec['CHECK_PATH'] != '' && !$this->getEngine()->isSafePath($rec['CHECK_PATH'])) {
                $out['ERR_CHECK_PATH'] = 1;
                $ok = 0;
            }
            if ($ok) {
                if (empty($rec['ID'])) {
                    $rec['ENABLED'] = 1;
                    $rec['ID'] = SQLInsert('autopatcher_packages', $rec);
                } else {
                    SQLUpdate('autopatcher_packages', $rec);
                }
                if ($old_name != '' && $old_name != $rec['NAME'] && is_file($this->packageArchive($old_name))) {
                    @rename($this->packageArchive($old_name), $this->packageArchive($rec['NAME']));
                }
                if ($staged != '') {
                    $target = $this->packageArchive($rec['NAME']);
                    @unlink($target);
                    if (@rename($staged, $target)) {
                        $rec['CACHED'] = date('Y-m-d H:i:s');
                        $rec['CACHED_SHA'] = $staged_version;
                        $this->setPackageStatus($rec, 'cached', basename($target) . ', ' . round(filesize($target) / 1024) . ' KB');
                    }
                } elseif ($rec['URL'] != '' && (!is_file($this->packageArchive($rec['NAME'])) || $rec['URL'] != $old_url)) {
                    // save now, download in the background
                    $this->startBackgroundDownload($rec);
                }
                $this->redirect("?");
            }
            if ($staged != '') {
                @unlink($staged);
            }
        }
        foreach (array('ID', 'NAME', 'TITLE', 'URL', 'CHECK_PATH', 'MODE') as $k) {
            $out[$k] = isset($rec[$k]) ? $this->esc($rec[$k]) : '';
        }
        $out['UPDATE_MODE'] = isset($rec['UPDATE_MODE']) ? (int)$rec['UPDATE_MODE'] : 1;
        $out['DEFAULT_CHECK_PATH'] = 'modules/&lt;name&gt;/&lt;name&gt;.class.php';
    }

    // ------------------------------------------------------------------
    // Install / uninstall
    // ------------------------------------------------------------------

    /**
     * Called by control_modules on every cycle.php start (before cycles are launched),
     * after market installs and after the system update ("?mode=clear").
     */
    function install($parent_name = '')
    {
        parent::install($parent_name);
        if (function_exists('subscribeToEvent')) {
            subscribeToEvent($this->name, 'HOURLY');
            subscribeToEvent($this->name, 'AUTOPATCHER_DOWNLOAD');
        }
        $this->is_boot = (PHP_SAPI == 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && preg_match('/cycle\.php$/', $_SERVER['SCRIPT_FILENAME']));
        $this->autoRun($this->is_boot ? 'boot' : 'install');
        $this->is_boot = false;
    }

    function uninstall()
    {
        // patched files are left as they are; data in cms/autopatcher/ is kept
        SQLExec('DROP TABLE IF EXISTS autopatcher_patches');
        SQLExec('DROP TABLE IF EXISTS autopatcher_packages');
        if (function_exists('unsubscribeFromEvent')) {
            unsubscribeFromEvent($this->name, 'HOURLY');
            unsubscribeFromEvent($this->name, 'AUTOPATCHER_DOWNLOAD');
        }
        parent::uninstall();
    }

    function dbInstall($data = '')
    {
        $data = <<<EOD
 autopatcher_patches: ID int(10) unsigned NOT NULL auto_increment
 autopatcher_patches: FILENAME varchar(255) NOT NULL DEFAULT ''
 autopatcher_patches: TITLE varchar(255) NOT NULL DEFAULT ''
 autopatcher_patches: ENABLED int(3) NOT NULL DEFAULT '1'
 autopatcher_patches: STATUS varchar(20) NOT NULL DEFAULT ''
 autopatcher_patches: MESSAGE text
 autopatcher_patches: CHECKED datetime
 autopatcher_patches: APPLIED datetime
 autopatcher_patches: APPLIED_HASH text

 autopatcher_packages: ID int(10) unsigned NOT NULL auto_increment
 autopatcher_packages: NAME varchar(100) NOT NULL DEFAULT ''
 autopatcher_packages: TITLE varchar(255) NOT NULL DEFAULT ''
 autopatcher_packages: URL varchar(255) NOT NULL DEFAULT ''
 autopatcher_packages: CHECK_PATH varchar(255) NOT NULL DEFAULT ''
 autopatcher_packages: MODE int(3) NOT NULL DEFAULT '0'
 autopatcher_packages: ENABLED int(3) NOT NULL DEFAULT '1'
 autopatcher_packages: STATUS varchar(20) NOT NULL DEFAULT ''
 autopatcher_packages: MESSAGE text
 autopatcher_packages: CACHED datetime
 autopatcher_packages: INSTALLED datetime
 autopatcher_packages: LAST_REASON varchar(20) NOT NULL DEFAULT ''
 autopatcher_packages: FILES int(10) NOT NULL DEFAULT '0'
 autopatcher_packages: LAST_CHECK varchar(20) NOT NULL DEFAULT ''
 autopatcher_packages: CHECKED datetime
 autopatcher_packages: UPDATE_MODE int(3) NOT NULL DEFAULT '1'
 autopatcher_packages: CACHED_SHA varchar(100) NOT NULL DEFAULT ''
 autopatcher_packages: INSTALLED_SHA varchar(100) NOT NULL DEFAULT ''
 autopatcher_packages: LATEST_SHA varchar(100) NOT NULL DEFAULT ''
 autopatcher_packages: UPDATE_CHECKED datetime
 autopatcher_packages: UPDATE_MESSAGE varchar(255) NOT NULL DEFAULT ''
 autopatcher_packages: NAME_AUTO int(3) NOT NULL DEFAULT '0'
 autopatcher_packages: DL_STARTED datetime
EOD;
        parent::dbInstall($data);
    }
}
