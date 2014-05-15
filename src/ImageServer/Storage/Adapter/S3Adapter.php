<?php
namespace ImageServer\Storage\Adapter;

use Aws\Common\Aws;

class S3Adapter extends AbstractAdapter
{
    protected $aws;

    protected $s3Client;

    public function __construct($config = array())
    {
        $this->setConfig($config);

        $this->createAwsService();
        $this->createS3Client();
    }

    /**
     * Get a file from the s3 bucket
     */
    public function getFile($file, $withPath = true)
    {

    }

    /**
     * Stores a file and its thubmnails back to the s3 bucket
     */
    public function putFile($file, $destination)
    {

    }

    /**
     * Create the aws service builder
     */
    protected function createAwsService()
    {
        $this->aws = Aws::factory(include realpath(__DIR__ . '/../../../../config/aws.php'));
    }

    /**
     * Create the s3 client
     */
    protected function createS3Client()
    {
        $this->s3Client = $this->aws->get('s3');
    }
}
