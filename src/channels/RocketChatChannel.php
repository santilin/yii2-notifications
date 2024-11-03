<?php

namespace tuyakhov\notifications\channels;

use tuyakhov\notifications\channels\ChannelInterface;
use tuyakhov\notifications\NotificationInterface;
use yii\httpclient\Client;

class RocketChatChannel implements ChannelInterface
{
	public $webhookUrl;

	public function send(NotificationInterface $notification, $notifiable)
	{
		$message = $notification->exportFor('rocketChat');

		$client = new Client();
		$response = $client->createRequest()
		->setMethod('POST')
		->setUrl($this->webhookUrl)
		->setData([
			'text' => $message->text,
			'channel' => $message->channel,
		])
		->send();

		if (!$response->isOk) {
			// Handle error
			\Yii::error('Failed to send notification to Rocket.Chat: ' . $response->content);
		}
	}
}

