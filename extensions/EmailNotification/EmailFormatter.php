<?php
declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Notifications\Contracts\Notifiable;

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
                return $this->replaceVariables($fileContent, $data);
            }
        }
        
        // If template is a string, substitute variables
        if (is_string($template)) {
            return $this->replaceVariables($template, $data);
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
            }
            
            // Add footer if requested
            if ($this->defaultOptions['include_footer'] && isset($template['footer'])) {
                $html .= $this->replaceVariables($template['footer'], $data);
            }
            
            return $html;
        }
        
        // Fallback: return a simple message
        return '<p>Notification: ' . ($data['subject'] ?? 'No subject') . '</p>';
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
        
        // Replace simple variables in the form {{variable}}
        $result = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_\.]+)\}\}/',
            function ($matches) use ($data) {
                $key = $matches[1];
                
                // Handle nested keys with dot notation (e.g., user.name)
                if (strpos($key, '.') !== false) {
                    $parts = explode('.', $key);
                    $value = $data;
                    
                    foreach ($parts as $part) {
                        if (is_array($value) && isset($value[$part])) {
                            $value = $value[$part];
                        } else {
                            return ''; // Key not found
                        }
                    }
                    
                    return is_scalar($value) ? (string)$value : '';
                }
                
                return isset($data[$key]) && is_scalar($data[$key]) ? (string)$data[$key] : '';
            },
            $template
        );
        
        return $result;
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
        
        // Load templates from files if they exist
        if (file_exists($templatesPath)) {
            // Default template
            if (file_exists($templatesPath . '/default.html')) {
                $this->templates['default'] = $templatesPath . '/default.html';
            } else {
                $this->registerHardcodedDefaultTemplate();
            }
            
            // Alert template
            if (file_exists($templatesPath . '/alert.html')) {
                $this->templates['alert'] = $templatesPath . '/alert.html';
            }
            
            // Welcome template
            if (file_exists($templatesPath . '/welcome.html')) {
                $this->templates['welcome'] = $templatesPath . '/welcome.html';
            }
        } else {
            // Fall back to hardcoded templates
            $this->registerHardcodedDefaultTemplate();
        }
    }
    
    /**
     * Register the hardcoded default template as fallback
     */
    private function registerHardcodedDefaultTemplate(): void
    {
        // Default template with a simple responsive design (fallback)
        $this->templates['default'] = [
            'header' => '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                    <style>
                        @media only screen and (max-width: 620px) {
                            table.body {
                                width: 100%;
                                min-width: 320px;
                            }
                        }
                    </style>
                </head>
                <body style="font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                    <table border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; width: 100%; max-width: 600px; margin: 0 auto;">
                        <tr>
                            <td style="font-family: sans-serif; font-size: 14px; vertical-align: top; padding: 24px;">
                                <div style="background: #ffffff; border-radius: 3px; padding: 20px; border: 1px solid #e9e9e9;">
                                    <div style="text-align: center; margin-bottom: 25px;">
                                        <h1 style="color: #333333; font-size: 20px; font-weight: 400; margin: 0;">{{subject}}</h1>
                                    </div>
            ',
            'body' => '
                                    <div style="color: #333333; font-size: 16px; line-height: 1.6em;">
                                        <p>Hello,</p>
                                        <p>You have received a notification.</p>
                                        <p>{{message}}</p>
                                    </div>
            ',
            'footer' => '
                                    <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #e9e9e9; color: #999999; font-size: 12px; text-align: center;">
                                        <p>This is an automated message from {{app_name}}. Please do not reply to this email.</p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </body>
                </html>
            '
        ];
        
        // Alert template for notifications that require attention
        $this->templates['alert'] = [
            'header' => $this->templates['default']['header'],
            'body' => '
                                    <div style="color: #333333; font-size: 16px; line-height: 1.6em;">
                                        <p>Hello,</p>
                                        <p>⚠️ <strong>Important Alert:</strong> {{message}}</p>
                                        <div style="background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; margin: 15px 0; border-radius: 3px;">
                                            <p style="margin: 0;"><strong>Details:</strong> {{details}}</p>
                                        </div>
                                        <p>Please take action immediately.</p>
                                    </div>
            ',
            'footer' => $this->templates['default']['footer']
        ];
        
        // Welcome template for new user registrations
        $this->templates['welcome'] = [
            'header' => $this->templates['default']['header'],
            'body' => '
                                    <div style="color: #333333; font-size: 16px; line-height: 1.6em;">
                                        <p>Hello {{name}},</p>
                                        <p>Welcome to {{app_name}}! We\'re excited to have you join us.</p>
                                        <p>{{message}}</p>
                                        <div style="text-align: center; margin: 30px 0;">
                                            <a href="{{action_url}}" style="background-color: #3490dc; border-radius: 3px; color: #ffffff; display: inline-block; font-size: 16px; font-weight: 400; line-height: 1.4; padding: 12px 24px; text-decoration: none; text-align: center;">
                                                Get Started
                                            </a>
                                        </div>
                                        <p>If you have any questions, feel free to contact our support team.</p>
                                    </div>
            ',
            'footer' => $this->templates['default']['footer']
        ];
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