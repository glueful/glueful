<?php
	class Hash extends Extensions{
		public static function process($getParams, $postParams){
			if(isset($getParams['type'])){
 
 				switch ($getParams['type']) {
 					case 'md5':
 						$hash = md5($getParams['string']);
 						break;
 					default:
 						return array("ERR" => 'Invalid hash type');
 						break;
 				}

				return array("hash" => $hash);
			}else{
				return array("ERR" => 'Missing hash type');
			}
		}	
	}
?>
