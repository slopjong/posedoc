<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Command;

use Composer\Command\DockerBuildCommand;
use Composer\TestCase;
use Posedoc\DebianImage;

class DockerBuildCommandTest extends TestCase
{
    protected $command;
    protected $buildFiles;

    public function __construct()
    {
        $this->buildFiles = array(
            'example/image2' => (new DebianImage())->from('example/image1'),
            'example/image1' => (new DebianImage())->from('ubuntu'),
        );
    }

    public function setup()
    {
        $this->command = new DockerBuildCommand();
    }

    public function tearDown()
    {
        $command = null;
    }

    public function testGetBuildFiles()
    {
        static::callMethod($this->command, 'setBuildFiles', array($this->buildFiles));

        $gotBuildFiles = static::callMethod($this->command, 'getBuildFiles');
        $this->assertCount(2, $gotBuildFiles);
    }

    public function testSortBuildFiles()
    {
        $sortedBuildFiles = static::callMethod($this->command, 'sortDependencies', array($this->buildFiles));
        $this->assertSame(
            array('example/image1', 'example/image2'),
            array_keys($sortedBuildFiles)
        );
    }

    protected static function callMethod($obj, $name, array $args = array())
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        if ($args) {
            return $method->invokeArgs($obj, $args);
        } else {
            return $method->invoke($obj);
        }
    }
}
