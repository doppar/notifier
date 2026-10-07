<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Models\DatabaseNotification;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class NotifiableTest extends NotifierTestCase
{
    private function give(int $userId, int $count, bool $read = false): void
    {
        $insert = $this->pdo->prepare("INSERT INTO notifications (notifiable_type, notifiable_id, type, data, read_at, created_at) VALUES (?, ?, 'T', '{}', ?, '2026-01-01 00:00:00')");

        for ($i = 1; $i <= $count; $i++) {
            $insert->execute([TestUser::class, $userId, $read ? '2026-01-02 00:00:00' : null]);
        }
    }

    public function testNotificationsBelongToTheEntityWithNobodySignedIn(): void
    {
        $this->give(1, 2);
        $this->give(2, 5);

        $this->assertCount(2, $this->user(1)->notifications());
        $this->assertCount(5, $this->user(2)->notifications());
        $this->assertCount(0, $this->user(3)->notifications());
    }

    public function testUnreadReadAndTheUnreadCount(): void
    {
        $this->give(1, 3);
        $this->give(1, 2, true);
        $this->give(2, 4);

        $ann = $this->user(1);

        $this->assertCount(3, $ann->unreadNotifications());
        $this->assertCount(2, $ann->readNotifications());
        $this->assertSame(3, $ann->unreadNotificationsCount());
        $this->assertSame(4, $this->user(2)->unreadNotificationsCount());
        $this->assertSame(0, $this->user(3)->unreadNotificationsCount());
    }

    public function testNewestFirst(): void
    {
        $this->give(1, 3);

        $ids = array_map(fn ($n) => (int) $n->id, $this->user(1)->notifications()->all());

        $this->assertSame([3, 2, 1], $ids);
    }

    public function testMarkAllAsReadOnlyTouchesTheEntity(): void
    {
        $this->give(1, 3);
        $this->give(2, 2);

        $this->assertSame(3, $this->user(1)->markNotificationsAsRead());

        $this->assertSame(0, $this->user(1)->unreadNotificationsCount());
        $this->assertSame(2, $this->user(2)->unreadNotificationsCount(), 'somebody else is untouched');
        $this->assertSame(0, $this->user(1)->markNotificationsAsRead(), 'nothing left to mark');
    }

    public function testMarkOneAsReadIsLimitedToTheEntitysOwn(): void
    {
        $this->give(1, 1);
        $this->give(2, 1);
        $theirs = (int) $this->value("SELECT id FROM notifications WHERE notifiable_id = 2");
        $mine = (int) $this->value("SELECT id FROM notifications WHERE notifiable_id = 1");

        $this->assertFalse($this->user(1)->markNotificationAsRead($theirs), 'an id of another user is refused');
        $this->assertNull($this->value("SELECT read_at FROM notifications WHERE id = $theirs") ?: null);

        $this->assertTrue($this->user(1)->markNotificationAsRead($mine));
        $this->assertNotNull($this->value("SELECT read_at FROM notifications WHERE id = $mine") ?: null);
        $this->assertFalse($this->user(1)->markNotificationAsRead(999), 'no such notification');
    }

    public function testDeleteOneAndDeleteRead(): void
    {
        $this->give(1, 2);
        $this->give(1, 3, true);
        $this->give(2, 1, true);
        $unread = (int) $this->value("SELECT id FROM notifications WHERE notifiable_id = 1 AND read_at IS NULL LIMIT 1");
        $theirs = (int) $this->value("SELECT id FROM notifications WHERE notifiable_id = 2");

        $this->assertFalse($this->user(1)->deleteNotification($theirs), 'not allowed to delete another user\'s');
        $this->assertTrue($this->user(1)->deleteNotification($unread));
        $this->assertFalse($this->user(1)->deleteNotification($unread), 'already gone');

        $this->assertSame(3, $this->user(1)->deleteReadNotifications());
        $this->assertSame(1, $this->user(1)->unreadNotificationsCount());
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications WHERE notifiable_id = 2'));
    }

    public function testAnEntityThatIsNotSavedHasNoNotificationsAndTouchesNothing(): void
    {
        $this->give(1, 2);
        $unsaved = new TestUser();

        $this->assertCount(0, $unsaved->notifications());
        $this->assertSame(0, $unsaved->unreadNotificationsCount());
        $this->assertSame(0, $unsaved->markNotificationsAsRead());
        $this->assertSame(0, $unsaved->deleteReadNotifications());
        $this->assertSame(2, $this->user(1)->unreadNotificationsCount());
    }

    public function testTheTypeIsPartOfTheScope(): void
    {
        $this->pdo->prepare("INSERT INTO notifications (notifiable_type, notifiable_id, type, data, created_at) VALUES (?, 1, 'T', '{}', '2026-01-01 00:00:00')")->execute(['App\\Other']);

        $this->assertCount(0, $this->user(1)->notifications(), 'another model with the same id is not this entity');
    }

    public function testDeliveriesOfTheEntity(): void
    {
        $this->requireQueue();

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));
        $this->user(2)->notifyNow(new ConfigurableNotification(['database']));

        $deliveries = $this->user(1)->notificationDeliveries();

        $this->assertCount(1, $deliveries);
        $this->assertSame('database', $deliveries->first()->channel);
        $this->assertTrue($deliveries->first()->wasSent());
    }

    public function testThePayloadIsDecoded(): void
    {
        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $row = $this->user(1)->notifications()->first();

        $this->assertInstanceOf(DatabaseNotification::class, $row);
        $this->assertSame('Hello', $row->payload()['subject']);
    }

    public function testPruneDeletesOldReadNotificationsOnly(): void
    {
        $now = date('Y-m-d H:i:s');
        $old = date('Y-m-d H:i:s', time() - 40 * 86400);
        foreach ([['old read', $old, "'$old'"], ['old unread', $old, 'NULL'], ['new read', $now, "'$now'"]] as [$type, $created, $read]) {
            $this->pdo->exec("INSERT INTO notifications (notifiable_type, notifiable_id, type, data, read_at, created_at) VALUES ('x', 1, '$type', '{}', $read, '$created')");
        }

        $this->assertSame(1, DatabaseNotification::prune(30));
        $this->assertSame(['new read', 'old unread'], array_column($this->rows('SELECT type FROM notifications ORDER BY type'), 'type'));

        $this->assertSame(1, DatabaseNotification::prune(30, false), 'with everything: the old unread one goes too');
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
    }
}
