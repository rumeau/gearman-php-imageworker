<?php
$profile = getenv('PROFILE_IMAGE_SERVER_JOB') || false;
if ($profile) {
	$mtime = microtime(); 
    $mtime = explode(" ",$mtime); 
    $mtime = $mtime[1] + $mtime[0]; 
    $starttime = $mtime; 
    echo 'START MEMORY: ' . memory_get_usage() . PHP_EOL;
}

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
while($worker->work());

if ($profile) {
	echo 'END MEMORY: ' . memory_get_usage() . PHP_EOL;
	echo 'PEAK MEMORY: ' . memory_get_peak_usage(true) . PHP_EOL;

	$mtime = microtime(); 
    $mtime = explode(" ",$mtime); 
    $mtime = $mtime[1] + $mtime[0]; 
    $endtime = $mtime; 
    $totaltime = ($endtime - $starttime); 
    echo 'JOB EXECUTED IN ' . $totaltime . ' SECONDS'; 
}
