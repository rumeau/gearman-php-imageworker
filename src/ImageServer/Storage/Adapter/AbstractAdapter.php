<?php
namespace ImageServer\Storage\Adapter;

abstract class AbstractAdapter
{
    protected $config;

    public function setConfig($config = array())
    {
        $this->config = $config;

        return $this;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getFile($file, $withPath = true)
    {

    }

    public function putFiles($files = array())
    {

    }
}
