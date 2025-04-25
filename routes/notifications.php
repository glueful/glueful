<?php

/**
 * Notification Routes
 * 
 * This file defines routes for notification-related functionality including:
 * - Fetching user notifications
 * - Marking notifications as read/unread
 * - Managing notification preferences
 * - Sending test notifications
 * 
 * All routes in this file are protected by authentication middleware.
 */

use Glueful\Http\Router;
use Glueful\Controllers\NotificationsController;
use Symfony\Component\HttpFoundation\Request;

$notificationsController = new NotificationsController();

// Notification routes
Router::group('/notifications', function() use ($notificationsController) {
    
    // Get all notifications for the authenticated user
    Router::get('/', function() use ($notificationsController) {
        return $notificationsController->getNotifications();
    });
    
    // Get a single notification by ID
    Router::get('/{id}', function(array $params) use ($notificationsController) {
        return $notificationsController->getNotification($params);
    });
    
    // Mark notification as read
    Router::post('/{id}/read', function(array $params) use ($notificationsController) {
        return $notificationsController->markAsRead($params);
    });
    
    // Mark notification as unread
    Router::post('/{id}/unread', function(array $params) use ($notificationsController) {
        return $notificationsController->markAsUnread($params);
    });
    
    // Mark all notifications as read
    Router::post('/mark-all-read', function() use ($notificationsController) {
        return $notificationsController->markAllAsRead();
    });
    
    // Get notification preferences
    Router::get('/preferences', function() use ($notificationsController) {
        return $notificationsController->getPreferences();
    });
    
    // Update notification preferences
    Router::post('/preferences', function() use ($notificationsController) {
        return $notificationsController->updatePreferences();
    });
    
    // Delete a notification
    Router::delete('/{id}', function(array $params) use ($notificationsController) {
        return $notificationsController->deleteNotification($params);
    });
    
}, requiresAuth: true);