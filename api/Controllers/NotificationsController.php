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
class NotificationsController {
    
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;
    
    /**
     * Constructor
     */
    public function __construct() {
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
    private function createUserNotifiable(string $userUuid): object {
        return new class($userUuid) implements \Glueful\Notifications\Contracts\Notifiable {
            private $uuid;
            
            public function __construct(string $uuid) {
                $this->uuid = $uuid;
            }
            
            public function routeNotificationFor(string $channel) {
                return null;
            }
            
            public function getNotifiableId(): string {
                return $this->uuid;
            }
            
            public function getNotifiableType(): string {
                return 'user';
            }
            
            public function shouldReceiveNotification(string $notificationType, string $channel): bool {
                return true;
            }
            
            public function getNotificationPreferences(): array {
                return [];
            }
        };
    }
    
    /**
     * Get all notifications for the authenticated user
     * 
     * @return mixed HTTP response
     */
    public function getNotifications() {
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
            
            // Create notifiable from user
            $userNotifiable = $this->createUserNotifiable($userData['uuid']);
            
            // Retrieve notifications with pagination
            $notifications = $this->notificationService->getNotifications(
                $userNotifiable,
                $onlyUnread,
                $perPage,
                ($page - 1) * $perPage
            );
            
            // Get total count for pagination
            $totalCount = 0;
            if ($onlyUnread) {
                $totalCount = $this->notificationService->getUnreadCount($userNotifiable);
            } else {
                // Use getNotifications with a large limit to get total count
                // This is a workaround since countForNotifiable is not available
                $allNotifications = $this->notificationService->getNotifications(
                    $userNotifiable,
                    false,
                    1000, // Use a large number to get all notifications
                    0
                );
                $totalCount = count($allNotifications);
            }
            
            return Response::ok([
                'data' => $notifications,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => ceil($totalCount / $perPage)
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
    public function getNotification(array $params) {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Get notification using findById instead of find
            $notification = $this->notificationService->getRepository()->findById($params['id']);
            
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
    public function markAsRead(array $params) {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Get notification
            $notification = $this->notificationService->getRepository()->findById($params['id']);
            
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
    public function markAsUnread(array $params) {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Get notification
            $notification = $this->notificationService->getRepository()->findById($params['id']);
            
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
    public function markAllAsRead() {
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
    public function getPreferences() {
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
            
            return Response::ok(['preferences' => $preferences], 'Notification preferences retrieved successfully')->send();
            
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
    public function updatePreferences() {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            $data = Request::getPostData();
            
            if (!isset($data['notification_type']) || !isset($data['channels'])) {
                return Response::error('Notification type and channels are required', Response::HTTP_BAD_REQUEST)->send();
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
    public function deleteNotification(array $params) {
        try {
            // Get authenticated user
            $userData = Utils::getCurrentUser();
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Get notification
            $notification = $this->notificationService->getRepository()->findById($params['id']);
            
            // Check if notification exists
            if (!$notification) {
                return Response::error('Notification not found', Response::HTTP_NOT_FOUND)->send();
            }
            
            // Check if notification belongs to the user
            if ($notification->getNotifiableId() !== $userData['uuid']) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }
            
            // Delete notification using the correct method name
            $success = $this->notificationService->getRepository()->deleteNotification($notification->getId());
            
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
}