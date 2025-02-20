<?php

/**
 * Storage Configuration
 * 
 * Defines file storage settings and cloud provider configurations.
 * Supports local filesystem and S3-compatible storage.
 */
return [
    'storage' => [
        // Primary storage driver (local or s3)
        'driver' => env('STORAGE_DRIVER', 'local'),

        // Amazon S3 or compatible service settings
        's3' => [
            'key' => env('S3_ACCESS_KEY_ID'),          // AWS access key
            'secret' => env('S3_SECRET_ACCESS_KEY'),   // AWS secret key
            'region' => env('S3_REGION', 'us-east-1'), // AWS region
            'bucket' => env('S3_BUCKET'),              // S3 bucket name
            'endpoint' => env('S3_ENDPOINT'),          // Custom endpoint for MinIO/others
        ]
    ]
];