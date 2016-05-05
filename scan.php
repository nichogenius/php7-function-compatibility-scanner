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
    private $whitelist = array();
    private $stat = array(
        'directories' => 0,
        'files_scanned' => 0,
        'files_infected' => 0,
    );

    public function __construct()
    {
        $options = getopt('hd:e::', array('hide-ok', 'hide-whitelist'));
        if (isset($options['h'])) {
            $this->showHelp();
        } else {
            if (isset($options['e'])) {
                $ext = $options['e'];
                if ($ext[0] != '.') {
                    $ext = '.' . $ext;
                }
                $this->extension = $ext;
            }
            if (isset($options['hide-ok'])) {
                $this->flagHideOk = true;
            }
            if (isset($options['hide-whitelist'])) {
                $this->flagHideWhitelist = true;
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
            if (is_dir($dir . $file)) {
                $this->process($dir . $file . '/');
            } elseif (is_file($dir . $file)) {
                $ext = substr($file, strrpos($file, '.'));
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

    private function scan($path)
    {
        $this->stat['files_scanned']++;

        $fileContent = file_get_contents($path);
        $found = false;

        // check against simple text matches
        $patterns = array(
            'uname -a',
            '/etc/shadow',
            '/etc/passwd',
            'WSOstripslashes',
            'PD9waHA', // <?php
            'c3lzdGVt',
            '\x73\x79\x73\x74\x65\x6d' /* case, dec/hex issue? */, // system
            'cHJlZ19yZXBsYWNl',
            '\x70\x72\x65\x67\x5f\x72\x65\x70\x6c\x61\x63\x65' /* case, dec/hex issue? */, // preg_replace
            'ZXhlYyg',
            '\x65\x78\x65\x63' /* dec/hex issue? */, // exec
            '=\'base\'.(32*2).\'_de\'.\'code\'',
            '"base64_decode"',
            'YmFzZTY0X2RlY29kZ', // base64_decode
            /* 'eval', 'eval(', */
            'ev\x61l',
            '\x65\166\x61\154\x28' /* dec/hex issue? */,
            '\x65\x76\x61\x6C' /* case, dec/hex issue? */,
            'ZXZhbCg', // eval
            'eval(base64_decode(',
            '\x47\x4c\x4f\x42\x41LS', // GLOBALS
            'SFRUUF9VU0VSX0FHRU5U', // HTTP_USER_AGENT
            'YWxsb3dfdXJsX2ZvcGVu', // allow_url_fopen
            '${${', // ${${"\x47\x4c\x4f\x42...
            'file_get_contents(\'http://codepad.org',
            'PHPJiaMi',

            /* too open? */
            'md5($_GET[', // md5($_GET["ms-load"])
        );
        foreach ($patterns as $toSearch) {
            $substrCount = substr_count($fileContent, $toSearch);
            if ($substrCount > 0) {
                $found = true;
                break;
            }
        }

        // check against regexp patterns
        if (!$found) {
            $patterns = array(
                'eval\/\*[a-z0-9]+\*\/',
                // eval/*aw3*/
                'eval\([a-z0-9]{4,}\(\$[a-z0-9]{4,}, \$[0-9a-z]{4,}\)\);',
                // eval(v5JONDD($v5EKGVD, $vX3Z3DE));
                '(chr\(\d+\)\.){4,}',
                // chr(22).chr(33).chr(22).chr(22)
                '(\$[a-z0-9]{3,}\[\d+\]\.){4,}',
                // $saz98[5].$saz98[2].$saz98[1].$saz98[3].$saz98[5]
                'chr\(\d+\)\.""\.""\.""\.""\.""',
                // chr(88)."".""."".""."".
                '\$GLOBALS\[\$GLOBALS[\'[a-z0-9]{4,}\'\]\[\d+\]\.\$GLOBALS\[\'[a-z-0-9]{4,}\'\]\[\d+\].',
                // $GLOBALS[$GLOBALS['u101c7'][77].$GLOBALS['u101c7'][47].
                '\$GLOBALS\[\'[a-z0-9]{5,}\'\] = \$[a-z]+\d+\[\d+\]\.\$[a-z]+\d+\[\d+\]\.\$[a-z]+\d+\[\d+\]\.\$[a-z]+\d+\[\d+\]\.',
                // $GLOBALS['qjyxw29'] = $z26[1].$z26[30].$z26[2].
                'eval\([a-z0-9_]+\(base64_decode\(',
                // eval(xxtea_decrypt(base64_decode(
                '\$[a-z]{3,}=\$[a-z]{3,}\("",\$[a-z]{3,}\);\$[a-z]{3,}\(\);',
                // $ewn=$ner("",$iqkpi);$ewn();
                '{\s*eval\s*\(\s*\$',
                // {eval($
            );
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

    private function out($color, $serv, $text)
    {
        echo $color . ' ' . $serv . ' ' . self::ANSI_OFF . $text . PHP_EOL;
    }

    private function showHelp()
    {
        echo 'Usage scan.php -d <directory> [-e=.php] [--hide-ok] [--hide-whitelist]' . PHP_EOL;
        echo '    -d                Directory for searching' . PHP_EOL;
        echo '    -e=.php           Extension' . PHP_EOL;
        echo '    --hide-ok         Hide OK aka not infected messages' . PHP_EOL;
        echo '    --hide-whitelist  Hide whitelisted messages' . PHP_EOL;
    }

}

new MalwareScanner();
