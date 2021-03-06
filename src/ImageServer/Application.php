<?php
namespace ImageServer;

use RuntimeException;
use InvalidArgumentException;
use BadMethodCallException;
use GearmanJob;

class Application
{
    protected $config;

    protected $storageAdapter;

    protected $imageManipulator;

    public static $profile = false;

    public static $debug = false;

    public function __construct($config = array())
    {
        $this->setConfig($config);
        if (isset($this->config['debug']) && $this->config['debug'] === true) {
            static::$debug = true;
        }
        if (isset($this->config['profile']) && $this->config['profile'] === true) {
            static::$profile = true;
        }
        $this->prepareServices();
    }

    public function setConfig($config = array())
    {
        $this->config = $config;
    }

    /**
     * Process the job
     *
     * @param GearmanJob $job
     */
    public function run($job)
    {
        $start = $this->startProfiling();

        $json = $job->workload();
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::debug('The json data received is not valid');
            exit(GEARMAN_WORK_FAIL);
        }

        $error = $this->validateParams($data);
        if ($error) {
            self::debug($error);
            exit(GEARMAN_WORK_FAIL);
        }

        if (isset($data['storage_options'])) {
            foreach ($data['storage_options'] as $so => $args) {
                $method = 'set' . ucfirst($so);
                if (!is_array($args)) {
                    $args = array($args);
                }
                if (method_exists($this->storageAdapter, $method)) {
                    call_user_func_array(array($this->storageAdapter, $method), $args);
                }
            }
        }

        // TODO Filter $data params
        try {
            $fileName = $this->storageAdapter->getFile($data['filename']);
        } catch (\Exception $e) {
            self::debug($e->getMessage());
            exit(GEARMAN_WORK_FAIL);
        }

        $image = $this->imageManipulator->loadImage($fileName->tmpName);
        if (!is_object($image->getImage())) {
            self::debug('The worker was unable to generate a valid image resource to process');
            exit(GEARMAN_WORK_FAIL);
        }

        $task = 'processMethod' . ucfirst($data['task']);
        if (!method_exists($this->imageManipulator, $task)) {
            self::debug('The image manipulator does not have a method "' . $data['task'] . '"');
            exit(GEARMAN_WORK_FAIL);
        }

        $error = $this->processData($image, $data, $fileName);
        if ($error) {
            self::debug($error);
            exit(GEARMAN_WORK_FAIL);
        }

        global $profile, $starttime;
        if ($profile) {
            echo 'END MEMORY: ' . memory_get_usage() . PHP_EOL;
            echo 'PEAK MEMORY: ' . memory_get_peak_usage(true) . PHP_EOL;

            $mtime = microtime();
            $mtime = explode(" ", $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $endtime = $mtime;
            $totaltime = ($endtime - $starttime);
            echo 'JOB EXECUTED IN ' . $totaltime . ' SECONDS' . PHP_EOL;
        }

        $this->endProfiling($start);
        return true;
    }

    /**
     * Process all sizes in the data message
     *
     * @param Gmagick
     * @param array
     * @param string
     */
    protected function processData($image, $data, $fileName)
    {
        $name = $fileName->name;
        $meta = $fileName->meta;
        $contentType = $fileName->contenttype;
        $files = array();
        $temporalFiles = array();
        $this->debug(count($data['sizes']) . ' Sizes to create');
        foreach ($data['sizes'] as $suffix => $size) {
            $newName = $this->formatNewName($name, $suffix);

            $childTask = $data['task'];
            if (isset($size['task'])) {
                $childTask = $size['task'];
                unset($size['task']);
            }
            $childTask = 'processMethod' . ucfirst($childTask);
            $image = call_user_func_array(array($this->imageManipulator, $childTask), $size);

            // Save new thumbnail on a temporary file
            $tmpFileInfo = pathinfo($fileName->tmpName);
            $newTmpFile = $tmpFileInfo['dirname'] . DIRECTORY_SEPARATOR . $suffix . '_' . $tmpFileInfo['basename'];
            $image->writeimage($newTmpFile);
            $image->destroy();

            // Add thumbnail to upload queue
            $files[] = array(
                'source' => $newTmpFile,
                'destination' => $newName,
                'content_type' => $contentType,
                'meta' => $meta
            );
            $temporalFiles[] = $newTmpFile;
            self::debug('Creating image with task: ' . $childTask . ' and added to queue');
        }

        try {
            self::debug('--- Initializing upload ---');
            $this->storageAdapter->putFiles($files);
            self::debug('Done Uploading!');
            self::debug('-> Removing temp file');
            foreach ($temporalFiles as $toRemove) {
                unlink($toRemove);
                self::debug('Removed file: ' . $toRemove);
            }
            unlink($fileName->tmpName);
            $this->imageManipulator->getImage()->destroy();
        } catch (\Exception $e) {
            self::debug('Remove previous files in this task');
            foreach ($temporalFiles as $toRemove) {
                unlink($toRemove);
                self::debug('Removed file: ' . $toRemove);
            }
            self::debug('-> Removing temp file');
            unlink($fileName->tmpName);
            $this->imageManipulator->getImage()->destroy();
            return $e->getMessage();
        }

        // No errors
        return false;
    }

