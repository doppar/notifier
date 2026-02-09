<?php

namespace Doppar\Notifier\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;
use Doppar\Notifier\Concerns\Notifiable;

class MockNotifiable extends Model
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
    ];

    /**
     * Get Slack webhook URL for notifications
     *
     * @return string|null
     */
    public function routeNotificationForSlack(): ?string
    {
        return $this->slack_webhook_url ?? 'https://hooks.slack.com/services/TEST/WEBHOOK/URL';
    }

    /**
     * Get Discord webhook URL for notifications
     *
     * @return string|null
     */
    public function routeNotificationForDiscord(): ?string
    {
        return $this->discord_webhook_url ?? 'https://discord.com/api/webhooks/TEST/WEBHOOK';
    }
}
