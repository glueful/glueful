<?php

/**
 * Test extensions configuration
 */

return [
    // List of enabled extensions
    'enabled' => [
        'TestExtension',
        'AnotherTestExtension'
    ],

    // Per-extension configuration
    'config' => [
        'TestExtension' => [
            'test_setting' => true,
            'custom_option' => 'value'
        ],
        'AnotherTestExtension' => [
            'enabled_feature' => false,
            'api_key' => 'test_key_12345'
        ]
    ],

    // Extension load order
    'load_order' => [
        'TestExtension',
        'AnotherTestExtension'
    ]
];
