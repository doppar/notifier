<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Tests\Mock\SpyPruneCommand;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class PruneCommandTest extends NotifierTestCase
{
    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $old = date('Y-m-d H:i:s', time() - 100 * 86400);
        $insert = $this->pdo->prepare("INSERT INTO notifications (notifiable_type, notifiable_id, type, data, read_at, created_at) VALUES ('x', 1, ?, '{}', ?, ?)");

        foreach ([['old-read', $old, $old], ['old-unread', null, $old], ['new-read', $now, $now], ['new-unread', null, $now]] as [$type, $read, $created]) {
            $insert->execute([$type, $read, $created]);
        }
    }

    public function testItDeletesOldReadNotificationsByDefault(): void
    {
        $this->seed();
        $command = (new SpyPruneCommand())->withOptions(['days' => '90']);

        $this->assertSame(0, $command->handle());

        $this->assertSame(['new-read', 'new-unread', 'old-unread'], array_column($this->rows('SELECT type FROM notifications ORDER BY type'), 'type'));
        $this->assertStringContainsString('1 notification(s)', $command->lines[0]);
    }

    public function testAllIncludesUnread(): void
    {
        $this->seed();

        (new SpyPruneCommand())->withOptions(['days' => '90', 'all' => true])->handle();

        $this->assertSame(['new-read', 'new-unread'], array_column($this->rows('SELECT type FROM notifications ORDER BY type'), 'type'));
    }

    public function testDaysIsValidated(): void
    {
        $this->seed();

        foreach (['abc', '-5', ''] as $days) {
            $command = (new SpyPruneCommand())->withOptions(['days' => $days]);

            $this->assertSame(1, $command->handle(), "days=$days");
            $this->assertNotEmpty($command->errors);
        }
        $this->assertSame(4, (int) $this->value('SELECT count(*) FROM notifications'), 'nothing was deleted');
    }
}
