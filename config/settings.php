<?php
/**
 * Application Settings Configuration
 * 
 * This file returns application-wide settings as a JSON response for use in the front-end.
 * It uses environment variables with fallback default values.
 * 
 * Example usage in front-end JavaScript:
 * ```javascript
 * // Fetch application settings
 * fetch('/config/settings.php')
 *   .then(response => response.json())
 *   .then(settings => {
 *     // Now you can use settings.base, settings.cdn, etc.
 *     console.log('API Base URL:', settings.base);
 *     console.log('CDN URL:', settings.cdn);
 *     
 *     // Example: constructing an API endpoint URL
 *     const apiUrl = `${settings.base}users/profile`;
 *     
 *     // Example: loading an asset from CDN
 *     const imageUrl = `${settings.cdn}images/logo.png`;
 *   })
 *   .catch(error => console.error('Failed to load settings:', error));
 * ```
 */

header('Content-Type: application/json');

// Return JSON encoded configuration array
echo json_encode([
    'base' => env('API_BASE_URL', 'http://localhost/glueful/api/'),  // Base URL for API endpoints
    'docs' => env('WEBSITE_DOMAIN', 'http://localhost/glueful/') . '/docs/',  // URL for API documentation
    'cdn' => env('CDN_BASE_URL', 'http://localhost/cdn/'),  // Content Delivery Network URL for static assets
    'domain' => env('WEBSITE_DOMAIN', 'http://localhost/glueful/'),  // Main website domain URL
]);
exit;