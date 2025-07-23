# Glueful Session Analytics System

This comprehensive guide covers Glueful's sophisticated session analytics system, which provides real-time monitoring, comprehensive reporting, security analysis, and performance insights for session management across multiple authentication providers.

## Table of Contents

1. [Overview](#overview)
2. [SessionAnalytics Core Features](#sessionanalytics-core-features)
3. [SessionQueryBuilder Advanced Filtering](#sessionquerybuilder-advanced-filtering)
4. [SessionCacheManager Integration](#sessioncachemanager-integration)
5. [Real-time Metrics](#real-time-metrics)
6. [Historical Analysis](#historical-analysis)
7. [Security Analytics](#security-analytics)
8. [Performance Monitoring](#performance-monitoring)
9. [Geographic & Device Analytics](#geographic--device-analytics)
10. [User Activity Patterns](#user-activity-patterns)
11. [Configuration](#configuration)
12. [Usage Examples](#usage-examples)
13. [Production Optimization](#production-optimization)

## Overview

Glueful's Session Analytics system provides enterprise-grade monitoring and analysis capabilities for session management. The system tracks usage patterns, security metrics, performance indicators, and user behavior across multiple authentication providers.

### Key Features

- **Real-time Session Metrics**: Live monitoring with 1-minute cache optimization
- **Advanced Query Builder**: SQL-like interface for complex session filtering
- **Security Event Tracking**: Geographic anomaly detection and suspicious activity monitoring
- **Performance Analytics**: Session duration analysis, concurrent session monitoring, and cache optimization
- **Multi-Provider Support**: JWT, API Key, OAuth, SAML authentication analytics
- **Geographic Distribution**: IP-based location tracking and device analytics
- **Historical Trend Analysis**: Configurable time-range analysis with trend reporting
- **User Activity Patterns**: Hourly/weekly distribution and engagement analysis

### Architecture

The session analytics system consists of three main components:

1. **SessionAnalytics**: Comprehensive analytics engine with caching and reporting
2. **SessionQueryBuilder**: Advanced filtering and query capabilities
3. **SessionCacheManager**: Session data management with analytics integration

## SessionAnalytics Core Features

### Basic Usage

```php
use Glueful\Auth\SessionAnalytics;

// Get analytics instance
$analytics = new SessionAnalytics();

// Get comprehensive session analytics
$report = $analytics->getSessionAnalytics();

// Apply filters for specific analysis
$filteredReport = $analytics->getSessionAnalytics([
    'provider' => 'jwt',
    'min_activity' => 300  // Active in last 5 minutes
]);
```

### Comprehensive Analytics Report

The `getSessionAnalytics()` method returns a comprehensive report including:

```php
[
    'timestamp' => 1640995200,
    'total_sessions' => 1247,
    'active_sessions' => 892,       // Active in last 5 minutes
    'idle_sessions' => 234,         // 5 minutes to 1 hour
    'expired_sessions' => 121,      // Over 1 hour idle
    
    'by_provider' => [
        'jwt' => [
            'count' => 850,
            'active' => 620,
            'idle' => 180,
            'avg_duration' => 3600.5,
            'total_duration' => 3060425
        ],
        'apikey' => [
            'count' => 287,
            'active' => 200,
            'idle' => 87,
            'avg_duration' => 7200.3,
            'total_duration' => 2066486
        ]
    ],
    
    'by_user_role' => [
        'admin' => [
            'session_count' => 45,
            'unique_user_count' => 12,
            'avg_activity' => 1200.5
        ],
        'user' => [
            'session_count' => 1180,
            'unique_user_count' => 890,
            'avg_activity' => 890.2
        ]
    ],
    
    'by_time_range' => [
        'last_5_minutes' => [
            'new_sessions' => 23,
            'active_sessions' => 892,
            'unique_user_count' => 654
        ],
        'last_15_minutes' => [
            'new_sessions' => 67,
            'active_sessions' => 945,
            'unique_user_count' => 723
        ]
    ],
    
    'geographic_distribution' => [
        'countries' => [
            'United States' => 567,
            'Canada' => 234,
            'United Kingdom' => 189
        ],
        'cities' => [
            'United States/New York' => 234,
            'Canada/Toronto' => 123,
            'United Kingdom/London' => 189
        ],
        'ip_ranges' => [
            '192.168.x.x' => 45,
            '10.0.x.x' => 23
        ]
    ],
    
    'device_types' => [
        'devices' => [
            'desktop' => 823,
            'mobile' => 345,
            'tablet' => 79
        ],
        'browsers' => [
            'chrome' => 567,
            'firefox' => 234,
            'safari' => 189
        ],
        'platforms' => [
            'windows' => 456,
            'macos' => 234,
            'linux' => 123
        ]
    ],
    
    'security_events' => [
        'failed_logins' => ['count' => 12, 'unique_ips' => 8],
        'suspicious_locations' => ['count' => 3, 'locations' => []],
        'concurrent_sessions_violations' => ['count' => 5, 'users' => []],
        'session_hijacking_attempts' => ['count' => 0, 'patterns' => []],
        'unusual_activity_patterns' => ['count' => 2, 'patterns' => []]
    ],
    
    'performance_metrics' => [
        'avg_session_duration' => 2834.5,
        'peak_concurrent_sessions' => 1456,
        'session_creation_rate' => 12.5,
        'cache_hit_ratio' => 0.95,
        'avg_requests_per_session' => 23.4,
        'memory_usage_mb' => 45.2
    ],
    
    'user_activity' => [
        'most_active_users' => [
            'user-123' => [
                'session_count' => 8,
                'total_duration' => 28800,
                'last_seen' => 1640995180,
                'first_seen' => 1640908800
            ]
        ],
        'hourly_distribution' => [0, 5, 12, 23, 45, 67, 89, 234, ...], // 24 hours
        'weekly_distribution' => [234, 567, 445, 678, 789, 456, 234], // 7 days
        'total_unique_users' => 892
    ],
    
    'session_duration' => [
        'avg_duration' => 2834.5,
        'median_duration' => 1800.0,
        'min_duration' => 30,
        'max_duration' => 28800,
        'duration_buckets' => [
            '0-5min' => 234,
            '5-15min' => 345,
            '15-60min' => 456,
            '1-6hrs' => 189,
            '6hrs+' => 23
        ]
    ],
    
    'concurrent_sessions' => [
        'users_with_multiple_sessions' => 45,
        'max_sessions_per_user' => 5,
        'avg_sessions_per_user' => 1.4,
        'concurrency_distribution' => [
            1 => 847,  // 847 users with 1 session
            2 => 34,   // 34 users with 2 sessions
            3 => 8,    // 8 users with 3 sessions
            4 => 2,    // 2 users with 4 sessions
            5 => 1     // 1 user with 5 sessions
        ]
    ],
    
    'analysis_duration_ms' => 234.5
]
```

## SessionQueryBuilder Advanced Filtering

The SessionQueryBuilder provides SQL-like filtering capabilities for complex session analysis:

### Basic Filtering

```php
use Glueful\Auth\SessionCacheManager;

$sessionManager = new SessionCacheManager($cache);

// Create query builder
$query = $sessionManager->sessionQuery();

// Filter by provider
$jwtSessions = $query->whereProvider('jwt')->get();

// Filter by multiple providers
$apiSessions = $query->whereProviderIn(['apikey', 'oauth'])->get();

// Filter by user role
$adminSessions = $query->whereUserRole('admin')->get();

// Filter by user permission
$moderatorSessions = $query->whereUserHasPermission('moderate_content')->get();
```

### Time-based Filtering

```php
// Sessions active in last 5 minutes
$recentSessions = $query->whereLastActivityWithin(300)->get();

// Sessions idle for more than 1 hour
$idleSessions = $query->whereLastActivityOlderThan(3600)->get();

// Sessions created in date range
$rangeSessions = $query->whereCreatedBetween(
    strtotime('2024-01-01'), 
    strtotime('2024-01-31')
)->get();
```

### Advanced Filtering

```php
// Geographic filtering
$localSessions = $query->whereIpAddressLike('192.168.*')->get();

// Device filtering
$mobileSessions = $query->whereUserAgentLike('Mobile')->get();

// Role-based filtering
$privilegedSessions = $query->whereUserHasAnyRole(['admin', 'moderator'])->get();

// Custom filtering with callbacks
$complexSessions = $query->where(function($session) {
    return ($session['request_count'] ?? 0) > 50 && 
           isset($session['user']['permissions']['manage_users']);
})->get();
```

### Query Building and Optimization

```php
// Complex query with sorting and pagination
$sessions = $query
    ->whereProvider('jwt')
    ->whereLastActivityWithin(3600)
    ->whereUserHasAnyRole(['admin', 'user'])
    ->orderBy('last_activity', 'desc')
    ->paginate(1, 50)  // Page 1, 50 per page
    ->get();

// Count matching sessions
$count = $query->whereProvider('jwt')->count();

// Get first matching session
$session = $query->whereUser('user-uuid-123')->first();

// Check if sessions exist
$hasActiveSessions = $query->whereLastActivityWithin(300)->exists();

// Debug query (SQL-like representation)
$sqlRepresentation = $query->toSql();
// Output: "SELECT * FROM sessions WHERE provider = jwt AND last_activity >= 1640991600 ORDER BY last_activity DESC LIMIT 50"
```

### Nested Conditions

```php
// Complex OR conditions
$complexQuery = $query
    ->whereProvider('jwt')
    ->orWhere(function($subQuery) {
        $subQuery->whereProvider('apikey')
                 ->whereUserRole('admin');
    })
    ->get();

// Nested AND conditions
$nestedQuery = $query
    ->whereSessions(function($subQuery) {
        $subQuery->whereLastActivityWithin(300)
                 ->whereUserHasPermission('admin_access');
    })
    ->get();
```

## SessionCacheManager Integration

SessionCacheManager provides session data management with built-in analytics integration:

### Session Lifecycle Analytics

```php
use Glueful\Auth\SessionCacheManager;

$sessionManager = new SessionCacheManager($cache);

// Store session with provider tracking
$success = $sessionManager->storeSession(
    $userData, 
    $token, 
    'jwt',  // Provider for analytics tracking
    3600    // Custom TTL
);

// Provider-based session retrieval
$jwtSessions = $sessionManager->getSessionsByProvider('jwt');
$apiKeySessions = $sessionManager->getSessionsByProvider('apikey');

// User session analytics
$userSessions = $sessionManager->getUserSessions($userUuid);
$sessionCount = $sessionManager->getUserSessionCount($userUuid);

// Bulk operations with analytics
$terminatedCount = $sessionManager->terminateAllUserSessions($userUuid);
```

### Advanced Session Operations

```php
// Bulk session invalidation with criteria
$invalidatedCount = $sessionManager->invalidateSessionsWhere([
    'provider' => 'jwt',
    'last_activity_older_than' => 3600
]);

// Bulk session updates
$updatedCount = $sessionManager->updateSessionsWhere(
    ['provider' => 'oauth'], 
    ['security_level' => 'high']
);

// Provider migration
$migratedCount = $sessionManager->migrateSessions('jwt', 'oauth');

// Transaction-based bulk operations
$transaction = $sessionManager->transaction();
try {
    $sessionIds = $transaction->createSessions($bulkSessionData);
    $transaction->commit();
} catch (Exception $e) {
    $transaction->rollback();
    throw $e;
}
```

## Real-time Metrics

Real-time metrics provide immediate insights with 1-minute caching for optimal performance:

```php
$analytics = new SessionAnalytics();

// Get real-time metrics (cached for 1 minute)
$realTimeMetrics = $analytics->getRealTimeMetrics();

/*
[
    'timestamp' => 1640995200,
    'total_active' => 892,
    'sessions_last_minute' => 12,
    'sessions_last_hour' => 167,
    'unique_users' => 654,
    'avg_session_age' => 2834.5,
    'peak_concurrent' => 1456,
    'providers' => [
        'jwt' => 620,
        'apikey' => 200,
        'oauth' => 50,
        'saml' => 22
    ],
    'cache_hit_ratio' => 0.95
]
*/
```

### Real-time Dashboard Integration

```php
// Dashboard endpoint implementation
function getDashboardMetrics() {
    $analytics = new SessionAnalytics();
    
    return [
        'real_time' => $analytics->getRealTimeMetrics(),
        'hourly_trend' => $analytics->getSessionTrends(6, 30), // Last 6 hours, 30-min intervals
        'security_alerts' => $analytics->getSecurityEvents(1), // Last hour
    ];
}
```

## Historical Analysis

Comprehensive historical trend analysis with configurable time ranges:

```php
$analytics = new SessionAnalytics();

// Get session trends
$trends = $analytics->getSessionTrends(
    24,   // Last 24 hours
    60    // 60-minute intervals
);

/*
[
    [
        'timestamp' => 1640908800,
        'active_sessions' => 567,
        'new_sessions' => 23,
        'terminated_sessions' => 12
    ],
    [
        'timestamp' => 1640912400,
        'active_sessions' => 623,
        'new_sessions' => 45,
        'terminated_sessions' => 8
    ]
    // ... more data points
]
*/

// Weekly trends
$weeklyTrends = $analytics->getSessionTrends(
    168,  // 7 days * 24 hours
    360   // 6-hour intervals
);

// Custom time range analysis
$customAnalysis = $analytics->getSessionAnalytics([
    'time_range' => [
        'from' => strtotime('2024-01-01'),
        'to' => strtotime('2024-01-31')
    ]
]);
```

## Security Analytics

Comprehensive security monitoring and anomaly detection:

```php
$analytics = new SessionAnalytics();

// Get security events for last 24 hours
$securityEvents = $analytics->getSecurityEvents(24);

/*
[
    'failed_logins' => [
        'count' => 45,
        'unique_ips' => 23
    ],
    'suspicious_locations' => [
        'count' => 8,
        'locations' => [
            ['country' => 'Unknown', 'ip' => '192.168.1.100', 'attempts' => 3],
            ['country' => 'Russia', 'ip' => '203.45.67.89', 'attempts' => 5]
        ]
    ],
    'concurrent_sessions_violations' => [
        'count' => 12,
        'users' => ['user-uuid-1', 'user-uuid-2']
    ],
    'session_hijacking_attempts' => [
        'count' => 2,
        'patterns' => [
            'ip_change_pattern',
            'user_agent_mismatch'
        ]
    ],
    'unusual_activity_patterns' => [
        'count' => 5,
        'patterns' => [
            'rapid_location_change',
            'excessive_api_calls',
            'off_hours_activity'
        ]
    ]
]
*/
```

### Complex Security Filtering

```php
// Find suspicious sessions
$suspiciousSessions = $analytics->findSessionsWithCriteria([
    'ip_range' => [
        'start' => '192.168.1.1',
        'end' => '192.168.1.255'
    ],
    'user_agent_pattern' => 'bot',
    'time_range' => [
        'from' => strtotime('-2 hours'),
        'to' => time()
    ],
    'activity_threshold' => [
        'min_requests' => 100
    ],
    'geographic_constraint' => [
        'allowed_countries' => ['US', 'CA', 'GB']
    ]
]);

// Security audit for specific users
$userSecurityProfile = $analytics->findSessionsWithCriteria([
    'permission_combinations' => ['admin_access', 'user_management'],
    'security_level' => 'high',
    'concurrent_limit_exceeded' => true
]);
```

## Performance Monitoring

Detailed performance analytics and optimization insights:

```php
$analytics = new SessionAnalytics();

// Get performance metrics from comprehensive analytics
$fullAnalytics = $analytics->getSessionAnalytics();
$performanceMetrics = $fullAnalytics['performance_metrics'];

/*
[
    'avg_session_duration' => 2834.5,           // Average session length in seconds
    'peak_concurrent_sessions' => 1456,         // Highest concurrent session count
    'session_creation_rate' => 12.5,            // Sessions created per minute
    'cache_hit_ratio' => 0.95,                  // Cache efficiency (95%)
    'avg_requests_per_session' => 23.4,         // Request activity per session
    'memory_usage_mb' => 45.2                   // Current memory usage
]
*/

// Session duration analysis
$durationAnalysis = $fullAnalytics['session_duration'];

/*
[
    'avg_duration' => 2834.5,
    'median_duration' => 1800.0,
    'min_duration' => 30,
    'max_duration' => 28800,
    'duration_buckets' => [
        '0-5min' => 234,     // Short sessions
        '5-15min' => 345,    // Quick interactions
        '15-60min' => 456,   // Normal sessions
        '1-6hrs' => 189,     // Long sessions
        '6hrs+' => 23        // Extended sessions
    ]
]
*/
```

### Performance Optimization Insights

```php
// Identify performance bottlenecks
function analyzePerformanceBottlenecks() {
    $analytics = new SessionAnalytics();
    $metrics = $analytics->getSessionAnalytics();
    
    $insights = [];
    
    // Cache efficiency analysis
    if ($metrics['performance_metrics']['cache_hit_ratio'] < 0.85) {
        $insights[] = 'Cache hit ratio is low. Consider increasing cache TTL or optimizing cache keys.';
    }
    
    // Session duration analysis
    $longSessions = $metrics['session_duration']['duration_buckets']['6hrs+'];
    if ($longSessions > 50) {
        $insights[] = "High number of extended sessions ({$longSessions}). Check for session timeout configuration.";
    }
    
    // Concurrent session analysis
    $avgConcurrent = $metrics['concurrent_sessions']['avg_sessions_per_user'];
    if ($avgConcurrent > 2.0) {
        $insights[] = "High average concurrent sessions per user ({$avgConcurrent}). Consider implementing session limits.";
    }
    
    return $insights;
}
```

## Geographic & Device Analytics

Detailed geographic distribution and device analytics:

```php
$analytics = new SessionAnalytics();
$fullAnalytics = $analytics->getSessionAnalytics();

// Geographic distribution analysis
$geoData = $fullAnalytics['geographic_distribution'];

/*
[
    'countries' => [
        'United States' => 567,
        'Canada' => 234,
        'United Kingdom' => 189,
        'Germany' => 123,
        'France' => 89
    ],
    'cities' => [
        'United States/New York' => 234,
        'Canada/Toronto' => 123,
        'United Kingdom/London' => 189,
        'Germany/Berlin' => 67,
        'France/Paris' => 45
    ],
    'ip_ranges' => [
        '192.168.x.x' => 45,   // Internal network
        '10.0.x.x' => 23,      // VPN range
        '172.16.x.x' => 12     // Private network
    ],
    'total_countries' => 23,
    'total_cities' => 156
]
*/

// Device and browser analytics
$deviceData = $fullAnalytics['device_types'];

/*
[
    'devices' => [
        'desktop' => 823,
        'mobile' => 345,
        'tablet' => 79
    ],
    'browsers' => [
        'chrome' => 567,
        'firefox' => 234,
        'safari' => 189,
        'edge' => 123,
        'opera' => 45
    ],
    'platforms' => [
        'windows' => 456,
        'macos' => 234,
        'linux' => 123,
        'ios' => 89,
        'android' => 67
    ]
]
*/
```

### Geographic Security Analysis

```php
// Detect geographic anomalies
function detectGeographicAnomalies() {
    $analytics = new SessionAnalytics();
    
    // Find sessions from unusual locations
    $suspiciousLocations = $analytics->findSessionsWithCriteria([
        'geographic_constraint' => [
            'excluded_countries' => ['CN', 'RU', 'KP'], // High-risk countries
            'time_range' => [
                'from' => strtotime('-24 hours'),
                'to' => time()
            ]
        ]
    ]);
    
    // Analyze rapid location changes for same user
    $rapidLocationChanges = $analytics->findSessionsWithCriteria([
        'user_agent_pattern' => '',  // Any user agent
        'ip_range' => [
            'rapid_change_detection' => true,
            'time_window' => 3600  // Within 1 hour
        ]
    ]);
    
    return [
        'suspicious_locations' => count($suspiciousLocations),
        'rapid_changes' => count($rapidLocationChanges)
    ];
}
```

## User Activity Patterns

Comprehensive user behavior and activity pattern analysis:

```php
$analytics = new SessionAnalytics();
$fullAnalytics = $analytics->getSessionAnalytics();

// User activity analysis
$userActivity = $fullAnalytics['user_activity'];

/*
[
    'most_active_users' => [
        'user-uuid-123' => [
            'session_count' => 8,
            'total_duration' => 28800,    // 8 hours total
            'last_seen' => 1640995180,
            'first_seen' => 1640908800
        ],
        'user-uuid-456' => [
            'session_count' => 6,
            'total_duration' => 21600,    // 6 hours total
            'last_seen' => 1640994900,
            'first_seen' => 1640908800
        ]
    ],
    'hourly_distribution' => [
        0 => 12,    // 12 AM - 1 AM
        1 => 8,     // 1 AM - 2 AM
        2 => 5,     // 2 AM - 3 AM
        // ... 24 hours
        9 => 234,   // 9 AM - 10 AM (peak)
        14 => 189,  // 2 PM - 3 PM
        22 => 45    // 10 PM - 11 PM
    ],
    'weekly_distribution' => [
        0 => 234,   // Sunday
        1 => 567,   // Monday (peak)
        2 => 445,   // Tuesday
        3 => 678,   // Wednesday
        4 => 789,   // Thursday
        5 => 456,   // Friday
        6 => 234    // Saturday
    ],
    'total_unique_users' => 892
]
*/
```

### Activity Pattern Insights

```php
// Generate activity insights
function generateActivityInsights($userActivity) {
    $insights = [];
    
    // Peak hours analysis
    $hourlyDistribution = $userActivity['hourly_distribution'];
    $peakHour = array_keys($hourlyDistribution, max($hourlyDistribution))[0];
    $insights['peak_hour'] = "Peak activity at {$peakHour}:00 with {$hourlyDistribution[$peakHour]} sessions";
    
    // Weekly pattern analysis
    $weeklyDistribution = $userActivity['weekly_distribution'];
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $peakDay = array_keys($weeklyDistribution, max($weeklyDistribution))[0];
    $insights['peak_day'] = "Peak day: {$dayNames[$peakDay]} with {$weeklyDistribution[$peakDay]} sessions";
    
    // Most active users analysis
    $topUsers = array_slice($userActivity['most_active_users'], 0, 5, true);
    $insights['top_users'] = count($topUsers) . " highly active users identified";
    
    return $insights;
}
```

## Configuration

### Environment Variables

```env
# Session Analytics Configuration
SESSION_ANALYTICS_ENABLED=true
SESSION_ANALYTICS_CACHE_TTL=3600
SESSION_ANALYTICS_REAL_TIME_TTL=60

# Performance Monitoring
SESSION_PERFORMANCE_TRACKING=true
SESSION_DURATION_BUCKETS=300,900,3600,21600

# Security Monitoring
SESSION_SECURITY_MONITORING=true
SESSION_GEO_TRACKING=true
SESSION_DEVICE_TRACKING=true

# Cache Configuration
SESSION_CACHE_PREFIX=session:
SESSION_PROVIDER_INDEX_PREFIX=provider:
SESSION_PERMISSION_CACHE_TTL=1800
```

### Analytics Configuration

```php
// config/session_analytics.php
return [
    'enabled' => env('SESSION_ANALYTICS_ENABLED', true),
    
    'cache' => [
        'ttl' => env('SESSION_ANALYTICS_CACHE_TTL', 3600),
        'real_time_ttl' => env('SESSION_ANALYTICS_REAL_TIME_TTL', 60),
        'prefix' => env('SESSION_CACHE_PREFIX', 'session_analytics:')
    ],
    
    'performance' => [
        'tracking_enabled' => env('SESSION_PERFORMANCE_TRACKING', true),
        'duration_buckets' => [300, 900, 3600, 21600], // 5min, 15min, 1hr, 6hr
        'memory_monitoring' => true
    ],
    
    'security' => [
        'monitoring_enabled' => env('SESSION_SECURITY_MONITORING', true),
        'geo_tracking' => env('SESSION_GEO_TRACKING', true),
        'device_tracking' => env('SESSION_DEVICE_TRACKING', true),
        'failed_login_threshold' => 10,
        'suspicious_location_detection' => true
    ],
    
    'providers' => [
        'jwt' => [
            'analytics_enabled' => true,
            'session_ttl' => 3600
        ],
        'apikey' => [
            'analytics_enabled' => true,
            'session_ttl' => 7200
        ],
        'oauth' => [
            'analytics_enabled' => true,
            'session_ttl' => 1800
        ],
        'saml' => [
            'analytics_enabled' => true,
            'session_ttl' => 28800
        ]
    ],
    
    'reporting' => [
        'default_time_range' => 24, // hours
        'max_results' => 1000,
        'batch_size' => 100
    ]
];
```

## Usage Examples

### Dashboard Implementation

```php
class SessionDashboardController
{
    private SessionAnalytics $analytics;
    private SessionCacheManager $sessionManager;
    
    public function __construct(SessionAnalytics $analytics, SessionCacheManager $sessionManager)
    {
        $this->analytics = $analytics;
        $this->sessionManager = $sessionManager;
    }
    
    public function getDashboardData(): array
    {
        return [
            'overview' => $this->getOverviewMetrics(),
            'real_time' => $this->analytics->getRealTimeMetrics(),
            'trends' => $this->analytics->getSessionTrends(24, 60),
            'security' => $this->analytics->getSecurityEvents(24),
            'performance' => $this->getPerformanceInsights(),
            'geographic' => $this->getGeographicSummary()
        ];
    }
    
    private function getOverviewMetrics(): array
    {
        $analytics = $this->analytics->getSessionAnalytics();
        
        return [
            'total_sessions' => $analytics['total_sessions'],
            'active_sessions' => $analytics['active_sessions'],
            'unique_users' => $analytics['user_activity']['total_unique_users'],
            'avg_duration' => $analytics['session_duration']['avg_duration'],
            'top_provider' => $this->getTopProvider($analytics['by_provider'])
        ];
    }
    
    private function getTopProvider(array $providers): string
    {
        return array_keys($providers, max(array_column($providers, 'count')))[0];
    }
}
```

### Security Monitoring

```php
class SessionSecurityMonitor
{
    private SessionAnalytics $analytics;
    
    public function __construct(SessionAnalytics $analytics)
    {
        $this->analytics = $analytics;
    }
    
    public function runSecurityCheck(): array
    {
        $alerts = [];
        
        // Check for failed login attempts
        $securityEvents = $this->analytics->getSecurityEvents(1); // Last hour
        if ($securityEvents['failed_logins']['count'] > 50) {
            $alerts[] = [
                'type' => 'high_failed_logins',
                'severity' => 'high',
                'count' => $securityEvents['failed_logins']['count'],
                'unique_ips' => $securityEvents['failed_logins']['unique_ips']
            ];
        }
        
        // Check for suspicious locations
        if ($securityEvents['suspicious_locations']['count'] > 0) {
            $alerts[] = [
                'type' => 'suspicious_locations',
                'severity' => 'medium',
                'count' => $securityEvents['suspicious_locations']['count'],
                'locations' => $securityEvents['suspicious_locations']['locations']
            ];
        }
        
        // Check for concurrent session violations
        if ($securityEvents['concurrent_sessions_violations']['count'] > 10) {
            $alerts[] = [
                'type' => 'concurrent_violations',
                'severity' => 'medium',
                'count' => $securityEvents['concurrent_sessions_violations']['count']
            ];
        }
        
        return $alerts;
    }
    
    public function findCompromisedSessions(): array
    {
        return $this->analytics->findSessionsWithCriteria([
            'ip_range' => [
                'start' => '0.0.0.0',
                'end' => '255.255.255.255'
            ],
            'user_agent_pattern' => 'bot',
            'activity_threshold' => [
                'min_requests' => 200
            ],
            'time_range' => [
                'from' => strtotime('-2 hours'),
                'to' => time()
            ]
        ]);
    }
}
```

### Performance Optimization

```php
class SessionPerformanceOptimizer
{
    private SessionAnalytics $analytics;
    private SessionCacheManager $sessionManager;
    
    public function __construct(SessionAnalytics $analytics, SessionCacheManager $sessionManager)
    {
        $this->analytics = $analytics;
        $this->sessionManager = $sessionManager;
    }
    
    public function optimizePerformance(): array
    {
        $optimizations = [];
        
        // Clean up idle sessions
        $idleSessionsCleared = $this->cleanupIdleSessions();
        if ($idleSessionsCleared > 0) {
            $optimizations[] = "Cleared {$idleSessionsCleared} idle sessions";
        }
        
        // Optimize cache usage
        $cacheOptimized = $this->optimizeCacheUsage();
        if ($cacheOptimized) {
            $optimizations[] = "Cache usage optimized";
        }
        
        // Balance provider load
        $loadBalanced = $this->balanceProviderLoad();
        if ($loadBalanced) {
            $optimizations[] = "Provider load balanced";
        }
        
        return $optimizations;
    }
    
    private function cleanupIdleSessions(): int
    {
        // Find sessions idle for more than 2 hours
        $idleSessions = $this->sessionManager->sessionQuery()
            ->whereLastActivityOlderThan(7200)
            ->get();
        
        $cleaned = 0;
        foreach ($idleSessions as $session) {
            if (isset($session['token'])) {
                $this->sessionManager->destroySession($session['token']);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    private function optimizeCacheUsage(): bool
    {
        $metrics = $this->analytics->getRealTimeMetrics();
        
        // If cache hit ratio is low, increase TTL for stable sessions
        if ($metrics['cache_hit_ratio'] < 0.85) {
            // This would involve updating session TTLs
            return true;
        }
        
        return false;
    }
    
    private function balanceProviderLoad(): bool
    {
        $analytics = $this->analytics->getSessionAnalytics();
        $providers = $analytics['by_provider'];
        
        // Find overloaded providers
        $totalSessions = array_sum(array_column($providers, 'count'));
        $avgPerProvider = $totalSessions / count($providers);
        
        foreach ($providers as $provider => $data) {
            if ($data['count'] > $avgPerProvider * 1.5) {
                // Provider is overloaded - could implement load balancing logic
                return true;
            }
        }
        
        return false;
    }
}
```

## Production Optimization

### High-Volume Environments

```php
// Optimized analytics for high-volume production
class ProductionSessionAnalytics extends SessionAnalytics
{
    public function getOptimizedAnalytics(array $filters = []): array
    {
        // Use sampling for very large datasets
        $sampleSize = 10000; // Analyze sample of 10k sessions
        
        // Get cached results first
        $cacheKey = 'analytics:optimized:' . md5(json_encode($filters));
        $cached = $this->cache->get($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }
        
        // Get sampled analytics
        $analytics = $this->getSampledAnalytics($sampleSize, $filters);
        
        // Cache for 5 minutes in production
        $this->cache->set($cacheKey, $analytics, 300);
        
        return $analytics;
    }
    
    private function getSampledAnalytics(int $sampleSize, array $filters): array
    {
        // Implementation would sample sessions for large-scale analytics
        return $this->getSessionAnalytics($filters);
    }
}
```

### Memory Optimization

```php
// Memory-efficient session processing
class MemoryOptimizedAnalytics
{
    public function processLargeDataset(): array
    {
        $analytics = new SessionAnalytics();
        
        // Process in chunks to avoid memory issues
        $chunkSize = 1000;
        $offset = 0;
        $results = [];
        
        do {
            $chunk = $analytics->getSessionAnalytics([
                'limit' => $chunkSize,
                'offset' => $offset
            ]);
            
            // Process chunk
            $results = $this->mergeAnalyticsResults($results, $chunk);
            
            $offset += $chunkSize;
            
            // Force garbage collection
            gc_collect_cycles();
            
        } while (count($chunk) === $chunkSize);
        
        return $results;
    }
    
    private function mergeAnalyticsResults(array $existing, array $new): array
    {
        // Merge logic for combining chunked results
        return array_merge_recursive($existing, $new);
    }
}
```

## Summary

Glueful's Session Analytics system provides enterprise-grade monitoring and analysis capabilities:

- **Real-time Monitoring**: Live session metrics with optimized caching
- **Advanced Filtering**: SQL-like query builder for complex session analysis
- **Security Analytics**: Geographic anomaly detection and suspicious activity monitoring
- **Performance Insights**: Session duration analysis and optimization recommendations
- **User Behavior Analysis**: Activity patterns and engagement metrics
- **Multi-Provider Support**: Analytics across JWT, API Key, OAuth, and SAML authentication

The system is designed for production environments with high-volume optimization, memory management, and comprehensive caching strategies to ensure optimal performance while providing detailed insights into session usage patterns, security events, and system performance.