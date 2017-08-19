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
    //Pretty Colors
    private $ANSI_GREEN = "\033[32m";
    private $ANSI_RED = "\033[31m";
    private $ANSI_YELLOW = "\033[33m";
    private $ANSI_BLUE = "\033[36m";
    private $ANSI_OFF = "\033[0m";

    private $dir = '';
    private $extension = '.php';
    private $flagChecksum = false;
    private $flagHideOk = false;
    private $flagHideWhitelist = false;
    private $flagTime = false;
    private $extraCheck = false;
    private $verbose = false;
    private $whitelist = array();
    private $ignore = array();
    private $stat = array(
        'directories' => 0,
        'files_scanned' => 0,
        'files_infected' => 0,
    );
    private $followSymlink = false;

    private $patterns_raw  = array();
    private $patterns_iraw = array();
    private $patterns_re   = array();

    public function __construct()
    {
        //Read Run Options
        $this->parseArgs();

        //Load Patterns
        $this->initializePatterns();

        //Initiate Scan       
        $this->run($this->dir);
    }

    private function disableColor()
    {
        $this->ANSI_GREEN  = '';
        $this->ANSI_RED    = '';
        $this->ANSI_YELLOW = '';
        $this->ANSI_BLUE   = '';
        $this->ANSI_OFF    = '';
    }

    private function error($msg)
    {
        echo $this->ANSI_RED . 'Error: ' . $msg . $this->ANSI_OFF . PHP_EOL;
        $this->showHelp();
        echo PHP_EOL . $this->ANSI_RED . 'Quiting' . PHP_EOL;
        exit(-1);
    }

    private function initializePatterns()
    {
        $this->patterns_raw  = $this->loadPatterns(dirname(__FILE__) . '/patterns_raw.txt');
        $this->patterns_iraw = $this->loadPatterns(dirname(__FILE__) . '/patterns_iraw.txt');
        $this->patterns_re   = $this->loadPatterns(dirname(__FILE__) . '/patterns_re.txt');
        if ($this->extraCheck) {
            array_push($this->patterns_raw, "googleBot", "htaccess");
        }
    }

    private function inWhitelist($hash)
    {
        return in_array($hash, $this->whitelist);
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

    private function loadPatterns($file)
    {
        $list = array();
        if (is_readable($file)) {
            foreach (file($file) as $pattern) {
                //Check if the line is only whitespace and skips.
                if (strlen(trim($pattern)) == 0) {
                    continue;
                }
                //Check if first char in pattern is a '#' which indicates a comment and skips.
                if ($pattern[0] === '#') {
                    continue;
                }
                $list[] = trim($pattern);
            }
        }
        return $list;
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

    private function parseArgs()
    {
        $options = getopt('d:e:i:cxlhkwntv', array('directory:', 'extension:', 'ignore:', 'checksum', 'extra-check', 'follow-link', 'help', 'hide-ok', 'hide-whitelist', 'no-color', 'time', 'verbose'));
        
        //Help Option should be first 
        if (isset($options['help']) || isset($options['h'])) {
            $this->showHelp();
            exit;
        }
        
        //Options that Require Additional Parameters
        if (isset($options['directory']) || isset($options['d'])) {
            $this->dir = isset($options['directory']) ? $options['directory'] : $options['d'];
        }
        if (isset($options['extension']) || isset($options['e'])) {
            $ext = isset($options['extension']) ? $options['extension'] : $options['e'];
            if ($ext[0] != '.') {
                $ext = '.' . $ext;
            }
            $this->extension = strtolower($ext);
        }
        if (isset($options['ignore']) || isset($options['i'])) {
            $tmp = isset($options['ignore']) ? $options['ignore'] : $options['i'];
            $this->ignore = is_array($tmp) ? $tmp : array($tmp);
        }

        //Simple Flag Options
        if (isset($options['checksum']) || isset($options['c'])){
            $this->flagChecksum = true;
        }
        if (isset($options['extra-check']) || isset($options['x'])) {
            $this->extraCheck = true;
        }
        if (isset($options['follow-symlink']) || isset($options['l'])) {
            $this->followSymlink = true;
        }
        if (isset($options['hide-ok']) || isset($options['k'])) {
            $this->flagHideOk = true;
        }
        if (isset($options['hide-whitelist']) || isset($options['w'])) {
            $this->flagHideWhitelist = true;
        }
        if (isset($options['no-color']) || isset($options['n'])){
            $this->disableColor();
        }
        if (isset($options['time']) || isset($options['t'])){
            $this->flagTime = true;
        }
        if (isset($options['verbose']) || isset($options['v'])){
            $this->verbose = true;
        }
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

    private function printPath($found, $path, $pattern, $hash)
    {
        $output_string = '# ';

        //OK
        if (!$found) {
            if ($this->flagHideOk){return;}
            $state = 'OK';
            $state_color = $this->ANSI_GREEN;
        }
        //WL
        elseif ($this->inWhitelist($hash)) {
            if ($this->flagHideWhitelist) {return;}
            $state = 'WL';
            $state_color = $this->ANSI_YELLOW;
        }
        //ER
        else {
            $state = 'ER';
            $state_color = $this->ANSI_RED;
        }
        $output_string = $state_color . $output_string . $state . $this->ANSI_OFF . ' ';

        //Include cTime
        if ($this->flagTime) {
            $changed_time = filectime($path);
            $htime = date('H:i d-m-Y', $changed_time);
            $output_string = $output_string . $this->ANSI_BLUE   . $htime . $this->ANSI_OFF . ' ';
        }

        //Include Checksum
        if ($this->flagChecksum) {
            $output_string = $output_string . $this->ANSI_BLUE   .  $hash . $this->ANSI_OFF . ' ';
        }

        //Append Path
        //'#' and {} included to prevent accidental script execution attempts
        // in the event that script output is pasted into a root terminal
        $opath = '# ' . '{' . $path . '}';
        $output_string = $output_string . $opath . ' ';

        //'#' added again as code snippets have the potential to be valid shell commands
        if ($found) {
            $opatt = "# $pattern";
            $output_string = $output_string . $state_color . $opatt . $this->ANSI_OFF;
        }

        $output_string = $output_string . PHP_EOL;

        echo $output_string;
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

    private function run($dir)
    {   
        //Make sure a directory was specified.
        if  ($this->dir === '') {
            $this->error('No directory specified');
        }
        
        //Make sure the input is a valid directory path.
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            $this->error('Specified path is not a directory: ' . $dir);
        }
        
        $start = time();
        $this->loadWhitelist();
        $this->process($dir . '/');
        $this->report($start, $dir . '/');
    }

    private function scan($path)
    {
        $this->stat['files_scanned']++;

        $fileContent = file_get_contents($path);
        $found = false;
        $hash  = '';
        $toSearch = '';
	    
        foreach ($this->patterns_raw as $toSearch) {
            if (strpos($fileContent, $toSearch) !== FALSE){
                $found = true;
                if ($hash === ''){
                    $hash = md5($fileContent);
                }
                $this->printPath($found, $path, $toSearch, $hash);
                if (!$this->verbose){
                    break;
                }
            }
        }
        
        if (!$found || $this->verbose) {
            foreach ($this->patterns_iraw as $toSearch) {
                if (stripos($fileContent, $toSearch) !== FALSE){
                    $found = true;
                    if ($hash === ''){
                        $hash = md5($fileContent);
                    }
                    $this->printPath($found, $path, $toSearch, $hash);
                    if (!$this->verbose){
                        break;
                    }
                }
            }
        }

        if (!$found || $this->verbose) {
            foreach ($this->patterns_re as $toSearch) {
                if (preg_match('/' . $toSearch . '/im', $fileContent)) {
                    $found = true;
                    if ($hash === ''){
                        $hash = md5($fileContent);
                    }
                    $this->printPath($found, $path, $toSearch, $hash);
                    if (!$this->verbose){
                        break;
                    }
                }
            }
        }

        if (!$found) {
            $this->printPath($found, $path, $toSearch, $hash);
            return false;
        }

        if ($found && $this->inWhitelist($hash)) {
            return false;
        }

	$this->stat['files_infected']++;
        return true;
    }

    private function showHelp()
    {
        echo 'Usage: scan.php -d <directory>' . PHP_EOL;
        echo '    -h                   --help             Show this help message'                   . PHP_EOL;
        echo '    -d <directory>       --directory        Directory for searching'                  . PHP_EOL;
        echo '    -e <file extension>  --extension        File Extension to Scan'                   . PHP_EOL;
        echo '    -i <directory|file>  --ignore           Directory of file to igonre'              . PHP_EOL;
        echo '    -c                   --checksum         Display MD5 Checksum of file'             . PHP_EOL;
        echo '    -x                   --extra-check      Adds GoogleBot and htaccess to Scan List' . PHP_EOL;
        echo '    -l                   --follow-symlink   Follow symlinked directories'             . PHP_EOL;
        echo '    -k                   --hide-ok          Hide OK aka not infected messages'        . PHP_EOL;
        echo '    -w                   --hide-whitelist   Hide whitelisted messages'                . PHP_EOL;
        echo '    -t                   --time             Show time of last file change'            . PHP_EOL;
        echo '    -v                   --verbose          Continue scanning file afater first hit'  . PHP_EOL;
    }

}

new MalwareScanner();
?>
