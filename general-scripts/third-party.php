<?php

class app {

    public $workdir = __DIR__;
    
    public $svn;

    const REPO_URL = 'https://svn.strath.ac.uk/repos/moodle';
    const EXTERNALS_URL = self::REPO_URL . '/core2/externals';
    const PLUGINS_ROOT_URL = self::REPO_URL . '/plugins';
    const PLUGIN_DIR_API_URL = 'https://download.moodle.org/api/1.3/pluglist.php';

    public $currentbuild = 'vest';

    public function __construct(svn $svn) {
        $this->svn = $svn;
    }

    public function cmd_update_vendor($args) {
        if (count($args) != 3) {
            die("Usage: third-party update_vendor [plugin name in svn] [moodle plugin name] [moodle version]\n");
        }
        list ($svnname, $moodlename, $moodleversion) = $args;
        
        $url = self::PLUGINS_ROOT_URL . '/' . $svnname . '/vendor';

        $checkoutdir = $this->workdir . '/' . $svnname;
        $this->checkout_or_update($url, $checkoutdir, 'immediates');

        if (file_exists($checkoutdir . '/current')) {
            die("This plugin has a current directory.\n");
        }
        $metadata = $this->get_plugin_metadata($moodlename);

        if (is_null($metadata)) {
            die("Plugin $moodlename not found in directory\n");
        }

        $latestcompatibleversion = 0;
        $downloadurl = null;
        $downloadmd5 = null;
        foreach($metadata->versions as $version) {
            $versionno = $version->version;
            if ($versionno < $latestcompatibleversion) {
                continue;
            }
            if ($version->maturity != 200) {
                continue;
            }
            foreach ($version->supportedmoodles as $supportedmoodle) {
                if ($supportedmoodle->release == $moodleversion) {
                    $latestcompatibleversion = $versionno;
                    $downloadurl = $version->downloadurl;
                    $downloadmd5 = $version->downloadmd5;
                    break;
                }
            }
        }

        if (empty($latestcompatibleversion)) {
            die("No compatible version found\n");
        }

        if (file_exists($checkoutdir . '/b' . $latestcompatibleversion)) {
            die("Already at latest version ($latestcompatibleversion)\n");
        }

        print_r([$latestcompatibleversion, $downloadurl, $downloadmd5]);

        $filepath = $this->download_file($downloadurl, $downloadmd5);
        
        // TODO: START HERE - unzip the file etc.
        $z = new \ZipArchive();
        $z->open($filepath);

        $toplevelfolders = []; // We need there to be only one.
        for ($i = 0; $i < $z->numFiles; $i++) {

            $path = $z->getNameIndex($i);
            $parts = explode('/', $path);
            $toplevelfolders[$parts[0]] = 1;

        }

        if (count($toplevelfolders) != 1) {
            die("Zip file should only have a single top level folder.\n");
        }

        // Check the folder doesn't exist.
        $foldername = array_keys($toplevelfolders)[0];
        $extractedfolderpath = $checkoutdir . '/' . $foldername;
        if (file_exists($extractedfolderpath)) {
            die("Folder $foldername already exists.\n");
        }

        $z->extractTo($checkoutdir);

        // Rename folder to b something.
        rename($extractedfolderpath, $checkoutdir . '/b' . $latestcompatibleversion);
    }

    public function download_file($url, $md5 = null) {
        echo "Downloading: $url\n";
        $downloaddir = $this->workdir . '/downloads';
        if (!file_exists($downloaddir)) {
            mkdir($downloaddir, 0777, true);
        }

        $downloadpath = $downloaddir . '/test.zip';
        $downloadhandle = fopen($downloadpath, 'w');
        set_time_limit(0); // unlimited max execution time
        $options = array(
        CURLOPT_FILE    => $downloadhandle,
        CURLOPT_TIMEOUT =>  28800, // set this to 8 hours so we dont timeout on big files
        CURLOPT_URL     => $url,
        );

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        // TODO: Get rid of these options for security.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $success = curl_exec($ch);
        
        curl_close($ch);
        fclose($downloadhandle);

        if (!$success) {
            die("Download of $url failed\n");
        }

        if (!empty($md5)) {
            $filemd5 = md5_file($downloadpath);
            if ($filemd5 != $md5) {
                throw new \Exception("md5 hash $filemd5 does not match $md5");
            }
        }

        return $downloadpath;
    }

    public function get_plugin_metadata($pluginname) {
        $cachefile = $this->workdir . '/pluglist.json';
        if (!file_exists($cachefile)) {
            file_put_contents($this->workdir . '/pluglist.json', file_get_contents(self::PLUGIN_DIR_API_URL));
        }
        $metadata = json_decode(file_get_contents($cachefile));
        foreach ($metadata->plugins as $plugin) {
            if ($plugin->component == $pluginname) {
                return $plugin;
            }
        }
        return;
    }

    public function checkout_or_update($url, $dir, $depth = 'infinity') {
        if (file_exists($dir)) {
            $dir = realpath($dir);
            list ($output, $returnvar) = $this->svn->run_command("update --depth $depth $dir");
        } else {
            list ($output, $returnvar) = $this->svn->run_command("checkout --depth $depth $url $dir");
        }

        if ($returnvar) {
            throw new \Exception("Failed to get externals");
        }
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