<?php

return [
    'levels' => [
        'flexible' => 1,
        'moderate' => 2,
        'strict' => 3,
    ],
    'default_level' => env('DEFAULT_SECURITY_LEVEL', 1),
    'permissions_enabled' => env('ENABLE_PERMISSIONS', true),
];
