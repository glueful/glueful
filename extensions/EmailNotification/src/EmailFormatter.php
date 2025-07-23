<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Exceptions\BusinessLogicException;

/**
 * Email Formatter
 *
 * Responsible for formatting notification data into email-friendly format
 * with both HTML and plain text versions.
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EmailFormatter
{
    /**
     * @var array Formatter templates keyed by notification type
     */
    private array $templates = [];

    /**
     * @var array Default formatting options
     */
    private array $defaultOptions = [
        'include_footer' => true,
        'include_header' => true,
        'default_template' => 'default',
        'templates_path' => null,
    ];

    /**
     * EmailFormatter constructor
     *
     * @param array $templates Custom templates
     * @param array $options Formatting options
     */
    public function __construct(array $templates = [], array $options = [])
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);

        // Set default templates path if not provided
        if ($this->defaultOptions['templates_path'] === null) {
            $this->defaultOptions['templates_path'] = __DIR__ . '/Templates/html';
        }

        // Register default templates
        $this->registerDefaultTemplates();

        // Register any provided custom templates
        foreach ($templates as $name => $template) {
            $this->registerTemplate($name, $template);
        }
    }

    /**
     * Format notification data for email delivery
     *
     * @param array $data The notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @return array Formatted email data with subject, content, etc.
     */
    public function format(array $data, Notifiable $notifiable): array
    {

        // Determine the notification type and corresponding template
        $type = $data['type'] ?? 'default';
        $templateName = $data['template_name'] ?? $this->defaultOptions['default_template'];

        // Start with basic email structure
        $result = [
            'subject' => $data['subject'] ?? 'Notification',
            'text_content' => '',
            'html_content' => '',
            'attachments' => $data['attachments'] ?? []
        ];

        // Optional CC and BCC
        if (!empty($data['cc'])) {
            $result['cc'] = $data['cc'];
        }

        if (!empty($data['bcc'])) {
            $result['bcc'] = $data['bcc'];
        }

        // Get the template content
        $template = $this->getTemplate($type, $templateName);

        // Set notification data for template rendering
        $templateData = $data['template_data'] ?? $data;

        // Always include subject and title in template data
        $templateData['subject'] = $result['subject'];
        $templateData['title'] = $result['subject']; // Add title as an alias to subject

        // Ensure logo_url is always available, using config default if not provided
        if (!isset($templateData['logo_url'])) {
            $templateData['logo_url'] = config('mail.logo_url', 'https://brand.glueful.com/logo.png');
        }

        // Add notifiable information
        $templateData['notifiable_id'] = $notifiable->getNotifiableId();
        $templateData['notifiable_type'] = $notifiable->getNotifiableType();

        // Apply the template to get HTML content
        $result['html_content'] = $this->renderTemplate($template, $templateData);

        // Generate plain text version
        $result['text_content'] = $this->htmlToText($result['html_content']);

        return $result;
    }

    /**
     * Register a template for a notification type
     *
     * @param string $name Template name
     * @param array|string $template Template data or path
     * @return self
     */
    public function registerTemplate(string $name, $template): self
    {
        $this->templates[$name] = $template;
        return $this;
    }

    /**
     * Get a template for the notification type
     *
     * @param string $type Notification type
     * @param string $name Template name
     * @return array|string Template data
     */
    public function getTemplate(string $type, string $name = 'default')
    {
        // First try to find a template specific to this notification type
        $typedTemplateName = $type . '.' . $name;

        if (isset($this->templates[$typedTemplateName])) {
            return $this->templates[$typedTemplateName];
        }

        // Fall back to the named template regardless of type
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        // Last resort: use the default template
        return $this->templates['default'];
    }

    /**
     * Render a template with provided data
     *
     * @param array|string $template Template data or path
     * @param array $data Variables for template
     * @return string Rendered template
     */
    protected function renderTemplate($template, array $data): string
    {

        // If template is a file path, load the file
        if (is_string($template) && file_exists($template)) {
            $fileContent = file_get_contents($template);
            if ($fileContent !== false) {
                // Process the template content with variables
                $rendered = $this->replaceVariables($fileContent, $data);

                // Apply the layout if it's not already a complete HTML document
                if (strpos($rendered, '<!DOCTYPE html>') === false) {
                    $rendered = $this->applyLayout($rendered, $data);
                }

                return $rendered;
            } else {
                error_log("EmailFormatter: Failed to load template file");
            }
        }

        // If template is a string, substitute variables
        if (is_string($template)) {
            $rendered = $this->replaceVariables($template, $data);

            // Apply the layout if it's not already a complete HTML document
            if (strpos($rendered, '<!DOCTYPE html>') === false) {
                $rendered = $this->applyLayout($rendered, $data);
            }

            return $rendered;
        }

        // If template is a structured array with header, body, footer
        if (is_array($template)) {
            $html = '';

            // Add header if requested
            if ($this->defaultOptions['include_header'] && isset($template['header'])) {
                $html .= $this->replaceVariables($template['header'], $data);
            }

            // Add body (required)
            if (isset($template['body'])) {
                $html .= $this->replaceVariables($template['body'], $data);
            } else {
                error_log("EmailFormatter: Template body is missing!");
            }

            // Add footer if requested
            if ($this->defaultOptions['include_footer'] && isset($template['footer'])) {
                $html .= $this->replaceVariables($template['footer'], $data);
            }

            // Apply the layout if it's not already a complete HTML document
            if (strpos($html, '<!DOCTYPE html>') === false) {
                $html = $this->applyLayout($html, $data);
            }

            return $html;
        }

        // Fallback: return a simple message
        $fallback = '<p>Notification: ' . ($data['subject'] ?? 'No subject') . '</p>';
        return $this->applyLayout($fallback, $data);
    }

    /**
     * Apply layout template to content
     *
     * @param string $content The template content
     * @param array $data Variables for substitution
     * @return string Content wrapped in layout
     */
    protected function applyLayout(string $content, array $data): string
    {
        $layoutPath = $this->defaultOptions['templates_path'] . '/partials/layout.html';

        if (file_exists($layoutPath)) {
            $layout = file_get_contents($layoutPath);
            if ($layout !== false) {
                // Add the content to the data for the layout template
                $layoutData = array_merge($data, ['content' => $content]);
                return $this->replaceVariables($layout, $layoutData);
            }
        }

        // If layout doesn't exist, just return the content
        return $content;
    }

    /**
     * Replace variables in a template string with support for conditional blocks
     *
     * @param string $template Template string
     * @param array $data Variables for substitution
     * @return string Template with variables replaced
     */
    protected function replaceVariables(string $template, array $data): string
    {
        // Process template includes in the form {{> partial_name}}
        $template = preg_replace_callback(
            '/\{\{>\s+([a-zA-Z0-9_\-\.\/]+)\}\}/',
            function ($matches) use ($data) {
                $partialName = trim($matches[1]);
                return $this->includePartial($partialName, $data);
            },
            $template
        );

        // Process conditional blocks {{#if variable}}...content...{{/if}}
        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_\.]+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($data) {
                $variable = $matches[1];
                $content = $matches[2];

                // Check if variable exists and is truthy
                if (isset($data[$variable]) && $data[$variable]) {
                    return $content;
                }

                return ''; // Remove block if condition fails
            },
            $template
        );

        // Replace simple variables in the form {{variable}} or {{variable|default}}
        $result = preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($matches) use ($data) {
                $parts = explode('|', $matches[1]);
                $key = trim($parts[0]);
                $default = isset($parts[1]) ? trim($parts[1]) : '';

                // Handle nested keys with dot notation (e.g., user.name)
                if (strpos($key, '.') !== false) {
                    $keyParts = explode('.', $key);
                    $value = $data;

                    foreach ($keyParts as $part) {
                        if (is_array($value) && isset($value[$part])) {
                            $value = $value[$part];
                        } else {
                            return $default; // Key not found, use default
                        }
                    }

                    return is_scalar($value) ? (string)$value : $default;
                }

                return isset($data[$key]) && is_scalar($data[$key]) ? (string)$data[$key] : $default;
            },
            $template
        );

        return $result;
    }

    /**
     * Include a partial template
     *
     * @param string $partialName Name of the partial to include
     * @param array $data Variables for substitution
     * @return string Rendered partial content
     */
    protected function includePartial(string $partialName, array $data): string
    {
        $partialsPath = $this->defaultOptions['templates_path'] . '/partials';
        $partialFile = $partialsPath . '/' . $partialName . '.html';

        if (file_exists($partialFile)) {
            $partialContent = file_get_contents($partialFile);
            if ($partialContent !== false) {
                // Process the partial content (allows nested includes)
                return $this->replaceVariables($partialContent, $data);
            }
        }

        return '<!-- Partial not found: ' . $partialName . ' -->';
    }

    /**
     * Convert HTML to plain text
     *
     * @param string $html HTML content
     * @return string Plain text version
     */
    protected function htmlToText(string $html): string
    {
        // Replace common HTML elements with plain text equivalents
        $text = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '<p>', '</p>', '<div>', '</div>', '&nbsp;'],
            ["\n", "\n", "\n", "\n", "\n", "\n", "\n", ' '],
            $html
        ));

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove excess whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Register default email templates
     */
    private function registerDefaultTemplates(): void
    {
        $templatesPath = $this->defaultOptions['templates_path'];

        // Make sure templates directory exists
        if (!file_exists($templatesPath)) {
            throw BusinessLogicException::operationNotAllowed(
                'email_template_loading',
                "Email templates directory not found: {$templatesPath}"
            );
        }

        // First register the default template (required)
        $defaultTemplatePath = $templatesPath . '/default.html';
        if (!file_exists($defaultTemplatePath)) {
            throw BusinessLogicException::operationNotAllowed(
                'email_template_loading',
                "Default email template not found: {$defaultTemplatePath}"
            );
        }

        $this->templates['default'] = $defaultTemplatePath;

        // Scan for all HTML templates in the directory
        $files = glob($templatesPath . '/*.html');
        foreach ($files as $file) {
            $templateName = pathinfo($file, PATHINFO_FILENAME);

            // Skip default as we already registered it
            if ($templateName === 'default') {
                continue;
            }

            $this->templates[$templateName] = $file;
        }
    }

    /**
     * Set default formatting options
     *
     * @param array $options Formatting options
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
        return $this;
    }
}
