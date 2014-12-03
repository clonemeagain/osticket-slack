<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class SlackPluginConfig extends PluginConfig {
    function getOptions() {        
        return array(
            'slack' => new SectionBreakField(array(
                'label' => 'Slack notifier',
            )),
            'slack-webhook-url' => new TextboxField(array(
                'label' => 'Webhook URL',
                'configuration' => array('size'=>100, 'length'=>200),
            )),			            
        );
    }	
}
