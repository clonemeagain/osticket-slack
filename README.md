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

If you want to add the Department as a field in each slack notice, tick the Checkbox in the Plugin config.

The channel you select will receive an event notice, like:
```
Aaron [10:56 AM] added an integration to this channel: osTicket Notification
```
You should also receive an email from Slack telling you about the new Integration.

## Test!
Create a ticket!

You should see something like the following appear in your Slack channel:

![slack-new-ticket](https://user-images.githubusercontent.com/5077391/31572647-923e07b0-b0f6-11e7-9515-98205d6f800f.png)

When a user replies, you'll get something like:

![slack-reply](https://user-images.githubusercontent.com/5077391/31572648-9279eb18-b0f6-11e7-97da-9a9c63a200d4.png)

Notes, Replies from Agents and System messages shouldn't appear.

## Adding pull's from original repo:
 +0.2 - 17 december 2016
 +[feature] "Ignore when subject equals regex" by @ramonfincken