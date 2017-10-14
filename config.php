<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class SlackPluginConfig extends PluginConfig {

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('slack');
    }

    function getOptions() {
        list ($__, $_N) = self::translate();

        return array(
            'slack'             => new SectionBreakField(array(
                'label' => $__('Slack notifier'),
                'hint'  => $__('Create a new app for your workspace first!')
                    )),
            'slack-webhook-url' => new TextboxField(array(
                'label'         => 'Webhook URL',
                'configuration' => array(
                    'size'   => 100,
                    'length' => 200
                ),
                    )),
        );
    }

}
