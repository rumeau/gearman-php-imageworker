<?php
namespace ImageServer\ImageManipulator;

use Gmagick;

class GmagickManipulator
{
    protected $options;

    protected $gmagick;

    public function __construct($options = array())
    {
        $this->options = $options;

        return $this;
    }

    public function loadImage($filename)
    {
        $this->gmagick = new Gmagick($filename);

        return $this;
    }

    public function resize($width, $height, $fit = true)
    {
        $this->gmagick->thumbnailimage($width, $height, $fit);
        return $this->gmagick;
    }
}
