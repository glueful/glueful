<?php
class SMS extends Extensions{

	public static function process($getParams, $postParams)
	{
		if(!isset($getParams['number']) || !isset($getParams['message']) || !isset($getParams['gateway']))
		{
      throw new Exception("Send failed, provide number, message and gateway");
		}

		$number=trim($getParams['number']);
		$message=trim($getParams['message']);
		$gateway=trim($getParams['gateway']);
		$from=(trim($getParams['from'])) ? trim($getParams['from']) : 'LibertyGold';

		$numbers=explode(',', $number);
		$numberList=array();
		foreach ($numbers as $number) 
		{
			$numberList[]=self::formatNumber($number);
		}
		switch ($getParams['gateway'])
		{
			case 'infobip':
				return self::infobip($numberList,$message,$from);
				break;
			
			default:
				# code...
				break;
		}
	}

	/**
	 * formatNumber for internationally standardized to a fifteen digit maximum length. Phone numbers are usually prefixed with + 
	 * @param string $number the number to be formatted
	 */ 
	private static function formatNumber($number)
	{
		if(!$number)
		{
			throw new Exception("phone number not set", 404);
		}

		if(substr($number, 0, 1) == "+")
		{
      $number = $number;
    }
    else
    {
      $number = '+233'.ltrim($number, '0');
    }
    return $number;
	}

	/**
	 * Infobip sms sending
	 * @param string|array $numbers number to receive message
	 * @param string $message message to be sent
	 * @param string $from senders identity
	 */
	private static function infobip($numbers, $message, $from)
	{
		$username='WebAd123';
		$password='Password1';
		$auth=base64_encode("$username:$password");
		$url='https://api.infobip.com/sms/1/text/multi';
		$fields['messages']=array(
		   array('from'=>$from,'to'=>$numbers,'text'=>$message)
		);
		$fieldsJson=json_encode($fields);
		$headers[]="Authorization: Basic $auth";
		$headers[]='Content-Type: application/json';

		$ch = curl_init();
		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_POSTFIELDS, $fieldsJson);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		//set headers
		if($headers)
		{
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		//execute post
		$result = curl_exec($ch);
		//close connection
		curl_close($ch);
		
		$result=json_decode($result,true);
		return $result;
	}

	private static function LogActivities($message)
	{
		if($message)
		{
			
		}
	}

}
?>