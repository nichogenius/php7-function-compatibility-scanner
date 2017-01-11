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
            '"p"."r"."e"."g"."_"', // preg_
            
            /* 'eval', 'eval(', */
            'eval("?>',
            'ev\x61l',
            '\x65\166\x61\154\x28' /* dec/hex issue? */,
            '\x65\x76\x61\x6C' /* case, dec/hex issue? */,
            'ZXZhbCg', // eval
            "'ev'.'al'.'",
            '/eval\s*\(/i',

            'eval(base64_decode(',
            '\x47\x4c\x4f\x42\x41LS', // GLOBALS
            'SFRUUF9VU0VSX0FHRU5U', // HTTP_USER_AGENT
            'YWxsb3dfdXJsX2ZvcGVu', // allow_url_fopen
            '${${', // ${${"\x47\x4c\x4f\x42...
            'file_get_contents(\'http://codepad.org',
            'PHPJiaMi',
            '@include($_GET[',
            'system($_GET[',

            /* too open? */
            // 'gzinflate(base64_decode(',
            'md5($_GET[', // md5($_GET["ms-load"])
            'ShellBOT',
    	    'bgeteam',
    	    'DisablePHP=',
    	    'moban.html',
    	    '<?php eval',
    	    '$data = base64_decode("',
    	    'a,b,c,d,e,f,g',
            ' freetellafriend.com',
            'SHELL_PASSWORD',
    	    'curl_get_from_webpage',
    		'base=base64_encode',
    		'@x0powo',
    		'@preg_replace',
    		'1@1.com',
    		'META http-equiv="refresh" content="0;',
            '="create_";global',
            'YW55cmVzdWx0cy5uZXQ=',

            // imported manul samples
            'ZOBUGTEL',
            'MagelangCyber',
            '//rasta//',
            'Baby_Drakon',
            'Net@ddress Mail',
            'Created By EMMA',
            '3xp1r3',
            'NinjaVirus Here',
            '<dot>IrIsT',
            'Hacked By EnDLeSs',
            'Punker2Bot',
            'Zed0x',
            'darkminz',
            'ReaL_PuNiShEr',
            'OoN_Boy',
            '__VIEWSTATEENCRYPTED',
            'M4ll3r',
            'createFilesForInputOutput',
            'Pashkela',
            '== "bindshell"',
            'Webcommander at',
            'YENI3ERI',
            'd3lete',
            'Made by Delorean',
            'R0lGODlhEwAQALMAAAAAAP///5ycAM7OY///nP//zv/OnPf39////wAAAAAA',
            'Cybester90',
            'ayu pr1 pr2 pr3 pr4 pr5 pr6',
            'f0VMRgEBAQA',
            '0d0a0d0a676c6f62616c20246d795f736d7',
            'etalfnizg',
            'JHZpc2l0Y291bnQgPSAkSFRUUF9DT09LSUVfV',
            'edoced_46esab',
            'VOBRA GANGO',
            'itsoknoproblembro',
            'HTTP flood complete after',
            'exploitcookie',
            'az88pix00q98',
            'The Dark Raver',
            'Q3JlZGl0IDogVW5kZXJncm91bmQgRGV2aWwgJm5ic3A7ICB8DQo8YSBocmVmP',
            '463839610c000b00800100ffffffffffff21f90401000001002c000',
            'AAAAAAAAMAAwABAAAAeAUAADQAAADsCQAAAAAAADQAIAADACgAFwAUAAEA',
            'HJ3HjutckoRfpXf9A1zQO2AwDRrRey9uGvTeez79qAao1a0rgudkZkR8Ra',
            'Ly83MTg3OWQyMTJkYzhjYmY0ZDRmZDA0NGEzZDE3Zjk3ZmI2N',
            'DJ7VIU7RICXr6sEEV2cBtHDSOe9nVdpEGhEmvRVRNURfw1wQ',
            'Asmodeus',
            'Cautam fisierele de configurare',
            'BRUTEFORCING',
            'FaTaLisTiCz_Fx Fx29Sh',
            'w4ck1ng shell',
            'private Shell by m4rco',
            'Shell by Mawar_Hitam',
            'LS0gRHVtcDNkIGJ5IFBpcnVsaW4uUEhQIFdlYnNoM2xsIHYxLjAgYzBkZWQgYnkgcjBkcjEgOkw\=',
            '5jb20iKW9yIHN0cmlzdHIoJHJlZmVyZXIsImFwb3J0Iikgb3Igc3RyaXN0cigkcmVmZXJlciwibmlnbWEiKSBvciBzdHJpc3RyKCRyZWZlcmVyLCJ3ZWJhbHRhIikgb3Igc3RyaXN0cigk',
            'X1NFU1NJT05bJ3R4dGF1dGhpbiddID0gdHJ1ZTsNCiAgICBpZiAoJF9QT1NUWydybSddKSB7DQogICAgICBzZXRjb29raWUoJ3R4dGF1dGhfJy4kcm1ncm91cCwgbW',
            'zehirhacker',
            'R0lGODlhFAAUAKIAAAAAAP///93d3cDAwIaGhgQEBP///wAAACH5BAEAAAYALAAAAAAUABQAA',
            'm91dCwgJGVvdXQpOw0Kc2VsZWN0KCRyb3V0ID0gJHJpbiwgdW5kZWYsICRlb3V0ID0gJHJpbiwgMTIwKTsNCmlmICghJHJvdXQgICYmICAhJGVvdX',
            'CB2aTZpIDEwMjQtDQojLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLQ0KI3JlcXVp',
            'DX_Header_drawn',
            'BDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAQABADASIAAhEBA',
            'casus15',
            'temp_r57_table',
            'By Psych0',
            'c99ftpbrutecheck',
            'K!LL3r',
            'MrHazem',
            'BY MMNBOBZ',
            'ConnectBackShell',
            'Hackeado',
            'd3b~X',
            'REREFER_PTTH',
            'Joomla_brute_Force',
            '/usr/sbin/httpd',
            'tmhapbzcerff',
            'IrSecTeam',
            'Spammer',
            'FLoodeR',
            'eriuqer',
            'sshkeys',
            '<kuku>',
            'Backdoor',
            'eggdrop',
            'rwxrwxrwx',
            'profexor.hell',
            'GIF89A;<?php',
            '$sh3llColor',
            'fwrite($fpsetv, getenv("HTTP_COOKIE")',
            'putbot $bot',
            'bind join - *',
            'privmsg $chan',
            'fopen\'(/etc/passwd',
            '\u003c\u0069\u006d\u0067\u0020\u0073\u0072\u0063\u003d\u0022\u0068\u0074\u0074\u0070\u003a\u002f\u002f',
            '\x31\xdb\xf7\xe3\x53\x43\x53\x6a\x02\x89\xe1\xb0\x66\xcd',
            'find / \-type f \-name \.htpasswd',
            'find / \-type f \-perm \-02000 \-ls',
            'find / \-type f \-perm \-04000 \-ls',
            'if(\'\'==($df=@ini_get(\'disable_functions',
            'system\"$cmd 1> /tmp/',
            'ncftpput -u ',
            'wsoEx(',
            'WSOsetcookie(',
            'Dr.abolalh',
            'C0derz.com',
            'Mr.HiTman',
        );
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

        // check against regexp patterns
        if (!$found) {
            $patterns = array(
                'eval\/\*[a-z0-9]+\*\/',
                // eval/*aw3*/
                'eval\([a-z0-9]{4,}\(\$[a-z0-9]{4,}, \$[0-9a-z]{4,}\)\);',
                // eval(v5JONDD($v5EKGVD, $vX3Z3DE));
                '(chr\(\d+\)\.){4,}',
                // chr(22).chr(33).chr(22).chr(22)
                '(chr\(\d+\^\d+\)\.){4,}',
                // chr(95^57).chr(95^54).chr(95^51).chr(95^58)
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

                // imported manul samples
                'Googlebot[\'"]{0,1}\s*\)\){echo\s+file_get_contents',
                'eVaL\(\s*trim\(\s*baSe64_deCoDe\(',
                'if\s*\(\s*mail\s*\(\s*\$mails\[\$i\]\s*,\s*\$tema\s*,\s*base64_encode\s*\(\s*\$text',
                'fwrite\s*\(\s*\$fh\s*,\s*stripslashes\s*\(\s*@*\$_(GET|POST|SERVER|COOKIE|REQUEST)\[',
                'echo\s+file_get_contents\s*\(\s*base64_url_decode\s*\(\s*@*\$_(GET|POST|SERVER|COOKIE|REQUEST)',
                'chr\s*\(\s*101\s*\)\s*\.\s*chr\s*\(\s*118\s*\)\s*\.\s*chr\s*\(\s*97\s*\)\s*\.\s*chr\s*\(\s*108\s*\)',
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

    /**
     * @see http://stackoverflow.com/a/13914119
     */
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