    protected function prepareServices()
    {
        $imageServerConfig = $this->config['imageserver'];
        $storageType = $imageServerConfig['storage']['type'];
        $storageOptions = isset($imageServerConfig['storage']['options']) ? $imageServerConfig['storage']['options'] : array();
        $classTmpl = 'ImageServer\Storage\Adapter\%sAdapter';
        $class = sprintf($classTmpl, ucfirst($storageType));

        if (!class_exists($class)) {
            throw new RuntimeException('The adapter of type "' . $class . '" does not exists');
        }

        $this->storageAdapter = new $class($storageOptions);

        $manipulatorType = $imageServerConfig['manipulation']['type'];
        $manipulatorOptions = isset($imageServerConfig['manipulation']['options']) ? $imageServerConfig['manipulation']['options'] : array();
        $classTmpl = 'ImageServer\ImageManipulator\%sManipulator';
        $class = sprintf($classTmpl, ucfirst($manipulatorType));
        if (!class_exists($class)) {
            throw new RuntimeException('The manipulator of type "' . $class . '" does not exists');
        }

        $this->imageManipulator = new $class($manipulatorOptions);
    }

    protected function validateParams($params)
    {
        if (!isset($params['filename'])) {
            return 'You must provide a filename to process';
        }

        if (!isset($params['task'])) {
            return 'The data submitted must include a default task for the image manipulator';
        } elseif (!method_exists($this->imageManipulator, 'processMethod' . ucfirst($params['task']))) {
            return 'The task provided is not a valid task for the manipulator';
        }

        if (!isset($params['sizes']) || !count($params['sizes'])) {
            return 'You must define at least one image size to process';
        }

        foreach ($params['sizes'] as $key => $size) {
            if (!is_string($key)) {
                return 'Size key must be a string';
            }

            if (isset($size['task']) && !method_exists($this->imageManipulator, 'processMethod' . ucfirst($size['task']))) {
                return 'The task provided is not a valid task for the manipulator';
            }
        }
    }

    protected function formatNewName($name, $suffix)
    {
        $info = pathinfo($name);
        $newTarget = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '_' . $suffix;
        if (isset($info['extension'])) {
            $newTarget .= '.' . $info['extension'];
        }

        return $newTarget;
    }

    public static function debug($msg)
    {
        if (defined('DEBUG_MODE') && DEBUG_MODE === 1) {
            echo $msg . PHP_EOL;
        }
    }

    protected function startProfiling()
    {
        if (!static::$profile) {
            return;
        }

        $mtime = microtime();
        $mtime = explode(" ", $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $starttime = $mtime;

        echo '[PROFILE] START MEMORY: ' . memory_get_usage() . PHP_EOL;

        return $starttime;
    }

    protected function endProfiling($starttime)
    {
        if (!static::$profile) {
            return;
        }

        echo '[PROFILE] END MEMORY: ' . memory_get_usage() . PHP_EOL;
        echo '[PROFILE] PEAK MEMORY: ' . memory_get_peak_usage(true) . PHP_EOL;

        $mtime = microtime();
        $mtime = explode(" ", $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $endtime = $mtime;
        $totaltime = ($endtime - $starttime);
        echo '[PROFILE] JOB EXECUTED IN ' . $totaltime . ' SECONDS' . PHP_EOL;
    }
}
