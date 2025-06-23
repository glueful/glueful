<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Glueful\Queue\WorkerOptions;
use Cron\CronExpression;
use Psr\Log\LoggerInterface;

/**
 * Scheduled Scaling Service for Queue Workers
 *
 * Automatically scales workers based on predefined schedules and time patterns.
 * Supports cron-like expressions for complex scheduling scenarios.
 */
class ScheduledScaler
{
    private ProcessManager $processManager;
    private LoggerInterface $logger;
    private array $schedules = [];
    private array $activeSchedules = [];

    public function __construct(ProcessManager $processManager, LoggerInterface $logger)
    {
        $this->processManager = $processManager;
        $this->logger = $logger;
    }

    /**
     * Add a scaling schedule
     */
    public function addSchedule(
        string $name,
        string $cronExpression,
        string $queueName,
        int $workerCount,
        array $options = []
    ): void {
        $this->schedules[$name] = [
            'cron' => $cronExpression,
            'queue' => $queueName,
            'workers' => $workerCount,
            'options' => $options,
            'enabled' => true,
            'last_run' => null,
            'next_run' => null,
        ];

        $this->updateNextRunTime($name);
    }

    /**
     * Remove a scaling schedule
     */
    public function removeSchedule(string $name): bool
    {
        if (isset($this->schedules[$name])) {
            unset($this->schedules[$name]);
            unset($this->activeSchedules[$name]);
            return true;
        }
        return false;
    }

    /**
     * Enable or disable a schedule
     */
    public function setScheduleEnabled(string $name, bool $enabled): bool
    {
        if (isset($this->schedules[$name])) {
            $this->schedules[$name]['enabled'] = $enabled;
            if ($enabled) {
                $this->updateNextRunTime($name);
            }
            return true;
        }
        return false;
    }

