<?php
require_once 'api/bootstrap.php';
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

$connection = new Connection();
$queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

$userUuid = 'hHKO88GdD4Ou';

// Check all notifications for this user
$userNotifications = $queryBuilder->select('notifications', ['*'])
    ->where(['notifiable_id' => $userUuid, 'notifiable_type' => 'user'])
    ->get();

echo "=== USER NOTIFICATIONS ===" . PHP_EOL;
echo "User UUID: {$userUuid}" . PHP_EOL;
echo "Total notifications for user: " . count($userNotifications) . PHP_EOL;

if (!empty($userNotifications)) {
    foreach ($userNotifications as $notification) {
        echo "ID: {$notification['id']}, UUID: {$notification['uuid']}, Type: {$notification['type']}, Subject: {$notification['subject']}" . PHP_EOL;
    }
}

// Check all notifications in the table
$allNotifications = $queryBuilder->select('notifications', ['id', 'notifiable_type', 'notifiable_id', 'type', 'subject'])
    ->orderBy(['created_at' => 'DESC'])
    ->limit(10)
    ->get();

echo PHP_EOL . "=== ALL NOTIFICATIONS (last 10) ===" . PHP_EOL;
echo "Total notifications in table: " . count($allNotifications) . PHP_EOL;

foreach ($allNotifications as $notification) {
    echo "ID: {$notification['id']}, Type: {$notification['notifiable_type']}, Notifiable ID: {$notification['notifiable_id']}, Subject: {$notification['subject']}" . PHP_EOL;
}