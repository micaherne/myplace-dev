<?php

class app {

    public $workdir = __DIR__;
    
    public $svn;

    const REPO_URL = 'https://svn.strath.ac.uk/repos/moodle';
    const EXTERNALS_URL = self::REPO_URL . '/core2/externals';

    const TRUNK_REGEX = '/\\^\/plugins\/([^\/]*)\/([^\/]*)/';

    public $currentbuild = 'vest';

    public function __construct(svn $svn) {
        $this->svn = $svn;
    }

    public function sync_externals() {
        $dir = $this->get_externals_dir();
        return $this->checkout_or_update(self::EXTERNALS_URL, $dir);
    }

    public function checkout_or_update($url, $dir) {
        if (file_exists($dir)) {
            $dir = realpath($dir);
            list ($output, $returnvar) = $this->svn->run_command("update $dir");
        } else {
            list ($output, $returnvar) = $this->svn->run_command("checkout " . self::EXTERNALS_URL . " $dir");
        }

        if ($returnvar) {
            throw new \Exception("Failed to get externals");
        }
    }

    public function get_externals_dir() {
        return $this->workdir . '/externals';
    }

    public function cmd_release_rc($args) {
        $plugins = $this->get_plugins();
        $pluginname = array_shift($args);
        if (is_null($pluginname)) {
            throw new \Exception("Plugin required");
        }

        $plugin = null;
        foreach ($plugins as $p) {
            if ($p['name'] == $pluginname) {
                $plugin = $p;
                break;
            }
        }

        if (is_null($plugin)) {
            throw new \Exception("Plugin $plugin not found");
        }

        if ($plugin['type'] != 'tags' || strpos($plugin['identifier'], 'rc') === false) {
            throw new \Exception("Not a release candidate");
        }

        $matches = [];
        if (!preg_match('/v(\\d+)\\.(\\d+)\\-rc(\\d)+/', $plugin['identifier'], $matches)) {
            throw new \Exception(sprintf("Identifier %s not in correct format", $plugin['identifier']));
        }



        // Check that branches/v$matches[1] is exactly the same as tagged RC.
        $rcurl = $plugin['svnpath'];
        if (strpos($rcurl, '^') === 0) {
            $rcurl = self::REPO_URL . substr($rcurl, 1);
        }
        $branchurl = self::REPO_URL . '/plugins/' . $plugin['name'] . '/branches/v' . $matches[1];
        list($output, $returnvar) = $this->svn->run_command("diff $rcurl $branchurl");
        if (!empty($output)) {
            throw new \Exception("Different code between $rcurl and $branchurl");
        }

                
        // Check out branch branches/v$matches[1]
        $checkoutdir = $this->workdir . '/' . $plugin['name'];
        $this->checkout_or_update($branchurl, $checkoutdir);

        
        // Update maturity in version.php to MATURITY_STABLE and check back in
        $versionpath = $checkoutdir . '/version.php';
        $count = 0;
        file_put_contents($versionpath, str_replace('MATURITY_RC', 'MATURITY_STABLE', file_get_contents($versionpath), $count));

        if ($count !== 1) {
            throw new \Exception("Unable to find and replace MATURITY_RC token");
        }

        echo "TODO: Commit $checkoutdir with message (what??)\n";

        // Tag tags/v$matches[1].$matches[2] from branches/v$matches[1]
        $tagurl = self::REPO_URL . '/plugins/' . $plugin['name'] . '/tags/v' . $matches[1] . '.' . $matches[2];
        $relativetagurl = '^/plugins/' . $plugin['name'] . '/tags/v' . $matches[1] . '.' . $matches[2];
        echo "TODO: Tag $tagurl from $branchurl\n";

        // Update externals file.
        if (strpos($plugin['svnpath'], $relativetagurl) !== 0) {
            throw new \Exception("Release URL $relativetagurl should be part of RC URL");
        }
        echo "TODO: Change {$plugin['svnpath']} to $relativetagurl in externals\n";

        // Output a message to update Jira project?
        echo "TODO: Remind to release version {$matches[1]} in Jira\n";
    }

    public function get_plugins() {
        $this->sync_externals();
        $lines = explode("\n", file_get_contents(
            $this->get_externals_dir() . '/branches/' . $this->currentbuild . '/svn_externals.txt'
        ));
        // Remove comments.
        $lines = array_filter($lines, function($val) {
            return strpos($val, '#') !== 0;
        });

        $result = array_map(function($val) {
            $parts = explode(' ', $val, 2);
            $result = [
                'svnpath' => $parts[0],
                'installdir' => $parts[1]
            ];
            $parts = explode('/', $parts[0]);
            if (array_shift($parts) != '^') {
                return;
            }
            if (array_shift($parts) != 'plugins') {
                return;
            }
            $details = array_combine(
                ['name', 'type', 'identifier'],
                array_pad($parts, 3, null)
            );
            
            $result += $details;
            return $result;
        }, $lines);

        return array_values(array_filter(array_values($result)));
    }

    public function process($args) {
        $script = array_shift($args);
        $commandmethod = 'cmd_' . array_shift($args);
        if (!method_exists($this, $commandmethod)) {
            throw new \Exception("Unknown command " . $args[1]);
        }

        return $this->$commandmethod($args);
    }

}

class svn {

    public function run_command($command, $auth = true) {
        $username = $_SERVER['SVN_USERNAME'] ?? null;
        $password = $_SERVER['SVN_PASSWORD'] ?? null;
        if ($auth && is_null($username) || is_null($password)) {
            throw new \Exception("Invalid username or password");
        }
        
        $credentials = '';
        if ($auth) {
            $credentials = " --username $username --password $password ";
        }
        $cmd = "svn $credentials $command";
        $cmdredacted = "svn --username " . str_repeat('*', strlen($username));
        $cmdredacted .= " --password " . str_repeat('*', strlen($password));
        $cmdredacted .= " $command";
        echo "SVN: $cmdredacted\n";

        $output = null;
        $returnvar = null;
        exec($cmd, $output, $returnvar);

        return [$output, $returnvar];
    }

}

$svn = new svn();

$app = new app($svn);

$app->process($argv);