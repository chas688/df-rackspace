<?php

namespace DreamFactory\Core\Rackspace\Components;

use DreamFactory\Core\Enums\HttpStatusCodes;
use DreamFactory\Core\Exceptions\DfException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\File\Components\RemoteFileSystem;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\FileUtilities;
use InvalidArgumentException;
use OpenCloud\Common\Exceptions\ObjFetchError;
use OpenCloud\Common\Request\Response\Http;
use OpenCloud\Common\Collection;
use OpenCloud\ObjectStore\Service;
use OpenCloud\ObjectStore\Container;
use OpenCloud\ObjectStore\DataObject;
use OpenCloud\Common\Exceptions\ContainerNotFoundError;

/**
 * Class OpenStackObjectStorageSystem
 *
 * @package DreamFactory\Core\Rackspace\Components
 */
class OpenStackObjectStorageSystem extends RemoteFileSystem
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var Service
     */
    protected $blobConn = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @throws DfException
     */
    protected function checkConnection()
    {
        if (empty($this->blobConn)) {
            throw new DfException('No valid connection to blob file storage.');
        }
    }

    /**
     * @param array $config
     *
     * @throws InvalidArgumentException
     * @throws DfException
     */
    public function __construct($config)
    {
        Session::replaceLookups($config, true);
        $this->container = array_get($config, 'container');

        if (empty($username = array_get($config, 'username'))) {
            throw new InvalidArgumentException('Object Store username can not be empty.');
        }

        $secret = ['username' => $username];

        if (empty($apiKey = array_get($config, 'api_key'))) {
            if (empty($password = array_get($config, 'password'))) {
                throw new InvalidArgumentException('Object Store credentials must contain an API key or a password.');
            }
            // openstack
            $secret['password'] = $password;
            $authUrl = array_get($config, 'url');
            $region = array_get($config, 'region');
        } else {
            // rackspace
            $secret['apiKey'] = $apiKey;
            $authUrl = array_get($config, 'url', 'https://identity.api.rackspacecloud.com/');
            $region = array_get($config, 'region', 'DFW');
        }

        if (empty($authUrl)) {
            throw new InvalidArgumentException('Object Store authentication URL can not be empty.');
        }
        if (empty($region)) {
            throw new InvalidArgumentException('Object Store region can not be empty.');
        }

        if (!empty($tenantName = array_get($config, 'tenant_name'))) {
            $secret['tenantName'] = $tenantName;
        }

        try {
            if (empty($apiKey)) {
                $os = new DfOpenStack($authUrl, $secret);
            } else {
                $pos = stripos($authUrl, '/v');
                if (false !== $pos) {
                    $authUrl = substr($authUrl, 0, $pos);
                }
                $authUrl = FileUtilities::fixFolderPath($authUrl) . 'v2.0';
                $os = new DfRackspace($authUrl, $secret);
            }

            $this->blobConn = $os->ObjectStore('cloudFiles', $region);
            if (!$this->containerExists($this->container)) {
                $this->createContainer(['name' => $this->container]);
            }
        } catch (\Exception $ex) {
            throw new DfException('Failed to launch OpenStack service: ' . $ex->getMessage());
        }
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        unset($this->blobConn);
    }

    /**
     * List all containers, just names if noted
     *
     * @param bool $include_properties If true, additional properties are retrieved
     *
     * @throws DfException
     * @return array
     */
    public function listContainers($include_properties = false)
    {
        $this->checkConnection();

        if (!empty($this->container)) {
            return $this->listResource($include_properties);
        }

        try {
            /** @var Collection $containers */
            $containers = $this->blobConn->ContainerList();

            $out = [];

            /** @var Container $container */
            while (($container = $containers->Next())) {
                $name = rtrim($container->name);
                $out[] = ['name' => $name, 'path' => $name];
            }

            return $out;
        } catch (\Exception $ex) {
            throw new DfException('Failed to list containers: ' . $ex->getMessage());
        }
    }

    /**
     * Gets all properties of a particular container, if options are false,
     * otherwise include content from the container
     *
     * @param string $container Container name
     * @param bool   $include_files
     * @param bool   $include_folders
     * @param bool   $full_tree
     *
     * @throws DfException
     * @return array
     */
    public function getContainer(
        $container,
        $include_files = true,
        $include_folders = true,
        $full_tree = false
    ){
        $this->checkConnection();
        $result = $this->getFolder($container, '', $include_files, $include_folders, $full_tree);

        return $result;
    }

    public function getContainerProperties($container)
    {
        $this->checkConnection();
        $result = ['name' => $container];

        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            $result['size'] = $container->bytes;
        } catch (ContainerNotFoundError $ex) {
            throw new DfException('Failed to find container: ' . $ex->getMessage());
        } catch (\Exception $ex) {
            throw new DfException('Failed to get container: ' . $ex->getMessage());
        }

        return $result;
    }

    /**
     * Check if a container exists
     *
     * @param  string $container Container name
     *
     * @throws DfException
     * @return boolean
     */
    public function containerExists($container = '')
    {
        $this->checkConnection();

        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);

            return !empty($container);
        } catch (ContainerNotFoundError $ex) {
            return false;
        } catch (\Exception $ex) {
            throw new DfException('Failed to list containers: ' . $ex->getMessage());
        }
    }

    /**
     * @param array $properties
     * @param array $metadata
     *
     * @return array
     * @throws BadRequestException
     * @throws DfException
     * @throws \Exception
     */
    public function createContainer($properties, $metadata = [])
    {
        $this->checkConnection();

        $name = array_get($properties, 'name', array_get($properties, 'path'));
        if (empty($name)) {
            throw new BadRequestException('No name found for container in create request.');
        }
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container();
            $params = ['name' => $name];
            if (!$container->Create($params)) {
                throw new \Exception('');
            }

            return ['name' => $name, 'path' => $name];
        } catch (\Exception $ex) {
            throw new DfException("Failed to create container '$name': " . $ex->getMessage());
        }
    }

    /**
     * Update a container with some properties
     *
     * @param string $container
     * @param array  $properties
     *
     * @throws DfException
     * @return void
     */
    public function updateContainerProperties($container, $properties = [])
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            if (!$container->Update()) {
                throw new \Exception('');
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to update container '$container': " . $ex->getMessage());
        }
    }

    /**
     * Delete a container and all of its content
     *
     * @param string $container
     * @param bool   $force Force a delete if it is not empty
     *
     * @throws DfException
     * @throws \Exception
     * @return void
     */
    public function deleteContainer($container, $force = false)
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            if (!$container->Delete()) {
                throw new \Exception('');
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to delete container '$container': " . $ex->getMessage());
        }
    }

    /**
     * Check if a blob exists
     *
     * @param  string $container Container name
     * @param  string $name      Blob name
     *
     * @throws \Exception
     * @return boolean
     */
    public function blobExists($container = '', $name = '')
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject($name);

            return !empty($obj);
        } catch (\Exception $ex) {
        }

        return false;
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $blob
     * @param string $type
     *
     * @throws DfException
     * @throws \Exception
     */
    public function putBlobData($container = '', $name = '', $blob = '', $type = '')
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject();
            $obj->SetData($blob);
            $obj->name = $name;
            if (!empty($type)) {
                $obj->content_type = $type;
            }
            if (!$obj->Create()) {
                throw new \Exception('');
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to create blob '$name': " . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $localFileName
     * @param string $type
     *
     * @throws DfException
     * @throws \Exception
     */
    public function putBlobFromFile($container = '', $name = '', $localFileName = '', $type = '')
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject();
            $params = ['name' => $name];
            if (!empty($type)) {
                $params['content_type'] = $type;
            }

            if (!$obj->Create($params, $localFileName)) {
                throw new \Exception('');
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to create blob '$name': " . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $src_container
     * @param string $src_name
     * @param array  $properties
     *
     * @throws DfException
     * @throws \Exception
     */
    public function copyBlob($container = '', $name = '', $src_container = '', $src_name = '', $properties = [])
    {
        $this->checkConnection();
        try {
            /** @var Container $src_container */
            $src_container = $this->blobConn->Container($src_container);
            if (empty($src_container)) {
                throw new \Exception("No container named '$src_container'");
            }
            /** @var Container $dest_container */
            $dest_container = $this->blobConn->Container($container);
            if (empty($dest_container)) {
                throw new \Exception("No container named '$container'");
            }

            $source = $src_container->DataObject($src_name);
            $destination = $dest_container->DataObject();
            $destination->name = $name;

            $source->Copy($destination);
        } catch (\Exception $ex) {
            throw new DfException("Failed to copy blob '$name': " . $ex->getMessage());
        }
    }

    /**
     * Get blob
     *
     * @param  string $container     Container name
     * @param  string $name          Blob name
     * @param  string $localFileName Local file name to store downloaded blob
     *
     * @throws DfException
     * @throws \Exception
     */
    public function getBlobAsFile($container = '', $name = '', $localFileName = '')
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject($name);

            if (!$obj->SaveToFilename($localFileName)) {
                throw new \Exception('');
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to retrieve blob '$name': " . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     *
     * @throws DfException
     * @throws \Exception
     * @return string
     */
    public function getBlobData($container = '', $name = '')
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject($name);

            return $obj->SaveToString();
        } catch (\Exception $ex) {
            throw new DfException("Failed to retrieve blob '$name': " . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param bool   $noCheck
     *
     * @throws DfException
     * @throws \Exception
     */
    public function deleteBlob($container = '', $name = '', $noCheck = false)
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = null;
            try {
                $obj = $container->DataObject($name);
            } catch (\Exception $ex) {
                if ($noCheck) {
                    return;
                }
                throw $ex;
            }
            if ($obj) {
                $obj->Delete();
            }
        } catch (\Exception $ex) {
            if ($ex instanceof ObjFetchError) {
                throw new NotFoundException("File '$name' was not found.'");
            }
            throw new DfException('Failed to delete blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * List blobs
     *
     * @param  string $container Container name
     * @param  string $prefix    Optional. Filters the results to return only blobs whose name begins with the
     *                           specified prefix.
     * @param  string $delimiter Optional. Delimiter, i.e. '/', for specifying folder hierarchy
     *
     * @throws \Exception
     * @return array
     */
    public function listBlobs($container = '', $prefix = '', $delimiter = '')
    {
        $this->checkConnection();

        $options = [];
        if (!empty($prefix)) {
            $options['prefix'] = $prefix;
        }
        if (!empty($delimiter)) {
            $options['delimiter'] = $delimiter;
        }

        /** @var Container $container */
        $container = $this->blobConn->Container($container);
        if (empty($container)) {
            throw new \Exception("No container named '$container'");
        }

        /** @var Collection $list */
        $list = $container->ObjectList($options);

        $out = [];

        /** @var DataObject $obj */
        while (($obj = $list->Next())) {
            if (!empty($obj->name)) {
                if (0 == strcmp($prefix, $obj->name)) {
                    continue;
                }
                $out[] = [
                    'name'           => $obj->name,
                    'content_type'   => $obj->content_type,
                    'content_length' => $obj->bytes,
                    'last_modified'  => gmdate('D, d M Y H:i:s \G\M\T', strtotime($obj->last_modified))
                ];
            } elseif (!empty($obj->subdir)) // sub directories formatted differently
            {
                $out[] = [
                    'name'           => $obj->subdir,
                    'content_type'   => null,
                    'content_length' => 0,
                    'last_modified'  => null
                ];
            }
        }

        return $out;
    }

    /**
     * List blob
     *
     * @param  string $container Container name
     * @param  string $name      Blob name
     *
     * @throws DfException
     * @throws \Exception
     * @return array
     */
    public function getBlobProperties($container, $name)
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject($name);

            $file = [
                'name'           => $obj->name,
                'content_type'   => $obj->content_type,
                'content_length' => $obj->bytes,
                'last_modified'  => gmdate('D, d M Y H:i:s \G\M\T', strtotime($obj->last_modified))
            ];

            return $file;
        } catch (\Exception $ex) {
            throw new DfException('Failed to list metadata: ' . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param array  $params
     *
     * @throws DfException
     * @throws \Exception
     */
    public function streamBlob($container, $name, $params = [])
    {
        $this->checkConnection();
        try {
            /** @var Container $container */
            $container = $this->blobConn->Container($container);
            if (empty($container)) {
                throw new \Exception("No container named '$container'");
            }

            $obj = $container->DataObject($name);

            header('Last-Modified: ' . $obj->last_modified);
            header('Content-Type: ' . $obj->content_type);
            header('Content-Length:' . $obj->content_length);

            $disposition =
                (isset($params['disposition']) && !empty($params['disposition'])) ? $params['disposition']
                    : 'inline';

            header('Content-Disposition: ' . $disposition . '; filename="' . $name . '";');
            $index = 0;
            $size = (integer)$obj->content_length;
            $chunk = \Config::get('df.file_chunk_size');
            ob_clean();

            while ($index < $size) {
                $header = ['Range' => 'bytes=' . $index . '-' . ($index + $chunk - 1)];
                /** @var Http $result */
                $result = $container->Service()->Request($obj->Url(), 'GET', $header);
                $info = $result->info();
                $length = array_get($info, 'size_download');
                $index += $length;
                flush();
                echo $result->HttpBody();
            }
        } catch (\Exception $ex) {
            if ($ex instanceof ObjFetchError) {
                $code = HttpStatusCodes::HTTP_NOT_FOUND;
                $status_header = "HTTP/1.1 $code";
                header($status_header);
                header('Content-Type: text/html');
                echo 'Failed to stream/download file. File ' . $name . ' was not found. ' . $ex->getMessage();
            } else {
                throw new DfException('Failed to stream blob: ' . $ex->getMessage());
            }
        }
    }
}