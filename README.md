PHP malware scanner
===================

Traversing directories for files with php extensions and testing files against text or regexp rules, the rules based on self gathered samples and publicly vailable malwares/webshells.
The goal is to find infected files and fight against kiddies, because to easy to bypass rules.

How to use?
-----------

```
$ php ./scan.php -h
Usage scan.php -d <directory> [-i=<directory|file>] [-e=.php] [--hide-ok] [--hide-whitelist]
    -d                    Directory for searching
    -e=.php               Extension
    -i=<directory|file>   Directory of file to igonre
    --hide-ok             Hide OK aka not infected messages
    --hide-whitelist      Hide whitelisted messages
    --extra-check         Adds GoogleBot and htaccess to Scan List
    --follow-symlink      Follow symlinked directories
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
