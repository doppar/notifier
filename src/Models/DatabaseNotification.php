<?php

namespace Doppar\Notifier\Models;

use Phaseolies\Database\Entity\Model;

/**
 * @property string $data
 */
class DatabaseNotification extends Model
{
    /**
     * The database table associated with notifications
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * List of attributes that are allowed to be mass-assigned
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
        'created_at',
    ];

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
     * Get the notification's content as an array
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = json_decode((string) $this->data, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Delete old notifications
     *
     * @param int $days
     * @param bool $onlyRead
     * @return int
     */
    public static function prune(int $days, bool $onlyRead = true): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(0, $days) * 86400);
        $matching = static function () use ($cutoff, $onlyRead) {
            $query = static::query()->where('created_at', '<', $cutoff);

            return $onlyRead ? $query->whereNotNull('read_at') : $query;
        };

        $count = (int) $matching()->count();

        if ($count > 0) {
            $matching()->delete();
        }

        return $count;
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