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
    private $ost;
    private $cfg;

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        $plugin_config = $this->getConfig();

// Listen for osTicket to tell us it's made a new ticket or updated
// an existing ticket:
        if ($plugin_config->get('notify-new')) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        }
        if ($plugin_config->get('notify-replies')) {
            Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
        }
// Tasks? Signal::connect('task.created',array($this,'onTaskCreated'));
    }

    private function validate_install(PluginConfig $plugin_config) {
// We're at the "Signals Received" stage, so we should be able to trust the $ost/$cfg globals are ready
        global $cfg, $ost;
        $this->ost = $ost;
        $this->cfg = $cfg;

// Validate config
        $error = false;

        if (!$this->cfg instanceof OsticketConfig) {
            error_log("Somehow the configuration wasn't loaded while the Slack notification plugin was attempting to process a ticket.");
            $error = true;
        }
        if (!$this->ost instanceof osTicket) {
            error_log("global ost wasn't ready, can't log");
            return true;
        }

        if (!$plugin_config->get('slack-webhook-url')) {
            $this->ost->logError('Slack Plugin not yet configured', 'You need to read the Readme and configure a webhook URL before using this.');
            $error = true;
        }

        if (!extension_loaded('curl')) {
            $this->ost->logError('Slack Plugin: curl extension missing', 'You need to install and enable the php_curl extension before we can use it to send notifications.');
            $error = true;
        }

        if (!strlen($plugin_config->get('message-template'))) {
            $this->ost->logInfo('Slack: Regenerating message template', 'It was missing/empty in the plugin config, reverting to default.');
            $this->getConfig()->set('message-template', SlackPluginConfig::$template);
        }

        return $error;
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        if ($this->validate_install($this->getConfig())) {
// bail before attempting to send things
            error_log("Slack plugin not ready, No notifications will be attempted until setup is completed.");
            return;
        }

        try {
// Convert any HTML in the message into text
            $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

            $colour = $this->getConfig('nm-colour') ?: 'good';

            if (!$plaintext) {
                $plaintext = '[empty]';
            }

// Format the messages we'll send.
            $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                    , __("New Ticket")
                    , $this->cfg->getUrl()
                    , $ticket->getId()
                    , $ticket->getNumber()
                    , __("created"));
            $this->ost->logDebug("Slack Sending...", $heading . "\n" . $plaintext);
            $this->sendToSlack($ticket, $heading, $plaintext, $colour);
        } catch (Exception $e) {
            $this->ost->logError("Slack: Exception encountered!", sprintf("New ticket error for ticket_id: %s\n%s", $ticket->getId(), $e->getMessage));
            $this->ost->logDebug("Slack Trace", $e->getTraceAsString());
        }
    }

    /**
     * Basically, the onTicketUpdated thread was getting long
     * 
     * @param ThreadEntry $entry
     * @param PluginConfig $config
     * @return boolean|string
     */
    private function getColour($entry, $config) {
        // Setup what types of messages we'll pass through to slack, defaults to just user-messages:
        if ($config->get('post-user-messages') && $entry instanceof MessageThreadEntry) {
            // User messages always? 
            return $config->get('um-colour') ?: 'warning';
        }

// See if the admin has allowed Agent responses to be posted to Slack:
        elseif ($config->get('post-agent-messages') && $entry instanceof ResponseThreadEntry) {
            return $config->get('am-colour') ?: '#439FE0'; // a blue colour
        }

// See if System messages should be posted, good for "Overdue" type messages:
        elseif ($config->get('post-system-messages')) {
            return $config->get('sm-colour') ?: 'danger';
        }
        return FALSE;
    }

    /**
     * What to do with an Updated Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        $config = $this->getConfig();

        if ($this->validate_install($config)) {
// bail before attempting to send things
            error_log("Slack plugin not ready, No notifications will be attempted until setup is completed.");
            return;
        }

// Fetch the colour for the post
        $colour = $this->getColour($entry, $config);

        if ($colour === FALSE) {
// Response was not selected for slack notification by admin... 
            $this->ost->logDebug("Slack Ignoring message", "You can use the plugin config to enable this type of notice if desired.");
            return;
        }


// Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
// Admin created ticket's won't work here.
            $this->ost->logDebug("Slack ignoring message", "Because there is no associated ticket.");
            return;
        }

// Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            $this->ost->logDebug("Slack ignoring message", "Because we don't want to notify twice on new Tickets");
            return;
        }

        try {
// Convert any HTML in the message into text
            $plaintext = Format::html2text($entry->getBody()->getClean());

            if (!$plaintext) {
                $plaintext = '[empty]';
            }

// Format the messages we'll send
            $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                    , __("Ticket")
                    , $this->cfg->getUrl()
                    , $ticket->getId()
                    , $ticket->getNumber()
                    , __("updated"));
            $this->ost->logDebug("Slack Sending...", $heading . "\n" . $plaintext);
            $this->sendToSlack($ticket, $heading, $plaintext, $colour);
        } catch (Exception $e) {
            $this->ost->logError("Slack: Exception encountered!", sprintf("Reply to ticket error for ticket_id: %s\n%s", $ticket->getId(), $e->getMessage), false);
            $this->ost->logDebug("Slack Trace", $e->getTraceAsString());
        }
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

// Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig()->get('slack-regex-subject-ignore');
// Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $this->ost->logDebug('Slack Ignored Message', 'Slack notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        }

        $formatted_heading = $this->slack_text_formatter($heading);
        if (!$formatted_heading) {
            $formatted_heading = '[heading text missing]';
        }

// Pull template from config, and use that. 
        $template          = $this->getConfig()->get('message-template') ?: SlackPluginConfig::$template;
// Add our custom variables to an array 
        $custom_vars       = [
            'slack_safe_message' => $this->slack_text_formatter($body),
        ];
// process using the ticket's variable replacer, thereby inheriting all ticket contextually available variables.
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

// Build a payload with the formatted data:
        $payload                   = [];
        $payload['attachments'][0] = [
            'pretext'     => $formatted_heading,
            'fallback'    => $formatted_heading,
            'color'       => $colour,
            // 'author'      => $ticket->getOwner(),
//  'author_link' => $cfg->getUrl() . 'scp/users.php?id=' . $ticket->getOwnerId(), // not included, as these assume Author is a Slack user.
// 'author_icon' => $this->get_gravatar($ticket->getEmail()),
            'title'       => $ticket->getSubject(),
            'title_link'  => $this->cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
            'ts'          => time(),
            'footer'      => 'via osTicket Slack Plugin',
            'footer_icon' => 'https://platform.slack-edge.com/img/default_application_icon.png',
            'text'        => $formatted_message,
            'mrkdwn_in'   => ["text"]
        ];
// Add a field for tasks if there are open ones
        if (($num_tasks                 = $ticket->getNumOpenTasks()) != false) {
            $payload['attachments'][0]['fields'][] = [
                'title' => __('Open Tasks'),
                'value' => $num_tasks,
                'short' => TRUE,
            ];
        }
// Change the colour to Fuschia if ticket is overdue
        if ($ticket->isOverdue()) {
            $payload['attachments'][0]['title']  = 'Overdue: ' . $payload['attachments'][0]['title'];
            $payload['attachments'][0]['colour'] = '#ff00ff';
        }

// Format the payload:
        $data_string = utf8_encode(json_encode($payload));

// After the formatting into sendable format, we set some simpler vars for later use
        $payload['original'] = true;
        $payload['message']  = $formatted_message;

        $url = $this->getConfig()->get('slack-webhook-url');
        $this->curl_me($url, $data_string, $payload);
    }

    /**
     * Curl Wrapper, handles their error codes with osTicket logs
     * 
     * @param type $url
     * @param type $data_string utf8 encoded json
     * @param boolean $payload array used to build the $data_string
     */
    function curl_me($url, $data_string, $payload) {

        if (!$data_string) {
            $this->ost->logError("Slack: No Url for Curl!", "We need that webhook.. this is an error, because it shouldn't get here without one!");
            return false;
        }


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
        $curl_result   = curl_exec($ch);
        $http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error_number  = curl_errno($ch);
        curl_close($ch);

        if ($curl_result === false || $http_response !== 200) {
// Check the statusCode against common slack posting errors

            switch ($http_response) {
                case 200: {
// this is the good result!
                        $this->ost->logDebug("Slack Notification Success", "Notification to slack successful!");
                        break;
                    }
                case 400: {
                        if ($payload['original']) {
//Simply and resend, something went wonky
                            $this->ost->logWarn("Slack Notification Issue", "There was a problem sending to their server, not sure what yet, but it could be related to the message we sent, so we're simplifying the notice and trying it again resending, you don't have to do anything, unless you get a bunch of these.. then try turning off some config options, or let the dev know.");
                            $payload['original'] = false;
                            $this->simplify_and_resend($url, $payload);
                        }
                        else {
                            $this->ost->logError("Slack Notification Error", sprintf("We tried to resend a message %s to %s however it failed twice, we simplified it and it still failed, so something is wrong.", $payload['message'], $url), true);
                        }
                        break;
                    }
                case 403:
                case 410: {
                        $message = ($http_response == 403) ? 'Permissions issue' : 'Channel might be archived.';
// Permissions error with endpoint, unable to post!
                        $this->ost->logError("Slack Notification Error", sprintf("Channel Error attempting to send to: %s\nHttp code: %d\ncurl-error: %d \nBased on http status code, it could be %s.", $url, $http_response, $error_number, $message), true);
                        // No point in trying again, there was a problem that can't be solved by retrying.
                        return;
                    }
                case 500: {
//TODO: Build a queue to store these in.. even just a folder with json dumps per ticket id.. and what for replies? hmm. 
                        $this->ost->logError("Slack Notification Error", sprintf("Slack Server Error attempting to send to: %s\nHttp code: %d\ncurl-error: %d \nBased on http status code, it's like at their end, working on a way of caching these to resend later.", $url, $http_response, $error_number), true);
                        return;
                    }
            }
        }
        // If we hit here, all was fine.
        $this->ost->logDebug("Slack: Message sent", "Check the thread for a notice, or turn off debugging because it's working fine.");
    }

    /**
     * Formats text according to the Slack
     * formatting rules:https://api.slack.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */
    function slack_text_formatter($text) {
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
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Something went wrong sending the message the first time, either a user or message error. 
     * Let's try removing some detail and sending again. 
     * 
     * @param type $url
     * @param type $payload
     */
    private function simplify_and_resend($url, $payload) {

// Rewrite the original payload without any of the extras/fields/colors etc:
        $src         = $payload['attachments'][0];
        $new_payload = [];

// Deliberately shorten all the text fields:
        $new_payload['attachments'][0] = [
            'pretext'    => wordwrap($src['pretext'], 40),
            'fallback'   => wordwrap($src['fallback'], 40),
            'title'      => wordwrap($src['title'], 40),
            'title_link' => $src['title_link'], // change this? 
            'ts'         => time(),
            'footer'     => 'Fallback alert',
            'text'       => wordwrap($src['text'], 100),
            'mrkdwn_in'  => ['text']
        ];

// Format to a curl-able form:
        $data_string = utf8_encode(json_encode($new_payload));

// Set these to trigger an error message if it still can't be sent4
        $new_payload['original'] = false;
        $new_payload['message']  = $src['text'];

// Overwrite the payload & try sending again
        $this->curl_me($url, $data_string, $new_payload);
    }

    //******* Plugin Utility functions 
    /**
     * Find the ticket from the database which ThreadEntry $entry relates to
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
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall() {
        $errors = array();
        parent::uninstall($errors);
    }

    /**
     * Plugin seems to want this.
     */
    public function getForm() {
        return array();
    }

}
