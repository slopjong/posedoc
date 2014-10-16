<?php

namespace Composer\Command;


use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\GitHub;
use Posedoc\DockerFileInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Posedoc\BaseImage;

// before migrating to composer the posedoc tool defined global
// constants so we do this here and not in the constructor to get
// the code completion work
define('ROOT_DIR',      realpath(".")); // in a phar __DIR__ starts with phar://
define('IMAGES_DIR',    ROOT_DIR . '/images');
define('BUILD_DIR',     ROOT_DIR . '/.tmp');
define('AUTH_FILE',     BUILD_DIR . '/auth.json');
define('PROJECT_DIR',   BUILD_DIR . '/project');
define('ASSETS_DIR',    BUILD_DIR . '/assets');
define('CACHE_DIR',     BUILD_DIR . '/cache');
define('DOCKER_FILE',   BUILD_DIR . '/Dockerfile');
define('POSIGNORE',     ROOT_DIR . '/.posignore');

/**
 * Class DockerBuildCommand
 * @package Composer\Command
 *
 * @link http://symfony.com/doc/current/components/console/introduction.html
 */
class DockerBuildCommand extends Command
{
    protected $buildFiles = array();
    protected $posignore = array();

    protected function configure()
    {
        $this
            ->setName('docker-build')
            ->setDescription('Build docker containers.')
            ->setHelp(<<<EOT
Build the docker containers from the given docker description files.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (! file_exists(IMAGES_DIR)) {
            echo 'Run this tool from your proejct root' . PHP_EOL;
            exit();
        }

        if (! file_exists(CACHE_DIR)) {
            mkdir(CACHE_DIR, 0755, true);
        }

        if (! file_exists(ASSETS_DIR)) {
            mkdir(ASSETS_DIR, 0755, true);
        }

        if (! file_exists(PROJECT_DIR)) {
            mkdir(PROJECT_DIR, 0755, true);
        }

        // Read the .posignore file to skip specific images.
        if (! file_exists(POSIGNORE)) {
            return;
        }

        $handle = @fopen(POSIGNORE, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line && substr($line, 0, 1) !== '#') {
                    $this->posignore[] = $line;
                }
            }
            if (!feof($handle)) {
                echo "Error: reading .posignore failed" . PHP_EOL;
            }
            fclose($handle);
        }

        $this->buildAll();
    }

    protected function initOAuth()
    {
        $fs = new Filesystem();

        $requireComposerJsonFile = false;
        $disablePlugins = true;
        $composer = $this->getComposer(
            $requireComposerJsonFile,
            $disablePlugins
        );

        // if the file composer.json doesn't exist, getConfig() will
        // return null so we need to initialize the config first in
        // order to set the auth file path
        if (! $composer->getConfig()) {
            $composer->setConfig(new Config());
        }

        if (defined('AUTH_FILE')) {
            $authFile = new JsonFile(AUTH_FILE);
        } else {
            $authFile = new JsonFile(__DIR__ . '/auth.json');
        }

        $jsonConfigSource = new JsonConfigSource($authFile);
        $composer->getConfig()->setAuthConfigSource($jsonConfigSource);

        $io = $this->getIO();
        $config = $composer->getConfig();

        $github = new GitHub($io, $config);
        $github->authorizeOAuthInteractively('github.com');
    }

    public function add($imageName, $buildFile)
    {
        echo $imageName .' loaded'. PHP_EOL;
        $this->buildFiles[$imageName] = include $buildFile;
    }

    /**
     * Copies files/directories to the 'compile' directory
     */
    protected function copyAddAssets(BaseImage $image, $imageName)
    {
        echo 'Copy assets ...'. PHP_EOL;
        $assets = $image->getAssets();
        foreach ($assets as $asset) {
            system('cp -r '. IMAGES_DIR .'/'. $imageName .'/'. $asset .' '. ASSETS_DIR);
        }
    }

    protected function checkoutProjects()
    {
        $projects = array();

        /** @var WebProjectInterface $build */
        foreach ($this->buildFiles as $imageName => $build) {
            $projects = array_merge($projects, $build->getProjects());
        }

        $projects = array_unique($projects);

        // checkout the project or pull the latest commits if the project
        // has already been cloned
        foreach ($projects as $project) {

            $repoName = explode('/', $project);
            $projectName = str_replace('.git', '', array_pop($repoName));

            if (file_exists(PROJECT_DIR .'/'. $projectName)) {
                echo "Updating project ..." . PHP_EOL;
                // @todo submodules may also have been updated
                system('cd '. PROJECT_DIR .'/'. $projectName .' && git pull && cd -');
            } else {
                echo "Cloning project ..." . PHP_EOL;
                $command = 'git clone --recursive '. $project .' '. PROJECT_DIR .'/'. $projectName;
                system($command);
            }
        }
    }

    public function buildAll()
    {
        $this->loadBuildFiles();

        $this->checkoutProjects();

        /** @var DockerFileInterface $build */
        foreach ($this->buildFiles as $imageName => $build) {

            if (in_array($imageName, $this->posignore)) {
                $this->outputHeader('SKIPPING '. $imageName);
                continue;
            }

            $this->outputHeader('BUILDING '. $imageName);
            $this->copyAddAssets($build, $imageName);

            file_put_contents(DOCKER_FILE, $build->toDockerFile());

            chdir(BUILD_DIR);

            system("docker build --no-cache=true -t $imageName .");

            $tarFileName = str_replace('/', '_', $imageName);
            system("docker save -o $tarFileName.tar $imageName");
            system("mv $tarFileName.tar ../builds");

            chdir(ROOT_DIR);
        }

        $this->cleanup();
    }

    protected function loadBuildFiles()
    {
        $objects = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(IMAGES_DIR),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach($objects as $fileName => $object){
            if  (basename($fileName) === 'build.php') {
                $imageName = str_replace(
                    array(IMAGES_DIR .'/', '/build.php'),
                    array('', ''),
                    $fileName
                );

                if (! in_array($imageName, $this->posignore)) {
                    $this->add($imageName, $fileName);
                }
            }
        }
    }

    protected function cleanup()
    {
        echo "Cleaning up ..." . PHP_EOL;
        unlink(DOCKER_FILE);
        system('rm -rf '. ASSETS_DIR .'/*');
    }

    protected function outputHeader($headerMessage)
    {
        echo PHP_EOL . PHP_EOL .str_pad("", 80, "#") . PHP_EOL;
        echo "# $headerMessage" . PHP_EOL;
        echo str_pad("", 80, "#") . PHP_EOL . PHP_EOL;
    }
}
