<?php

require_once(INCLUDE_DIR.'class.signal.php');
require_once(INCLUDE_DIR.'class.plugin.php');
require_once('config.php');

class SlackPlugin extends Plugin {
    var $config_class = "SlackPluginConfig";
	
	function bootstrap() {		
		Signal::connect('model.created', array($this, 'onTicketCreated'), 'Ticket');		
    }
	
	function onTicketCreated($ticket){		
		try {			
			global $ost;		
			$payload = array(
						'attachments' =>
							array (
								array (	
									'pretext' => "New Ticket <" . $ost->getConfig()->getUrl() . "scp/tickets.php?id=" 
												. $ticket->getId() . "|#" . $ticket->getNumber() . "> created",
									'fallback' => "New Ticket <" . $ost->getConfig()->getUrl() . "scp/tickets.php?id=" 
												. $ticket->getId() . "|#" . $ticket->getNumber() . "> created",
									'color' => "#D00000",
									'fields' => 
									array(
										array (
											'title' => $ticket->getSubject(),
											'value' => "created by " . $ticket->getName() . "(" . $ticket->getEmail() 
														. ") in " . $ticket->getDeptName() . "(Department) via " 
														. $ticket->getSource(),
											'short' => False,
										),											
									),
								),
							),
						);
						
			$data_string = utf8_encode(json_encode($payload));
			$url = $this->getConfig()->get('slack-webhook-url');
			 
			$ch = curl_init($url);                                                                      			
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
				'Content-Type: application/json',                                                                                
				'Content-Length: ' . strlen($data_string))                                                                       
			);	
			
			if(curl_exec($ch) === false){
				throw new Exception($url . ' - ' . curl_error($ch));
			}
			else{
				$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if($statusCode != '200'){
					throw new Exception($url . ' Http code: ' . $statusCode);					
				}				
			}
			curl_close($ch);
		}
		catch(Exception $e) {
			error_log('Error posting to Slack. '. $e->getMessage());
		}
	}	
}