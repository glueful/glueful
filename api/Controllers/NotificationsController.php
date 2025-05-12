<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Helpers\Utils;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Repository\NotificationRepository;

/**
 * Notifications Controller
 *
 * Handles notification operations such as retrieval, marking as read/unread,
 * and preference management.
 *
 * @package Glueful\Controllers
 */
class NotificationsController
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->notificationService = new NotificationService(
            new NotificationDispatcher(
                new ChannelManager()
            ),
            new NotificationRepository()
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
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

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
            $userNotifiable = $this->createUserNotifiable($userData['uuid']);

            // Retrieve notifications with pagination and filters
            $notifications = $this->notificationService->getNotifications(
                $userNotifiable,
                $onlyUnread,
                $perPage,
                ($page - 1) * $perPage,
                $filters
            );

            // Get total count for pagination using the service layer method, including filters
            $totalCount = $this->notificationService->countNotifications(
                $userNotifiable,
                $onlyUnread,
                $filters
            );

            return Response::ok([
                'data' => $notifications,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => ceil($totalCount / $perPage)
                ],
                'filters' => [
                    'applied' => !empty($filters),
                    'parameters' => $filters
                ]
            ], 'Notifications retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve notifications: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get a single notification by ID
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function getNotification(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Use UUID-based lookup instead of ID-based
            $notification = $this->notificationService->getNotificationByUuid($params['id']);

            // Check if notification exists
            if (!$notification) {
                return Response::error('Notification not found', Response::HTTP_NOT_FOUND)->send();
            }

            // Check if notification belongs to the user
            if ($notification->getNotifiableId() !== $userData['uuid']) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            return Response::ok($notification, 'Notification retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve notification: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Mark notification as read
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function markAsRead(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get notification using UUID-based lookup
            $notification = $this->notificationService->getNotificationByUuid($params['id']);

            // Check if notification exists
            if (!$notification) {
                return Response::error('Notification not found', Response::HTTP_NOT_FOUND)->send();
            }

            // Check if notification belongs to the user
            if ($notification->getNotifiableId() !== $userData['uuid']) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            // Mark as read
            $notification = $this->notificationService->markAsRead($notification);

            return Response::ok($notification, 'Notification marked as read')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to mark notification as read: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Mark notification as unread
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function markAsUnread(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get notification using UUID-based lookup
            $notification = $this->notificationService->getNotificationByUuid($params['id']);

            // Check if notification exists
            if (!$notification) {
                return Response::error('Notification not found', Response::HTTP_NOT_FOUND)->send();
            }

            // Check if notification belongs to the user
            if ($notification->getNotifiableId() !== $userData['uuid']) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            // Mark as unread
            $notification = $this->notificationService->markAsUnread($notification);

            return Response::ok($notification, 'Notification marked as unread')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to mark notification as unread: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Mark all notifications as read
     *
     * @return mixed HTTP response
     */
    public function markAllAsRead()
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Create notifiable from user
            $userNotifiable = $this->createUserNotifiable($userData['uuid']);

            // Mark all as read
            $this->notificationService->markAllAsRead($userNotifiable);

            return Response::ok(null, 'All notifications marked as read')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to mark all notifications as read: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get notification preferences
     *
     * @return mixed HTTP response
     */
    public function getPreferences()
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Create notifiable from user
            $userNotifiable = $this->createUserNotifiable($userData['uuid']);

            // Get preferences using the proper service method
            $preferences = $this->notificationService->getPreferences($userNotifiable);

            $message = 'Notification preferences retrieved successfully';
            return Response::ok(['preferences' => $preferences], $message)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve notification preferences: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update notification preferences
     *
     * @return mixed HTTP response
     */
    public function updatePreferences()
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            $data = Request::getPostData();

            if (!isset($data['notification_type']) || !isset($data['channels'])) {
                $errorMsg = 'Notification type and channels are required';
                return Response::error($errorMsg, Response::HTTP_BAD_REQUEST)->send();
            }

            // Create notifiable from user
            $userNotifiable = $this->createUserNotifiable($userData['uuid']);

            // Use setPreference instead of savePreference
            $preference = $this->notificationService->setPreference(
                $userNotifiable,
                $data['notification_type'],
                $data['channels'],
                $data['enabled'] ?? true,
                $data['settings'] ?? null
            );

            return Response::ok($preference, 'Notification preferences updated successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to update notification preferences: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Delete a notification
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function deleteNotification(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get notification using UUID-based lookup
            $notification = $this->notificationService->getNotificationByUuid($params['id']);

            // Check if notification exists
            if (!$notification) {
                return Response::error('Notification not found', Response::HTTP_NOT_FOUND)->send();
            }

            // Check if notification belongs to the user
            if ($notification->getNotifiableId() !== $userData['uuid']) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            // Delete notification using UUID-based method
            $success = $this->notificationService->getRepository()->deleteNotificationByUuid($notification->getUuid());

            if (!$success) {
                return Response::error('Failed to delete notification', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Notification deleted successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to delete notification: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get notification performance metrics
     *
     * @return mixed HTTP response
     */
    public function getNotificationMetrics()
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract token for permission check
            $token = $userData['token'] ?? null;

            // Check if user has admin permissions using PermissionManager
            if (!\Glueful\Permissions\PermissionManager::can('notifications', 'manage', $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            // Get metrics from the notification service
            $metrics = $this->notificationService->getPerformanceMetrics();

            return Response::ok([
                'metrics' => $metrics,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], 'Notification performance metrics retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve notification metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get metrics for a specific notification channel
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function getChannelMetrics(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract token for permission check
            $token = $userData['token'] ?? null;

            // Check if user has admin permissions using PermissionManager
            if (!\Glueful\Permissions\PermissionManager::can('notifications', 'manage', $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            if (!isset($params['channel'])) {
                return Response::error('Channel parameter is required', Response::HTTP_BAD_REQUEST)->send();
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
                    Response::HTTP_NOT_FOUND
                )->send();
            }

            // Get metrics for the specific channel
            $metrics = $this->notificationService->getChannelMetrics($channelName);

            return Response::ok([
                'metrics' => $metrics,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], "Metrics for channel '{$channelName}' retrieved successfully")->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve channel metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Reset metrics for a specific notification channel
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function resetChannelMetrics(array $params)
    {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract token for permission check
            $token = $userData['token'] ?? null;

            // Check if user has admin permissions using PermissionManager
            if (!\Glueful\Permissions\PermissionManager::can('notifications', 'manage', $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            if (!isset($params['channel'])) {
                return Response::error('Channel parameter is required', Response::HTTP_BAD_REQUEST)->send();
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
                    Response::HTTP_NOT_FOUND
                )->send();
            }

            // Reset metrics for the specific channel
            $success = $this->notificationService->resetChannelMetrics($channelName);

            if (!$success) {
                return Response::error(
                    "Failed to reset metrics for channel '{$channelName}'",
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }

            return Response::ok(
                null,
                "Metrics for channel '{$channelName}' reset successfully"
            )->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to reset channel metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}
