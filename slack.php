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
        Signal::connect('ticket.created', function(Ticket $ticket) {
            $this->onTicketCreated($ticket);
        });
    }

    function onTicketCreated(Ticket $ticket) {
        $c = $this->getConfig();

        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        $msg  = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getBaseUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
        $body = sprintf('%s %s (%s) %s %s (%s) %s %s %s CONTROLSTART!date^%d^{date} {time}|%sCONTROLEND'
                , __("created by")
                , $ticket->getName()
                , $ticket->getEmail()
                , __('in')
                , $ticket->getDeptName()
                , __('Department')
                , __('via')
                , $ticket->getSource()
                , __('on')
                , strtotime($ticket->getCreateDate())
                , $ticket->getCreateDate());

        // Obey message formatting rules:https://api.slack.com/docs/message-formatting
        $formatter     = ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;'];
        $msg           = str_replace(array_keys($formatter), array_values($formatter), $msg);
        $body          = str_replace(array_keys($formatter), array_values($formatter), $body);
        // put the <>'s control characters back in
        $moreformatter = ['CONTROLSTART' => '<', 'CONTROLEND' => '>'];
        $msg           = str_replace(array_keys($moreformatter), array_values($moreformatter), $msg);
        $body          = str_replace(array_keys($moreformatter), array_values($moreformatter), $body);
        error_log("Message: $msg");
        error_log("BODY: $body");

        try {
            $payload['attachments'][] = [
                'pretext'  => $msg,
                'fallback' => $msg,
                'color'    => "#D00000",
                'fields'   =>
                [
                    [
                        'title'  => $ticket->getSubject(),
                        'value'  => $body,
                        'short'  => FALSE,
                        'mrkdwn' => false,
                    ],
                ],
            ];

            $data_string = utf8_encode(json_encode($payload));
            $url         = $c->get('slack-webhook-url');

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

}
