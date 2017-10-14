<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once('config.php');

class SlackPlugin extends Plugin {

    var $config_class = "SlackPluginConfig";

    function bootstrap() {
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
    }

    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%s - %sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getBaseUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , $ticket->getSubject()
                , __("created"));
        $body    = sprintf('%s %s (%s) %s'
                , __("Created by")
                , $ticket->getName()
                , $ticket->getEmail()
                , "\n\n" . $plaintext);
        $this->sendToSlack($ticket, $heading, $body);
    }

    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            return;
        }
        $ticket      = $this->getTicket($entry);
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            // don't post the same thing twice.. let onCreated handle it.
            return;
        }

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($entry->getBody()->getClean());

        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%s %sCONTROLEND %s'
                , __("Ticket")
                , $cfg->getBaseUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , $ticket->getSubject()
                , __("updated"));
        $body    = sprintf('%s %s (%s) %s %s %s'
                , __("by")
                , $entry->getPoster()
                , $ticket->getEmail()
                , __('in')
                , $ticket->getDeptName()
                , "\n\n" . $plaintext);
        $this->sendToSlack($ticket, $heading, $body, 'warning');
    }

    function sendToSlack(Ticket $ticket, $heading, $body, $colour = 'good') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }

        // Obey message formatting rules:https://api.slack.com/docs/message-formatting
        $formatter     = ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;'];
        $heading       = str_replace(array_keys($formatter), array_values($formatter), $heading);
        $body          = str_replace(array_keys($formatter), array_values($formatter), $body);
        // put the <>'s control characters back in
        $moreformatter = ['CONTROLSTART' => '<', 'CONTROLEND' => '>'];
        $heading       = str_replace(array_keys($moreformatter), array_values($moreformatter), $heading);
        $body          = str_replace(array_keys($moreformatter), array_values($moreformatter), $body);

        try {
            $payload['attachments'][] = [
                'pretext'     => $heading,
                'fallback'    => $heading,
                'color'       => $colour,
                "author"      => $ticket->getName(),
                "author_link" => $cfg->getBaseUrl() . 'scp/users.php?id=' . $ticket->getOwner()->getId(),
                'ts'          => Misc::gmtime(),
                'footer'      => __('Department') . ': ' . $ticket->getDeptName() . ' -> ' . $ticket->getTopic(),
                "fields"      => [
                    [
                        "title"      => $ticket->getSubject(),
                        "title_link" => $cfg->getBaseUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
                        "value"      => $body,
                        "short"      => false,
                    ]
                ]
            ];

            $data_string = utf8_encode(json_encode($payload));
            $url         = $this->getConfig()->get('slack-webhook-url');

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );

            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception($url . ' Http code: ' . $statusCode);
                }
            }
            curl_close($ch);
        } catch (\Exception $e) {
            $ost->logError('Slack posting issue!', $e->getMessage(), true);
            error_log('Error posting to Slack. ' . $e->getMessage());
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */
    private static function getTicket(ThreadEntry $entry) {
        static $ticket;
        if (!$ticket) {
            // aquire ticket from $entry.. I suspect there is a more efficient way.
            $ticket_id = Thread::objects()->filter([
                        'id' => $entry->getThreadId()
                    ])->values_flat('object_id')->first() [0];

            // Force lookup rather than use cached data..
            $ticket = Ticket::lookup(array(
                        'ticket_id' => $ticket_id
            ));
        }
        return $ticket;
    }

}
