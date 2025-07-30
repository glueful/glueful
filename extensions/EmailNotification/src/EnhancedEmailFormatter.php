<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Enhanced Email Formatter with Twig Support
 *
 * Extends the base EmailFormatter to add optional Twig template support
 * and enhanced Symfony Mailer features while maintaining backward compatibility
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EnhancedEmailFormatter extends EmailFormatter
{
    /**
     * @var Environment|null Twig environment for template rendering
     */
    private ?Environment $twig = null;

    /**
     * @var bool Whether to use Twig for template rendering
     */
    private bool $useTwig = false;

    /**
     * EnhancedEmailFormatter constructor
     *
     * @param array $templates Custom templates
     * @param array $options Formatting options
     * @param bool $enableTwig Whether to enable Twig support
     */
    public function __construct(array $templates = [], array $options = [], bool $enableTwig = false)
    {
        parent::__construct($templates, $options);

        if ($enableTwig) {
            $this->initializeTwig();
        }
    }

    /**
     * Initialize Twig environment
     */
    private function initializeTwig(): void
    {
        $templatesPath = $this->defaultOptions['templates_path'] ?? __DIR__ . '/Templates/twig';

        if (!is_dir($templatesPath)) {
            // Create twig templates directory if it doesn't exist
            mkdir($templatesPath, 0755, true);
        }

        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache' => false, // Disable cache for development
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        $this->useTwig = true;
    }

    /**
     * Format with Twig template
     *
     * @param string $template Template name (without .twig extension)
     * @param array $data Template data
     * @return Email Symfony Email object
     */
    public function formatWithTwig(string $template, array $data): Email
    {
        if (!$this->twig) {
            throw new \RuntimeException('Twig is not initialized. Enable it in constructor.');
        }

        // Render HTML version
        $html = $this->twig->render($template . '.html.twig', $data);

        // Try to render text version if template exists
        $text = '';
        try {
            $text = $this->twig->render($template . '.text.twig', $data);
        } catch (\Exception $e) {
            // If text template doesn't exist, convert HTML to text
            $text = $this->htmlToText($html);
        }

        $email = new Email();
        $email->html($html);
        $email->text($text);

        return $email;
    }

    /**
     * Build an enhanced email with Symfony Mailer features
     *
     * @param string $templateName Template name
     * @param array $data Email data
     * @return Email Configured Email object
     */
    public function buildEmailFromTemplate(string $templateName, array $data): Email
    {
        // Use parent formatter to get HTML and text content
        $formatted = $this->format($data, $data['notifiable'] ?? new DummyNotifiable());

        $email = new Email();
        $email->subject($formatted['subject'] ?? '');
        $email->html($formatted['html_content'] ?? '');
        $email->text($formatted['text_content'] ?? '');

        // Enhanced features with Symfony Mailer

        // Set priority if specified
        if (isset($data['priority'])) {
            $priority = match ($data['priority']) {
                'highest' => Email::PRIORITY_HIGHEST,
                'high' => Email::PRIORITY_HIGH,
                'normal' => Email::PRIORITY_NORMAL,
                'low' => Email::PRIORITY_LOW,
                'lowest' => Email::PRIORITY_LOWEST,
                default => Email::PRIORITY_NORMAL,
            };
            $email->priority($priority);
        }

        // Embed images if specified
        if (isset($data['embedImages']) && is_array($data['embedImages'])) {
            foreach ($data['embedImages'] as $cid => $path) {
                if (file_exists($path)) {
                    $email->embedFromPath($path, $cid);
                }
            }
        }

        // Add custom headers if specified
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $name => $value) {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }

        // Set return path if specified
        if (isset($data['returnPath'])) {
            $email->returnPath($data['returnPath']);
        }

        // Add attachments
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if (is_string($attachment) && file_exists($attachment)) {
                    $email->attachFromPath($attachment);
                } elseif (is_array($attachment) && isset($attachment['path'])) {
                    $email->attachFromPath(
                        $attachment['path'],
                        $attachment['name'] ?? null,
                        $attachment['contentType'] ?? null
                    );
                }
            }
        }

        return $email;
    }

    /**
     * Enable or disable Twig support
     *
     * @param bool $enable Whether to enable Twig
     * @return self
     */
    public function setUseTwig(bool $enable): self
    {
        if ($enable && !$this->twig) {
            $this->initializeTwig();
        }

        $this->useTwig = $enable;
        return $this;
    }

    /**
     * Get Twig environment
     *
     * @return Environment|null
     */
    public function getTwig(): ?Environment
    {
        return $this->twig;
    }
}
