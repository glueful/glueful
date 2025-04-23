<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions;
use PHPMailer\PHPMailer\{PHPMailer, SMTP, Exception as PHPMailerException};

final class AdvancedEmail extends Extensions
{
    private const REQUIRED_FIELDS = ['message', 'from', 'to', 'subject'];
    private static string $template = '{{content}}';
    private static string $footer = '';

    public static function process(array $getParams, array $postParams): array
    {
        try {
            self::validateParams($postParams);
            $attachment = $postParams['attachment'] ?? null;
            
            $result = self::sendEmail($postParams, $attachment);
            
            return self::respond([
                'success' => true,
                'message' => 'Email sent successfully',
                'details' => $result
            ]);

        } catch (PHPMailerException $e) {
            return self::error("Email sending failed: {$e->getMessage()}", 500);
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            error_log("Email error: " . $e->getMessage());
            return self::error('Failed to send email', 500);
        }
    }

    private static function validateParams(array $params): void
    {
        $missing = array_diff(self::REQUIRED_FIELDS, array_keys($params));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Missing required fields: ' . implode(', ', $missing)
            );
        }
    }

    private static function sendEmail(array $data, ?array $attachment): array
    {
        // Use the properly imported PHPMailer classes
        // No need for require_once as we already imported the classes at the top
        
        $mailer = self::configureMailer($data);
        
        // Set email content
        $mailer->Subject = $data['subject'];
        $mailer->Body = self::wrapTemplate($data['message']);
        $mailer->isHTML(true);

        // Add CC and BCC recipients
        self::addRecipients($mailer, $data);
        
        // Handle attachments
        if (isset($data['type']) && $data['type'] === 'attachment' && $attachment) {
            self::addAttachment($mailer, $attachment);
        }

        // Send email
        if (!$mailer->send()) {
            throw new PHPMailerException($mailer->ErrorInfo);
        }

        return [
            'recipients' => count($mailer->getAllRecipientAddresses()),
            'has_attachments' => !empty($attachment),
            'timestamp' => time()
        ];
    }

    private static function configureMailer(array $data): PHPMailer
    {
        $mailer = new PHPMailer(true);
        
        // Extract sender information
        [$fromName, $fromEmail] = self::parseSenderInfo($data['from']);
        
        // Configure SMTP
        $mailer->isSMTP();
        $mailer->Host = config('mail.smtp.host');
        $mailer->SMTPAuth = config('mail.smtp.auth', true);
        $mailer->Username = config('mail.smtp.username');
        $mailer->Password = config('mail.smtp.password');
        $mailer->SMTPSecure = config('mail.smtp.secure');
        $mailer->Port = config('mail.smtp.port');
        
        // Set sender
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addReplyTo($fromEmail, $fromName);
        
        // Add primary recipient
        $mailer->addAddress(trim($data['to']));
        
        return $mailer;
    }

    private static function parseSenderInfo(string $from): array
    {
        if (preg_match('/^(.+?)\s*<(.+?)>$/', trim($from), $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }
        return ['', $from];
    }

    private static function addRecipients(PHPMailer $mailer, array $data): void
    {
        if (!empty($data['cc'])) {
            foreach ((array)$data['cc'] as $cc) {
                $mailer->addCC(trim($cc));
            }
        }

        if (!empty($data['bcc'])) {
            foreach ((array)$data['bcc'] as $bcc) {
                $mailer->addBCC(trim($bcc));
            }
        }
    }

    private static function addAttachment(PHPMailer $mailer, array $attachment): void
    {
        if (empty($attachment['location']) || empty($attachment['name'])) {
            throw new \InvalidArgumentException('Invalid attachment data');
        }

        $content = @file_get_contents($attachment['location']);
        if ($content === false) {
            throw new \RuntimeException("Could not read attachment: {$attachment['location']}");
        }

        $mailer->addStringAttachment(
            $content,
            $attachment['name'],
            PHPMailer::ENCODING_BASE64,
            $attachment['mime_type'] ?? 'application/pdf'
        );
    }

    public static function setTemplate(string $template): void
    {
        self::$template = $template;
    }

    public static function setFooter(string $footer): void
    {
        self::$footer = $footer;
    }

    private static function wrapTemplate(string $content): string
    {
        return str_replace('{{content}}', $content, self::$template) . self::$footer;
    }

    /**
     * Format a successful response
     *
     * @param array $data Response data
     * @return array Formatted response
     */
    private static function respond(array $data): array
    {
        return array_merge([
            'success' => true,
            'code' => 200
        ], $data);
    }

    /**
     * Format an error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return array Formatted error response
     */
    private static function error(string $message, int $code = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => $code
        ];
    }

    public static function push(array $data): array
    {
        try {
            // Ensure required fields are present
            self::validateParams($data);
            
            // Process attachments if provided
            $attachment = $data['attachment'] ?? null;
            
            // Use the main sendEmail method for consistency
            $result = self::sendEmail($data, $attachment);
            
            return [
                'success' => true,
                'message' => 'Email sent successfully',
                'details' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e instanceof PHPMailerException ? 500 : 400
            ];
        }
    }
}
