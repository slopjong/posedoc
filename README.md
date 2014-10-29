Posedoc - Docker Provisioning Tool
==================================

Posedoc helps you containerize composer-based web applications. This tool includes composer and enhances it by a docker build command.

Installation / Usage
--------------------

1. Download the [`composer.phar`](https://slopjong.github.io/composer.phar) executable or build it from its sources.

    ``` sh
    $ git clone https://github.com/slopjong/posedoc.git
    $ cd posedoc/bin
    $ ./compile
    ```

2. Copy composer.phar from the bin directory to the root directory of your [posedoc build files](https://github.com/slopjong/posedoc-build-files).
3. Run posedoc: `php composer.phar docker-build`
4. Find your docker image tarball in `builds`.


Global installation of Composer (manual)
----------------------------------------

Follow instructions [in the documentation](http://getcomposer.org/doc/00-intro.md#globally)


Requirements
------------

PHP 5.3.2 or above (at least 5.3.4 recommended to avoid potential bugs)

Authors
-------

* Composer
  * Nils Adermann - <http://twitter.com/naderman> - <http://www.naderman.de><br />
  * Jordi Boggiano - <http://twitter.com/seldaek> - <http://seld.be><br />
  * See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

* Docker extension
  * Romain Schmitz - <http://twitter.com/slopjong> - <http://slopjong.de><br />

License
-------

Composer is licensed under the MIT License - see the LICENSE file for details
