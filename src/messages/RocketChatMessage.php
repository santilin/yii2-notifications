<?php

namespace tuyakhov\notifications\messages;

class RocketChatMessage
{
	public $text;
	public $channel;

	public function __construct($text, $channel)
	{
		$this->text = $text;
		$this->channel = $channel;
	}
}

