<?php

declare(strict_types=1);

use Mapi\Api\Library\Extensions;
use Mapi\Api\API;
use PHPMailer\PHPMailer\{PHPMailer, Exception as PHPMailerException};

class Email extends Extensions
{
    public static string $template = "{{content}}";
    public static string $footer = "";
    private static bool $useSmtp = false;
    private static array $smtpConfig = [];

    public static function process(array $getParams, array $postParams): array
    {
        try {
            self::validateParams($postParams);

            if (defined('FORCE_ADVANCED_EMAIL') && FORCE_ADVANCED_EMAIL === TRUE) {
                return self::handleAdvancedEmail($getParams, $postParams);
            }

            if (self::$useSmtp) {
                return self::sendViaSmtp($postParams);
            }

            if (@$postParams["type"] == "attachment") {
                return self::handleAttachmentEmail($postParams);
            }

            return self::handleBasicEmail($postParams);
        } catch (\Exception $e) {
            return ["ERR" => $e->getMessage()];
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
            $mailer->Host = self::$smtpConfig['host'] ?? 'localhost';
            $mailer->SMTPAuth = self::$smtpConfig['auth'] ?? false;
            $mailer->Username = self::$smtpConfig['username'] ?? '';
            $mailer->Password = self::$smtpConfig['password'] ?? '';
            $mailer->SMTPSecure = self::$smtpConfig['secure'] ?? '';
            $mailer->Port = self::$smtpConfig['port'] ?? 25;

            // Set email content
            $mailer->setFrom($data['from']);
            $mailer->addAddress($data['to']);
            if (!empty($data['cc'])) $mailer->addCC($data['cc']);
            if (!empty($data['bcc'])) $mailer->addBCC($data['bcc']);
            
            $mailer->Subject = $data['subject'];
            $mailer->Body = self::wrapTemplate($data['message']);
            $mailer->isHTML(true);

            // Handle attachments if present
            if (isset($data['type']) && $data['type'] === 'attachment' && !empty($data['attachment'])) {
                foreach ($data['attachment'] as $attachment) {
                    $mailer->addAttachment(
                        $attachment['location'],
                        $attachment['name']
                    );
                }
            }

            return $mailer->send() ? ['SUCCESS' => true] : ['ERR' => 'Could not send email'];
        } catch (PHPMailerException $e) {
            return ['ERR' => "Mail error: {$e->getMessage()}"];
        }
    }

    private static function handleAdvancedEmail(array $getParams, array $postParams): array
    {
        @include_once("AdvancedEmail.php");
        AdvancedEmail::setTemplate(self::$template);
        AdvancedEmail::setFooter(self::$footer);

        return API::processRequest(
            [
                "f" => "AdvancedEmail:push",
                "token" => $getParams['token']
            ],
            [
                "from" => $postParams['from'],
                "to" => $postParams['to'],
                "cc" => $postParams['cc'] ?? null,
                "bcc" => $postParams['bcc'] ?? null,
                "subject" => $postParams['subject'],
                "message" => $postParams['message']
            ]
        );
    }

    private static function handleAttachmentEmail(array $postParams): array
    {
        return self::sendAttachment($postParams, $postParams["attachment"]) 
            ? ["SUCCESS" => true] 
            : ["ERR" => "Could not send email"];
    }

    private static function handleBasicEmail(array $postParams): array
    {
        return self::send($postParams) 
            ? ["SUCCESS" => true] 
            : ["ERR" => "Could not send email"];
    }

    private static function send(array $data): bool
    {
        $headers  = "MIME-Version: 1.0\r\n"; 
        $headers .= "From: ".$data["from"]."\r\n"; 
        if($data["cc"]){
            $headers .= "Cc: ".$data["cc"]."\r\n";
        }
        if($data["bcc"]){
            $headers .= "Bcc: ".$data["bcc"]."\r\n";
        }
        $headers .= "Reply-To: ".$data["from"]."\r\n";
        $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";

        $send = @mail($data["to"], $data["subject"], self::wrapTemplate($data["message"]), $headers);
        return $send ? true : false;
    }

    private static function sendAttachment(array $data, array $attachments): bool
    {
        $file_type = 'application/pdf';
        
        $boundary = md5(serialize($data)); 
        
        //header
        $headers = "MIME-Version: 1.0\r\n"; 
        $headers .= "From:". $data["from"] . "\r\n"; 
        if($data["cc"]){
            $headers .= "Cc: ".$data["cc"]."\r\n";
        }
        if($data["bcc"]){
            $headers .= "Bcc: ".$data["bcc"]."\r\n";
        }
        $headers .= "Reply-To: ". $data["from"] . "\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary = ".$boundary."\r\n\r\n";
        
        //html message
        $body = "--".$boundary."\r\n";
        $body .= "Content-Type: text/html; charset=ISO-8859-1\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n"; 
        $body .= $data["message"]; 

        $body .= "<!-- Content-Type: text/plain; charset=ISO-8859-1 -->\r\n";

        foreach ($attachments as $key => $attachment) {
            $encoded_content = chunk_split(base64_encode(file_get_contents($attachment["location"])));

            //attachment
            $body .= "--".$boundary."\r\n";
            $body .="Content-Type: ".$file_type."; name=\"".$attachment["name"]."\"\r\n";
            $body .="Content-Disposition: attachment; filename=\"".$attachment["name"]."\"\r\n";
            $body .="Content-Transfer-Encoding: base64\r\n";
            $body .="X-Attachment-Id: ".rand(1000,99999)."\r\n\r\n"; 
            $body .= $encoded_content; 
        }

        $send = @mail($data["to"], $data["subject"], $body, $headers);
        return $send ? true : false;
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