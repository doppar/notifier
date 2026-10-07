<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Supports\Facades\Notification;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;
use Doppar\Queue\Facades\Queue;

class BuildersTest extends NotifierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireQueue();
    }

    public function testToSendsToOneEntityWithTheChosenChannels(): void
    {
        $id = Notification::to($this->user(2))->via(['webhook'])->send(new ConfigurableNotification(['database']));
        $this->drain();

        $this->assertIsString($id);
        $this->assertSame('https://hooks.example.test/2', $this->http[0]['url']);
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testToAfterDelays(): void
    {
        Notification::to($this->user(1))->after(120)->send(new ConfigurableNotification(['database']));

        $this->assertEqualsWithDelta(time() + 120, (int) $this->value('SELECT available_at FROM queue_jobs'), 5);
    }

    public function testSendNowDeliversWithoutTheQueue(): void
    {
        Notification::to($this->user(1))->sendNow(new ConfigurableNotification(['database']));

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'));
    }

    public function testToManyReturnsOneIdPerRecipient(): void
    {
        $ids = Notification::toMany([$this->user(1), $this->user(2), $this->user(3)])
            ->batchSize(2)
            ->send(new ConfigurableNotification(['database']));
        $this->drain();

        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($this->rows('SELECT notifiable_id FROM notifications ORDER BY notifiable_id'), 'notifiable_id')));
    }

    public function testToManyAcceptsAGenerator(): void
    {
        $generator = (function () {
            yield $this->user(1);
            yield $this->user(3);
        })();

        $ids = Notification::toMany($generator)->send(new ConfigurableNotification(['database']));

        $this->assertCount(2, $ids);
    }

    public function testToAllSendsToEveryMatchingRecord(): void
    {
        $count = Notification::toAll(TestUser::class)->chunkSize(2)->send(new ConfigurableNotification(['database']));
        $this->drain();

        $this->assertSame(3, $count);
        $this->assertSame(3, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testToAllWithAFilter(): void
    {
        $count = Notification::toAll(TestUser::class)->where('id', 2)->send(new ConfigurableNotification(['database']));
        $this->drain();

        $this->assertSame(1, $count);
        $this->assertSame(2, (int) $this->value('SELECT notifiable_id FROM notifications'));
    }

    public function testScheduleAfterAndAt(): void
    {
        Notification::schedule(new ConfigurableNotification(['database']))->to($this->user(1))->after(3600);
        Notification::schedule(new ConfigurableNotification(['database']))->to($this->user(2))->at(time() + 7200);

        $times = array_map('intval', array_column($this->rows('SELECT available_at FROM queue_jobs ORDER BY id'), 'available_at'));
        $this->assertEqualsWithDelta(time() + 3600, $times[0], 5);
        $this->assertEqualsWithDelta(time() + 7200, $times[1], 5);
        $this->assertSame(0, $this->drain(), 'neither is due');
    }

    public function testScheduleWithoutARecipientFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No recipient');

        Notification::schedule(new ConfigurableNotification(['database']))->after(10);
    }

    public function testTheNotifyHelper(): void
    {
        $id = notify($this->user(1))->send(new ConfigurableNotification(['database']));

        $this->assertIsString($id);
        $this->assertSame(1, Queue::size('notifications'));
        $this->assertInstanceOf(Notification::class, notify());
    }

    public function testTheSameNotificationObjectCanGoToManyPeopleWithSeparateDeliveries(): void
    {
        $notification = new ConfigurableNotification(['database']);

        Notification::toMany([$this->user(1), $this->user(2)])->send($notification);
        $this->drain();

        $this->assertSame(2, (int) $this->value('SELECT count(DISTINCT notification_id) FROM notification_deliveries'), 'one notification id per recipient');
        $this->assertSame(2, (int) $this->value("SELECT count(*) FROM notification_deliveries WHERE status = 'sent'"));
    }
}
