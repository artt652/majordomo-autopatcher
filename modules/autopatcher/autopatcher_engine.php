<?php
/**
 * AutoPatcher engine
 *
 * Pure PHP (7.0+ / 8.x), no MajorDoMo dependencies.
 * Applies idempotent text patches to files under a root directory.
 *
 * Supported patch formats (one patch = one file in the patches directory):
 *   *.diff, *.patch  - unified diff (git diff / diff -u), paths relative to root, a/ b/ prefixes stripped
 *   *.replace        - SEARCH/REPLACE blocks:
 *                        # title: Short title
 *                        FILE: lib/hooks.inc.php
 *                        <<<<<<< SEARCH
 *                        old lines
 *                        =======
 *                        new lines
 *                        >>>>>>> REPLACE
 *
 * Every change is evaluated against the current file content:
 *   applied  - the new text is already there (patch applied earlier or merged upstream)
 *   pending  - the old text is found exactly once, the change can be applied
 *   conflict - neither old nor new text is found unambiguously (upstream changed the code)
 * A patch is applied only as a whole: if any change of any file is in conflict, nothing is written.
 * Files are written via temp file + php -l (for .php) + rename, with a backup of the original.
 */

class AutopatcherEngine
{
    const ST_APPLIED = 'applied';
    const ST_PENDING = 'pending';
    const ST_CONFLICT = 'conflict';
    const ST_ERROR = 'error';

    public $root;
    public $dataDir;
    public $phpBinary = '';
    public $keepBackups = 30;
    protected $logger = null;
    protected $lockHandle = null;

    public function __construct($root, $dataDir, $logger = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $this->dataDir = rtrim(str_replace('\\', '/', $dataDir), '/') . '/';
        $this->logger = $logger;
    }

