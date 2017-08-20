PHP malware scanner
===================

Traversing directories for files with php extensions and testing files against text or regexp rules, the rules based on self gathered samples and publicly vailable malwares/webshells.
The goal is to find infected files and fight against kiddies, because to easy to bypass rules.

How to use?
-----------

```
Usage: php scan.php -d <directory>
    -h                   --help             Show this help message
    -d <directory>       --directory        Directory for searching
    -e <file extension>  --extension        File Extension to Scan
    -i <directory|file>  --ignore           Directory of file to ignore
    -a                   --all-output       Enables --checksum,--comment,--pattern,--time
    -b                   --base64           Scan for base64 encoded PHP keywords
    -m                   --checksum         Display MD5 Hash/Checksum of file
    -c                   --comment          Display comments for matched patterns
    -x                   --extra-check      Adds GoogleBot and htaccess to Scan List
    -l                   --follow-symlink   Follow symlinked directories
    -k                   --hide-ok          Hide results with 'OK' status
    -w                   --hide-whitelist   Hide results with 'WL' status
    -n                   --no-color         Disable color mode
    -s                   --no-stop          Continue scanning file after first hit
    -p                   --pattern          Show Patterns next to the file name
    -t                   --time             Show time of last file change
```

Ignore argument could be used multiple times and accept glob style matching ex.: "cache*", "??-cache.php" or "/cache" etc.

Patterns
--------

There are two different pattern source, each line in these files is a patter so  patterns_raw.txt lines searched as-is, patterns_re.txt used with preg_match function.

Whitelisting
------------

See [whitelist.txt](https://github.com/scr34m/php-malware-scanner/blob/master/whitelist.txt) file for a predefined MD5 hash list. Only the first 32 characters are used, rest of the line ignored so feel free to leave a comment.

Resources
---------

* [PHPScanner](https://github.com/PHPScannr/phpFUS)
* [PMF - PHP Malware Finder](https://github.com/nbs-system/php-malware-finder)
* [check regexp online](http://www.phpliveregex.com)
* [malware samples 1](https://github.com/nbs-system/php-malware-finder/tree/master/php-malware-finder/samples)
* [malware samples 2](https://github.com/r4v/php-exploits)
* [malware samples 3](https://github.com/nikicat/web-malware-collection)
* [malware samples 4](https://github.com/antimalware/manul/tree/master/src/scanner/static/signatures)

Licensing
---------

PHP malware scanner is [licensed](https://github.com/scr34m/php-malware-scanner/blob/master/LICENSE.txt) under the GNU General Public License v3.
