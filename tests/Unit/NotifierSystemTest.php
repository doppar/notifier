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
}
