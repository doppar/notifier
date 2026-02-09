<?php

namespace Doppar\Notifier\Tests\Unit;

use Phaseolies\Support\UrlGenerator;
use Phaseolies\Support\LoggerService;
use Phaseolies\Http\Request;
use Phaseolies\Database\Database;
use Phaseolies\DI\Container;
use PHPUnit\Framework\TestCase;
use PDO;
use Doppar\Notifier\Tests\Mock\MockContainer;
use Doppar\Notifier\NotificationManager;
use Doppar\Notifier\Tests\Mock\Channels\TestCustomChannel;
use Doppar\Notifier\Tests\Mock\Models\DatabaseNotification;
use Doppar\Notifier\Tests\Mock\Models\MockNotifiable;
use Doppar\Notifier\Tests\Mock\Notifications\TestEmailNotification;
use Doppar\Notifier\Supports\Facades\Notification;
use Doppar\Notifier\Concerns\NotificationBuilder;
use Doppar\Notifier\Concerns\BulkNotificationBuilder;
use Doppar\Notifier\Concerns\QueryNotificationBuilder;
use Doppar\Notifier\Concerns\ScheduledNotificationBuilder;
use Doppar\Notifier\NotificationDispatcher;
use Doppar\Queue\Facades\Queue;

class NotifierSystemTest extends TestCase
{
    private $pdo;
    private $manager;

    protected function setUp(): void
    {
        Container::setInstance(new MockContainer());
        $container = new Container();
        $container->bind('request', fn() => new Request());
        $container->bind('url', fn() => UrlGenerator::class);
        $container->bind('db', fn() => new Database('default'));
        $container->singleton('notification.manager', NotificationManager::class);
        $container->singleton('log', LoggerService::class);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createNotificationTables();
        $this->setupDatabaseConnections();

        $this->manager = new NotificationManager();
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        $this->manager = null;
        $this->tearDownDatabaseConnections();
    }

    private function createNotificationTables(): void
    {
        // Create notifications table
        $this->pdo->exec("
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notifiable_type TEXT NOT NULL,
                notifiable_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                data TEXT NOT NULL,
                metadata TEXT,
                read_at TEXT,
                created_at TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE INDEX idx_notifiable ON notifications(notifiable_type, notifiable_id)
        ");

        $this->pdo->exec("
            CREATE INDEX idx_read_at ON notifications(read_at)
        ");

        // Create queue_jobs table (for queued notifications)
        $this->pdo->exec("
            CREATE TABLE queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                reserved_at INTEGER,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                slack_webhook_url TEXT NULL,
                discord_webhook_url TEXT NULL
            )
        ");
    }

    private function setupDatabaseConnections(): void
    {
        $this->setStaticProperty(Database::class, 'connections', []);
        $this->setStaticProperty(Database::class, 'transactions', []);

        $this->setStaticProperty(Database::class, 'connections', [
            'default' => $this->pdo,
            'sqlite' => $this->pdo
        ]);
    }

    private function tearDownDatabaseConnections(): void
    {
        $this->setStaticProperty(Database::class, 'connections', []);
        $this->setStaticProperty(Database::class, 'transactions', []);
    }

    private function setStaticProperty(string $className, string $propertyName, $value): void
    {
        try {
            $reflection = new \ReflectionClass($className);
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, $value);
            $property->setAccessible(false);
        } catch (\ReflectionException $e) {
            $this->fail("Failed to set static property {$propertyName}: " . $e->getMessage());
        }
    }

    // =====================================================
    // TEST NOTIFICATION MANAGER
    // =====================================================

    public function testInvalidChannelThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification channel [invalid] is not supported.');

        $this->manager->channel('invalid');
    }

    public function testExtendWithNonExistentClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver class [NonExistentClass] does not exist.');

