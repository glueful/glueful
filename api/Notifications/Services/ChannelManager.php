<?php
declare(strict_types=1);

namespace Glueful\Notifications\Services;

use Glueful\Notifications\Contracts\NotificationChannel;
use InvalidArgumentException;

/**
 * Channel Manager Service
 * 
 * Manages notification channels for delivery of notifications.
 * Acts as a registry for channel drivers and handles channel operations.
 * 
 * @package Glueful\Notifications\Services
 */
class ChannelManager
{
    /**
     * @var array Registered notification channels
     */
    private array $channels = [];
    
    /**
     * @var array Configuration options for the channel manager
     */
    private array $config;
    
    /**
     * ChannelManager constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    /**
     * Register a notification channel
     * 
     * @param NotificationChannel $channel The channel to register
     * @return self
     * @throws InvalidArgumentException If a channel with the same name already exists
     */
    public function registerChannel(NotificationChannel $channel): self
    {
        $channelName = $channel->getChannelName();
        
        if ($this->hasChannel($channelName)) {
            throw new InvalidArgumentException("Channel '{$channelName}' is already registered.");
        }
        
        $this->channels[$channelName] = $channel;
        
        return $this;
    }
    
    /**
     * Get a registered channel by name
     * 
     * @param string $name Channel name
     * @return NotificationChannel The notification channel
     * @throws InvalidArgumentException If the channel doesn't exist
     */
    public function getChannel(string $name): NotificationChannel
    {
        if (!$this->hasChannel($name)) {
            throw new InvalidArgumentException("Channel '{$name}' is not registered.");
        }
        
        return $this->channels[$name];
    }
    
    /**
     * Check if a channel is registered
     * 
     * @param string $name Channel name
     * @return bool Whether the channel exists
     */
    public function hasChannel(string $name): bool
    {
        return isset($this->channels[$name]);
    }
    
    /**
     * Remove a registered channel
     * 
     * @param string $name Channel name
     * @return self
     */
    public function removeChannel(string $name): self
    {
        if ($this->hasChannel($name)) {
            unset($this->channels[$name]);
        }
        
        return $this;
    }
    
    /**
     * Get all registered channels
     * 
     * @return array Array of registered channels
     */
    public function getChannels(): array
    {
        return $this->channels;
    }
    
    /**
     * Get available channel names
     * 
     * @return array Array of channel names
     */
    public function getAvailableChannels(): array
    {
        return array_keys($this->channels);
    }
    
    /**
     * Get only channels that are currently available for sending
     * 
     * @return array Array of available channels
     */
    public function getActiveChannels(): array
    {
        return array_filter($this->channels, function(NotificationChannel $channel) {
            return $channel->isAvailable();
        });
    }
    
    /**
     * Get the manager configuration
     * 
     * @return array Manager configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Set a configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }
}