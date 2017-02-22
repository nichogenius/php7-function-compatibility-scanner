<?php

/*
 * Copyright (c) 2016 Gabor Gyorvari
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *  http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class MalwareScanner
{
    const ANSI_GREEN = "\033[32m";
    const ANSI_RED = "\033[31m";
    const ANSI_YELLOW = "\033[33m";
    const ANSI_OFF = "\033[0m";

    private $extension = '.php';
    private $flagHideOk = false;
    private $flagHideWhitelist = false;
    private $extraCheck = false;
    private $whitelist = array();
    private $ignore = array();
    private $stat = array(
        'directories' => 0,
        'files_scanned' => 0,
        'files_infected' => 0,
    );
    private $followSymlink = false;

    public function __construct()
    {
        $options = getopt('hd:e::i::', array('hide-ok', 'hide-whitelist', 'extra-check', 'follow-symlink'));
        if (isset($options['h'])) {
            $this->showHelp();
        } else {
            if (isset($options['e'])) {
                $ext = $options['e'];
                if ($ext[0] != '.') {
                    $ext = '.' . $ext;
                }
                $this->extension = strtolower($ext);
            }
            if (isset($options['i'])) {
                $this->ignore = is_array($options['i']) ? $options['i'] : array($options['i']);
            }
            if (isset($options['hide-ok'])) {
                $this->flagHideOk = true;
            }
            if (isset($options['hide-whitelist'])) {
                $this->flagHideWhitelist = true;
            }
            if (isset($options['extra-check'])) {
                $this->extraCheck = true;
            }
            if (isset($options['follow-symlink'])) {
                $this->followSymlink = true;
            }
            if (isset($options['d'])) {
                $this->run($options['d']);
            } else {
                $this->out(MalwareScanner::ANSI_RED, 'ER', 'No directory specified');
                $this->showHelp();
            }
        }
    }

    public function run($dir)
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            $this->out(self::ANSI_RED, 'ER', 'Specified path is not a directory: ' . $dir);
            exit(-1);
        }
        $start = time();
        $this->loadWhitelist();
        $this->process($dir . '/');
        $this->report($start, $dir . '/');
    }

    private function loadWhitelist()
    {
        if (!is_file(__DIR__ . '/whitelist.txt')) {
            return;
        }
        $fp = fopen(__DIR__ . '/whitelist.txt', 'r');
        while (!feof($fp)) {
            $line = fgets($fp);
            $this->whitelist[] = substr($line, 0, 32);
        }
    }

    private function inWhitelist($hash)
    {
        return in_array($hash, $this->whitelist);
    }

    private function process($dir)
    {
        $dh = opendir($dir);
        if (!$dh) {
            return;
        }
        $this->stat['directories']++;
        while (($file = readdir($dh)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            if ($this->isIgnored($dir . $file)) {
                continue;
            }
            if (!$this->followSymlink && is_link($dir . $file)) {
                continue;
            }
            if (is_dir($dir . $file)) {
                $this->process($dir . $file . '/');
            } elseif (is_file($dir . $file)) {
                $ext = strtolower(substr($file, strrpos($file, '.')));
                if ($ext == $this->extension) {
                    $this->scan($dir . $file);
                }
            }
        }
        closedir($dh);
    }

    private function report($start, $dir)
    {
        $end = time();
        echo 'Start time: ' . strftime('%Y-%m-%d %H:%M:%S', $start) . PHP_EOL;
        echo 'End time: ' . strftime('%Y-%m-%d %H:%M:%S', $end) . PHP_EOL;
        echo 'Total execution time: ' . ($end - $start) . PHP_EOL;
        echo 'Base directory: ' . $dir . PHP_EOL;
        echo 'Total directories scanned: ' . $this->stat['directories'] . PHP_EOL;
        echo 'Total files scanned: ' . $this->stat['files_scanned'] . PHP_EOL;
        echo 'Total malware identified: ' . $this->stat['files_infected'] . PHP_EOL;
    }

    private function loadPatterns($file)
    {
        $list = array();
        if (is_readable($file)) {
            foreach (file($file) as $pattern) {
                $list[] = trim($pattern);
            }
        }
        return $list;
    }

    private function scan($path)
    {
        $this->stat['files_scanned']++;

        $fileContent = file_get_contents($path);
        $found = false;
        $toSearch = '';
        $patterns = $this->loadPatterns(dirname(__FILE__) . '/patterns_raw.txt');
        if ($this->extraCheck) {
            array_push($patterns, "googleBot", "htaccess");
        }
        foreach ($patterns as $toSearch) {
            $substrCount = substr_count($fileContent, $toSearch);
            if ($substrCount > 0) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $patterns = $this->loadPatterns(dirname(__FILE__) . '/patterns_re.txt');
            foreach ($patterns as $toSearch) {
                if (preg_match('/' . $toSearch . '/is', $fileContent)) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            if (!$this->flagHideOk) {
                $this->out(self::ANSI_GREEN, 'OK', $path);
            }
            return false;
        }

        // file hash is on whithelist hash then skip
        $hash = md5($fileContent);
        if ($found && $this->inWhitelist($hash)) {
            if (!$this->flagHideWhitelist) {
                $this->out(self::ANSI_YELLOW, 'WL', $path);
            }
            return false;
        }

        if ($found) {
            $this->stat['files_infected']++;
            $this->out(self::ANSI_RED, 'ER', $path . ' -> ' . $toSearch . ' ' . $hash);
        }

        return true;
    }

    private function isIgnored($pathname)
    {
        foreach ($this->ignore as $pattern) {
            $match = $this->pathMatches($pathname, $pattern);
            if ($match) {
                return true;
            }
        }
        return false;
    }

    // @see http://stackoverflow.com/a/13914119
    private function pathMatches($path, $pattern, $ignoreCase = false)
    {
        $expr = preg_replace_callback(
            '/[\\\\^$.[\\]|()?*+{}\\-\\/]/',
            function ($matches) {
                switch ($matches[0]) {
                    case '*':
                        return '.*';
                    case '?':
                        return '.';
                    default:
                        return '\\' . $matches[0];
                }
            },
            $pattern
        );

        $expr = '/' . $expr . '/';
        if ($ignoreCase) {
            $expr .= 'i';
        }

        return (bool)preg_match($expr, $path);
    }

    private function out($color, $serv, $text)
    {
        echo $color . ' ' . $serv . ' ' . self::ANSI_OFF . $text . PHP_EOL;
    }

    private function showHelp()
    {
        echo 'Usage scan.php -d <directory> [-i=<directory|file>] [-e=.php] [--hide-ok] [--hide-whitelist]' . PHP_EOL;
        echo '    -d                    Directory for searching' . PHP_EOL;
        echo '    -e=.php               Extension' . PHP_EOL;
        echo '    -i=<directory|file>   Directory of file to igonre' . PHP_EOL;
        echo '    --hide-ok             Hide OK aka not infected messages' . PHP_EOL;
        echo '    --hide-whitelist      Hide whitelisted messages' . PHP_EOL;
        echo '    --extra-check         Adds GoogleBot and htaccess to Scan List' . PHP_EOL;
        echo '    --follow-symlink      Follow symlinked directories' . PHP_EOL;
    }

}

new MalwareScanner();
