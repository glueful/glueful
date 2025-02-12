<?php
// Add to your config file
return [
    'storage' => [
        'driver' => env('STORAGE_DRIVER', 'local'), // 'local' or 's3'
        's3' => [
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'), // Optional for MinIO/custom endpoints
        ]
    ]
];