<?php
require 'vendor/autoload.php';

use Symfony\Component\ClassLoader\UniversalClassLoader;

$loader = new UniversalClassLoader();
$loader->register();
$loader->registerNamespace('ImageServer', __DIR__ . '/src');

$application = new ImageServer\Application(include 'config/config.php');

$worker = new GearmanWorker();

include 'config/gearman.php';

if (isset($gearman['servers'])) {
    if (!is_array($gearman['servers'])) {
        $gearman['servers'] = array($gearman['servers']);
    }
    foreach ($gearman['servers'] as $server) {
        $worker->addServer($server[0], $server[1]);
    }
} else {
    $worker->addServer();
}

$functionName = isset($gearman['function_name']) ? $gearman['function_name'] : 'image_server';
$worker->addFunction($functionName, array($application, 'run'));
while ($worker->work()) {
    break;
}
