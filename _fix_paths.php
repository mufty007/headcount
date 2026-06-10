<?php

/**
 * One-off maintenance script: make every entry script locate vendor/ resiliently
 * (walk up to find vendor/autoload.php) instead of using a fixed "../../" depth.
 * Also drops the redundant explicit src/helpers.php require (Composer loads it).
 *
 * Run:  php _fix_paths.php
 * Then delete this file.
 */

$root = __DIR__ . DIRECTORY_SEPARATOR . 'public';

$resilientBlock = <<<'PHP'
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
PHP;

// Rule A: __DIR__-relative vendor/autoload.php require -> resilient block.
$autoloadPattern = '~require(?:_once)?\s+__DIR__\s*\.\s*\'(?:/\.\.)+/vendor/autoload\.php\'\s*;~';

// Rule B: explicit src/helpers.php require (any __DIR__/BASE_PATH relative) -> remove.
$helpersPattern = '~[ \t]*require(?:_once)?\s+(?:__DIR__|BASE_PATH)\s*\.\s*\'(?:/\.\.)*/src/helpers\.php\'\s*;\r?\n?~';

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$changed = [];
$autoloadHits = 0;
$helpersHits = 0;

foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $code = file_get_contents($path);
    $orig = $code;

    $code = preg_replace_callback($autoloadPattern, function () use ($resilientBlock, &$autoloadHits) {
        $autoloadHits++;
        return $resilientBlock;
    }, $code);

    $code = preg_replace_callback($helpersPattern, function () use (&$helpersHits) {
        $helpersHits++;
        return '';
    }, $code);

    if ($code !== $orig) {
        file_put_contents($path, $code);
        $changed[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    }
}

echo "Files changed: " . count($changed) . "\n";
echo "Autoload requires rewritten: {$autoloadHits}\n";
echo "Helpers requires removed: {$helpersHits}\n";
echo "----\n";
foreach ($changed as $c) {
    echo $c . "\n";
}
