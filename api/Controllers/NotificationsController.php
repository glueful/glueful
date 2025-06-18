<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Constants\ErrorCodes;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Notifications Controller
 *
 * Handles notification operations such as retrieval, marking as read/unread,
 * and preference management.
 *
 * @package Glueful\Controllers
 */
class NotificationsController extends BaseController
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * Constructor
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?SymfonyRequest $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        // Get the notification repository directly
        $notificationRepo = new \Glueful\Repository\NotificationRepository();

        $this->notificationService = new NotificationService(
            new NotificationDispatcher(
                new ChannelManager()
            ),
            $notificationRepo
        );
    }

    /**
     * Create a Notifiable instance from a user UUID
     *
     * @param string $userUuid User UUID
     * @return object Anonymous class implementing Notifiable interface
     */
    private function createUserNotifiable(string $userUuid): object
    {
        return new class ($userUuid) implements \Glueful\Notifications\Contracts\Notifiable {
            private $uuid;

            public function __construct(string $uuid)
            {
                $this->uuid = $uuid;
            }

            public function routeNotificationFor(string $channel)
            {
                return null;
            }

            public function getNotifiableId(): string
            {
                return $this->uuid;
            }

            public function getNotifiableType(): string
            {
                return 'user';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return true;
            }

            public function getNotificationPreferences(): array
            {
                return [];
            }
        };
    }

    /**
     * Get all notifications for the authenticated user
     *
     * @return mixed HTTP response
     */
    public function getNotifications()
    {
        $this->requirePermission('notifications.read', 'notifications');
        $this->rateLimitMethod();

        $request = new Request();
        $queryParams = $request->getQueryParams();

        // Extract parameters with defaults
        $page = (int)($queryParams['page'] ?? 1);
        $perPage = (int)($queryParams['per_page'] ?? 20);
        $onlyUnread = isset($queryParams['unread']) && $queryParams['unread'] === 'true';

        // Build filters for type, date range and priority
        $filters = [
            'created_at' => [] // Initialize created_at filter as an empty array
        ];

        // Filter by type
        if (isset($queryParams['type']) && !empty($queryParams['type'])) {
            $filters['type'] = $queryParams['type'];
        }

        // Filter by priority
        if (isset($queryParams['priority']) && !empty($queryParams['priority'])) {
            $priorities = explode(',', $queryParams['priority']);
            if (count($priorities) > 1) {
                $filters['priority'] = ['in' => $priorities];
            } else {
                $filters['priority'] = $queryParams['priority'];
            }
        }

        // Filter by date range for created_at
        if (isset($queryParams['date_from']) && !empty($queryParams['date_from'])) {
            $filters['created_at']['gte'] = $queryParams['date_from'];
        }

        if (isset($queryParams['date_to']) && !empty($queryParams['date_to'])) {
            $filters['created_at']['lte'] = $queryParams['date_to'];
        }

        // Create notifiable from user
        $user = $this->getCurrentUser();
        $userNotifiable = $this->createUserNotifiable($user->uuid);

        // Cache notifications list
        $cacheKey = sprintf(
            'notifications:user:%s:page:%d:filters:%s',
            $user->uuid,
            $page,
            md5(serialize($filters))
        );

        $notifications = $this->cacheResponse(
            $cacheKey,
            function () use ($userNotifiable, $onlyUnread, $perPage, $page, $filters) {
                return $this->notificationService->getNotifications(
                    $userNotifiable,
                    $onlyUnread,
                    $perPage,
                    ($page - 1) * $perPage,
                    $filters
                );
            },
            300,
            ['user:' . $user->uuid, 'notifications']
        );

        // Cache total count
        $countCacheKey = sprintf(
            'notifications:count:user:%s:filters:%s',
            $user->uuid,
            md5(serialize($filters))
        );

        $totalCount = $this->cacheResponse($countCacheKey, function () use ($userNotifiable, $onlyUnread, $filters) {
            return $this->notificationService->countNotifications(
                $userNotifiable,
                $onlyUnread,
                $filters
            );
        }, 300, ['user:' . $user->uuid, 'notifications']);

        return Response::ok([
            'data' => $notifications,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'last_page' => ceil($totalCount / $perPage)
            ],
            'filters' => [
                'applied' => !empty($filters['type'])
                    || !empty($filters['priority'])
                    || !empty($filters['created_at']),
                'parameters' => $filters
            ]
        ], 'Notifications retrieved successfully')->send();
    }

    /**
     * Get a single notification by ID
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function getNotification(array $params)
    {
        $this->requirePermission('notifications.read_own', 'notifications');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Use UUID-based lookup instead of ID-based
        $notification = $this->notificationService->getNotificationByUuid($params['id']);

        // Check if notification exists
        if (!$notification) {
            return Response::error('Notification not found', ErrorCodes::NOT_FOUND)->send();
        }

        // Check if notification belongs to the user
        if ($notification->getNotifiableId() !== $user->uuid) {
            return Response::error('Forbidden', ErrorCodes::FORBIDDEN)->send();
        }

        return Response::ok($notification, 'Notification retrieved successfully')->send();
    }

    /**
     * Mark notification as read
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function markAsRead(array $params)
    {
        $this->requirePermission('notifications.update', 'notifications');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Get notification using UUID-based lookup
        $notification = $this->notificationService->getNotificationByUuid($params['id']);

        // Check if notification exists
        if (!$notification) {
            return Response::error('Notification not found', ErrorCodes::NOT_FOUND)->send();
        }

        // Check if notification belongs to the user
        if ($notification->getNotifiableId() !== $user->uuid) {
            return Response::error('Forbidden', ErrorCodes::FORBIDDEN)->send();
        }

        // Mark as read
        $notification = $this->notificationService->markAsRead($notification);

        // Invalidate user's notifications cache
        $this->invalidateCache(['user:' . $user->uuid, 'notifications']);

        // Audit log
        $this->auditLogger->audit(
            'notifications',
            'notification_marked_read',
            \Glueful\Logging\AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $user->uuid,
                'notification_uuid' => $notification->getUuid()
            ]
        );

        return Response::ok($notification, 'Notification marked as read')->send();
    }

    /**
     * Mark notification as unread
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function markAsUnread(array $params)
    {
        $this->requirePermission('notifications.update', 'notifications');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Get notification using UUID-based lookup
        $notification = $this->notificationService->getNotificationByUuid($params['id']);

        // Check if notification exists
        if (!$notification) {
            return Response::error('Notification not found', ErrorCodes::NOT_FOUND)->send();
        }

        // Check if notification belongs to the user
        if ($notification->getNotifiableId() !== $user->uuid) {
            return Response::error('Forbidden', ErrorCodes::FORBIDDEN)->send();
        }

        // Mark as unread
        $notification = $this->notificationService->markAsUnread($notification);

        // Invalidate user's notifications cache
        $this->invalidateCache(['user:' . $user->uuid, 'notifications']);

        return Response::ok($notification, 'Notification marked as unread')->send();
    }

    /**
     * Mark all notifications as read
     *
     * @return mixed HTTP response
     */
    public function markAllAsRead()
    {
        $this->requirePermission('notifications.update', 'notifications');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Create notifiable from user
        $userNotifiable = $this->createUserNotifiable($user->uuid);

        // Mark all as read
        $this->notificationService->markAllAsRead($userNotifiable);

        // Invalidate user's notifications cache
        $this->invalidateCache(['user:' . $user->uuid, 'notifications']);

        // Audit log
        $this->auditLogger->audit(
            'notifications',
            'all_notifications_marked_read',
            \Glueful\Logging\AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $user->uuid
            ]
        );

        return Response::ok(null, 'All notifications marked as read')->send();
    }

    /**
     * Get notification preferences
     *
     * @return mixed HTTP response
     */
    public function getPreferences()
    {
        $this->requirePermission('notifications.preferences.read', 'notification_preferences');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Create notifiable from user
        $userNotifiable = $this->createUserNotifiable($user->uuid);

        // Cache preferences
        $cacheKey = sprintf('preferences:user:%s', $user->uuid);
        $preferences = $this->cacheResponse($cacheKey, function () use ($userNotifiable) {
            return $this->notificationService->getPreferences($userNotifiable);
        }, 1800, ['user:' . $user->uuid, 'preferences']);

        $message = 'Notification preferences retrieved successfully';
        return Response::ok(['preferences' => $preferences], $message)->send();
    }

    /**
     * Update notification preferences
     *
     * @return mixed HTTP response
     */
    public function updatePreferences()
    {
        $this->requirePermission('notifications.preferences.update', 'notification_preferences');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        $data = Request::getPostData();

        if (!isset($data['notification_type']) || !isset($data['channels'])) {
            $errorMsg = 'Notification type and channels are required';
            return Response::error($errorMsg, ErrorCodes::BAD_REQUEST)->send();
        }

        // Create notifiable from user
        $userNotifiable = $this->createUserNotifiable($user->uuid);

        // Use setPreference instead of savePreference
        $preference = $this->notificationService->setPreference(
            $userNotifiable,
            $data['notification_type'],
            $data['channels'],
            $data['enabled'] ?? true,
            $data['settings'] ?? null
        );

        // Invalidate preferences cache
        $this->invalidateCache(['user:' . $user->uuid, 'preferences']);

        return Response::ok($preference, 'Notification preferences updated successfully')->send();
    }

    /**
     * Delete a notification
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function deleteNotification(array $params)
    {
        $this->requirePermission('notifications.delete', 'notifications');
        $this->rateLimitMethod();

        $user = $this->getCurrentUser();
        // Get notification using UUID-based lookup
        $notification = $this->notificationService->getNotificationByUuid($params['id']);

        // Check if notification exists
        if (!$notification) {
            return Response::error('Notification not found', ErrorCodes::NOT_FOUND)->send();
        }

        // Check if notification belongs to the user
        if ($notification->getNotifiableId() !== $user->uuid) {
            return Response::error('Forbidden', ErrorCodes::FORBIDDEN)->send();
        }

        // Delete notification using UUID-based method
        $success = $this->notificationService->getRepository()->deleteNotificationByUuid($notification->getUuid());

        if (!$success) {
            return Response::error('Failed to delete notification', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }

        // Invalidate user's notifications cache
        $this->invalidateCache(['user:' . $user->uuid, 'notifications']);

        // Audit log
        $this->auditLogger->audit(
            'notifications',
            'notification_deleted',
            \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
            [
                'user_uuid' => $user->uuid,
                'notification_uuid' => $notification->getUuid()
            ]
        );

        return Response::ok(null, 'Notification deleted successfully')->send();
    }

    /**
     * Get notification performance metrics
     *
     * @return mixed HTTP response
     */
    public function getNotificationMetrics()
    {
        $this->requirePermission('notifications.metrics.read', 'notification_metrics');
        $this->rateLimitMethod('getNotificationMetrics', ['attempts' => 10, 'window' => 60]);

        // Cache metrics
        $metrics = $this->cacheResponse('notification_metrics', function () {
            return $this->notificationService->getPerformanceMetrics();
        }, 60, ['metrics', 'notifications']);

        return Response::ok([
            'metrics' => $metrics,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ], 'Notification performance metrics retrieved successfully')->send();
    }

    /**
     * Get metrics for a specific notification channel
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function getChannelMetrics(array $params)
    {
        $this->requirePermission('notifications.metrics.read', 'notification_metrics');
        $this->rateLimitMethod('getChannelMetrics', ['attempts' => 10, 'window' => 60]);

        if (!isset($params['channel'])) {
            return Response::error('Channel parameter is required', ErrorCodes::BAD_REQUEST)->send();
        }

        $channelName = $params['channel'];

        // Check if the channel exists
        $availableChannels = $this->notificationService->getDispatcher()
            ->getChannelManager()
            ->getAvailableChannels();
        if (!in_array($channelName, $availableChannels)) {
            $errorMsg = "Channel '{$channelName}' not found or not available";
            return Response::error(
                $errorMsg,
                ErrorCodes::NOT_FOUND
            )->send();
        }

        // Cache channel metrics
        $cacheKey = sprintf('channel_metrics:%s', $channelName);
        $metrics = $this->cacheResponse($cacheKey, function () use ($channelName) {
            return $this->notificationService->getChannelMetrics($channelName);
        }, 60, ['metrics', 'channel:' . $channelName]);

        return Response::ok([
            'metrics' => $metrics,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ], "Metrics for channel '{$channelName}' retrieved successfully")->send();
    }

    /**
     * Reset metrics for a specific notification channel
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function resetChannelMetrics(array $params)
    {
        $this->requirePermission('notifications.metrics.reset', 'notification_metrics');
        $this->rateLimitMethod('resetChannelMetrics', ['attempts' => 5, 'window' => 60]);

        if (!isset($params['channel'])) {
            return Response::error('Channel parameter is required', ErrorCodes::BAD_REQUEST)->send();
        }

        $channelName = $params['channel'];

        // Check if the channel exists
        $availableChannels = $this->notificationService->getDispatcher()
            ->getChannelManager()
            ->getAvailableChannels();
        if (!in_array($channelName, $availableChannels)) {
            $errorMsg = "Channel '{$channelName}' not found or not available";
            return Response::error(
                $errorMsg,
                ErrorCodes::NOT_FOUND
            )->send();
        }

        // Reset metrics for the specific channel
        $success = $this->notificationService->resetChannelMetrics($channelName);

        if (!$success) {
            return Response::error(
                "Failed to reset metrics for channel '{$channelName}'",
                ErrorCodes::INTERNAL_SERVER_ERROR
            )->send();
        }

        // Invalidate channel metrics cache
        $this->invalidateCache(['metrics', 'channel:' . $channelName]);

        // Audit log
        $user = $this->getCurrentUser();
        $this->auditLogger->audit(
            'admin',
            'channel_metrics_reset',
            \Glueful\Logging\AuditEvent::SEVERITY_INFO,
            [
                'admin_uuid' => $user->uuid,
                'channel' => $channelName
            ]
        );

        return Response::ok(
            null,
            "Metrics for channel '{$channelName}' reset successfully"
        )->send();
    }
}