    /**
     * Process all scheduled scaling operations
     */
    public function processSchedules(): array
    {
        $results = [];
        $currentTime = new \DateTime();

        foreach ($this->schedules as $name => $schedule) {
            if (!$schedule['enabled']) {
                continue;
            }

            if ($this->shouldRunSchedule($name, $currentTime)) {
                $result = $this->executeSchedule($name, $schedule);
                if ($result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Check if a schedule should run
     */
    private function shouldRunSchedule(string $name, \DateTime $currentTime): bool
    {
        $schedule = $this->schedules[$name];

        // Check if schedule has never run or if it's time to run
        if ($schedule['next_run'] === null) {
            $this->updateNextRunTime($name);
        }

        $nextRun = new \DateTime($schedule['next_run']);
        return $currentTime >= $nextRun;
    }

    /**
     * Execute a scheduled scaling operation
     */
    private function executeSchedule(string $name, array $schedule): ?array
    {
        try {
            $queueName = $schedule['queue'];
            $targetWorkers = $schedule['workers'];
            $currentWorkers = $this->processManager->getWorkerCount($queueName);

            // Create worker options
            $workerOptions = $this->createWorkerOptions($schedule['options']);

            // Scale the workers
            $this->processManager->scale($targetWorkers, $queueName, $workerOptions);

            // Update schedule tracking
            $this->schedules[$name]['last_run'] = date('Y-m-d H:i:s');
            $this->updateNextRunTime($name);

            $this->logger->info('Executed scheduled scaling', [
                'schedule_name' => $name,
                'queue' => $queueName,
                'from_workers' => $currentWorkers,
                'to_workers' => $targetWorkers,
                'next_run' => $this->schedules[$name]['next_run'],
            ]);

            return [
                'schedule_name' => $name,
                'queue' => $queueName,
                'action' => $targetWorkers > $currentWorkers ? 'scale_up' : 'scale_down',
                'from_workers' => $currentWorkers,
                'to_workers' => $targetWorkers,
                'reason' => "Scheduled scaling: {$name}",
                'next_run' => $this->schedules[$name]['next_run'],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to execute scheduled scaling', [
                'schedule_name' => $name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update the next run time for a schedule
     */
    private function updateNextRunTime(string $name): void
    {
        if (!isset($this->schedules[$name])) {
            return;
        }

        $schedule = $this->schedules[$name];
        try {
            $cron = new CronExpression($schedule['cron']);
            $this->schedules[$name]['next_run'] = $cron->getNextRunDate()->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->logger->error('Invalid cron expression for schedule', [
                'schedule_name' => $name,
                'cron' => $schedule['cron'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create worker options from schedule options
     */
    private function createWorkerOptions(array $options): WorkerOptions
    {
        return new WorkerOptions(
            memory: $options['memory'] ?? 128,
            timeout: $options['timeout'] ?? 60,
            maxJobs: $options['max_jobs'] ?? 1000,
            maxAttempts: $options['max_attempts'] ?? 3,
        );
    }

    /**
     * Load schedules from configuration
     */
    public function loadSchedulesFromConfig(array $config): void
    {
        $schedules = $config['schedules'] ?? [];

        foreach ($schedules as $name => $scheduleConfig) {
            $this->addSchedule(
                $name,
                $scheduleConfig['cron'],
                $scheduleConfig['queue'],
                $scheduleConfig['workers'],
                $scheduleConfig['options'] ?? []
            );
        }
    }

    /**
     * Get all schedules
     */
    public function getSchedules(): array
    {
        return $this->schedules;
    }

    /**
     * Get schedule by name
     */
    public function getSchedule(string $name): ?array
    {
        return $this->schedules[$name] ?? null;
    }

    /**
     * Add predefined business hour schedules
     */
    public function addBusinessHourSchedules(string $queueName, int $businessWorkers = 4, int $offHoursWorkers = 1): void
    {
        // Scale up at 8 AM Monday-Friday
        $this->addSchedule(
            "business_hours_start_{$queueName}",
            '0 8 * * 1-5', // 8 AM, Monday-Friday
            $queueName,
            $businessWorkers,
            ['reason' => 'Business hours start']
        );

        // Scale down at 6 PM Monday-Friday
        $this->addSchedule(
            "business_hours_end_{$queueName}",
            '0 18 * * 1-5', // 6 PM, Monday-Friday
            $queueName,
            $offHoursWorkers,
            ['reason' => 'Business hours end']
        );

        // Weekend scaling (Saturday/Sunday low activity)
        $this->addSchedule(
            "weekend_scaling_{$queueName}",
            '0 0 * * 6-0', // Midnight Saturday and Sunday
            $queueName,
            $offHoursWorkers,
            ['reason' => 'Weekend low activity']
        );
    }

    /**
     * Add high-traffic period schedules
     */
    public function addHighTrafficSchedules(string $queueName): void
    {
        // Black Friday preparation (scale up heavily)
        $this->addSchedule(
            "black_friday_prep_{$queueName}",
            '0 6 * 11 5', // 6 AM on Black Friday (last Friday of November)
            $queueName,
            10,
            ['reason' => 'Black Friday preparation']
        );

        // Cyber Monday preparation
        $this->addSchedule(
            "cyber_monday_prep_{$queueName}",
            '0 6 * 11 1', // 6 AM on first Monday after Black Friday
            $queueName,
            8,
            ['reason' => 'Cyber Monday preparation']
        );

        // End of month processing (reports, billing, etc.)
        $this->addSchedule(
            "end_of_month_{$queueName}",
            '0 2 28-31 * *', // 2 AM on last few days of month
            $queueName,
            6,
            ['reason' => 'End of month processing']
        );
    }

    /**
     * Add maintenance window schedules
     */
    public function addMaintenanceSchedules(string $queueName): void
    {
        // Weekly maintenance - scale down Sunday early morning
        $this->addSchedule(
            "weekly_maintenance_{$queueName}",
            '0 3 * * 0', // 3 AM Sunday
            $queueName,
            1,
            ['reason' => 'Weekly maintenance window']
        );

        // Daily quiet period - scale down during low activity hours
        $this->addSchedule(
            "quiet_period_{$queueName}",
            '0 2 * * *', // 2 AM daily
            $queueName,
            1,
            ['reason' => 'Daily quiet period']
        );
    }

    /**
     * Preview when schedules will run
     */
    public function previewSchedules(int $days = 7): array
    {
        $preview = [];
        $startDate = new \DateTime();
        $endDate = (clone $startDate)->modify("+{$days} days");

        foreach ($this->schedules as $name => $schedule) {
            if (!$schedule['enabled']) {
                continue;
            }

            try {
                $cron = new CronExpression($schedule['cron']);
                $runs = $cron->getMultipleRunDates(10, $startDate, $endDate);

                foreach ($runs as $runDate) {
                    $preview[] = [
                        'schedule_name' => $name,
                        'queue' => $schedule['queue'],
                        'workers' => $schedule['workers'],
                        'run_time' => $runDate->format('Y-m-d H:i:s'),
                        'cron' => $schedule['cron'],
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error previewing schedule', [
                    'schedule_name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Sort by run time
        usort($preview, function ($a, $b) {
            return $a['run_time'] <=> $b['run_time'];
        });

        return $preview;
    }

    /**
     * Validate cron expression
     */
    public function validateCronExpression(string $expression): bool
    {
        try {
            new CronExpression($expression);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get next run time for a schedule
     */
    public function getNextRunTime(string $name): ?string
    {
        return $this->schedules[$name]['next_run'] ?? null;
    }

    /**
     * Force run a schedule now (bypass cron timing)
     */
    public function forceRunSchedule(string $name): ?array
    {
        if (!isset($this->schedules[$name])) {
            return null;
        }

        $schedule = $this->schedules[$name];
        $result = $this->executeSchedule($name, $schedule);

        if ($result) {
            $result['forced'] = true;
        }

        return $result;
    }

    /**
     * Get statistics about schedule executions
     */
    public function getScheduleStats(): array
    {
        $stats = [
            'total_schedules' => count($this->schedules),
            'enabled_schedules' => 0,
            'disabled_schedules' => 0,
            'schedules_with_next_run' => 0,
        ];

        foreach ($this->schedules as $schedule) {
            if ($schedule['enabled']) {
                $stats['enabled_schedules']++;
            } else {
                $stats['disabled_schedules']++;
            }

            if ($schedule['next_run'] !== null) {
                $stats['schedules_with_next_run']++;
            }
        }

        return $stats;
    }
}
