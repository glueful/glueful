<?php

namespace Glueful\Uploader\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Glueful\Uploader\UploadException;

class S3Storage implements StorageInterface {
    private S3Client $client;

    public function __construct() {
        $config = [
            'version' => 'latest',
            'region'  => config('storage.s3.region'),
            'credentials' => [
                'key'    => config('storage.s3.key'),
                'secret' => config('storage.s3.secret'),
            ]
        ];

        if ($endpoint = config('storage.s3.endpoint')) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        $this->client = new S3Client($config);
    }

    public function store(string $sourcePath, string $destinationPath): string {
        try {
            $this->client->putObject([
                'Bucket' => config('storage.s3.bucket'),
                'Key'    => $destinationPath,
                'SourceFile' => $sourcePath,
                'ACL'    => 'public-read',
            ]);
            return $destinationPath;
        } catch (AwsException $e) {
            throw new UploadException('S3 upload failed: ' . $e->getMessage());
        }
    }

    public function getUrl(string $path): string {
        return $this->client->getObjectUrl(config('storage.s3.bucket'), $path);
    }

    public function exists(string $path): bool {
        return $this->client->doesObjectExist(config('storage.s3.bucket'), $path);
    }

    public function delete(string $path): bool {
        try {
            $this->client->deleteObject([
                'Bucket' => config('storage.s3.bucket'),
                'Key'    => $path
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string 
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => config('storage.s3.bucket'),
            'Key'    => $path
        ]);

        $request = $this->client->createPresignedRequest($command, "+{$expiry} seconds");
        return (string) $request->getUri();
    }
}
