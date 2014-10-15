<?php

namespace Posedoc;


interface BaseImageInterface
{
    public function from($from, $tag = 'latest');
    public function maintainer($firstName, $lastName, $email);
    public function requireProject($repository);
    public function addAsset($source, $destination);
    public function env($variable, $value);
    public function run($command);
    public function volume($source, $destination);
}
