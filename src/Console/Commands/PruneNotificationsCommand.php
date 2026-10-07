<?php

namespace Doppar\Notifier\Console\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Notifier\Models\DatabaseNotification;

class PruneNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command
     *
     * @var string
     */
    protected $name = 'notification:prune {--days=90} {--all}';

    /**
     * The description of the console command
     *
     * @var string
     */
    protected $description = 'Delete old read notifications (all of them with --all)';

    /**
     * Execute the console command
     *
     * @return int
     */
    public function handle(): int
    {
        $days = $this->option('days');

        if (!is_numeric($days) || (int) $days < 0) {
            $this->error('The --days option must be a number of days, zero or more.');

            return Command::FAILURE;
        }

        $count = DatabaseNotification::prune((int) $days, !$this->option('all'));

        $this->info("✔ {$count} notification(s) older than {$days} day(s) deleted.");

        return Command::SUCCESS;
    }
}
