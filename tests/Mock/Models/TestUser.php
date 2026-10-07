<?php

namespace Doppar\Notifier\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;
use Doppar\Notifier\Concerns\Notifiable;
use Doppar\Notifier\Contracts\Notification;

class TestUser extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $connection = 'default';

    protected $timeStamps = false;

    protected $creatable = [
        'id',
        'name',
        'email',
        'slack_webhook_url',
        'discord_webhook_url',
        'webhook_url',
        'muted',
    ];

    /**
     * Keep the channels the person switched off (a comma separated list in the muted column) out
     *
     * @param Notification $notification
     * @param string $channel
     * @return bool
     */
    public function wantsNotification(Notification $notification, string $channel): bool
    {
        return !in_array($channel, array_filter(explode(',', (string) $this->muted)), true);
    }
}
