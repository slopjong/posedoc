<?php

namespace Posedoc;


interface DebianImageInterface extends BaseImageInterface
{
    public function apt($package);
}
