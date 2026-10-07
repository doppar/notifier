<?php

namespace Doppar\Notifier\Tests\Mock;

use Doppar\Notifier\Console\Commands\PruneNotificationsCommand;

class SpyPruneCommand extends PruneNotificationsCommand
{
    /**
     * @var array<string, mixed>
     */
    public array $givenOptions = [];

    /**
     * @var array<int, string>
     */
    public array $lines = [];

    /**
     * @var array<int, string>
     */
    public array $errors = [];

    /**
     * Give the command its options
     *
     * @param array<string, mixed> $options
     * @return static
     */
    public function withOptions(array $options): static
    {
        $this->givenOptions = $options;

        return $this;
    }

    protected function option($key = null)
    {
        return $key === null ? $this->givenOptions : ($this->givenOptions[$key] ?? null);
    }

    protected function info($string): void
    {
        $this->lines[] = $string;
    }

    protected function error($string): void
    {
        $this->errors[] = $string;
    }
}
