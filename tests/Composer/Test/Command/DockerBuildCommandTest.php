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

    public function testSetGetConfig()
    {
        $config = array(
            // in a phar __DIR__ starts with phar:// so we use realpath()
            'ROOT_DIR'    => realpath("."),
            'BUILD_DIR'   => realpath(".") . '/.tmp',
        );

        $config = array_merge($config, array(
            'IMAGES_DIR'  => $config['ROOT_DIR']  . '/images',
            'AUTH_FILE'   => $config['BUILD_DIR'] . '/auth.json',
            'PROJECT_DIR' => $config['BUILD_DIR'] . '/project',
            'ASSETS_DIR'  => $config['BUILD_DIR'] . '/assets',
            'CACHE_DIR'   => $config['BUILD_DIR'] . '/cache',
            'DOCKER_FILE' => $config['BUILD_DIR'] . '/Dockerfile',
            'POSIGNORE'   => $config['ROOT_DIR']  . '/.posignore',
        ));

        $this->callMethod($this->command, 'setConfig', array($config));
        $actualConfig = $this->callMethod($this->command, 'getConfig');
        $this->assertSame($config, $actualConfig);
    }

    /**
     * @dataProvider buildFileSets
     */
    public function testGetBuildFiles($buildFiles)
    {
        static::callMethod(
            $this->command,
            'setBuildFiles',
            array(
                0 => $buildFiles // first method argument
            )
        );

        $gotBuildFiles = static::callMethod(
            $this->command,
            'getBuildFiles'
        );

        $this->assertCount(count($buildFiles), $gotBuildFiles);
    }

    /**
     * @dataProvider buildFilesForFilterTest
     * @param $buildFiles
     * @param $expectedFilteredBuildFiles
     */
    public function testFilterBuildFiles($buildFiles, $expectedFilteredBuildFiles)
    {
        $externalFlag = true;
        $actualFilteredBuildFiles = $this->callMethod(
            $this->command,
            'filterBuildFiles',
            array(
                0 => $buildFiles,   // first method argument
                1 => $externalFlag, // second method argument
            )
        );

        $keys = array_keys($actualFilteredBuildFiles);
        sort($expectedFilteredBuildFiles['external']);
        sort($keys);

        $this->assertEquals(
            $expectedFilteredBuildFiles['external'],
            $keys,
            'External dependencies don\'t match'
        );


        $externalFlag = false;
        $actualFilteredBuildFiles = $this->callMethod(
            $this->command,
            'filterBuildFiles',
            array(
                0 => $buildFiles,   // first method argument
                1 => $externalFlag, // second method argument
            )
        );

        $keys = array_keys($actualFilteredBuildFiles);
        sort($expectedFilteredBuildFiles['internal']);
        sort($keys);

        $this->assertEquals(
            $expectedFilteredBuildFiles['internal'],
            $keys,
            'Internal dependencies don\'t match'
        );
    }

    /**
     * @dataProvider buildFilesForSortTests
     */
    public function testSortBuildFiles($buildFiles, $expectedSortedBuildFiles)
    {
        $sortedBuildFiles = static::callMethod(
            $this->command,
            'sortBuildFiles',
            array(
                0 => $buildFiles // first method argument
            )
        );

        $this->assertSame(
            $expectedSortedBuildFiles,
            array_keys($sortedBuildFiles)
        );
    }

    /**
     * @dataProvider dependencyTrees
     * @param $buildFiles
     * @param $trees
     */
    public function testDependencyTree($buildFiles, $trees)
    {
        // iterate over all build files and test if the tree got correctly build
        foreach ($buildFiles as $buildFileKey => $buildFile) {
            $actualTree = $this->callMethod(
                $this->command,
                'getDependencyTree',
                array(
                    0 => $buildFiles,   // first method argument
                    1 => $buildFile,    // second method argument
                )
            );

            $expectedTree = $trees[$buildFileKey];
            $this->assertSame($expectedTree, $actualTree);
        }
    }

    //****************************************************************
    // DATA PROVIDER

    public function buildFilesForSortTests()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(
            array(
                $this->buildFilesSet1()[0],
                array(
                    'example/image1',
                    'example/image2',
                ),
            ),
            array(
                $this->buildFilesSet2()[0],
                array(
                    'example/image1',
                    'example/image5',
                    'example/image2',
                    'example/image3',
                    'example/image4',
                ),
            ),
        );
    }

    public function buildFilesForFilterTest()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(
            array(
                $this->buildFilesSet1()[0],
                array(
                    'internal' => array(
                        'example/image2',
                    ),
                    'external' => array(
                        'example/image1',
                    ),
                ),
            ),
            array(
                $this->buildFilesSet2()[0],
                array(
                    'internal' => array(
                        'example/image2',
                        'example/image3',
                        'example/image4',
                    ),
                    'external' => array(
                        'example/image1',
                        'example/image5',
                    ),
                ),
            ),
        );
    }

    public function buildFileSets()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(
            $this->buildFilesSet1(), // data set for test run #1
            $this->buildFilesSet2(), // data set for test run #2
        );
    }

    public function buildFilesSet1()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(array(
            'example/image2' =>
                (new DebianImage())->from('example/image1'),
            'example/image1' =>
                (new DebianImage())->from('ubuntu')
            ,
        ));
    }

    public function buildFilesSet2()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(array(
            'example/image4' =>
                (new DebianImage())->from('example/image2'),
            'example/image2' =>
                (new DebianImage())->from('example/image1'),
            'example/image1' =>
                (new DebianImage())->from('ubuntu'),
            'example/image3' =>
                (new DebianImage())->from('example/image2'),
            'example/image5' =>
                (new DebianImage())->from('debian'),
        ));
    }

    public function buildFilesSet3()
    {
        // PHPUnit always expects an array from a data provider whose elements
        // are used as the test method arguments. If we want to use an array
        // as a test data set, we need to return a nested array
        return array(array(
            'example/image4' =>
                (new DebianImage())->from('example/image2'),
            'example/image2' =>
                (new DebianImage())->from('example/image1'),
            'example/image1' =>
                (new DebianImage())->from('ubuntu'),
            'example/image3' =>
                (new DebianImage())->from('example/image2'),
            'example/image5' =>
                (new DebianImage())->from('debian'),
        ));
    }

    public function dependencyTrees()
    {
        return array(
            // data set for test run #1
            array(
                $this->buildFilesSet2()[0],
                array(
                    'example/image4' => array('example/image2', 'example/image1'),
                    'example/image3' => array('example/image2', 'example/image1'),
                    'example/image2' => array('example/image1'),
                    'example/image1' => array(),
                    'example/image5' => array(),
                )
            ),
        );
    }

    //****************************************************************
    // HELPERS

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

    //****************************************************************
    // SETUP/CLEANUP

    public function setup()
    {
        $this->command = new DockerBuildCommand();
    }

    public function tearDown()
    {
        $command = null;
    }
}
