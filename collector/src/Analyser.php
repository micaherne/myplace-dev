<?php

namespace Myplace\Dev\Collector;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Analyser {

    private $pool;
    private $log;
    private $usecache = true; // use already cached data instead of collecting
    private static $projectmap = [
        'mod-strathcom' => 'STRATHCOM'
    ];

    public function __construct(CacheItemPoolInterface $pool, LoggerInterface $log = null) {
        $this->pool = $pool;
        if (!is_null($log)) {
            $this->log = $log;
        } else {
            $this->log = new NullLogger();
        }
    }

    public function plugin_versions($plugin) {
        $item = $this->pool->getItem('plugin_' . str_replace('-', '_', $plugin) . '_tags');
        if (!$item->isHit()) {
            $this->log->warn('Plugin metadata not found. You may need to run the collector first.');
            return;
        }
        $tags = $item->get();

        $jiraprojects = $this->pool->getItem('jira_projects')->get();

        if (!isset(self::$projectmap[$plugin]) || !isset($jiraprojects[self::$projectmap[$plugin]])) {
            $this->log->warn("Unable to find Jira project for $plugin");
            return;
        }
        
        $jiraproject = $jiraprojects[self::$projectmap[$plugin]];

        foreach ($tags as $tag) {
            $jiratag = $this->find_jira_version_for_tag($tag, array_keys($jiraproject->versions));
            if (!is_null($jiratag)) {
                echo "Matched $tag with " . $jiratag . "\n";
            }
        }
    }

    private function find_jira_version_for_tag($tag, $jiraversions) {

        $parser = new \Composer\Semver\VersionParser();

        // Strip modifier.
        $tagparts = explode('-', $tag, 2);
        $tag = $tagparts[0];

        try {
            $normalised = $parser->normalize($tag, true);
            $this->log->debug("Plugin normalised: $normalised");
        } catch (\UnexpectedValueException $e) {
            $this->log->debug("Unable to parse tag $tag");
            return null;
        }

        // TODO: Refactor duplication in next two blocks.

        // Try to find exact version.
        foreach ($jiraversions as $jiraversion) {
            try {
                $jiranormalised = $parser->normalize($jiraversion);
            } catch (\UnexpectedValueException $e) {
                $this->log->debug("Unable to parse Jira version $jiraversion");
                continue;
            }
            $this->log->debug("$jiraversion normalised: $jiranormalised");

            // TODO: Remove any prefixes - compare only numeric bit.
            
            if ($jiranormalised == $normalised) {
                return $jiraversion;
            }
        }

        // Try to match major version only.
        foreach ($jiraversions as $jiraversion) {
            try {
                $jiranormalised = $parser->normalize($jiraversion);
            } catch (\UnexpectedValueException $e) {
                $this->log->debug("Unable to parse Jira version $jiraversion");
                continue;
            }

            $j = explode('.', $jiranormalised);
            $n = explode('.', $normalised);

            if ($j[1] == 0 && $j[0] == $n[0]) {
                return $jiraversion;
            }
        }

    }

}