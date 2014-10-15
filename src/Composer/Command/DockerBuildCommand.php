<?php

namespace Composer\Command;


use Composer\Config;
use Composer\Config\ConfigSourceInterface;
use Composer\Config\JsonConfigSource;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\GitHub;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DockerBuildCommand extends Command
{
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
}
