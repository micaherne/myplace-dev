<?php

namespace Myplace\Dev\Collector;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Collector {

    private $pool;
    private $log;
    private $svnroot = 'http://svn.strath.ac.uk/repos/moodle';
    private $usecache = true; // use already cached data instead of collecting

    public function __construct(CacheItemPoolInterface $pool, LoggerInterface $log = null) {
        $this->pool = $pool;
        if (!is_null($log)) {
            $this->log = $log;
        } else {
            $this->log = new NullLogger();
        }
    }

    public function collect($build) {

        $plugins = $this->collect_build_plugins($build);

        foreach ($plugins as $pluginname => $plugindata) {
            $tags = $this->collect_plugin_tags($plugindata);
        }

        $jiraprojects = $this->collect_jira_projects();
        // print_r($jiraprojects);
    }

    private function collect_build_plugins($build) {
        
        $this->log->info("Collecting build plugins for $build");

        // Check for cached.
        $key = $build . '_plugins';
        $item = $this->pool->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $tempfile = tempnam(sys_get_temp_dir(), 'myplace-dev');
        unlink($tempfile);
        $this->svn("export {$this->svnroot}/core2/externals/branches/{$build}/svn_externals.txt $tempfile");
        $externalstxt = file_get_contents($tempfile);
        unlink($tempfile);

        $plugins = [];

        foreach(explode("\n", $externalstxt) as $line) {
            if (strpos($line, '^') !== 0) {
                continue;
            }

            list($svnlocation, $mdllocation) = explode(" ", $line);

            $svnparts = explode('/', $svnlocation);

            if ($svnparts[3] !== 'tags') {
                continue;
            }

            $svnparts[0] = $this->svnroot;

            $svnlocation = implode('/', $svnparts);
            $svnroot = implode('/', array_slice($svnparts, 0, 3));
            $plugins[$svnparts[2]] = (object) [
                'name' => $svnparts[2],
                'tag' => $svnparts[4],
                'svnlocation' => $svnlocation,
                'svnroot' => $svnroot
            ];
        }

        $item->set($key, $plugins);
        $this->pool->save($item);

        return $plugins;
    }

    private function collect_plugin_tags($plugindata) {

        $this->log->info("Collecting tags for {$plugindata->name}");

        // Check cache.
        $cachekey = "plugin_" . str_replace("-", "_", $plugindata->name) . "_tags";
        $item = $this->pool->getItem($cachekey);
        if ($item->isHit()) {
            return $item->get();
        }

        $tags = $this->svn("ls {$plugindata->svnroot}/tags");
        $tags = explode("\n", $tags);
        $tags = array_map(function($tag) {
            return trim($tag, '//');
        }, $tags);
        $tags = array_filter($tags, function($tag) {
            return !empty($tag);
        });

        $item->set($tags);
        $this->pool->save($item);

        return $tags;
    }

    private function collect_jira_projects() {

        $this->log->info("Collecting Jira projects");

        $cachekey = 'jira_projects';
        $item = $this->pool->getItem($cachekey);
        if ($item->isHit()) {
            return $item->get();
        }

        $url = 'http://jira.lte.strath.ac.uk/rest/api/2/project';
        $projectsjson = file_get_contents($url);
        $projects = json_decode($projectsjson);

        $projectsdata = [];
        foreach($projects as $project) {
            $projectdata = (object) [
                'key' => $project->key,
                'name' => $project->name,
                'versions' => []
            ];

            $this->log->info("Contacting Jira for project {$project->key}");
            $projectjson = file_get_contents($project->self);
            $projectobj = json_decode($projectjson);

            foreach ($projectobj->versions as $version) {
                $projectdata->versions[$version->name] = $version;
            }

            $projectsdata[$project->key] = $projectdata;
        }

        $item->set($projectsdata);
        $this->pool->save($item);

        return $projectsdata;
    }

    private function svn($command) {
        $this->log->debug("Running SVN command: $command");
        return `svn $command`;
    }

}