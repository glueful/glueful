<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions;
use Glueful\API;
use Glueful\Extensions\AdvancedEmail;
use PHPMailer\PHPMailer\{PHPMailer, Exception as PHPMailerException};

class Email extends Extensions
{
    public static string $template = "{{content}}";
    public static string $footer = "";
    private static bool $useSmtp;
    private static array $smtpConfig = [];

    public static function __constructStatic()
    {
        self::$useSmtp = config('mail.smtp.useSmtp', false);
    }

    public static function process(array $getParams, array $postParams): array
    {
        try {
            self::validateParams($postParams);

            if (defined('FORCE_ADVANCED_EMAIL') && config('mail.force_advanced')=== TRUE) {
                return self::handleAdvancedEmail($getParams, $postParams);
            }

            if (self::$useSmtp) {
                return self::sendViaSmtp($postParams);
            }

            // Use PHPMailer consistently instead of native mail() function
            return self::sendViaPHPMailer($postParams);
        } catch (\Exception $e) {
            return [
                "success" => false,
                "error" => $e->getMessage(),
                "code" => $e instanceof PHPMailerException ? 500 : 400
            ];
        }
    }

    public static function configureSmtp(array $config): void
    {
        self::$useSmtp = true;
        self::$smtpConfig = $config;
    }

    private static function validateParams(array $params): void
    {
        $required = ['message', 'from', 'to', 'subject'];
        $missing = array_diff($required, array_keys($params));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Missing required fields: ' . implode(', ', $missing)
            );
        }
    }

    private static function sendViaSmtp(array $data): array
    {
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            
            // Configure SMTP
            $mailer->Host = self::$smtpConfig['host'] ?? config('mail.smtp.host', 'localhost');
            $mailer->SMTPAuth = self::$smtpConfig['auth'] ?? config('mail.smtp.auth', false);
            $mailer->Username = self::$smtpConfig['username'] ??  config('mail.smtp.username', '');
            $mailer->Password = self::$smtpConfig['password'] ??  config('mail.smtp.password', '');
            $mailer->SMTPSecure = self::$smtpConfig['secure'] ?? config('mail.smtp.secure', 'tls');
            $mailer->Port = self::$smtpConfig['port'] ?? (int) config('mail.smtp.port', 587);

            return self::finalizeMailer($mailer, $data);
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => "Mail error: {$e->getMessage()}", 'code' => 500];
        }
    }

    private static function sendViaPHPMailer(array $data): array
    {
        try {
            $mailer = new PHPMailer(true);
            
            // Use sendmail transport by default
            $mailer->isSendmail();
            
            return self::finalizeMailer($mailer, $data);
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => "Mail error: {$e->getMessage()}", 'code' => 500];
        }
    }
    
    private static function finalizeMailer(PHPMailer $mailer, array $data): array
    {
        // Parse sender information
        if (preg_match('/^(.+?)\s*<(.+?)>$/', trim($data['from']), $matches)) {
            $fromName = trim($matches[1]);
            $fromEmail = trim($matches[2]);
        } else {
            $fromName = '';
            $fromEmail = $data['from'];
        }
        
        // Set email content
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addReplyTo($fromEmail, $fromName);
        $mailer->addAddress($data['to']);
        
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
            
        $mailer->Subject = $data['subject'];
        $mailer->Body = self::wrapTemplate($data['message']);
        $mailer->isHTML(true);

        // Handle attachments if present
        if (isset($data['type']) && $data['type'] === 'attachment' && !empty($data['attachment'])) {
            foreach ($data['attachment'] as $attachment) {
                if (file_exists($attachment['location'])) {
                    $mailer->addAttachment(
                        $attachment['location'],
                        $attachment['name']
                    );
                }
            }
        }

        // Send email
        if (!$mailer->send()) {
            throw new PHPMailerException($mailer->ErrorInfo);
        }

        return [
            'success' => true, 
            'message' => 'Email sent successfully',
            'recipients' => count($mailer->getAllRecipientAddresses())
        ];
    }

    private static function handleAdvancedEmail(array $getParams, array $postParams): array
    {
        AdvancedEmail::setTemplate(self::$template);
        AdvancedEmail::setFooter(self::$footer);

        return API::processRequest(
            [
                "f" => "AdvancedEmail:push",
                "token" => $getParams['token'] ?? null
            ],
            [
                "from" => $postParams['from'],
                "to" => $postParams['to'],
                "cc" => $postParams['cc'] ?? null,
                "bcc" => $postParams['bcc'] ?? null,
                "subject" => $postParams['subject'],
                "message" => $postParams['message'],
                "attachment" => $postParams['attachment'] ?? null
            ]
        );
    }

    public static function setTemplate(string $template): void
    {
        self::$template = $template;
    }

    private static function wrapTemplate(string $content): string
    {
        return str_replace("{{content}}", $content, self::$template).self::$footer;
    }

    public static function setFooter(string $footer): void
    {
        self::$footer = $footer;
    }
}
?>