    public function log($message)
    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }

    public function patchesDir()
    {
        return $this->dataDir . 'patches/';
    }

    public function backupDir()
    {
        return $this->dataDir . 'backup/';
    }

    public function ensureDirs()
    {
        foreach (array($this->dataDir, $this->patchesDir(), $this->backupDir(), $this->dataDir . 'packages/', $this->dataDir . 'tmp/') as $dir) {
            if (!is_dir($dir)) {
                @umask(0);
                @mkdir($dir, 0777, true);
            }
        }
        if (!is_file($this->dataDir . '.htaccess')) {
            @file_put_contents($this->dataDir . '.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($this->dataDir . 'index.html')) {
            @file_put_contents($this->dataDir . 'index.html', '');
        }
    }

    // ------------------------------------------------------------------
    // Locking (cycle.php boot and web request can run at the same time)
    // ------------------------------------------------------------------

    public function lock()
    {
        $this->ensureDirs();
        $this->lockHandle = @fopen($this->dataDir . 'autopatcher.lock', 'c');
        if (!$this->lockHandle) {
            return true; // cannot lock (read-only?) - do not block the work
        }
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }
        return true;
    }

    public function unlock()
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    // ------------------------------------------------------------------
    // Patch discovery and parsing
    // ------------------------------------------------------------------

    /**
     * @return array list of patch file names (sorted), e.g. 10-hooks.diff
     */
    public function listPatchFiles()
    {
        $result = array();
        $dir = $this->patchesDir();
        if (!is_dir($dir)) {
            return $result;
        }
        $files = scandir($dir);
        if (!is_array($files)) {
            return $result;
        }
        foreach ($files as $file) {
            if ($file == '.' || $file == '..' || !is_file($dir . $file)) {
                continue;
            }
            if (preg_match('/\.(diff|patch|replace)$/i', $file)) {
                $result[] = $file;
            }
        }
        // natural order: 5-x before 10-y, 20-y before 100-z
        usort($result, 'strnatcasecmp');
        return $result;
    }

    public static function isSafeId($id)
    {
        // any file name without path separators / control chars (Android adds " (1)" to downloads)
        $id = (string)$id;
        return $id !== '' && $id[0] !== '.' && strpbrk($id, "/\\:*?\"<>|") === false
            && !preg_match('/[\x00-\x1f]/', $id) && (bool)preg_match('/\.(diff|patch|replace)$/i', $id);
    }

    /**
     * Parse patch file
     * @return array ['id','title','description','type','files'=>[path=>['create'=>bool,'ops'=>[...]]],'error']
     */
    public function loadPatch($id)
    {
        $patch = array('id' => $id, 'title' => $id, 'description' => '', 'type' => '', 'files' => array(), 'error' => '',
            'source' => '', 'root' => '', 'exclude' => array(), 'unsupported' => array(), 'excluded' => array());
        if (!self::isSafeId($id) || !is_file($this->patchesDir() . $id)) {
            $patch['error'] = 'Patch file not found';
            return $patch;
        }
        $text = (string)file_get_contents($this->patchesDir() . $id);
        return $this->parseText($text, preg_match('/\.replace$/i', $id) ? 'replace' : 'diff', $patch);
    }

    /**
     * Parse patch text without a file (import preview)
     */
    public function parseText($text, $type, $patch = null)
    {
        if ($patch === null) {
            $patch = array('id' => '', 'title' => '', 'description' => '', 'type' => '', 'files' => array(), 'error' => '',
                'source' => '', 'root' => '', 'exclude' => array(), 'unsupported' => array(), 'excluded' => array());
        }
        $text = str_replace("\r\n", "\n", (string)$text);
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        if ($type == 'replace') {
            $patch['type'] = 'replace';
            $this->parseReplace($text, $patch);
        } else {
            $patch['type'] = 'diff';
            $this->parseDiff($text, $patch);
        }
        $this->applyHeaders($patch);
        if ($patch['error'] == '' && !count($patch['files'])) {
            $patch['error'] = 'No changes found in patch';
        }
        foreach (array_keys($patch['files']) as $path) {
            if (!$this->isSafePath($path)) {
                $patch['error'] = 'Unsafe path in patch: ' . $path;
                $patch['files'] = array();
                break;
            }
        }
        return $patch;
    }

    public function isSafePath($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        if ($path === '' || $path[0] == '/' || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '..' || $part === '') {
                return false;
            }
        }
        // never patch own data or DB config
        if (preg_match('/^cms\/autopatcher\//', $path) || $path === 'config.php') {
            return false;
        }
        return true;
    }

    protected function parseHeader($line, &$patch)
    {
        if (preg_match('/^#\s*title:\s*(.+)$/i', $line, $m)) {
            $patch['title'] = trim($m[1]);
            return true;
        }
        if (preg_match('/^#\s*description:\s*(.+)$/i', $line, $m)) {
            $patch['description'] .= ($patch['description'] != '' ? "\n" : '') . trim($m[1]);
            return true;
        }
        if (preg_match('/^#\s*source:\s*(\S+)\s*$/i', $line, $m)) {
            $patch['source'] = $m[1];
            return true;
        }
        if (preg_match('/^#\s*root:\s*(\S+)\s*$/i', $line, $m)) {
            $patch['root'] = trim(str_replace('\\', '/', $m[1]), '/');
            return true;
        }
        if (preg_match('/^#\s*exclude:\s*(.+?)\s*$/i', $line, $m)) {
            $patch['exclude'][] = $m[1];
            return true;
        }
        return false;
    }

    /**
     * # exclude: drop files (glob, path as in the diff); # root: prefix for all paths
     * (e.g. a PR to a standalone repo that lives in modules/<name>/ here);
     * unsupported changes (deletion, rename, binary) are an error unless excluded
     */
    protected function applyHeaders(&$patch)
    {
        if ($patch['error'] != '') {
            return;
        }
        $isExcluded = function ($path) use ($patch) {
            foreach ($patch['exclude'] as $glob) {
                if (fnmatch($glob, $path) || fnmatch(rtrim($glob, '/') . '/*', $path)) {
                    return true;
                }
            }
            return false;
        };
        foreach (array_keys($patch['files']) as $path) {
            if ($isExcluded($path)) {
                $patch['excluded'][] = $path;
                unset($patch['files'][$path]);
            }
        }
        $problems = array();
        foreach ($patch['unsupported'] as $path => $reason) {
            if ($isExcluded($path)) {
                $patch['excluded'][] = $path;
            } else {
                $problems[] = $path . ' (' . $reason . ')';
            }
        }
        if (count($problems)) {
            $patch['error'] = 'Not supported: ' . implode(', ', $problems) . '. Add "# exclude: <path>" to skip these files';
            return;
        }
        if ($patch['root'] !== '') {
            if (!$this->isSafePath($patch['root'] . '/x')) {
                $patch['error'] = 'Unsafe root: ' . $patch['root'];
                return;
            }
            $files = array();
            foreach ($patch['files'] as $path => $spec) {
                $files[$patch['root'] . '/' . $path] = $spec;
            }
            $patch['files'] = $files;
        }
    }

    protected function parseReplace($text, &$patch)
    {
        $lines = explode("\n", $text);
        $total = count($lines);
        $file = '';
        $i = 0;
        while ($i < $total) {
            $line = $lines[$i];
            if ($this->parseHeader($line, $patch)) {
                $i++;
                continue;
            }
            if (preg_match('/^FILE:\s*(.+?)\s*$/', $line, $m)) {
                $file = $m[1];
                if (!isset($patch['files'][$file])) {
                    $patch['files'][$file] = array('create' => false, 'ops' => array());
                }
                $i++;
                continue;
            }
            if (rtrim($line) === '<<<<<<< SEARCH') {
                if ($file === '') {
                    $patch['error'] = 'SEARCH block before FILE: line ' . ($i + 1);
                    return;
                }
                $start = $i + 1;
                $search = array();
                $replace = array();
                $i++;
                while ($i < $total && rtrim($lines[$i]) !== '=======') {
                    $search[] = $lines[$i];
                    $i++;
                }
                $i++;
                while ($i < $total && rtrim($lines[$i]) !== '>>>>>>> REPLACE') {
                    $replace[] = $lines[$i];
                    $i++;
                }
                if ($i >= $total) {
                    $patch['error'] = 'Unterminated SEARCH/REPLACE block at line ' . $start;
                    return;
                }
                if (!count($search)) {
                    $patch['error'] = 'Empty SEARCH block at line ' . $start;
                    return;
                }
                $patch['files'][$file]['ops'][] = array(
                    'search' => implode("\n", $search) . "\n",
                    'replace' => count($replace) ? implode("\n", $replace) . "\n" : '',
                    'line' => 0,
                    'label' => 'block @' . $start,
                );
                $i++;
                continue;
            }
            $i++;
        }
    }

    protected function diffPath($raw)
    {
        $raw = preg_replace('/\t.*$/', '', $raw);
        $raw = trim($raw);
        if ($raw === '/dev/null') {
            return $raw;
        }
        if (strlen($raw) > 1 && $raw[0] == '"' && substr($raw, -1) == '"') {
            $raw = stripcslashes(substr($raw, 1, -1));
        }
        if (preg_match('/^[ab]\//', $raw)) {
            $raw = substr($raw, 2);
        }
        return $raw;
    }

    protected function parseDiff($text, &$patch)
    {
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $total = count($lines);
        $i = 0;
        $seenDiff = false;
        $gitPath = '';
        while ($i < $total) {
            $line = $lines[$i];
            if (!$seenDiff && $this->parseHeader($line, $patch)) {
                $i++;
                continue;
            }
            if (preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $gm)) {
                $seenDiff = true;
                $gitPath = $gm[2];
                $i++;
                continue;
            }
            if ($gitPath !== '' && preg_match('/^(rename from|copy from|Binary files|GIT binary patch)/', $line, $um)) {
                $what = array('rename from' => 'rename', 'copy from' => 'copy', 'Binary files' => 'binary file', 'GIT binary patch' => 'binary file');
                $patch['unsupported'][$gitPath] = $what[$um[1]];
                $i++;
                continue;
            }
            if (substr($line, 0, 4) === '--- ' && $i + 1 < $total && substr($lines[$i + 1], 0, 4) === '+++ ') {
                $seenDiff = true;
                $old = $this->diffPath(substr($line, 4));
                $new = $this->diffPath(substr($lines[$i + 1], 4));
                $i += 2;
                $file = $new;
                $create = ($old === '/dev/null');
                $skip = false;
                if ($new === '/dev/null') {
                    // deletion: never delete files; an error unless excluded (see applyHeaders)
                    $patch['unsupported'][$old] = 'file deletion';
                    $skip = true;
                } elseif ($old !== '/dev/null' && $old !== $new) {
                    $patch['unsupported'][$new] = 'rename';
                    $skip = true;
                }
                if (!$skip && !isset($patch['files'][$file])) {
                    $patch['files'][$file] = array('create' => $create, 'ops' => array());
                }
                // hunks
                while ($i < $total && preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $lines[$i], $m)) {
                    $oldCount = (isset($m[2]) && $m[2] !== '') ? (int)$m[2] : 1;
                    $newStart = (int)$m[3];
                    $newCount = (isset($m[4]) && $m[4] !== '') ? (int)$m[4] : 1;
                    $hunkLine = $i + 1;
                    $i++;
                    $oldLines = array();
                    $newLines = array();
                    $oldNoEol = false;
                    $newNoEol = false;
                    $lastSide = '';
                    while ($i < $total && (count($oldLines) < $oldCount || count($newLines) < $newCount || ($i < $total && substr($lines[$i], 0, 1) === '\\'))) {
                        $l = $lines[$i];
                        $c = ($l === '') ? ' ' : $l[0];
                        $body = (string)substr($l, 1);
                        if ($c === '\\') {
                            if ($lastSide === '-' || $lastSide === ' ') $oldNoEol = true;
                            if ($lastSide === '+' || $lastSide === ' ') $newNoEol = true;
                        } elseif ($c === ' ') {
                            $oldLines[] = $body;
                            $newLines[] = $body;
                        } elseif ($c === '-') {
                            $oldLines[] = $body;
                        } elseif ($c === '+') {
                            $newLines[] = $body;
                        } else {
                            break;
                        }
                        $lastSide = $c;
                        $i++;
                    }
                    if (count($oldLines) != $oldCount || count($newLines) != $newCount) {
                        $patch['error'] = 'Malformed hunk at line ' . $hunkLine;
                        return;
                    }
                    $search = count($oldLines) ? implode("\n", $oldLines) . ($oldNoEol ? '' : "\n") : '';
                    $replace = count($newLines) ? implode("\n", $newLines) . ($newNoEol ? '' : "\n") : '';
                    if ($skip) {
                        // hunks of a deleted / renamed file: consumed, not applied
                    } elseif ($create) {
                        $patch['files'][$file]['content'] = $replace;
                    } else {
                        if ($search === '') {
                            $patch['error'] = 'Hunk without context at line ' . $hunkLine . ' (regenerate diff with context)';
                            return;
                        }
                        $patch['files'][$file]['ops'][] = array(
                            'search' => $search,
                            'replace' => $replace,
                            'line' => $newStart,
                            'label' => 'hunk @' . $hunkLine,
                        );
                    }
                }
                continue;
            }
            $i++;
        }
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * Positions of $needle in $haystack that start at the beginning of a line
     */
    public static function linePositions($haystack, $needle)
    {
        $result = array();
        if ($needle === '') {
            return $result;
        }
        $offset = 0;
        while (($pos = strpos($haystack, $needle, $offset)) !== false) {
            if ($pos == 0 || $haystack[$pos - 1] == "\n") {
                $result[] = $pos;
            }
            $offset = $pos + 1;
        }
        return $result;
    }

    protected static function lineOf($text, $pos)
    {
        return substr_count($text, "\n", 0, $pos) + 1;
    }

    /**
     * Choose one position: unique match, or the one at the expected line (diff hint)
     */
    protected static function pickPosition($content, $positions, $expectedLine)
    {
        if (count($positions) == 1) {
            return $positions[0];
        }
        if (count($positions) > 1 && $expectedLine > 0) {
            foreach ($positions as $pos) {
                if (self::lineOf($content, $pos) == $expectedLine) {
                    return $pos;
                }
            }
        }
        return false;
    }

    /**
     * Evaluate one op against content.
     * @return array [status, newContent, message, matchedLine]
     */
    protected function evaluateOp($content, $op, $expectedLine)
    {
        $search = $op['search'];
        $replace = $op['replace'];
        $searchPos = self::linePositions($content, $search);
        $replacePos = ($replace !== '') ? self::linePositions($content, $replace) : array();
        $replaceContainsSearch = ($replace !== '' && strpos($replace, $search) !== false);
        $searchContainsReplace = ($replace !== '' && strpos($search, $replace) !== false);

        if ($replaceContainsSearch) {
            // insertion around an anchor: anchor stays after applying
            $pos = self::pickPosition($content, $replacePos, $expectedLine);
            if ($pos !== false) {
                return array(self::ST_APPLIED, $content, '', self::lineOf($content, $pos));
            }
            if (count($replacePos) > 1) {
                return array(self::ST_CONFLICT, $content, 'new text found ' . count($replacePos) . ' times', 0);
            }
        } else {
            if (!count($searchPos) && ($replace === '' || count($replacePos))) {
                // old text gone and new text present (or pure deletion)
                if ($searchContainsReplace || $replace === '' || self::pickPosition($content, $replacePos, $expectedLine) !== false) {
                    $line = count($replacePos) ? self::lineOf($content, $replacePos[0]) : 0;
                    return array(self::ST_APPLIED, $content, '', $line);
                }
                return array(self::ST_CONFLICT, $content, 'new text found ' . count($replacePos) . ' times', 0);
            }
        }

        $pos = self::pickPosition($content, $searchPos, $expectedLine);
        if ($pos !== false) {
            $new = substr($content, 0, $pos) . $replace . substr($content, $pos + strlen($search));
            return array(self::ST_PENDING, $new, '', self::lineOf($content, $pos));
        }
        if (count($searchPos) > 1) {
            return array(self::ST_CONFLICT, $content, 'old text found ' . count($searchPos) . ' times (ambiguous)', 0);
        }
        return array(self::ST_CONFLICT, $content, 'old text not found (file changed upstream?)', 0);
    }

    protected static function toEol($text, $eol)
    {
        return ($eol === "\n") ? $text : str_replace("\n", $eol, $text);
    }

    /**
     * Evaluate patch (optionally reversed) against current files.
     * @return array ['status','message','files'=>[path=>['status','old','new','exists','details'=>[]]],'counts'=>[]]
     */
    public function evaluate($patch, $reverse = false, $overrides = array())
    {
        $res = array('status' => self::ST_APPLIED, 'message' => '', 'files' => array(),
            'counts' => array(self::ST_APPLIED => 0, self::ST_PENDING => 0, self::ST_CONFLICT => 0));
        if ($patch['error'] != '') {
            $res['status'] = self::ST_ERROR;
            $res['message'] = $patch['error'];
            return $res;
        }
        $messages = array();
        foreach ($patch['files'] as $path => $spec) {
            $full = $this->root . $path;
            // $overrides: simulated file contents (path => content, false = file removed)
            $hasOverride = array_key_exists($path, $overrides);
            $exists = $hasOverride ? ($overrides[$path] !== false) : is_file($full);
            $fileRes = array('status' => self::ST_APPLIED, 'exists' => $exists, 'old' => null, 'new' => null, 'details' => array(), 'create' => !empty($spec['create']));

            if (!empty($spec['create'])) {
                $want = isset($spec['content']) ? $spec['content'] : '';
                $current = $exists ? str_replace("\r\n", "\n", (string)($hasOverride ? $overrides[$path] : file_get_contents($full))) : null;
                if (!$reverse) {
                    if (!$exists) {
                        $fileRes['status'] = self::ST_PENDING;
                        $fileRes['new'] = $want;
                    } elseif ($current === $want) {
                        $fileRes['status'] = self::ST_APPLIED;
                    } else {
                        $fileRes['status'] = self::ST_CONFLICT;
                        $fileRes['details'][] = 'new file already exists with different content';
                    }
                } else {
                    if (!$exists) {
                        $fileRes['status'] = self::ST_APPLIED;
                    } elseif ($current === $want) {
                        $fileRes['status'] = self::ST_PENDING;
                        $fileRes['new'] = false; // remove (moved to backup)
                    } else {
                        $fileRes['status'] = self::ST_CONFLICT;
                        $fileRes['details'][] = 'created file was modified, not removing';
                    }
                }
                $res['counts'][$fileRes['status']]++;
                $res['files'][$path] = $fileRes;
                continue;
            }

            if (!$exists) {
                $fileRes['status'] = self::ST_CONFLICT;
                $fileRes['details'][] = 'file not found';
                $res['counts'][self::ST_CONFLICT]++;
                $res['files'][$path] = $fileRes;
                continue;
            }

            $original = $hasOverride ? (string)$overrides[$path] : (string)file_get_contents($full);
            $eol = (strpos($original, "\r\n") !== false) ? "\r\n" : "\n";
            $content = $original;
            $fileRes['old'] = $original;
            $pending = 0;
            $conflict = 0;
            $drift = 0;
            $ops = $spec['ops'];
            if ($reverse) {
                $ops = array_reverse($ops);
            }
            foreach ($ops as $op) {
                $search = self::toEol($reverse ? $op['replace'] : $op['search'], $eol);
                $replace = self::toEol($reverse ? $op['search'] : $op['replace'], $eol);
                if ($search === '') {
                    // reverse of a pure deletion cannot be located
                    $fileRes['details'][] = $op['label'] . ': cannot be reverted automatically (deletion without context)';
                    $conflict++;
                    $res['counts'][self::ST_CONFLICT]++;
                    continue;
                }
                $expected = ($op['line'] > 0 && !$reverse) ? $op['line'] + $drift : 0;
                list($st, $newContent, $msg, $matched) = $this->evaluateOp($content, array('search' => $search, 'replace' => $replace), $expected);
                if ($matched > 0 && $op['line'] > 0) {
                    $drift = $matched - $op['line'];
                }
                $res['counts'][$st]++;
                if ($st == self::ST_PENDING) {
                    $pending++;
                    $content = $newContent;
                } elseif ($st == self::ST_CONFLICT) {
                    $conflict++;
                    $fileRes['details'][] = $op['label'] . ': ' . $msg;
                }
            }
            if ($conflict) {
                $fileRes['status'] = self::ST_CONFLICT;
            } elseif ($pending) {
                $fileRes['status'] = self::ST_PENDING;
                $fileRes['new'] = $content;
            }
            $res['files'][$path] = $fileRes;
        }

        foreach ($res['files'] as $path => $f) {
            if ($f['status'] == self::ST_CONFLICT) {
                $res['status'] = self::ST_CONFLICT;
                $messages[] = $path . ': ' . implode('; ', $f['details']);
            } elseif ($f['status'] == self::ST_PENDING && $res['status'] != self::ST_CONFLICT) {
                $res['status'] = self::ST_PENDING;
            }
        }
        $res['message'] = implode("\n", $messages);
        return $res;
    }

    // ------------------------------------------------------------------
    // Applying
    // ------------------------------------------------------------------

    public function findPhp()
    {
        if ($this->phpBinary != '') {
            return $this->phpBinary;
        }
        $candidates = array();
        if (defined('PHP_BINARY') && PHP_BINARY != '') {
            $bin = PHP_BINARY;
            if (preg_match('/php-cgi(\.exe)?$/i', $bin) || preg_match('/php-fpm/i', $bin)) {
                $candidates[] = dirname($bin) . DIRECTORY_SEPARATOR . (stripos($bin, '.exe') !== false ? 'php.exe' : 'php');
            }
            $candidates[] = $bin;
        }
        if (defined('PHP_BINDIR')) {
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        }
        foreach ($candidates as $c) {
            if (@is_file($c) && @is_executable($c) && !preg_match('/php-fpm/i', $c)) {
                $this->phpBinary = $c;
                return $c;
            }
        }
        $this->phpBinary = 'php';
        return $this->phpBinary;
    }

    /**
     * @return true|string true if OK or lint not possible, error text otherwise
     */
    public function lintPhp($file)
    {
        if (!function_exists('exec')) {
            return true;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled)) {
            return true;
        }
        $output = array();
        $code = 0;
        @exec(escapeshellarg($this->findPhp()) . ' -n -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        $text = trim(implode("\n", $output));
        if ($code === 0) {
            return true;
        }
        if ($code == 126 || $code == 127 || $text === '') {
            $this->log('php -l not available (' . $code . '), syntax check skipped');
            return true;
        }
        return str_replace($file, basename($file), $text);
    }

    protected function backupFile($path, $backupRoot)
    {
        $src = $this->root . $path;
        // .bak suffix: a backup of a .php file must never be executable from the web
        $dst = $backupRoot . $path . '.bak';
        if (!is_dir(dirname($dst))) {
            @umask(0);
            @mkdir(dirname($dst), 0777, true);
        }
        return @copy($src, $dst);
    }

    protected function pruneBackups()
    {
        $dir = $this->backupDir();
        $list = @scandir($dir);
        if (!is_array($list)) return;
        $list = array_values(array_filter($list, function ($f) use ($dir) {
            return $f != '.' && $f != '..' && is_dir($dir . $f);
        }));
        sort($list, SORT_STRING);
        while (count($list) > $this->keepBackups) {
            $this->removeDir($dir . array_shift($list));
        }
    }

    public function removeDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f == '.' || $f == '..') continue;
            $p = $dir . '/' . $f;
            if (is_dir($p) && !is_link($p)) {
                $this->removeDir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * Apply (or revert) a patch atomically.
     * @return array ['status' => applied|conflict|error|..., 'message', 'changed' => [paths], 'backup' => dir]
     */
    public function apply($patch, $reverse = false, $dryRun = false)
    {
        $eval = $this->evaluate($patch, $reverse);
        $result = array('status' => $eval['status'], 'message' => $eval['message'], 'changed' => array(), 'backup' => '', 'eval' => $eval);
        if ($eval['status'] != self::ST_PENDING || $dryRun) {
            return $result;
        }

        $backupRoot = $this->backupDir() . date('Ymd-His') . '_' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $patch['id']) . ($reverse ? '_revert' : '') . '/';
        $temps = array();
        $errors = array();

        // 1. write temp files next to originals and lint them
        foreach ($eval['files'] as $path => $f) {
            if ($f['status'] != self::ST_PENDING || $f['new'] === false) {
                continue;
            }
            $full = $this->root . $path;
            if (!is_dir(dirname($full))) {
                @umask(0);
                if (!@mkdir(dirname($full), 0777, true)) {
                    $errors[] = $path . ': cannot create directory';
                    break;
                }
            }
            $tmp = $full . '.autopatcher.tmp';
            if (@file_put_contents($tmp, $f['new']) === false) {
                $errors[] = $path . ': cannot write (permissions?)';
                break;
            }
            $temps[$path] = $tmp;
            if (preg_match('/\.php$/i', $path)) {
                $lint = $this->lintPhp($tmp);
                if ($lint !== true) {
                    $errors[] = $path . ': ' . $lint;
                    break;
                }
            }
        }
        if (count($errors)) {
            foreach ($temps as $tmp) {
                @unlink($tmp);
            }
            $result['status'] = self::ST_ERROR;
            $result['message'] = implode("\n", $errors);
            return $result;
        }

        // 2. backup originals
        foreach ($eval['files'] as $path => $f) {
            if ($f['status'] == self::ST_PENDING && $f['exists']) {
                if (!$this->backupFile($path, $backupRoot)) {
                    $errors[] = $path . ': backup failed';
                }
            }
        }
        if (count($errors)) {
            foreach ($temps as $tmp) {
                @unlink($tmp);
            }
            $result['status'] = self::ST_ERROR;
            $result['message'] = implode("\n", $errors);
            return $result;
        }
        @file_put_contents($backupRoot . '.autopatcher.json', json_encode(array(
            'patch' => $patch['id'], 'reverse' => $reverse, 'time' => date('Y-m-d H:i:s'),
            'files' => array_keys($temps),
        )));

        // 3. replace files (rename is atomic on the same filesystem)
        $done = array();
        foreach ($eval['files'] as $path => $f) {
            if ($f['status'] != self::ST_PENDING) {
                continue;
            }
            $full = $this->root . $path;
            if ($f['new'] === false) {
                // revert of a created file: keep it in backup, remove from tree
                $ok = @unlink($full);
            } else {
                $perms = $f['exists'] ? (@fileperms($full) & 0777) : 0;
                $ok = @rename($temps[$path], $full);
                if (!$ok) {
                    // Windows cannot rename over an existing file
                    $ok = (@copy($temps[$path], $full) && @unlink($temps[$path])) || is_file($full) && md5_file($full) === md5($f['new']);
                }
                if ($ok && $perms) {
                    @chmod($full, $perms);
                }
            }
            if (!$ok) {
                $errors[] = $path . ': cannot replace file';
                break;
            }
            $done[] = $path;
        }

        if (count($errors)) {
            // rollback what was replaced
            foreach ($done as $path) {
                $bak = $backupRoot . $path . '.bak';
                if (is_file($bak)) {
                    @copy($bak, $this->root . $path);
                } else {
                    @unlink($this->root . $path);
                }
            }
            foreach ($temps as $tmp) {
                @unlink($tmp);
            }
            $result['status'] = self::ST_ERROR;
            $result['message'] = implode("\n", $errors) . "\nRolled back.";
            return $result;
        }

        if (function_exists('opcache_invalidate')) {
            foreach ($done as $path) {
                @opcache_invalidate($this->root . $path, true);
            }
        }
        $this->pruneBackups();
        $result['status'] = $reverse ? 'reverted' : self::ST_APPLIED;
        $result['changed'] = $done;
        $result['backup'] = $backupRoot;
        $this->log(($reverse ? 'Reverted ' : 'Applied ') . $patch['id'] . ': ' . implode(', ', $done));
        return $result;
    }
}