        $this->manager->extend('custom', 'NonExistentClass');
    }

    public function testExtendWithInvalidDriverClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver class must extend ChannelDriver.');

        $this->manager->extend('custom', \stdClass::class);
    }

    public function testHasChannel(): void
    {
        $this->assertTrue($this->manager->hasChannel('database'));
        $this->assertTrue($this->manager->hasChannel('slack'));
        $this->assertTrue($this->manager->hasChannel('discord'));
        $this->assertFalse($this->manager->hasChannel('nonexistent'));

        $this->manager->extend('custom', TestCustomChannel::class);
        $this->assertTrue($this->manager->hasChannel('custom'));
    }

    public function testGetChannels(): void
    {
        $channels = $this->manager->getChannels();

        $this->assertContains('database', $channels);
        $this->assertContains('slack', $channels);
        $this->assertContains('discord', $channels);

        $this->manager->extend('custom', TestCustomChannel::class);

        $channels = $this->manager->getChannels();
        $this->assertContains('custom', $channels);
    }

    public function testCreateDatabaseNotification(): void
    {
        $notification = DatabaseNotification::create([
            'notifiable_type' => MockNotifiable::class,
            'notifiable_id' => 1,
            'type' => TestEmailNotification::class,
            'data' => json_encode(['title' => 'Test', 'message' => 'Hello']),
            'metadata' => json_encode(['priority' => 'high']),
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertInstanceOf(DatabaseNotification::class, $notification);
        $this->assertEquals(MockNotifiable::class, $notification->notifiable_type);
        $this->assertEquals(1, $notification->notifiable_id);
        $this->assertNull($notification->read_at);
    }

    public function testMarkNotificationAsRead(): void
    {
        $notification = DatabaseNotification::create([
            'notifiable_type' => MockNotifiable::class,
            'notifiable_id' => 1,
            'type' => TestEmailNotification::class,
            'data' => json_encode(['message' => 'Test']),
            'metadata' => json_encode([]),
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($notification->isUnread());
        $this->assertFalse($notification->isRead());

        $result = $notification->markAsRead();

        $this->assertTrue($result);
        $this->assertNotNull($notification->read_at);
        $this->assertTrue($notification->isRead());
        $this->assertFalse($notification->isUnread());
    }

    public function testMarkNotificationAsUnread(): void
    {
        $notification = DatabaseNotification::create([
            'notifiable_type' => MockNotifiable::class,
            'notifiable_id' => 1,
            'type' => TestEmailNotification::class,
            'data' => json_encode(['message' => 'Test']),
            'metadata' => json_encode([]),
            'read_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($notification->isRead());

        $result = $notification->markAsUnread();

        $this->assertTrue($result);
        $this->assertNull($notification->read_at);
        $this->assertTrue($notification->isUnread());
    }

    public function testMarkAlreadyReadNotificationAsRead(): void
    {
        $notification = DatabaseNotification::create([
            'notifiable_type' => MockNotifiable::class,
            'notifiable_id' => 1,
            'type' => TestEmailNotification::class,
            'data' => json_encode(['message' => 'Test']),
            'metadata' => json_encode([]),
            'read_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $notification->markAsRead();
        $this->assertTrue($result);
    }

    public function testMarkAlreadyUnreadNotificationAsUnread(): void
    {
        $notification = DatabaseNotification::create([
            'notifiable_type' => MockNotifiable::class,
            'notifiable_id' => 1,
            'type' => TestEmailNotification::class,
            'data' => json_encode(['message' => 'Test']),
            'metadata' => json_encode([]),
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $notification->markAsUnread();
        $this->assertTrue($result);
    }

    public function testNotificationBuilderCreation(): void
    {
        $notifiable = new MockNotifiable(['id' => 1]);
        $builder = Notification::to($notifiable);

        $this->assertInstanceOf(NotificationBuilder::class, $builder);
    }

    public function testNotificationBuilderWithDelay(): void
    {
        $notifiable = new MockNotifiable(['id' => 1]);

        $builder = Notification::to($notifiable)->after(300);

        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('delay');
        $property->setAccessible(true);

        $this->assertEquals(300, $property->getValue($builder));
    }

    public function testBulkNotificationBuilder(): void
    {
        $notifiables = [
            new MockNotifiable(['id' => 1]),
            new MockNotifiable(['id' => 2]),
            new MockNotifiable(['id' => 3]),
        ];

        $builder = Notification::toMany($notifiables);

        $this->assertInstanceOf(BulkNotificationBuilder::class, $builder);
    }

    public function testBulkNotificationWithBatchSize(): void
    {
        $notifiables = array_map(
            fn($i) => new MockNotifiable(['id' => $i]),
            range(1, 250)
        );

        $builder = Notification::toMany($notifiables)->batchSize(100);

        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('batchSize');
        $property->setAccessible(true);

        $this->assertEquals(100, $property->getValue($builder));
    }

    public function testQueryNotificationBuilder(): void
    {
        $builder = Notification::toAll(MockNotifiable::class);

        $this->assertInstanceOf(QueryNotificationBuilder::class, $builder);
    }

    public function testNotifyHelperWithoutArgument(): void
    {
        $result = notify();

        $this->assertInstanceOf(Notification::class, $result);
    }

    public function testNotifyHelperWithNotifiable(): void
    {
        $notifiable = new MockNotifiable(['id' => 1]);
        $result = notify($notifiable);

        $this->assertInstanceOf(NotificationBuilder::class, $result);
    }
}
