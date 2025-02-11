<?php
declare(strict_types=1);

use Mapi\Api\Library\APIEngine;

/**
 * Handle file upload with proper security checks
 */
function uploadBlob(string $token, array $getParams, array $fileParams): array 
{
    if (!validateUploadParams($token, $getParams, $fileParams)) {
        throw new \InvalidArgumentException("Missing Blob Upload Parameters", 404);
    }

    $fileParams = isset($getParams['key']) ? $fileParams[$getParams['key']] : $fileParams;
    $uploadFilename = generateSecureFilename($fileParams['name']);
    $uploadPath = rtrim(UPLOADS_DIRECTORY, '/') . '/' . $uploadFilename;

    if (file_exists($uploadPath)) {
        return ['ERR' => 'Duplicate upload'];
    }

    if (!moveUploadedFile($fileParams['tmp_name'], $uploadPath)) {
        return ['ERR' => "Failed to upload: {$fileParams['tmp_name']} => {$uploadPath}"];
    }

    return saveFileRecord($token, $getParams, $fileParams, $uploadFilename);
}

/**
 * Convert base64 string to temporary image file
 */
function base64ToImage(string $base64_string): string 
{
    $output_file = sprintf(
        '/tmp/%s',
        md5(microtime() . random_int(100000, 999999))
    );

    $decodedData = base64_decode($base64_string, true);
    if ($decodedData === false) {
        throw new \InvalidArgumentException('Invalid base64 string');
    }

    if (file_put_contents($output_file, $decodedData) === false) {
        throw new \RuntimeException('Failed to save temporary file');
    }

    return $output_file;
}

/**
 * Validate upload parameters
 */
function validateUploadParams(string $token, array $getParams, array $fileParams): bool 
{
    return !empty($getParams['user_id']) && !empty($token) && !empty($fileParams);
}

/**
 * Generate secure filename with timestamp
 */
function generateSecureFilename(string $originalName): string 
{
    return time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
}

/**
 * Move uploaded file securely
 */
function moveUploadedFile(string $tmpPath, string $destPath): bool 
{
    if (@rename($tmpPath, $destPath)) {
        chmod($destPath, 0644);
        return true;
    }
    return false;
}

/**
 * Save file record to database
 */
function saveFileRecord(string $token, array $getParams, array $fileParams, string $uploadFilename): array 
{
    $params = ENABLE_API_FIELD_ENCRYPTION ? 
        [
            'n3963' => $fileParams['name'],
            'm9797' => $fileParams['type'],
            'u9765' => $uploadFilename,
            'u9094' => $getParams['user_id'],
            's3024' => $fileParams['size'],
            's3524' => ACTIVE_STATUS,
            'token' => $token
        ] : 
        [
            'name' => $fileParams['name'],
            'mime_type' => $fileParams['type'],
            'url' => $uploadFilename,
            'user_id' => $getParams['user_id'],
            'size' => $fileParams['size'],
            'status' => ACTIVE_STATUS,
            'token' => $token
        ];

    $response = APIEngine::saveData('blobs', 'save', $params);

    if (ENABLE_API_FIELD_ENCRYPTION) {
        $response['i7084'] = $response['id'];
        $response['u9765'] = CDN_BASE_URL . $uploadFilename;
        unset($response['id']);
    } else {
        $response['url'] = CDN_BASE_URL . $uploadFilename;
    }

    return $response;
}
?>