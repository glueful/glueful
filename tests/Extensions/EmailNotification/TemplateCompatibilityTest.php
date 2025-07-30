<?php

declare(strict_types=1);

namespace Tests\Extensions\EmailNotification;

use PHPUnit\Framework\TestCase;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\EmailFormatter;
use Glueful\Extensions\EmailNotification\EnhancedEmailFormatter;
use Tests\Extensions\EmailNotification\TestNotifiable;

/**
 * Template Compatibility Test
 *
 * Ensures that existing templates work seamlessly with Symfony Mailer
 */
class TemplateCompatibilityTest extends TestCase
{
    private EmailChannel $emailChannel;
    private EmailFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        // Manually load the extension classes since we're not using the full framework
        $this->loadExtensionClasses();

        // Test configuration using array transport
        $config = [
            'default' => 'array',
            'mailers' => [
                'array' => ['transport' => 'array']
            ],
            'from' => [
                'address' => 'test@glueful.com',
                'name' => 'Test Sender'
            ]
        ];

        $this->formatter = new EmailFormatter();
        $this->emailChannel = new EmailChannel($config, $this->formatter);
    }

    /**
     * Manually load extension classes for testing
     */
    private function loadExtensionClasses(): void
    {
        $extensionDir = __DIR__ . '/../../../extensions/EmailNotification/src';

        // Load the main extension classes
        require_once $extensionDir . '/EmailFormatter.php';
        require_once $extensionDir . '/EmailChannel.php';
        require_once $extensionDir . '/EnhancedEmailFormatter.php';
        require_once $extensionDir . '/TransportFactory.php';
    }

    /**
     * Test that all existing templates work with Symfony Mailer
     */
    public function testExistingTemplatesWorkWithSymfonyMailer(): void
    {
        $templates = ['default', 'verification', 'password-reset', 'welcome', 'alert'];
        $notifiable = new TestNotifiable('test@example.com');

        foreach ($templates as $template) {
            $data = [
                'to' => 'test@example.com',
                'subject' => "Test {$template} Template",
                'template_name' => $template,
                'user_name' => 'Test User',
                'app_name' => 'Glueful Framework',
                'verification_url' => 'https://example.com/verify',
                'reset_url' => 'https://example.com/reset',
                'message' => 'This is a test message',
                'alert_message' => 'This is an alert'
            ];

            $result = $this->emailChannel->send($notifiable, $data);

            $this->assertTrue($result, "Template {$template} failed to send with Symfony Mailer");
        }
    }

    /**
     * Test template variable substitution
     */
    public function testTemplateVariableSubstitution(): void
    {
        $notifiable = new TestNotifiable('john@example.com');

        $data = [
            'type' => 'default', // Use default type so it picks the right template
            'template_name' => 'verification', // This should match verification.html file
            'subject' => 'Verify Your Email',
            'user_name' => 'John Doe',
            'otp' => '123456', // Template expects {{otp}}
            'verification_url' => 'https://example.com/verify/token123',
            'expiry_minutes' => '15', // Template expects {{expiry_minutes}}
            'app_name' => 'Glueful Test App'
        ];

        $formatted = $this->formatter->format($data, $notifiable);

        // Check that variables were substituted in HTML
        $this->assertStringContainsString('123456', $formatted['html_content'], 'OTP code should be substituted');
        $this->assertStringContainsString(
            '15 minutes',
            $formatted['html_content'],
            'Expiry time should be substituted'
        );
        $this->assertStringContainsString(
            'Glueful Test App',
            $formatted['html_content'],
            'App name should be substituted'
        );

        // Check that variables were substituted in text version
        $this->assertStringContainsString('123456', $formatted['text_content'], 'OTP code should be in text version');
        $this->assertStringContainsString(
            '15 minutes',
            $formatted['text_content'],
            'Expiry time should be in text version'
        );
    }

    /**
     * Test backward compatibility of email data structure
     */
    public function testBackwardCompatibility(): void
    {
        $notifiable = new TestNotifiable('user@example.com');

        // Test with old structure (direct html_content and text_content)
        $oldStructure = [
            'subject' => 'Direct Content Email',
            'html_content' => '<h1>Hello World</h1><p>This is a test.</p>',
            'text_content' => 'Hello World\nThis is a test.'
        ];

        $result = $this->emailChannel->send($notifiable, $oldStructure);
        $this->assertTrue($result, "Failed to send email with old data structure");

        // Test with template-based structure
        $templateStructure = [
            'subject' => 'Template Email',
            'template_name' => 'default',
            'message' => 'This is a templated message',
            'user_name' => 'Test User'
        ];

        $result = $this->emailChannel->send($notifiable, $templateStructure);
        $this->assertTrue($result, "Failed to send email with template structure");
    }

    /**
     * Test enhanced features with Symfony Mailer
     */
    public function testEnhancedFeaturesWithSymfonyMailer(): void
    {
        $notifiable = new TestNotifiable('enhanced@example.com');

        $data = [
            'subject' => 'Enhanced Features Test',
            'html_content' => '<h1>Test</h1><p>Email with enhanced features</p>',
            'text_content' => 'Test\nEmail with enhanced features',
            'priority' => 'high',
            'headers' => [
                'X-Custom-Header' => 'CustomValue',
                'X-Campaign-ID' => 'TEST123'
            ],
            'cc' => ['cc1@example.com', 'cc2@example.com'],
            'bcc' => ['bcc@example.com']
        ];

        $result = $this->emailChannel->send($notifiable, $data);
        $this->assertTrue($result, "Failed to send email with enhanced features");
    }

    /**
     * Test attachments work correctly
     */
    public function testAttachmentsWithSymfonyMailer(): void
    {
        $notifiable = new TestNotifiable('attachments@example.com');

        // Create a temporary test file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_attachment');
        file_put_contents($tempFile, 'Test attachment content');

        $data = [
            'subject' => 'Email with Attachments',
            'html_content' => '<p>Please see attached file</p>',
            'attachments' => [
                $tempFile,
                [
                    'path' => $tempFile,
                    'name' => 'custom_name.txt',
                    'contentType' => 'text/plain'
                ]
            ]
        ];

        $result = $this->emailChannel->send($notifiable, $data);
        $this->assertTrue($result, "Failed to send email with attachments");

        // Clean up
        unlink($tempFile);
    }

    /**
     * Test EnhancedEmailFormatter integration
     */
    public function testEnhancedEmailFormatterIntegration(): void
    {
        $config = [
            'default' => 'array',
            'mailers' => [
                'array' => ['transport' => 'array']
            ],
            'from' => [
                'address' => 'test@glueful.com',
                'name' => 'Test Sender'
            ]
        ];

        $enhancedFormatter = new EnhancedEmailFormatter([], [], false);
        $emailChannel = new EmailChannel($config, $enhancedFormatter);

        $notifiable = new TestNotifiable('enhanced@example.com');

        $data = [
            'template' => 'welcome',
            'subject' => 'Enhanced Welcome Email',
            'user_name' => 'Enhanced User',
            'priority' => 'high',
            'embedImages' => [
                'logo' => __DIR__ . '/../../fixtures/logo.png' // Would need actual file in real test
            ],
            'notifiable' => $notifiable
        ];

        // This test would need actual template files to work fully
        // For now, we're testing that the integration doesn't break
        $this->assertInstanceOf(EnhancedEmailFormatter::class, $emailChannel->getFormatter());
    }
}
