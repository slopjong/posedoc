<?php

namespace Composer\Command;


use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\GitHub;
use Posedoc\DockerFileInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Posedoc\BaseImage;

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
    protected $config = array();

    protected function configure()
    {
        $this
            ->setName('docker-build')
            ->setDescription('Build docker containers.')
            ->setDefinition(array(
                new InputArgument(
                    'image',
                    InputArgument::OPTIONAL,
                    'Docker image to build',
                    'all'
                ),
                new InputOption(
                    'dry-run',
                    '', // option shortcut
                    InputOption::VALUE_REQUIRED,
                    'Process the images list but don\'t build them',
                    false // default value
                ),
                new InputOption(
                    'debug',
                    '',  // option shortcut
                    InputOption::VALUE_REQUIRED,
                    'Print additional debugging information',
                    false // default value
                ),
            ))
            ->setHelp(<<<EOT
Build the docker containers from the given docker description files.
EOT
            )
        ;
    }

    protected function setConfig(array $config)
    {
        $this->config = $config;
    }

    // This was introduced when we found out that we have to respect
    // composer's working directory option. That's why you might find
    // the get/set/initConfig methods a mess
    protected function initConfig($debugMode, $workingDir = '.')
    {
        $config = array(
            'ROOT_DIR'    => realpath($workingDir), // in a phar __DIR__ starts with phar://
            'BUILD_DIR'   => realpath($workingDir) . '/.tmp',
        );

        $config = array_merge($config, array(
            'DEBUG'         => $debugMode,
            'IMAGES_DIR'    => $config['ROOT_DIR']  . '/images',
            'AUTH_FILE'     => $config['BUILD_DIR'] . '/auth.json',
            'PROJECT_DIR'   => $config['BUILD_DIR'] . '/project',
            'ASSETS_DIR'    => $config['BUILD_DIR'] . '/assets',
            'CACHE_DIR'     => $config['BUILD_DIR'] . '/cache',
            'DOCKER_FILE'   => $config['BUILD_DIR'] . '/Dockerfile',
            'POSIGNORE'     => $config['ROOT_DIR']  . '/.posignore',
            'COMPOSER_FILE' => str_replace('phar://', '', dirname(dirname(dirname(__DIR__)))),
        ));

        return $config;
    }

    protected function getConfig()
    {
        return $this->config;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $workingDir = $input->getOption('working-dir');
        $debugMode = $input->getOption('debug');
        $this->setConfig($this->initConfig($debugMode, $workingDir));

        copy(
            $this->config['COMPOSER_FILE'],
            $this->config['BUILD_DIR'].'/composer.phar'
        );

        $buildImage = $input->getArgument('image');
        $dryRun = $input->getOption('dry-run');

        if (! file_exists($this->config['IMAGES_DIR'])) {
            echo 'Run this tool from your proejct root' . PHP_EOL;
            exit();
        }

        if (! file_exists($this->config['CACHE_DIR'])) {
            mkdir($this->config['CACHE_DIR'], 0755, true);
        }

        if (! file_exists($this->config['ASSETS_DIR'])) {
            mkdir($this->config['ASSETS_DIR'], 0755, true);
        }

        if (! file_exists($this->config['PROJECT_DIR'])) {
            mkdir($this->config['PROJECT_DIR'], 0755, true);
        }

        // Read the .posignore file to skip specific images.
        if (! file_exists($this->config['POSIGNORE'])) {
            return;
        }

        $handle = @fopen($this->config['POSIGNORE'], "r");
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

        $this->buildFiles = $this->loadBuildFiles();
        $this->buildFiles = $this->sortBuildFiles($this->buildFiles);

        $this->initOAuth();

        $this->checkoutProjects($dryRun);
        $this->build($buildImage, $dryRun);
        $this->cleanup();
    }

    protected function initOAuth()
    {
        // If the file exists we assume it contains a working token.
        // If the user revoked the token it's his responsibility to
        // delete the old auth file.
        if (file_exists($this->getConfig()['AUTH_FILE'])) {
            return;
        }

        // we need to create composer.json temporarily to get a non-null
        // object from getComposer(), delete it after getting the token
        // if the file didn't exist before
        $composerJsonExisted = true;
        if (! file_exists('composer.json')) {
            file_put_contents('composer.json', '{}');
            $composerJsonExisted = false;
        }

        $fs = new Filesystem();

        $requireComposerJsonFile = false;
        $disablePlugins = false;

        // $composer will be null if there's no composer.json
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

        $authFile = new JsonFile($this->config['AUTH_FILE']);

        $jsonConfigSource = new JsonConfigSource($authFile);
        $composer->getConfig()->setAuthConfigSource($jsonConfigSource);

        $io = $this->getIO();
        $config = $composer->getConfig();

        $github = new GitHub($io, $config);
        $github->authorizeOAuthInteractively('github.com');

        if (! $composerJsonExisted) {
            unlink('composer.json');
        }
    }

    /**
     * Copies files/directories to the 'compile' directory
     */
    protected function copyAddAssets(BaseImage $image, $imageName)
    {
        echo 'Copy assets ...'. PHP_EOL;
        $assets = $image->getAssets();
        foreach ($assets as $asset) {
            system('cp -r '. $this->config['IMAGES_DIR'] .'/'. $imageName .'/'. $asset .' '. $this->config['ASSETS_DIR']);
        }
    }

    protected function checkoutProjects($dryRun = false)
    {
        if ($dryRun) {
            $this->outputHeader('PROCESSING PROJECTS (dry mode)');
        } else {
            $this->outputHeader('PROCESSING PROJECTS repositories');
        }

        $projects = array();

        /** @var WebProjectInterface $build */
        foreach ($this->getBuildFiles() as $imageName => $build) {
            $projects = array_merge($projects, $build->getProjects());
        }

        $projects = array_unique($projects);

        // checkout the project or pull the latest commits if the project
        // has already been cloned
        foreach ($projects as $project) {

            $repoName = explode('/', $project);
            $projectName = str_replace('.git', '', array_pop($repoName));

            if ($dryRun) {
                echo "Cloning/updating project $projectName ..." . PHP_EOL;
                $command = 'git ls-remote '. $project;
                system($command);
                continue;
            }

            if (file_exists($this->config['PROJECT_DIR'] .'/'. $projectName)) {
                echo "Updating project $projectName ..." . PHP_EOL;
                // @todo submodules may also have been updated
                system('cd '. $this->config['PROJECT_DIR'] .'/'. $projectName .' && git pull && cd -');
            } else {
                echo "Cloning project $projectName ..." . PHP_EOL;
                $command = 'git clone --recursive '. $project .' '. $this->config['PROJECT_DIR'] .'/'. $projectName;
                system($command);
            }
        }
    }

    /**
     * Convenient getter to make this class testable by mocking up certain things.
     */
    protected function getBuildFiles()
    {
        return $this->buildFiles;
    }

    /**
     * Use this in unit tests only. Mocking up this command failed somehow.
     *
     * @param $buildFiles
     */
    protected function setBuildFiles(array $buildFiles)
    {
        $this->buildFiles = $buildFiles;
    }

    protected function build($image, $dryRun = false)
    {
        if ($image !== 'all') {
            echo "The tool doesn't support building a single image yet.";
            return;
        }

        $internalBuildFiles = $this->filterBuildFiles($this->getBuildFiles());

        foreach ($this->getBuildFiles() as $imageName => $build) {

            /** @var BaseImage $build */
            $build = $this->addComposerInstallCommand($build);
            $build = $this->setDefaultGitProtocol($build);

            if ($dryRun) {
                $this->outputHeader('BUILDING '. $imageName .' (dry mode)');
                continue;
            } else {
                $this->outputHeader('BUILDING '. $imageName);
            }

            $this->copyAddAssets($build, $imageName);

            file_put_contents($this->config['DOCKER_FILE'], $build->toDockerFile());

            chdir($this->config['BUILD_DIR']);

//            $noCache = in_array($build, $internalBuildFiles);
            system("docker build --force-rm --rm --no-cache=true -t $imageName .");

            $tarFileName = str_replace('/', '_', $imageName);
            system("docker save -o $tarFileName.tar $imageName");
            system("mv $tarFileName.tar ../builds");

            chdir($this->config['ROOT_DIR']);
        }
    }

    protected function setDefaultGitProtocol($build, $protocol = 'https')
    {
        $build->run(array('git', 'config', '--global',
            'url.https://github.com/.insteadOf', 'git://github.com/'));
        return $build;
    }

    // @todo addComposerInstallCommand is doing too much already
    //       it handles the git config and some removals
    protected function addComposerInstallCommand(BaseImage $build)
    {
        $directories = $build->getComposerInstallDirs();

        // for some reason the auth.json is read by composer but
        // it cannot clone repos always
        $authData = json_decode(
            file_get_contents(
                $this->getConfig()['AUTH_FILE']
            ),
            true
        );

        $token = @$authData['config']['github-oauth']['github.com'];

        // @todo: remove the token after the image is built
        if ($token) {
            $build->run(array(
                'git',
                'config',
                '--global',
                'github.accesstoken',
                $token,
            ));
        } else {
            if ($this->getConfig()['DEBUG']) {
                echo "No token found." . PHP_EOL;
            }
        }

        if (empty($directories)) {
            return $build;
        }

        // copy the github oauth token to the image to allow more than
        // 60 requests per hour, it will be removed later
        $class = new \ReflectionClass($build);
        $method = $class->getMethod('add');
        $method->setAccessible(true);
        $method->invokeArgs($build, array(
            'auth.json',
            '/root/.composer/auth.json',
        ));

        // install composer
        $class = new \ReflectionClass($build);
        $method = $class->getMethod('add');
        $method->setAccessible(true);
        $method->invokeArgs($build, array(
            'composer.phar',
            '/usr/share/composer/composer.phar',
        ));

        // install the composer dependencies
        foreach ($directories as $directory) {
            $composerCommand = array(
                'php',
                '/usr/share/composer/composer.phar',
                '--no-interaction',
                '--no-dev',
                "--working-dir=$directory",
            );

            // advise composer to print debugging messages in debug mode
            if ($this->getConfig()['DEBUG']) {
                $composerCommand[] = '-vvv';
            }

            $composerCommand[] = 'install';

            $build->run($composerCommand);
        }

        $build->run(array(
            'rm',
            '-rf',
            '/usr/share/composer/composer.phar',
            '/root/.composer',
            '/root/.gitconfig'
        ));

        return $build;
    }

    protected function loadBuildFiles()
    {
        $this->outputHeader('LOADING BUILD FILES');

        $objects = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->config['IMAGES_DIR']),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $buildFiles = array();
        foreach($objects as $fileName => $object){

            if  (basename($fileName) === 'build.php') {
                $imageName = str_replace(
                    array($this->config['IMAGES_DIR'] .'/', '/build.php'),
                    array('', ''),
                    $fileName
                );

                if (in_array($imageName, $this->posignore)) {
                    echo 'Skipping '. $imageName . PHP_EOL;
                    continue;
                }

                echo 'Loading '. $imageName . PHP_EOL;

                /** @var BaseImage $build */
                $build = include $fileName;
                $buildFiles[$imageName] = $build;
            }
        }

        return $buildFiles;
    }

    /**
     * Filters the build files that are not based on external docker images.
     * If the $external flag is specified, the filter is inverted and only
     * those build files are returned that are based on an external image
     * but not on an internal one.
     *
     * @param array $buildFiles
     * @param bool $externalFlag
     * @return array
     */
    protected function filterBuildFiles(array $buildFiles, $externalFlag = false)
    {
        // all image keys of internal images
        $imageKeys = array_keys($buildFiles);

        $external = array();
        $internal = array();

        foreach ($buildFiles as $imageKey => $buildFile) {
            $dependency = $buildFile->getFrom();

            // remove the tag
            $dependency = preg_replace('/:[^:]*$/', '', $dependency);

            if (in_array($dependency, $imageKeys)) {
                $internal[$imageKey] = $buildFile;
            } else {
                $external[$imageKey] = $buildFile;
            }
        }

        if ($externalFlag) {
            return $external;
        } else {
            return $internal;
        }
    }

    /**
     * Sort the build files according their dependency tree.
     *
     * @param $buildFiles
     * @return array
     */
    protected function sortBuildFiles($buildFiles)
    {
        $externalImages = $this->filterBuildFiles($buildFiles, true);
        $internalImages = $this->filterBuildFiles($buildFiles, false);

        uksort($internalImages, function ($imageKey1, $imageKey2) use ($buildFiles) {
            if (in_array($imageKey2, $this->getDependencyTree($buildFiles, $buildFiles[$imageKey1]))) {
                return 1;
            }
            return -1;
        });

        return array_merge($externalImages, $internalImages);
    }

    /**
     * Build a dependency tree excluding the image keys from external
     * resources. The result will be an array of image keys in the order
     * how the docker images should be created.
     *
     * @param array $buildFiles
     * @param BaseImage $buildFile
     * @param array $path
     * @return array
     */
    protected function getDependencyTree(
        array $buildFiles,
        BaseImage $buildFile,
        array $path = array()
    ) {
        // keys of all images that we are going to build later
        $imageKeys = array_keys($buildFiles);
        $parentImageKey = $buildFile->getFrom();
        $parentImageKey = preg_replace('/:[^:]*$/', '', $parentImageKey);

        if (in_array($parentImageKey, $imageKeys)) {
            // add the image key of the current build file to the dependency tree
            $path[] = $parentImageKey;

            // extend the path by the dependency tree of the parent image
            $path = $this->getDependencyTree(
                $buildFiles,
                $buildFiles[$parentImageKey],
                $path
            );

            return $path;
        }

        return $path;
    }

    protected function cleanup()
    {
        echo "Cleaning up ..." . PHP_EOL;
        if (file_exists($this->config['DOCKER_FILE'])) {
            unlink($this->config['DOCKER_FILE']);
        }
        system('rm -rf '. $this->config['ASSETS_DIR'] .'/*');
    }

    protected function outputHeader($headerMessage)
    {
        echo PHP_EOL . PHP_EOL .str_pad("", 80, "#") . PHP_EOL;
        echo "# $headerMessage" . PHP_EOL;
        echo str_pad("", 80, "#") . PHP_EOL . PHP_EOL;
    }
}
