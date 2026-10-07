<?php

namespace Doppar\Notifier\Models;

use Phaseolies\Database\Entity\Model;

/**
 * @property string $status
 */
class NotificationDelivery extends Model
{
    /**
     * The database table associated with deliveries
     *
     * @var string
     */
    protected $table = 'notification_deliveries';

    /**
     * List of attributes that are allowed to be mass-assigned
     *
     * @var array<int, string>
     */
    protected $creatable = [
        'delivery_key',
        'notification_id',
        'notification_type',
        'notifiable_type',
        'notifiable_id',
        'channel',
        'status',
        'attempts',
        'error',
        'sent_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Check if the delivery reached the channel
     *
     * @return bool
     */
    public function wasSent(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Check if the last attempt failed
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
