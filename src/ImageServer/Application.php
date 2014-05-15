<?php
namespace ImageServer;

use RuntimeException;
use InvalidArgumentException;
use BadMethodCallException;

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

    public function run($job)
    {
        $json = $job->workload();
        $data = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('The json data received is not valid');
        }

        $this->validateParams($data);

        // TODO Filter $data params
        $fileName = $this->storageAdapter->getFile($data['filename']);
        $image = $this->imageManipulator->loadImage($fileName->tempName);
        if (!$image instanceof Gmagick) {
            throw new RuntimeException('The worker was unable to generate a valid image resource to process');
        }

        $task = 'processMethod' . ucfirst($data['task']);
        if (!method_exists($this->imageManipulator, $task)) {
            throw new BadMethodCallException('The image manipulator does not have a method "' . $data['task'] . '"');
        }

        $this->processData($image, $data, $fileName);

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
        foreach ($data['sizes'] as $suffix => $size) {
            $newName = $this->formatNewName($name, $suffix);

            $childTask = $data['task'];
            if (isset($size['task'])) {
                $childTask = $size['task'];
                unset($size['task']);
            }
            $childTask = 'processMethod' . ucfirst($childTask);
            $image = call_user_method_array($childTask, $this->imageManipulator, $size);
        }
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
            throw new BadMethodCallException('You must provide a filename to process');
        }

        if (!isset($params['task'])) {
            throw new BadMethodCallException('The data submitted must include a default task for the image manipulator');
        } elseif (!method_exists($this->imageManipulator, 'processMethod' . ucfirst($params['task']))) {
            throw new BadMethodCallException('The task provided is not a valid task for the manipulator');
        }

        if (!isset($params['sizes']) || !count($params['sizes'])) {
            throw new BadMethodCallException('You must define at least one image size to process');
        }

        foreach ($params['sizes'] as $key => $size) {
            if (!is_string($key)) {
                throw new BadMethodCallException('Size key must be a string');
            }

            if (isset($size['task']) && !method_exists($this->imageManipulator, 'processMethod' . ucfirst($size['task']))) {
                throw new BadMethodCallException('The task provided is not a valid task for the manipulator');
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
