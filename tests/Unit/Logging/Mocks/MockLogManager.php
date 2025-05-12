<?php

namespace Tests\Unit\Logging\Mocks;

use Glueful\Logging\LogManager;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Formatter\LineFormatter;
use Tests\Unit\Logging\Mocks\MockLogSanitizer;

/**
 * MockLogManager for testing
 * 
 * A testable version of LogManager that allows for injecting mock handlers
 */
class MockLogManager extends LogManager
{
    /**
     * Logger instance
     * 
     * @var Logger
     */
    protected Logger $logger;
    
    /**
     * Flag to indicate test mode
     * 
     * @var bool
     */
    private bool $testMode = true;
    
    /**
     * Default logging channel name
     * 
     * @var string
     */
    private string $defaultChannel;
    
    /**
     * Maximum number of daily log files to keep
     * 
     * @var int
     */
    private int $maxFiles;
    
    /**
     * Minimum logging level
     * 
     * @var Level
     */
    private Level $minimumLevel;
    
    /**
     * Log rotation strategy
     * 
     * @var string
     */
    private string $rotationStrategy;
    
    /**
     * Rotation parameter value
     * 
     * @var mixed
     */
    private $rotationParameter;
    
    /**
     * Constructor that allows for test mode
     *
     * @param string $logFile Base path for log files (default: uses config)
     * @param int $maxFiles Maximum number of daily log files to keep (default: 30)
     * @param string $defaultChannel Default logging channel name (default: 'app')
     * @param bool $skipInitialization Skip handler initialization (for pure mocking)
     */
    public function __construct(string $logFile = "", int $maxFiles = 30, string $defaultChannel = 'app', bool $skipInitialization = false)
    {
        $this->defaultChannel = $defaultChannel;
        $this->maxFiles = $maxFiles;
        $this->minimumLevel = Level::Debug;
        $this->rotationStrategy = 'daily';
        
        // Create a logger with empty handlers if skipInitialization is true
        if ($skipInitialization) {
            $this->logger = new Logger($defaultChannel);
            return;
        }
        
        // Otherwise, proceed with standard initialization
        parent::__construct($logFile, $maxFiles, $defaultChannel);
    }
    
    /**
     * Replace all handlers with mocks
     *
     * @param array $handlers Array of handlers to use
     * @return self
     */
    public function setHandlers(array $handlers): self
    {
        // Remove all existing handlers
        $this->logger = new Logger($this->defaultChannel);
        
        // Add the provided handlers
        foreach ($handlers as $handler) {
            $this->logger->pushHandler($handler);
        }
        
        return $this;
    }
    
    /**
     * Add a mock database handler for testing
     * 
     * @param MockDatabaseLogHandler $dbHandler Mock database handler
     * @return self
     */
    public function addMockDatabaseHandler(MockDatabaseLogHandler $dbHandler): self
    {
        $this->logger->pushHandler($dbHandler);
        return $this;
    }
    
    /**
     * Override log method to make sure handlers process records
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        // Generate a LogRecord directly for testing purposes
        if (!isset($context['datetime'])) {
            $context['datetime'] = new \DateTimeImmutable();
        }
        
        // We need to call directly to the monolog logger
        $this->logger->log($level, $message, $context);
    }
    
    /**
     * Get the logger instance for testing
     * 
     * @return Logger
     */
    public function getMonologLogger(): Logger
    {
        return $this->logger;
    }
    
    /**
     * Create a test handler with the current rotation settings
     * 
     * Helper method to create handlers with consistent settings for testing
     * 
     * @param string $filename Log file path
     * @param Level|int $level Minimum log level for this handler
     * @param bool $bubble Whether to bubble logs up to higher handlers
     * @return RotatingFileHandler The configured handler
     */
    public function createTestHandler(string $filename, $level = Level::Debug, bool $bubble = true): RotatingFileHandler
    {
        $dateFormat = match ($this->rotationStrategy) {
            'monthly' => RotatingFileHandler::FILE_PER_MONTH,
            'weekly' => RotatingFileHandler::FILE_PER_DAY, // For weekly, we use daily format but set maxFiles=7
            default => RotatingFileHandler::FILE_PER_DAY,
        };
        
        // For weekly rotation strategy, set maxFiles to 7
        $maxFiles = $this->rotationStrategy === 'weekly' ? 7 : $this->maxFiles;
        
        $handler = new RotatingFileHandler(
            $filename,
            $maxFiles,
            $level,
            $bubble
        );
        
        // Set the filename format and date format
        $handler->setFilenameFormat('{filename}-{date}', $dateFormat);
        
        // Add a formatter for consistent output
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s"
        );
        $handler->setFormatter($formatter);
        
        return $handler;
    }
    
    /**
     * Exposed method for accessing rotationStrategy (for testing)
     * 
     * @return string
     */
    public function getRotationStrategy(): string
    {
        return $this->rotationStrategy;
    }
    
    /**
     * Exposed method for accessing rotationParameter (for testing)
     * 
     * @return mixed
     */
    public function getRotationParameter(): mixed
    {
        return $this->rotationParameter;
    }
    
    /**
     * Sanitize context data by removing sensitive values
     * Exposed for testing
     * 
     * @param array $context Context data to sanitize
     * @return array Sanitized context data
     */
    public function sanitizeContext(array $context): array
    {
        return MockLogSanitizer::sanitizeContext($context);
    }
    
    /**
     * Enrich context with additional information
     * Exposed for testing
     * 
     * @param array $context Original context
     * @return array Enriched context
     */
    public function enrichContext(array $context): array
    {
        return MockLogSanitizer::enrichContext($context);
    }
}
