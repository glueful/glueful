<?php
return [
    'base' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/'),
    'api_base_directory' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'api/',
    'api_base_url' => env('API_BASE_URL', 'http://localhost/mapi/api/'),
    'cdn' => env('CDN_BASE_URL', 'http://localhost/cdn/'),
    'domain' => env('WEBSITE_DOMAIN', 'http://localhost/mapi/'),
    'uploads' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'cdn/',
    'logs' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'logs/',
    'cache' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'fscache/',
    'json_definitions' => env('API_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'api-json-definitions/',
    'api_library' => env('API_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'api-library/',
    'intelli_cache' => env('API_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'intelli-cache/',
    'api_extensions' => env('API_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'api-extensions/',
    'project_extensions' => env('WEBSITE_BASE_DIRECTORY', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'extensions/',
    'api_docs' => env('API_DOCS_PATH', '/Users/michaeltawiahsowah/Sites/localhost/mapi/') . 'docs/',

];
