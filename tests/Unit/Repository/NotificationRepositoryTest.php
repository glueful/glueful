<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use Tests\Unit\Repository\Mocks\TestNotificationRepository;
use Tests\Unit\Repository\Mocks\MockNotificationConnection;
use Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Database\QueryBuilder;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Models\NotificationTemplate;

/**
 * Notification Repository Test
 *
 * Tests for the NotificationRepository class functionality including:
 * - Notification storage and retrieval
 * - Notification preferences management
 * - Template handling
 */
class NotificationRepositoryTest extends TestCase
{
    /** @var TestNotificationRepository */
    private TestNotificationRepository $notificationRepository;

    /** @var QueryBuilder&MockObject */
    private $mockQueryBuilder;

    /**
     * Setup before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock connection with in-memory SQLite
        $connection = new MockNotificationConnection();

        // Create mock query builder using the SQLite connection
        $this->mockQueryBuilder = $this->createMock(QueryBuilder::class);

        // Create repository with our mock query builder
        $this->notificationRepository = new TestNotificationRepository($this->mockQueryBuilder);

        // Create required tables for testing
        $connection->createNotificationTables();
    }

    /**
     * Test saving a notification
     */
    public function testSave(): void
    {
        // Sample notification
        $uuid = '12345678-1234-1234-1234-123456789012';
        $notification = new Notification(
            'account_created',
            'Welcome to Glueful',
            'user',
            'user-uuid'
        );
        $reflection = new \ReflectionClass($notification);

        // Set additional properties using reflection if needed
        $uuidProperty = $reflection->getProperty('uuid');
        $uuidProperty->setAccessible(true);
        $uuidProperty->setValue($notification, $uuid);

        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setAccessible(true);
        $dataProperty->setValue($notification, ['key' => 'value']);

        $priorityProperty = $reflection->getProperty('priority');
        $priorityProperty->setAccessible(true);
        $priorityProperty->setValue($notification, 'normal');

        // Configure query builder to return empty for find (new notification)
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([]); // No existing notification

        // Configure query builder for successful insert
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('insert')
            ->willReturn(1); // Insert success

        // Call the method
        $result = $this->notificationRepository->save($notification);

        // Assert result
        $this->assertTrue($result);
    }

    /**
     * Test finding notifications by notifiable
     */
    public function testFindForNotifiable(): void
    {
        // Sample notification data
        $notificationData = [
            [
                'id' => 1,
                'uuid' => '12345678-1234-1234-1234-123456789012',
                'type' => 'account_created',
                'subject' => 'Welcome to Glueful',
                'data' => json_encode(['key' => 'value']),
                'priority' => 'normal',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'read_at' => null,
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00'
            ],
            [
                'id' => 2,
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'type' => 'password_changed',
                'subject' => 'Your password was changed',
                'data' => json_encode(['ip' => '192.168.1.1']),
                'priority' => 'high',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'read_at' => null,
                'created_at' => '2023-01-02 00:00:00',
                'updated_at' => '2023-01-02 00:00:00'
            ]
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('whereNull')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('orderBy')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('offset')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn($notificationData);

        // Call the method
        $result = $this->notificationRepository->findForNotifiable('user', 'user-uuid');

        // Assert we got notification objects
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Notification::class, $result[0]);
        $this->assertInstanceOf(Notification::class, $result[1]);

        // Check first notification values
        $this->assertEquals('12345678-1234-1234-1234-123456789012', $result[0]->getUuid());
        $this->assertEquals('account_created', $result[0]->getType());
        $this->assertEquals('Welcome to Glueful', $result[0]->getSubject());
    }

    /**
     * Test finding a notification by UUID
     */
    public function testFindByUuid(): void
    {
        // Sample notification data
        $notificationData = [
            'id' => 1,
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'type' => 'account_created',
            'subject' => 'Welcome to Glueful',
            'data' => json_encode(['key' => 'value']),
            'priority' => 'normal',
            'notifiable_type' => 'user',
            'notifiable_id' => 'user-uuid',
            'read_at' => null,
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00'
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$notificationData]);

