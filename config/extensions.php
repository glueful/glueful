<?php
return [
    // Core extensions that are essential for framework functionality
    'core' => [
        'EmailNotification',
        // Add other core extensions here
    ],
    
    // Optional extensions that can be enabled/disabled as needed
    'optional' => [
        'SocialLogin',
        // Add other optional extensions here
    ],
    
    // All enabled extensions (both core and optional)
    'enabled' => [
        'SocialLogin',
        'EmailNotification', // Ensure core extensions are included here
    ],
    
    'paths' => [
        'extensions' => '/Users/michaeltawiahsowah/Sites/localhost/glueful/extensions/',
    ],
];
