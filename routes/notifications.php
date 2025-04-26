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

$notificationsController = new NotificationsController();

// Notification routes
Router::group('/notifications', function() use ($notificationsController) {
    
    /**
     * @route GET /notifications
     * @summary List Notifications
     * @description Retrieves all notifications for the currently authenticated user
     * @tag Notifications
     * @requiresAuth true
     * @response 200 application/json "Notifications retrieved successfully" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:[{
     *     uuid:string="Notification unique identifier",
     *     title:string="Notification title",
     *     body:string="Notification content",
     *     type:string="Notification type",
     *     read:boolean="Whether notification has been read",
     *     created_at:string="Creation timestamp"
     *   }],
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Unauthorized access"
     */
    Router::get('/', function() use ($notificationsController) {
        return $notificationsController->getNotifications();
    });
    
    /**
     * @route GET /notifications/{id}
     * @summary Get Notification
     * @description Retrieve a single notification by its UUID
     * @tag Notifications
     * @requiresAuth true
     * @param id path string true "Notification UUID"
     * @response 200 application/json "Notification retrieved successfully" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="Notification unique identifier",
     *     title:string="Notification title",
     *     body:string="Notification content",
     *     type:string="Notification type",
     *     read:boolean="Whether notification has been read",
     *     created_at:string="Creation timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "Notification not found"
     * @response 401 "Unauthorized access"
     */
    Router::get('/{id}', function(array $params) use ($notificationsController) {
        return $notificationsController->getNotification($params);
    });
    
    /**
     * @route POST /notifications/{id}/read
     * @summary Mark Notification as Read
     * @description Mark a specific notification as read
     * @tag Notifications
     * @requiresAuth true
     * @param id path string true "Notification UUID"
     * @response 200 application/json "Notification marked as read" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="Notification unique identifier",
     *     read:boolean="Read status (true)",
     *     updated_at:string="Update timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "Notification not found"
     * @response 401 "Unauthorized access"
     */
    Router::post('/{id}/read', function(array $params) use ($notificationsController) {
        return $notificationsController->markAsRead($params);
    });
    
    /**
     * @route POST /notifications/{id}/unread
     * @summary Mark Notification as Unread
     * @description Mark a specific notification as unread
     * @tag Notifications
     * @requiresAuth true
     * @param id path string true "Notification UUID"
     * @response 200 application/json "Notification marked as unread" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="Notification unique identifier",
     *     read:boolean="Read status (false)",
     *     updated_at:string="Update timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "Notification not found"
     * @response 401 "Unauthorized access"
     */
    Router::post('/{id}/unread', function(array $params) use ($notificationsController) {
        return $notificationsController->markAsUnread($params);
    });
    
    /**
     * @route POST /notifications/mark-all-read
     * @summary Mark All Notifications as Read
     * @description Mark all notifications for the authenticated user as read
     * @tag Notifications
     * @requiresAuth true
     * @response 200 application/json "All notifications marked as read" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     count:integer="Number of notifications marked as read",
     *     updated_at:string="Update timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Unauthorized access"
     */
    Router::post('/mark-all-read', function() use ($notificationsController) {
        return $notificationsController->markAllAsRead();
    });
    
    /**
     * @route GET /notifications/preferences
     * @summary Get Notification Preferences
     * @description Retrieve notification preferences for the authenticated user
     * @tag Notification Preferences
     * @requiresAuth true
     * @response 200 application/json "Notification preferences retrieved" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     email:boolean="Email notification setting",
     *     push:boolean="Push notification setting",
     *     in_app:boolean="In-app notification setting",
     *     types:{
     *       system:boolean="System notifications setting",
     *       security:boolean="Security notifications setting",
     *       account:boolean="Account notifications setting"
     *     }
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Unauthorized access"
     */
    Router::get('/preferences', function() use ($notificationsController) {
        return $notificationsController->getPreferences();
    });
    
    /**
     * @route POST /notifications/preferences
     * @summary Update Notification Preferences
     * @description Update notification preferences for the authenticated user
     * @tag Notification Preferences
     * @requiresAuth true
     * @requestBody email:boolean="Enable email notifications" push:boolean="Enable push notifications" in_app:boolean="Enable in-app notifications" types:{system:boolean="Enable system notifications",security:boolean="Enable security notifications",account:boolean="Enable account notifications"}
     * @response 200 application/json "Notification preferences updated" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     email:boolean="Updated email notification setting",
     *     push:boolean="Updated push notification setting",
     *     in_app:boolean="Updated in-app notification setting",
     *     types:{
     *       system:boolean="Updated system notifications setting",
     *       security:boolean="Updated security notifications setting",
     *       account:boolean="Updated account notifications setting"
     *     },
     *     updated_at:string="Update timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 400 "Invalid preferences format"
     * @response 401 "Unauthorized access"
     */
    Router::post('/preferences', function() use ($notificationsController) {
        return $notificationsController->updatePreferences();
    });
    
    /**
     * @route DELETE /notifications/{id}
     * @summary Delete Notification
     * @description Delete a specific notification by UUID
     * @tag Notifications
     * @requiresAuth true
     * @param id path string true "Notification UUID"
     * @response 200 application/json "Notification deleted successfully" {
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     uuid:string="Deleted notification unique identifier"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "Notification not found"
     * @response 401 "Unauthorized access"
     */
    Router::delete('/{id}', function(array $params) use ($notificationsController) {
        return $notificationsController->deleteNotification($params);
    });
    
}, requiresAuth: true);