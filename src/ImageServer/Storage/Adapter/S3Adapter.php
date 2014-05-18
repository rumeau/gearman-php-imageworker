<?php
namespace ImageServer\Storage\Adapter;

use Aws\Common\Aws;
use Aws\S3\Model\AcpBuilder;

class S3Adapter extends AbstractAdapter
{
    protected $aws;

    protected $s3Client;

    public function __construct($config = array())
    {
        $this->setConfig($config);
        if (!is_writeable($this->config['tmp_dir'])) {
            throw new \Exception('tmp_dir provided is not writable');
        }

        $this->createAwsService();
        $this->createS3Client();
    }

    /**
     * Get a file from the s3 bucket
     *
     * @param string File name with path
     * @param boolean
     * @return string     Temporary file name with path
     * @throws \Exception
     */
    public function getFile($file, $withPath = true)
    {
        $bucket  = $this->config['bucket'];
        $tmpDir  = isset($this->config['tmp_dir']) ? $this->config['tmp_dir'] : sys_get_temp_dir();
        echo '[DEBUG] Temp dir is: ' . $tmpDir;
        $tmpFile = tempnam($tmpDir, 'imgs-s3-');
        //unlink($tmpFile);
        $result  = array();
        
        try {
            $result = $this->s3Client->getObject(array(
                'Bucket' => $bucket,
                'Key'    => $file,
                'SaveAs' => $tmpFile
            ));
            echo '[DEBUG] ' . $result['Body']->getUri() . PHP_EOL;
        } catch (\Exception $e) {
            unlink($tmpFile);
            throw new \Exception('File "' . $file . '" could not be downloaded with message: ' . $e->getMessage());
        }

        $object = new \stdClass();
        $object->tmpName = $tmpFile;
        $object->name = $file;
        $object->meta = $result['Metadata'];
        $object->contenttype = $result['ContentType'];

        return $object;
    }

    /**
     * Stores all file thubmnails back to the s3 bucket
     *
     * @param array Filename => Destination key pair
     */
    public function putFile($files = array())
    {
        $bucket = $this->config['bucket'];

        if (isset($this->config['owner']) && !empty($this->config['owner'])) {
            $acpBuilder = AcpBuilder::newInstance();
            $acpBuilder->setOwner($this->config['owner'])
                ->addGrantForGroup(\Aws\S3\Enum\Permission::READ, \Aws\S3\Enum\Group::AUTHENTICATED_USERS);

            $acp = $acpBuilder->build();
        }

        $keys = array();
        $error = '';
        foreach ($files as $file) {
            $info = array(
                'Bucket'     => $bucket,
                'Key'        => $file['destination'],
                'SourceFile' => $file['source'],
                'ContentType'=> isset($file['content_type']) ? $file['content_type'] : 'image/jpg',
            );

            if (isset($file['meta'])) {
                $info['Meta'] = $file['meta'];
            }
            if (isset($acp)) {
                $info['ACP'] = $acp;
            }

            $result = $this->s3Client->putObject($info);
            if (isset($result['ETag'])) {
                $keys[]['Key'] = $file['destination'];
            } else {
                if (count($keys)) {
                    $this->s3Client->deleteObjects(array(
                        'Bucket' => $bucket,
                        'Objects'=> $keys
                    ));
                }
                $error = 'There was an error when uploading the files to the S3 bucket';
                break;
            }
        }
        if (!empty($error)) {
            throw new \Exception($error);
        }

        return true;
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
