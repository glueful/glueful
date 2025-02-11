<?php
class AdvancedEmail extends Extensions
{
    public static $template = "{{content}}";
    public static $footer   = "";

    public static function process($getParams, $postParams)
    {
        if (!isset($postParams['message']) || !isset($postParams['from']) || !isset($postParams['to']) || !isset($postParams['subject']))
        {
            throw new Exception("Send failed, missing parameters", 404);
        }

        $rd = self::send($postParams,$postParams["attachment"]);
        if ($rd['status'])
        {
            return array("SUCCESS" => true);
        }
        else
        {
            return array("ERR" => "Could not send email | " . $rd['msg'] . ' | ' . SMTP_PORT . SMTP_HOST . SMTP_USERNAME);
        }
    }

    private static function send($data,$attachment)
    {
        require_once API_EXTENSIONS_DIRECTORY . 'push/phpmailer/PHPMailerAutoload.php';
        $mail                         = new PHPMailer;
        $matches                      = array();
        $from_email                   = $from_name                   = '';
        $from_email                   = $data["from"];
        $file_type                    = 'application/pdf';
        list($from_name, $from_email) = explode(' <', trim($data["from"], '> '));
        $from_email                   = trim($from_email);
        $from_name                   = trim($from_name);
        $mail->isSMTP(); // Set mailer to use SMTP
        $mail->Host       = SMTP_HOST; // Specify main and backup SMTP servers
        $mail->SMTPAuth   = true; // Enable SMTP authentication
        $mail->Username   = SMTP_USERNAME; // SMTP username
        $mail->Password   = SMTP_PASSWORD; // SMTP password
        $mail->SMTPSecure = SMTP_SECURE; // Enable TLS encryption, `ssl` also accepted
        $mail->Port       = SMTP_PORT; // TCP port to connect to

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress(trim($data['to'])); // Add a recipient
        $mail->addReplyTo($from_email, $from_name);

        $mail->isHTML(true); // Set email format to HTML

        $mail->Subject = $data["subject"];
        $mail->Body    = self::wrapTemplate($data["message"]);
        //$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

        if ($data["cc"])
        {
            $mail->addCC($data["cc"]);
        }
        if ($data["bcc"])
        {
            $mail->addBCC($data["bcc"]);
        }
        if (@$data["type"] == "attachment")
        {
            $binary_content = file_get_contents($attachment["location"]);

            if ($binary_content === false)
            {
                throw new Exception("Could not fetch remote content from: '$url'");
            }

            $mail->addStringAttachment($binary_content, $attachment["name"], 'base64', $file_type);
        }

        if (!$mail->send())
        {
            $rdata['status'] = false;
            $rdata['msg']    = 'Mailer Error: ' . $mail->ErrorInfo;
        }
        else
        {
            $rdata['status'] = true;
            $rdata['msg']    = 'Message has been sent';
        }
        return $rdata;
    }

    public static function setTemplate($template)
    {
        self::$template = $template;
    }

    private static function wrapTemplate($content)
    {
        return str_replace("{{content}}", $content, self::$template) . self::$footer;
    }

    public static function setFooter($footer)
    {
        self::$footer = $footer;
    }
}
