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

    public function __construct($config = array())
    {
        $this->setConfig($config);
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
        $json = $job->workload();
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'The json data received is not valid' . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

        $error = $this->validateParams($data);
        if ($error) {
            echo $error . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

        // TODO Filter $data params
        try {
            $fileName = $this->storageAdapter->getFile($data['filename']);
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

        $image = $this->imageManipulator->loadImage($fileName->tmpName);
        if (!is_object($image->getImage())) {
            echo 'The worker was unable to generate a valid image resource to process' . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

        $task = 'processMethod' . ucfirst($data['task']);
        if (!method_exists($this->imageManipulator, $task)) {
            echo 'The image manipulator does not have a method "' . $data['task'] . '"' . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

        $error = $this->processData($image, $data, $fileName);
        if ($error) {
            echo $error . PHP_EOL;
            exit(GEARMAN_WORK_FAIL);
        }

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
        foreach ($data['sizes'] as $suffix => $size) {
            $newName = $this->formatNewName($name, $suffix);

            $childTask = $data['task'];
            if (isset($size['task'])) {
                $childTask = $size['task'];
                unset($size['task']);
            }
            $childTask = 'processMethod' . ucfirst($childTask);
            $image = call_user_method_array($childTask, $this->imageManipulator, $size);

            // Save new thumbnail on a temporary file
            $tmpFileInfo = pathinfo($fileName->tmpName);
            $newTmpFile = $tmpFileInfo['dirname'] . DIRECTORY_SEPARATOR . $suffix . '_' . $tmpFileInfo['basename'];

            // Add thumbnail to upload queue
            $files[] = array(
                'source' => $newTmpFile,
                'destination' => $newName,
                'content_type' => $contentType,
                'meta' => $meta
            );
            $temporalFiles[] = $newTmpFile;
        }

        try {
            $this->storageAdapter->putFiles($files);
            unlink($fileName->tmpName);
        } catch (\Exception $e) {
            foreach ($temporalFiles as $toRemove) {
                unlink($toRemove);
            }
            unlink($fileName->tmpName);
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
}
