<?php

namespace Posedoc;


class DebianImage extends BaseImage implements DebianImageInterface
{
    /**
     * @param string|array $package
     * @return $this
     */
    public function apt($package)
    {
        if (is_array($package)) {
            $package = join(' ', trim($package));
        }

        $this->package[] = trim($package);

        return $this;
    }
}
