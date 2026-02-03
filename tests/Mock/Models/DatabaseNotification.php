<?php

namespace Doppar\Notifier\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;

class DatabaseNotification extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifications';
    protected $connection = 'default';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $creatable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'data',
        'metadata',
        'read_at',
        'created_at'
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timeStamps = false;


    /**
     * Mark notification as read
     *
     * @return bool
     */
    public function markAsRead(): bool
    {
        if ($this->read_at) {
            return true;
        }

        $this->read_at = date('Y-m-d H:i:s');

        return $this->save();
    }

    /**
     * Mark notification as unread
     *
     * @return bool
     */
    public function markAsUnread(): bool
    {
        if (!$this->read_at) {
            return true;
        }

        $this->read_at = null;

        return $this->save();
    }

    /**
     * Check if notification is read
     *
     * @return bool
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Check if notification is unread
     *
     * @return bool
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Get the notifiable entity
     *
     * @return mixed
     */
    public function notifiable()
    {
        $class = $this->notifiable_type;

        return $class::find($this->notifiable_id);
    }
}
