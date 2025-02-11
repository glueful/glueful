<?php
	class Pin extends Extensions{
		public static function process($getParams, $postParams){
			if(isset($postParams['phone_number'])){
				// Check for conflicting mobiles
				$userCount = API::processRequest(
					array(
						'f' => 'users:count',
						'token' => $getParams['token'],
						'dbres' => $getParams['dbres'],
						'phone_number' => $postParams["phone_number"],
						'status:in' => 'active,pending'
						)
				);

				// process PIN creation for non conflicting phone numbers only
				if($userCount[0]['count'] == 1){
					$pin = rand(1000,9999);
					
					$users = API::processRequest(
						array(
							'f' => 'users:list',
							'token' => $getParams['token'],
							'dbres' => $getParams['dbres'],
							'phone_number' => $postParams["phone_number"],
							'status:in' => 'active,pending'
							)
					);

					$pinGenerated = API::processRequest(
						array(
							'f' => 'users:save',
							'token' => $getParams['token'],
							'dbres' => $getParams['dbres']
							),
						array(
							'pin' => md5($pin),
							'id' => $users[0]['id']
							)
					);

					$smsResponse = API::processRequest(
						array(
							"f" => "SMS:push", 
							"token" => $getParams["token"], 
							"dbres" => $getParams['dbres'], 
							"number" => $postParams["phone_number"], 
							"gateway" => "infobip", 
							"from" => SMS_SENDER, 
							"message" => 'Your login pin code is '.$pin
							)
					);
 

					return array("id" => $users[0]['id']);
				}
				elseif($userCount[0]['count'] > 1){
					return array("ERR" => 'Conflicting Mobiles: Unable to generate PIN for '.$postParams['phone_number'].'. Contact support.');
				}
				return array("ERR" => 'Unknown number '.$postParams['phone_number']);
			}
			return array("ERR" => 'Missing parameter');
		}	
	}
?>
