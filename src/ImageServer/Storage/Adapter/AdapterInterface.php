<?php
namespace ImageServer\Storage\Adapter;

interface AdapterInterface
{
    public function getFile($file, $withPath);

    public function putFile($file, $destination)
}
