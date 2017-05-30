<?php

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use Myplace\Dev\Collector\Collector;
use Myplace\Dev\Collector\Analyser;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


require_once __DIR__ . '/vendor/autoload.php';
$parser = new \Composer\Semver\VersionParser();
// PSR-6
$cachedir = __DIR__ . '/../data-temp';
$filesystemAdapter = new Local($cachedir);
$filesystem        = new Filesystem($filesystemAdapter);
$pool = new FilesystemCachePool($filesystem);

// PSR-3
$logger = new Logger('collector');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$collector = new Collector($pool, $logger);
$collector->collect('sauna');

$analyser = new Analyser($pool, $logger);
$strathcomdata = $analyser->plugin_versions('mod-strathcom');

// print_r($strathcomdata);
$byjiraversion = [];
foreach($strathcomdata as $svnversion => $jiraversion) {
    if (!isset($byjiraversion[$jiraversion])) {
        $byjiraversion[$jiraversion] = [];
    }
    $byjiraversion[$jiraversion][] = $svnversion;
}
$byjiraversion['other'] = $byjiraversion[null];
unset($byjiraversion[null]);
print_r($byjiraversion);