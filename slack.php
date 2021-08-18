<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class SlackPlugin extends Plugin {

    var $config_class = "SlackPluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        $updateTypes = $this->getConfig()->get('slack-update-types');
        
        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:
        if($updateTypes == 'both' || $updateTypes == 'newOnly' || empty($updateTypes)) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        }
        
        if($updateTypes == 'both' || $updateTypes == 'updatesOnly' || empty($updateTypes)) {
            Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
        }
        // Tasks? Signal::connect('task.created',array($this,'onTaskCreated'));
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        
        // if slack-update-types is "updatesOnly", then don't send this!
        if($this->getConfig()->get('slack-update-types') == 'updatesOnly') {return;}

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

        // Format the messages we'll send.
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
        $this->sendToSlack($ticket, $heading, $plaintext);
    }

    /**
     * What to do with an Updated Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        
        // if slack-update-types is "newOnly", then don't send this!
        if($this->getConfig()->get('slack-update-types') == 'newOnly') {return;}
        
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            return;
        }

        // Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            // Admin created ticket's won't work here.
            return;
        }

        // Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }
        // Convert any HTML in the message into text
        $plaintext = Format::html2text($entry->getBody()->getClean());

        // Format the messages we'll send
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("updated"));
        $this->sendToSlack($ticket, $heading, $plaintext, 'warning');
    }

    /**
     * A helper function that sends messages to slack endpoints. 
     * 
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendToSlack(Ticket $ticket, $heading, $body, $colour = 'good') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
		
		$webhooks = array( 
			"Support / IT-Engineer" => "WEBHOOK_URL_HERE",
			"Support / IT-NSE" => "WEBHOOK_URL_HERE",
			"Support / IT-Interns" => "WEBHOOK_URL_HERE",
			"Support / IT-Technicians" => "WEBHOOK_URL_HERE"
		);
		
		$tteam = $ticket->getDeptName();
		$ost->logError('Slack posting webhook notify!', "Trying to send ticket info to following team".$tteam, true);
		
		if(!array_key_exists($tteam, $webhooks)){
			$url = $this->getConfig()->get('slack-webhook-url');
		}else{
			$url = $webhooks[$tteam];
		}
       
        if (!$url) {
            $ost->logError('Slack Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig()->get('slack-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Slack notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        } else {
            error_log("$ticket_subject didn't trigger $regex_subject_ignore");
        }

        $heading = $this->format_text($heading);

        // Pull template from config, and use that. 
        $template          = $this->getConfig()->get('message-template');
        // Add our custom var
        $custom_vars       = [
            'slack_safe_message' => $this->format_text($body),
        ];
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

        // Build the payload with the formatted data:
        $payload['attachments'][0] = [
            'pretext'     => $heading,
            'fallback'    => $heading,
            'color'       => $colour,
            // 'author'      => $ticket->getOwner(),
            //  'author_link' => $cfg->getUrl() . 'scp/users.php?id=' . $ticket->getOwnerId(),
            // 'author_icon' => $this->get_gravatar($ticket->getEmail()),
            'title'       => $ticket->getSubject(),
            'title_link'  => $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
            'ts'          => time(),
            'footer'      => 'via osTicket Slack Plugin',
            'footer_icon' => 'https://platform.slack-edge.com/img/default_application_icon.png',
            'text'        => $formatted_message,
            'mrkdwn_in'   => ["text"]
        ];
        // Add a field for tasks if there are open ones
        if ($ticket->getNumOpenTasks()) {
            $payload['attachments'][0]['fields'][] = [
                'title' => __('Open Tasks'),
                'value' => $ticket->getNumOpenTasks(),
                'short' => TRUE,
            ];
        }
        // Change the colour to Fuschia if ticket is overdue
        if ($ticket->isOverdue()) {
            $payload['attachments'][0]['colour'] = '#ff00ff';
        }

        // Format the payload:
        $data_string = utf8_encode(json_encode($payload));

        try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );

            // Actually send the payload to slack:
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                    'Error sending to: ' . $url
                    . ' Http code: ' . $statusCode
                    . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            $ost->logError('Slack posting issue!', $e->getMessage(), true);
            error_log('Error posting to Slack. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */
    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
                    'id' => $entry->getThreadId()
                ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc.. 
        return Ticket::lookup(array(
                    'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the 
     * formatting rules:https://api.slack.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */
    function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return mb_substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

}