        // Call the method
        $result = $this->notificationRepository->findByUuid('12345678-1234-1234-1234-123456789012');

        // Assert result - we don't care if the type is exactly what we expect
        $this->assertInstanceOf(Notification::class, $result);
        $this->assertEquals('12345678-1234-1234-1234-123456789012', $result->getUuid());
    }

    /**
     * Test marking all notifications as read
     */
    public function testMarkAllAsRead(): void
    {
        // Sample unread notifications to return
        $unreadNotifications = [
            [
                'id' => 1,
                'uuid' => 'test-uuid-1',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-123',
                'read_at' => null
            ],
            [
                'id' => 2,
                'uuid' => 'test-uuid-2',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-123',
                'read_at' => null
            ]
        ];

        // Configure mock to return unread notifications and update count
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('whereNull')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn($unreadNotifications);
        // Mock update method to simulate successful updates
        $this->mockQueryBuilder
            ->expects($this->exactly(2))
            ->method('update')
            ->willReturn(1); // Each update returns 1 row affected

        // Mock transaction methods - void methods don't return values
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('beginTransaction');
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('commit');

        // Call the method
        $result = $this->notificationRepository->markAllAsRead('user', 'user-123');

        // Assert result
        $this->assertEquals(2, $result);
    }

    /**
     * Test saving a notification preference
     */
    public function testSavePreference(): void
    {
        // Sample preference - use constructor instead of setters
        $preference = new NotificationPreference(
            uniqid('preference_'), // Generate a unique ID string for test
            'user', // notifiable type
            'user-uuid', // notifiable id
            'account_security', // notification type
            ['email'], // channels
            true, // enabled
            ['frequency' => 'immediate'], // settings
            null // uuid (will be generated)
        );

        // Configure query builder to check if preference exists by UUID
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([]); // No existing preference

        // Mock insert for new preference
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('insert')
            ->willReturn(1); // Insert success

        // Call the method
        $result = $this->notificationRepository->savePreference($preference);

        // Assert result
        $this->assertTrue($result);
    }

    /**
     * Test finding notification preferences for a recipient
     */
    public function testFindPreferencesForNotifiable(): void
    {
        // Sample preference data to return
        $preferencesData = [
            [
                'id' => 'preference-1',
                'uuid' => 'preference-uuid-1',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-123',
                'notification_type' => 'account-updates',
                'channels' => json_encode(['email', 'push']),
                'enabled' => 1,
                'settings' => json_encode(['frequency' => 'immediate']),
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            [
                'id' => 'preference-2',
                'uuid' => 'preference-uuid-2',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-123',
                'notification_type' => 'marketing',
                'channels' => json_encode(['email']),
                'enabled' => 0,
                'settings' => json_encode(['frequency' => 'weekly']),
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ]
        ];

        // Configure mock to return sample preferences
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn($preferencesData);

        // Call the method
        $result = $this->notificationRepository->findPreferencesForNotifiable('user', 'user-123');

        // Assert we got preference objects
        $this->assertCount(2, $result);
        $this->assertInstanceOf(NotificationPreference::class, $result[0]);
        $this->assertInstanceOf(NotificationPreference::class, $result[1]);

        // Check values
        $this->assertEquals('account-updates', $result[0]->getNotificationType());
        $this->assertEquals(['email', 'push'], $result[0]->getChannels());
        $this->assertTrue($result[0]->isEnabled());

        $this->assertEquals('marketing', $result[1]->getNotificationType());
        $this->assertEquals(['email'], $result[1]->getChannels());
        $this->assertFalse($result[1]->isEnabled());
    }

    /**
     * Test saving a notification template
     */
    public function testSaveTemplate(): void
    {
        // Sample template using constructor pattern
        $template = new NotificationTemplate(
            'template-' . uniqid(), // Provide a string ID (required by the constructor)
            'Welcome Email',
            'account_created',
            'email',
            'Hello {{name}}, welcome to our application.',
            ['subject' => 'Welcome to {{app_name}}'], // parameters
            null // uuid will be generated
        );

        // Configure query builder to check if template exists by UUID
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([]); // No existing template

        // Mock insert for new template
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('insert')
            ->willReturn(1); // Insert success

        // Call the method
        $result = $this->notificationRepository->saveTemplate($template);

        // Assert result
        $this->assertTrue($result);
    }

    /**
     * Test finding notification templates
     */
    public function testFindTemplates(): void
    {
        // Sample template data matching the expected database structure
        $templateData = [
            'id' => 'template-1',
            'uuid' => 'template-uuid-123',
            'name' => 'Welcome Email',
            'notification_type' => 'account_created',
            'channel' => 'email',
            'content' => 'Hello {{name}}, welcome to our application.',
            'parameters' => json_encode(['subject' => 'Welcome to {{app_name}}']),
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => null
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([$templateData]);

        // Call the method
        $results = $this->notificationRepository->findTemplates('account_created', 'email');

        // Assert we got template objects
        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertInstanceOf(NotificationTemplate::class, $result);
        $this->assertEquals('account_created', $result->getNotificationType());
        $this->assertEquals('email', $result->getChannel());
        $this->assertEquals('Hello {{name}}, welcome to our application.', $result->getContent());
        $this->assertEquals(['subject' => 'Welcome to {{app_name}}'], $result->getParameters());
    }

    /**
     * Test finding notification template by UUID
     */
    public function testFindTemplateByUuid(): void
    {
        // Sample template data matching the expected database structure
        $templateData = [
            'id' => 'template-1',
            'uuid' => 'template-uuid-123',
            'name' => 'Welcome Email',
            'notification_type' => 'account_created',
            'channel' => 'email',
            'content' => 'Hello {{name}}, welcome to our application.',
            'parameters' => json_encode(['subject' => 'Welcome to {{app_name}}']),
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => null
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([$templateData]);

        // Call the method
        $result = $this->notificationRepository->findTemplateByUuid('template-uuid-123');

        // Assert result
        $this->assertInstanceOf(NotificationTemplate::class, $result);
        $this->assertEquals('template-1', $result->getId());
        $this->assertEquals('template-uuid-123', $result->getUuid());
        $this->assertEquals('account_created', $result->getNotificationType());
        $this->assertEquals('email', $result->getChannel());
        $this->assertEquals('Hello {{name}}, welcome to our application.', $result->getContent());
        $this->assertEquals(['subject' => 'Welcome to {{app_name}}'], $result->getParameters());
    }

    /**
     * Test counting notifications for a recipient
     */
    public function testCountForNotifiable(): void
    {
        // Configure mock to return count
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('whereNull')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([['count' => 5]]);

        // Call method
        $result = $this->notificationRepository->countForNotifiable('user', 'user-123', true);

        // Assert count
        $this->assertEquals(5, $result);
    }

    /**
     * Test deleting old notifications
     */
    public function testDeleteOldNotifications(): void
    {
        // Configure mock for successful delete
        $this->mockQueryBuilder
            ->method('delete')
            ->willReturn(true);

        // Call method
        $result = $this->notificationRepository->deleteOldNotifications(30);

        // Assert success
        $this->assertTrue($result);
    }

    /**
     * Test deleting a notification by UUID
     */
    public function testDeleteNotificationByUuid(): void
    {
        $testUuid = 'test-uuid-123';
        // Mock find method to return an existing record
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('get')
            ->willReturn([
                [
                    'uuid' => $testUuid,
                    'type' => 'test_notification',
                    'subject' => 'Test Subject',
                    'content' => 'Test Content',
                    'notifiable_type' => 'user',
                    'notifiable_id' => 'user-123'
                ]
            ]);

        // Configure mock for successful delete (QueryBuilder.delete returns bool)
        $this->mockQueryBuilder
            ->method('delete')
            ->willReturn(true);

        // Call method
        $result = $this->notificationRepository->deleteNotificationByUuid($testUuid);

        // Assert success
        $this->assertTrue($result);
    }
}
