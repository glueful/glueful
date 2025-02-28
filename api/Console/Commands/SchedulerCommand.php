<?php
declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Scheduler\JobScheduler;
use Glueful\Helpers\Utils;


/**
 * JobScheduler Command
 * 
 * Provides CLI interface for managing and running scheduled tasks.
 * Allows users to run, list, and manage scheduled jobs from the command line.
 * 
 * @package Glueful\Console\Commands
 */
class SchedulerCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'scheduler';
    
    /**
     * The description of the command
     */
    protected string $description = 'Manage and run scheduled tasks';
    
    /**
     * The command syntax
     */
    protected string $syntax = 'scheduler [action] [options]';
    
    /**
     * Command options
     */
    protected array $options = [
        'run'      => 'Run all due scheduled tasks',
        'run-all'  => 'Run all scheduled tasks (ignoring schedule)',
        'list'     => 'List all scheduled tasks',
        'work'     => 'Run scheduler in worker mode (continuous running)'
    ];
    
    /**
     * @var Schedule The scheduler instance
     */
    private JobScheduler $scheduler;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->scheduler = new JobScheduler();
        // $this->registerDefaultJobs();
    }
    
    /**
     * Get the command name
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

     /**
     * Get Command Description
     * 
     * Provides command summary:
     * - Shows in command lists
     * - Single line description
     * - Explains primary purpose
     * 
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return $this->description;
    }
    
    /**
     * Execute the command
     * 
     * @param array $args Command arguments
     * @param array $options Command options
     * @return int Exit code
     */
    public function execute(array $args = [], array $options = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->showHelp();
            return Command::SUCCESS;
        }
        
        $action = $args[0];
        
        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->showHelp();
            return Command::FAILURE;
        }
        
        try {
            switch ($action) {
                case 'run':
                    $this->runDueJobs();
                    break;
                
                case 'run-all':
                    $this->runAllJobs();
                    break;
                
                case 'list':
                    $this->listJobs();
                    break;
                
                case 'work':
                    $this->runWorker($options);
                    break;
                
                default:
                    $this->error("Action not implemented: $action");
                    return Command::FAILURE;
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Run due jobs
     */
    protected function runDueJobs(): void
    {
        $this->info('Running due scheduled tasks...');
        $this->scheduler->runDueJobs();
        $this->success('Scheduled tasks completed');
    }

    /**
     * Run all jobs
     */
    protected function runAllJobs(): void
    {
        $this->info('Running all scheduled tasks (ignoring schedule)...');
        $this->scheduler->runAllJobs();
        $this->success('All scheduled tasks completed');
    }

    /**
     * List all registered jobs
     */
    protected function listJobs(): void
    {
        $this->info('Registered Scheduled Tasks');
        $this->line('========================');

        $jobs = $this->scheduler->getJobs();
        
        if (empty($jobs)) {
            $this->warning('No scheduled tasks registered');
            return;
        }

        // Create a table header
        $this->line(
            Utils::padColumn('Name', 30) . 
            Utils::padColumn('Schedule', 20)
        );
        $this->line(str_repeat('-', 50));
        
        // Display each job
        foreach ($jobs as $job) {
            $this->line(
                Utils::padColumn($job['name'], 30) . 
                Utils::padColumn($job['schedule'], 20)
            );
        }
    }

    /**
     * Run scheduler in worker mode
     * 
     * Continuously runs the scheduler at specified interval
     * 
     * @param array $options Command options
     */
    protected function runWorker(array $options = []): void
    {
        $interval = isset($options['interval']) ? (int)$options['interval'] : 60;
        $maxRuns = isset($options['max-runs']) ? (int)$options['max-runs'] : 0;
        $runCount = 0;
        
        $this->info("Starting scheduler worker (interval: {$interval}s)" . 
             ($maxRuns > 0 ? ", max runs: {$maxRuns}" : ""));
        
        while (true) {
            $this->scheduler->runDueJobs();
            
            $runCount++;
            if ($maxRuns > 0 && $runCount >= $maxRuns) {
                $this->info("Maximum runs ({$maxRuns}) reached. Exiting.");
                break;
            }
            
            $this->line("Sleeping for {$interval} seconds...");
            sleep($interval);
        }
    }
    
    /**
     * Register default application jobs
     * 
     * Automatically scans the cron directory and registers all PHP files as jobs.
     * Each job is registered with a default daily schedule that can be overridden 
     * with schedule comments in the file.
     */
    // private function registerDefaultJobs(): void
    // {
    //     $cronDir = dirname(__DIR__, 3) . '/cron';
        
    //     // Skip if directory doesn't exist
    //     if (!is_dir($cronDir)) {
    //         $this->warning("Cron directory not found: $cronDir");
    //         return;
    //     }
        
    //     // Scan for PHP files
    //     $files = glob("$cronDir/*.php");
        
    //     if (empty($files)) {
    //         $this->info("No cron jobs found in: $cronDir");
    //         return;
    //     }
        
    //     foreach ($files as $file) {
    //         $jobName = pathinfo($file, PATHINFO_FILENAME);
    //         $schedule = $this->extractScheduleFromFile($file) ?? '@daily';
            
    //         // Register job with the scheduler
    //         $this->scheduler->register($schedule, function() use ($file) {
    //             require_once $file;
    //         }, $jobName);
            
    //         $this->info("Registered job: $jobName ($schedule)");
    //     }
    // }
    
    /**
     * Extract schedule from file comments
     * 
     * Looks for a special comment in the format:
     * // @schedule: * * * * *
     * 
     * @param string $filePath Path to cron job file
     * @return string|null Extracted schedule or null if not found
     */
    // private function extractScheduleFromFile(string $filePath): ?string
    // {
    //     $content = file_get_contents($filePath);
        
    //     // Look for schedule definition in file comments
    //     if (preg_match('/@schedule:\s*([^\n]+)/i', $content, $matches)) {
    //         return trim($matches[1]);
    //     }
        
    //     return null;
    // }

    /**
     * Get Command Help
     * 
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
scheduler Command
===============

Manages and runs scheduled tasks in the application.

Usage:
  scheduler run            Run all due scheduled tasks
  scheduler run-all        Run all scheduled tasks (ignoring schedule)
  scheduler list           List all scheduled tasks
  scheduler work           Run scheduler in worker mode (continuous running)

Options:
  -h, --help              Show this help message
  --interval=<seconds>    Worker mode sleep interval in seconds (default: 60)
  --max-runs=<count>      Maximum number of scheduler runs (default: infinite)

Examples:
  php glueful scheduler run
  php glueful scheduler list
  php glueful scheduler work --interval=300 --max-runs=24
HELP;
    }
    
    /**
     * Show command help
     */
    protected function showHelp(): void
    {
        $this->line($this->getHelp());
    }
}
