<?php

namespace Posedoc;


class BaseImage implements BaseImageInterface, DockerFileInterface, WebProjectInterface
{
    protected $from;
    protected $maintainer;
    protected $env = array();
    protected $add = array();
    protected $assets = array();
    protected $run = array();
    protected $volume = array();

    protected $project = array();

    protected $package = array();

    protected $directives_order = array();

    /**
     * @param $from
     * @param string $tag
     * @return $this
     */
    public function from($from, $tag = 'latest')
    {
        $this->from = $from .':'. $tag;
        return $this;
    }

    public function getFrom()
    {
        return $this->from;
    }

    /**
     * @param $firstName
     * @param $lastName
     * @param $email
     * @return $this
     */
    public function maintainer($firstName, $lastName, $email)
    {
        $data = array(
            trim($firstName),
            trim($lastName),
            '<'. trim($email) .'>'
        );

        $this->maintainer = join(' ', $data);
        return $this;
    }

    /**
     * @param $source
     * @param $destination
     * @return $this
     */
    public function addAsset($source, $destination)
    {
        $this->assets[] = $source;
        $this->add[] = 'assets/' . $source .' '. $destination;
        $this->_order('add');
        return $this;
    }

    public function getAssets()
    {
        return $this->assets;
    }

    /**
     * @param $variable
     * @param $value
     * @return $this
     */
    public function env($variable, $value)
    {
        $this->env[] = "ENV $variable $value";
        return $this;
    }

    public function volume($source, $destination)
    {
        $this->volume[] = "VOLUME";
    }

    /**
     * The command should be passed as an array since this respects the
     * docker cache.
     *
     * @param string|array $command
     * @link https://docs.docker.com/reference/builder/#run
     * @return $this
     */
    public function run($command)
    {
        if (is_array($command)) {
            $_command = '[';
            foreach ($command as $part) {
                $_command .= '"'. $part .'",';
            }
            // remove the trailing comma
            $_command = substr($_command, 0, -1);
            $_command .= ']';

            $this->run[] = $_command;
        } else {
            $this->run[] = $command;
        }

        $this->_order(__METHOD__);
        return $this;
    }

    public function getProjects()
    {
        return $this->project;
    }

    /**
     * @param string $gitRepository
     * @return $this
     */
    public function requireProject($gitRepository)
    {
        $this->project[] = $gitRepository;
        return $this;
    }

    /**
     * @param $projectName
     * @param $files
     * @return $this
     */
    public function addFromProject($projectName, array $files)
    {
        foreach ($files as $src => $dest) {
            $this->add[] = 'ADD project/'. $projectName .'/'. $src .' '. $dest;
        }
        return $this;
    }

    public function toDockerFile()
    {
        $dockerFile = file_get_contents(__DIR__ . '/../../res/Dockerfile.template');

        $dockerFile = str_replace(
            array(
                '{FROM}',
                '{MAINTAINER}',
                '{ENV}',
                '{ADD_PROJECT}',
                '{RUN_PACKAGE_MANAGER}',
                '{OTHER_COMMANDS}',
            ),
            array(
                $this->from,
                $this->maintainer,
                join(PHP_EOL, $this->env),
                join(PHP_EOL, $this->add),
                $this->packageManagerRunCommand(),
                $this->otherCommands(),
            ),
            $dockerFile
        );

        return $dockerFile;
    }

    protected function otherCommands()
    {
        $directives = array();

        foreach ($this->directives_order as $directive) {
            $key = $directive['key'];
            $position = $directive['position'];
            $command = str_replace('"', '\"', $this->{$key}[$position]);

            $directives[] = strtoupper($key) .' '. $this->{$key}[$position];
        }

        return join(PHP_EOL, $directives);
    }

    protected function packageManagerRunCommand()
    {
        if ($this->package) {
            $packages = join(' ', $this->package);
            $runPackageManager = array();

            switch (true) {
                case $this instanceof DebianImageInterface:

                    $runPackageManager = array(
                        'RUN apt-get -qq  update',
                        'RUN apt-get -qqy upgrade',
                        'RUN DEBIAN_FRONTEND=noninteractive apt-get -qqy install ' . $packages,
                        'RUN rm -rf /var/lib/apt/lists/*',
                    );

                    break;

                case $this instanceof DebianImageInterface:

                    $runPackageManager = array(
                        'RUN pacman -Syy',
                        'RUN pacman -S -q --noconfirm ' . $packages,
                    );

                    break;
            }

            return join(PHP_EOL, $runPackageManager);
        }

        return '';
    }

    protected function _order($method)
    {
        $arr = explode('::', $method);
        $key = array_pop($arr);
        $this->directives_order[] = array(
            'key'      => $key,
            'position' => count($this->$key) - 1
        );
    }
}
