<?php
declare(strict_types=1);

namespace Glueful\Notifications\Templates;

use Glueful\Notifications\Models\NotificationTemplate;
use InvalidArgumentException;

/**
 * Template Manager
 * 
 * Manages notification templates including loading, caching, and retrieving
 * templates for different notification types and channels.
 * 
 * @package Glueful\Notifications\Templates
 */
class TemplateManager
{
    /**
     * @var TemplateResolver The template resolver instance
     */
    private TemplateResolver $resolver;
    
    /**
     * @var array In-memory template storage
     */
    private array $templates = [];
    
    /**
     * @var array Configuration options
     */
    private array $config;
    
    /**
     * TemplateManager constructor
     * 
     * @param TemplateResolver|null $resolver Template resolver instance
     * @param array $config Configuration options
     */
    public function __construct(?TemplateResolver $resolver = null, array $config = [])
    {
        $this->resolver = $resolver ?? new TemplateResolver();
        $this->config = $config;
    }
    
    /**
     * Register a notification template
     * 
     * @param NotificationTemplate $template The template to register
     * @return self
     */
    public function registerTemplate(NotificationTemplate $template): self
    {
        $key = $this->resolver->generateTemplateId(
            $template->getNotificationType(),
            $template->getName(),
            $template->getChannel()
        );
        
        $this->templates[$key] = $template;
        
        return $this;
    }
    
    /**
     * Register multiple templates at once
     * 
     * @param array $templates Array of templates to register
     * @return self
     */
    public function registerTemplates(array $templates): self
    {
        foreach ($templates as $template) {
            if ($template instanceof NotificationTemplate) {
                $this->registerTemplate($template);
            }
        }
        
        return $this;
    }
    
    /**
     * Get a template by its components
     * 
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @return NotificationTemplate|null The requested template or null if not found
     */
    public function getTemplate(string $type, string $name, string $channel): ?NotificationTemplate
    {
        $key = $this->resolver->generateTemplateId($type, $name, $channel);
        
        return $this->templates[$key] ?? null;
    }
    
    /**
     * Get all registered templates
     * 
     * @return array All registered templates
     */
    public function getAllTemplates(): array
    {
        return $this->templates;
    }
    
    /**
     * Get templates for a specific notification type
     * 
     * @param string $type Notification type
     * @return array Templates for the specified type
     */
    public function getTemplatesForType(string $type): array
    {
        return array_filter($this->templates, function(NotificationTemplate $template) use ($type) {
            return $template->getNotificationType() === $type;
        });
    }
    
    /**
     * Get templates for a specific channel
     * 
     * @param string $channel Channel name
     * @return array Templates for the specified channel
     */
    public function getTemplatesForChannel(string $channel): array
    {
        return array_filter($this->templates, function(NotificationTemplate $template) use ($channel) {
            return $template->getChannel() === $channel;
        });
    }
    
    /**
     * Remove a template
     * 
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @return self
     */
    public function removeTemplate(string $type, string $name, string $channel): self
    {
        $key = $this->resolver->generateTemplateId($type, $name, $channel);
        
        if (isset($this->templates[$key])) {
            unset($this->templates[$key]);
        }
        
        return $this;
    }
    
    /**
     * Resolve templates for a notification across channels
     * 
     * @param string $type Notification type
     * @param string $name Template name
     * @param array|null $channels Specific channels to resolve for (null for all)
     * @return array Map of channel => template
     */
    public function resolveTemplates(string $type, string $name, ?array $channels = null): array
    {
        $channelsToUse = $channels ?? $this->getAvailableChannels();
        
        return $this->resolver->resolveForChannels(
            $type,
            $name,
            $channelsToUse,
            $this->templates
        );
    }
    
    /**
     * Get available channels from registered templates
     * 
     * @return array List of unique channel names
     */
    public function getAvailableChannels(): array
    {
        $channels = [];
        
        foreach ($this->templates as $template) {
            $channel = $template->getChannel();
            if (!in_array($channel, $channels)) {
                $channels[] = $channel;
            }
        }
        
        return $channels;
    }
    
    /**
     * Create a new template instance and register it
     * 
     * @param string $id Template ID
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @param string $content Template content
     * @param array|null $parameters Template parameters
     * @return NotificationTemplate The created template
     */
    public function createTemplate(
        string $id,
        string $type,
        string $name,
        string $channel,
        string $content,
        ?array $parameters = null
    ): NotificationTemplate {
        $template = new NotificationTemplate(
            $id,
            $name,
            $type,
            $channel,
            $content,
            $parameters
        );
        
        $this->registerTemplate($template);
        
        return $template;
    }
    
    /**
     * Get the template resolver
     * 
     * @return TemplateResolver The template resolver
     */
    public function getResolver(): TemplateResolver
    {
        return $this->resolver;
    }
    
    /**
     * Set the template resolver
     * 
     * @param TemplateResolver $resolver The template resolver
     * @return self
     */
    public function setResolver(TemplateResolver $resolver): self
    {
        $this->resolver = $resolver;
        return $this;
    }
    
    /**
     * Get configuration option
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Set configuration option
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
    
    /**
     * Render a template with data
     * 
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @param array $data Data for template rendering
     * @return string|null Rendered template content or null if template not found
     */
    public function renderTemplate(string $type, string $name, string $channel, array $data): ?string
    {
        $template = $this->resolver->resolve($type, $name, $channel, $this->templates);
        
        if ($template === null) {
            return null;
        }
        
        return $template->render($data);
    }
}