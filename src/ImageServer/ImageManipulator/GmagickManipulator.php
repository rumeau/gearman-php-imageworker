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

    public function getImage()
    {
        return $this->gmagick;
    }

    public function processMethodResize($width, $height, $fit = true)
    {
        $imageToProcess = clone $this->gmagick;
        $imageToProcess->thumbnailimage($width, $height, $fit);
        return $imageToProcess;
    }
}
