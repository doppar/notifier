<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Support\DeliveryLog;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class MigrationTest extends NotifierTestCase
{
    public function testTheMigrationCreatesTheTableTheLogCanWriteTo(): void
    {
        \Phaseolies\DI\Container::getInstance()->bind('schema', fn () => new \Phaseolies\Database\Migration\Schema('default'));

        $this->pdo->exec('DROP TABLE notification_deliveries');
        $migration = require __DIR__ . '/../../src/database/migrations/2026_10_07_000000_create_notification_deliveries_table.php';

        $migration->up();

        $columns = array_column($this->rows('PRAGMA table_info(notification_deliveries)'), 'name');
        foreach (['delivery_key', 'notification_id', 'notification_type', 'notifiable_type', 'notifiable_id', 'channel', 'status', 'attempts', 'error', 'sent_at', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columns, $column);
        }

        DeliveryLog::reset();
        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));
        $this->assertSame('sent', $this->value("SELECT status FROM notification_deliveries WHERE channel = 'database'"));

        $unique = array_filter($this->rows('PRAGMA index_list(notification_deliveries)'), fn ($i) => (int) $i['unique'] === 1);
        $this->assertNotEmpty($unique, 'one row per notification and channel is enforced');

        $migration->down();
        $this->assertSame([], $this->rows("SELECT name FROM sqlite_master WHERE name = 'notification_deliveries'"));
    }
}
