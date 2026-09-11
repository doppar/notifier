<?php

namespace Doppar\Notifier\Console\Commands;

use Phaseolies\Console\Schedule\Command;

class MakeNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'make:notification {name}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Create a new notification class';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        return $this->executeWithTiming(function () {
            [$name, $parts, $className] = $this->splitGeneratedName((string) $this->argument('name'));

            // Ensure class name ends with Notification
            if (!str_ends_with($className, 'Notification')) {
                $className .= 'Notification';
            }

            $namespace = 'App\\Notifications' . (count($parts) > 0 ? '\\' . implode('\\', $parts) : '');
            $fileName = count($parts) > 0 ? implode('/', $parts) . '/' . $className : $className;
            $filePath = $this->generatedFilePath('src/Notifications', $fileName);

            // Check if Notification already exists
            if (file_exists($filePath)) {
                $this->displayError('Notification already exists at:');
                $this->line('<fg=white>' . $this->relativePath($filePath) . '</>');
                return Command::FAILURE;
            }

            // Create directory if needed
            $directoryPath = dirname($filePath);
            if (!is_dir($directoryPath)) {
                mkdir($directoryPath, 0755, true);
            }

            // Generate and save Notification class
            $channel = strtolower(
                preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Notification', '', $className))
            );

            $content = $this->generateNotificationContent($namespace, $className, $channel);
            file_put_contents($filePath, $content);

            $this->displaySuccess('Notification created successfully');
            $this->line('<fg=yellow>📦 File:</> <fg=white>' . $this->relativePath($filePath) . '</>');
            $this->newLine();
            $this->line('<fg=yellow>⚙️  Class:</> <fg=white>' . $className . '</>');

            return Command::SUCCESS;
        });
    }

    /**
     * Generate Notification class content.
     */
    protected function generateNotificationContent(string $namespace, string $className, string $channel): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Doppar\Notifier\Contracts\Notification;

class {$className} extends Notification
{
    /**
     * Create a new notifiable instance.
     */
    public function __construct(){}

    /**
     * Determine which channels the notification should be delivered through.
     *
     * @param mixed \$notifiable
     * @return array
     */
    public function channels(\$notifiable): array
    {
        return [];
    }

     /**
     * Build the notification's payload.
     *
     * @param mixed \$notifiable
     * @return array
     */
    public function content(\$notifiable): array
    {
        return [];
    }
}

PHP;
    }
}
