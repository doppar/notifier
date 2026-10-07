<?php

namespace Doppar\Notifier\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Phaseolies\Support\UrlGenerator;
use Phaseolies\Support\LoggerService;
use Phaseolies\Http\Request;
use Phaseolies\Database\Database;
use Phaseolies\DI\Container;
use Doppar\Notifier\Tests\Mock\MockContainer;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\NotificationManager;
use Doppar\Notifier\NotificationEvents;
use Doppar\Notifier\Channels\MailChannel;
use Doppar\Notifier\Channels\Support\Http;
use Doppar\Notifier\Support\DeliveryLog;
use Doppar\Notifier\Testing\NotificationFake;
use Doppar\Queue\QueueManager;
use Doppar\Queue\QueueWorker;

abstract class NotifierTestCase extends TestCase
{
    protected PDO $pdo;

    protected ?QueueManager $queue = null;

    /**
     * The HTTP requests the channels made: url, body, headers
     *
     * @var array<int, array{url: string, body: string, headers: array<int, string>}>
     */
    protected array $http = [];

    /**
     * The answer the faked HTTP transport gives
     *
     * @var array{status: int, body: string, error: string}
     */
    protected array $httpAnswer = ['status' => 200, 'body' => 'ok', 'error' => ''];

    /**
     * The mails the faked mail sender received: address, name, the Mailable and the content
     *
     * @var array<int, array{address: string, name: string|null, mailable: object, content: array<string, mixed>}>
     */
    protected array $mails = [];

    protected function setUp(): void
    {
        $container = new MockContainer();
        Container::setInstance($container);
        $container->bind('request', fn() => new Request());
        $container->bind('url', fn() => UrlGenerator::class);
        $container->bind('db', fn() => new Database('default'));
        $container->singleton(NotificationManager::class, fn() => new NotificationManager());
        $container->singleton('log', LoggerService::class);

        config(['database.default' => 'default']);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();

        $reflection = new \ReflectionClass(Database::class);
        $reflection->getProperty('connections')->setValue(null, ['default' => $this->pdo, 'sqlite' => $this->pdo]);
        $reflection->getProperty('transactions')->setValue(null, []);

        if (static::queueAvailable()) {
            $this->queue = new QueueManager([
                'default' => 'database',
                'connections' => [
                    'database' => ['driver' => 'database', 'connection' => 'default'],
                    'memory' => ['driver' => 'memory'],
                ],
            ]);
            $container->singleton('queue.worker', fn() => $this->queue);
            $container->alias('queue.worker', QueueManager::class);
        }

        $this->http = [];
        $this->httpAnswer = ['status' => 200, 'body' => 'ok', 'error' => ''];
        Http::fake(function (string $url, string $body, array $headers): array {
            $this->http[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

            return $this->httpAnswer;
        });

        $this->mails = [];
        MailChannel::using(function (string $address, ?string $name, object $mailable, array $content): void {
            $this->mails[] = ['address' => $address, 'name' => $name, 'mailable' => $mailable, 'content' => $content];
        });

        NotificationEvents::flush();
        NotificationFake::deactivate();
        DeliveryLog::reset();
    }

    protected function tearDown(): void
    {
        NotificationEvents::flush();
        NotificationFake::deactivate();
        Http::fake(null);
        MailChannel::using(null);
        DeliveryLog::reset();

        $reflection = new \ReflectionClass(Database::class);
        $reflection->getProperty('connections')->setValue(null, []);
        $reflection->getProperty('transactions')->setValue(null, []);
    }

    /**
     * Check if the installed queue package has the storage drivers the queue tests need
     *
     * @return bool
     */
    protected static function queueAvailable(): bool
    {
        return interface_exists(\Doppar\Queue\Contracts\QueueDriver::class);
    }

    /**
     * Skip the test when the installed queue package is too old for it
     *
     * @return void
     */
    protected function requireQueue(): void
    {
        if (!static::queueAvailable()) {
            $this->markTestSkipped('Needs a doppar/queue release with the database, redis and memory drivers.');
        }
    }

    /**
     * Skip the test when the queue package does not have the priority, unique and backoff features
     *
     * @return void
     */
    protected function requireQueueFeatures(): void
    {
        $this->requireQueue();

        if (!method_exists(\Doppar\Queue\Job::class, 'uniqueId')) {
            $this->markTestSkipped('Needs a doppar/queue release with priority, unique jobs and backoff.');
        }
    }

    /**
     * Create the tables the notifier and the queue use
     *
     * @return void
     */
    protected function createTables(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, slack_webhook_url TEXT NULL, discord_webhook_url TEXT NULL, webhook_url TEXT NULL, muted TEXT NULL)');
        $this->pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, notifiable_type TEXT NOT NULL, notifiable_id INTEGER NOT NULL, type TEXT NOT NULL, data TEXT NOT NULL, metadata TEXT, read_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->createDeliveriesTable();
        $this->pdo->exec('CREATE TABLE queue_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER DEFAULT 0, priority INTEGER NOT NULL DEFAULT 0, reserved_at INTEGER, lease_expires_at INTEGER, available_at INTEGER NOT NULL, unique_key TEXT, created_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_queue_unique_key ON queue_jobs(unique_key)');
        $this->pdo->exec('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at INTEGER NOT NULL)');

        foreach ([[1, 'Ann', 'ann@example.com'], [2, 'Bob', 'bob@example.com'], [3, 'Cy', 'cy@example.com']] as [$id, $name, $email]) {
            $this->pdo->exec("INSERT INTO users (id, name, email, slack_webhook_url, discord_webhook_url, webhook_url) VALUES ($id, '$name', '$email', 'https://hooks.slack.test/$id', 'https://discord.test/hook/$id', 'https://hooks.example.test/$id')");
        }
    }

    /**
     * Create the delivery log table, as the package migration defines it
     *
     * @return void
     */
    protected function createDeliveriesTable(): void
    {
        $this->pdo->exec('CREATE TABLE notification_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_key TEXT NOT NULL UNIQUE, notification_id TEXT NOT NULL, notification_type TEXT NOT NULL, notifiable_type TEXT NOT NULL, notifiable_id INTEGER NOT NULL, channel TEXT NOT NULL, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error TEXT NULL, sent_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)');
    }

    /**
     * Get a user
     *
     * @param int $id
     * @return TestUser
     */
    protected function user(int $id = 1): TestUser
    {
        $user = TestUser::query()->where('id', $id)->first();
        $this->assertNotNull($user, "User $id exists");

        return $user;
    }

    /**
     * Run a query and return the rows
     *
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run a query and return its first value
     *
     * @param string $sql
     * @return mixed
     */
    protected function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * Create a worker for the notifications queue
     *
     * @return QueueWorker
     */
    protected function worker(): QueueWorker
    {
        return new QueueWorker($this->queue);
    }

    /**
     * Make every queued job available now
     *
     * @return void
     */
    protected function releaseQueuedJobs(): void
    {
        $this->pdo->exec('UPDATE queue_jobs SET available_at = ' . (time() - 1) . ', reserved_at = NULL, lease_expires_at = NULL');
    }

    /**
     * Run the queued notification jobs until none is ready
     *
     * @return int
     */
    protected function drain(): int
    {
        $worker = $this->worker();
        $ran = 0;

        while ($worker->runNextJob('notifications')) {
            $ran++;
        }

        return $ran;
    }
}
