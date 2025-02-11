<?php
class Email extends Extensions{
	public static $template = "{{content}}";
	public static $footer = "";

	public static function process($getParams, $postParams){
		if(!isset($postParams['message']) || !isset($postParams['from']) || !isset($postParams['to']) || !isset($postParams['subject'])) {
      throw new Exception("Send failed, missing parameters", 404);
		}

		if(defined('FORCE_ADVANCED_EMAIL') && FORCE_ADVANCED_EMAIL === TRUE){
			@include_once("AdvancedEmail.php");
			AdvancedEmail::setTemplate(self::$template);
			AdvancedEmail::setFooter(self::$footer);			

			return API::processRequest(
        array(
          "f"=>"AdvancedEmail:push",
          "token"=>$getParams['token']
        ),
        array(
          "from"=>$postParams['from'],
          "to"=>$postParams['to'],
          "cc"=>$postParams['cc'],
          "bcc"=>$postParams['bcc'],
          "subject"=>$postParams['subject'],
          "message"=>$postParams['message']
        )
      );
		}

		if(@$postParams["type"] == "attachment"){
			$response = self::sendAttachment($postParams, $postParams["attachment"]);
			if($response == true){
				return array("SUCCESS" => true);
			}else{
				return array("ERR" => "Could not send email");
			}
		}

		if(!isset($postParams["type"]) && self::send($postParams)){
			return array("SUCCESS" => true);
		}else{
			return array("ERR" => "Could not send email");
		}
	}

	private static function send($data){
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
	  if($send){       
	    return true;
	  }else{
	    return false;
	  }
	}

	private static function sendAttachment($data, $attachments){
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
	  $body .= "--".$boundary."\r\n";
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
	  if($send){       
	    return true;
	  }else{
	    return false;
	  }
	}

	public static function setTemplate($template){
		self::$template = $template;
	}

	private static function wrapTemplate($content){
		return str_replace("{{content}}", $content, self::$template).self::$footer;
	}

	public static function setFooter($footer){
		self::$footer = $footer;
	}
}
?>