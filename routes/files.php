<?php

use Glueful\Http\Router;
use Glueful\Controllers\FilesController;


$filesController = new FilesController();

// File routes
Router::group('/files', function() use ($filesController) {
    /**
     * @route GET /files/{uuid}
     * @summary Get File
     * @description Retrieves a file by its UUID, with optional image processing capabilities
     * @tag Files
     * @requiresAuth true
     * @param uuid path string true "UUID of the file to retrieve"
     * @param type query string false "Type of response format (file or image)"
     * @param w query integer false "Image width in pixels (1-1500)"
     * @param h query integer false "Image height in pixels (1-1500)"
     * @param q query integer false "Image quality (1-100)"
     * @response 200 application/json "File retrieved successfully" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="File unique identifier",
     *     url:string="File public URL",
     *     name:string="Filename",
     *     mime_type:string="File MIME type",
     *     size:integer="File size in bytes",
     *     type:string[image,file,pdf,word,excel,powerpoint,archive]="File type category",
     *     dimensions:{
     *       width:integer="Image width in pixels",
     *       height:integer="Image height in pixels"
     *     },
     *     cached:boolean="Whether the response is from cache",
     *     created_at:string="Creation timestamp",
     *     updated_at:string="Last update timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "File not found"
     * @response 401 "Unauthorized access"
     */
    Router::get('/{uuid}', function($params) use ($filesController) {
        return $filesController->getFile($params);
    });

    /**
     * @route POST /files
     * @summary Upload File
     * @description Uploads a new file to the system, supporting both multipart form uploads and base64 encoded content
     * @tag Files
     * @requiresAuth true
     * @requestBody file:file="File to upload (when using multipart/form-data)" base64:string="Base64 encoded file content (when using application/json)" name:string="Custom filename (optional)" mime_type:string="MIME type of the file (optional)" {required=file|base64}
     * @response 201 application/json "File uploaded successfully" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="File unique identifier",
     *     url:string="File public URL",
     *     name:string="Filename",
     *     mime_type:string="File MIME type",
     *     size:integer="File size in bytes",
     *     created_at:string="Creation timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 400 "Invalid file data"
     * @response 401 "Unauthorized access"
     * @response 413 "File too large"
     * @response 415 "Unsupported file type"
     */
    Router::post('/', function() use ($filesController) {
        return $filesController->uploadFile();
    });

    /**
     * @route DELETE /files/{uuid}
     * @summary Delete File
     * @description Permanently removes a file from the system
     * @tag Files
     * @requiresAuth true
     * @param uuid path string true "UUID of the file to delete"
     * @response 200 application/json "File deleted successfully" {
     *   success:boolean="Success status",
     *   message:string="File deleted successfully",
     *   code:integer="HTTP status code"
     * }
     * @response 404 "File not found"
     * @response 401 "Unauthorized access"
     * @response 403 "Permission denied"
     */
    Router::delete('/{uuid}', function($params) use ($filesController) {
        return $filesController->deleteFile($params);
    });
}, requiresAuth: true);