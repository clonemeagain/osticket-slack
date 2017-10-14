![Slack](https://a.slack-edge.com/ae57/img/slack_api_logo.png)

osTicket-slack
==============
An plugin for [osTicket](https://osticket.com) which posts notifications to a [Slack](https://slack.com) channel.

Install
--------
Clone this repo or download the zip file and place the contents into your `include/plugins` folder.

Info
------
This plugin uses CURL and tested on osTicket-1.10.1

## Requirements
- php_curl
- A slack account

## To Install into Slack 
- Navigate to https://api.slack.com/ select "Start Building"
- Name your App `osTicket Notification`, select your Workspace from the drop-down
- Select "Incoming Webhooks"
- Activate the Webhooks with the link (it defaults to Off, just click Off to change it to On)
- Scroll to the bottom and select "Add a new Webhook to Workspace"
- Select the endpoint of the webhook, (ie, channel to post to)
- Select "Authorize"
- Scroll down and copy the Webhook URL entirely, paste this into the Plugin config.

The channel you select will receive an event notice, like:
```
Aaron [10:56 AM] added an integration to this channel: osTicket Notification
```
You should also receive an email from Slack telling you about the new Integration.

## Test!
Create a ticket!

You should see something like:

![slack](https://user-images.githubusercontent.com/5077391/31570760-2a5bec20-b0d3-11e7-9429-c25e8cdcf328.png)