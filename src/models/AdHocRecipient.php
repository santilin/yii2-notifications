<?php
/**
 * @copyright Santilín <z@zzzzz.es>
 */

namespace tuyakhov\notifications\models;

use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotificationInterface;

class AdHocRecipient implements NotifiableInterface
{
	protected $channels;

	public function __construct(array $channels)
	{
		$this->channels = array_merge( ['flash'=>true], $channels);
	}

    public function shouldReceiveNotification(NotificationInterface $notification)
	{
		return true;
	}

    public function viaChannels()
	{
		return array_keys($this->channels);
	}

    public function routeNotificationFor($channel)
	{
		if (isset($this->channels[$channel])) {
			return $this->channels[$channel];
		}
		return null;
	}

}
