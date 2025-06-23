<?php

namespace tuyakhov\notifications\models;

use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotifiableTrait;

class OneRecipient implements NotifiableInterface
{
	use NotifiableTrait;

	/**
	 * @var NotifiableInterface[] List of recipients
	 */
	protected $recipients;

	/**
	 * @var array Extracted email addresses
	 */
	protected $emails = [];

	/**
	 * @param NotifiableInterface[] $recipients
	 */
	public function __construct(array $recipients)
	{
		$this->recipients = $recipients;
		$this->extractEmails();
	}

	/**
	 * Extract emails using NotifiableInterface methods.
	 */
	protected function extractEmails()
	{
		foreach ($this->recipients as $recipient) {
			if ($recipient instanceof NotifiableInterface) {
				// Prefer routeNotificationForMail if available
				if (method_exists($recipient, 'routeNotificationForMail')) {
					$email = $recipient->routeNotificationForMail();
				} else {
					// Fallback to generic method
					$email = $recipient->routeNotificationFor('mail');
				}

				// Support both single email and array of emails
				if (is_array($email)) {
					foreach ($email as $e) {
						if (!empty($e)) {
							$this->emails[] = $e;
						}
					}
				} elseif (!empty($email)) {
					$this->emails[] = $email;
				}
			}
		}
		$this->emails = array_unique($this->emails);
	}

	/**
	 * Returns emails for the mail channel.
	 * @param string $channel
	 * @return array|null
	 */
	public function routeNotificationFor($channel)
	{
		return $channel === 'mail' ? $this->emails : null;
	}

} // class
