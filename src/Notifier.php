<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\notifications;
use tuyakhov\notifications\NotificationException;
use tuyakhov\notifications\channels\ChannelInterface;
use tuyakhov\notifications\events\NotificationEvent;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Class Notifier is a component that can send multiple notifications to multiple recipients using available channels
 *
 * The following example shows how to create a Notifier instance and send your first notification:
 *
 * ```php
 * $notifier = new \tuyakhov\notifications\Notifier([
 *     'channels' => [...],
 * ]);
 * $notifier->send($recipients, $notifications);
 * ```
 *
 * Notifier is often used as an application component and configured in the application configuration like the following:
 *
 * ```php
 * [
 *      'components' => [
 *          'notifier' => [
 *              'class' => '\tuyakhov\notifications\Notifier',
 *              'channels' => [
 *                  'mail' => [
 *                      'class' => '\tuyakhov\notifications\channels\MailChannel',
 *                  ]
 *              ],
 *          ],
 *      ],
 * ]
 * ```
 * @package common\notifications
 */
class Notifier extends Component
{
    /**
     * @event NotificationEvent an event raised right after notification has been sent.
     */
    const EVENT_AFTER_SEND = 'afterSend';

    /**
     * @var array defines available channels
     * The syntax is like the following:
     *
     * ```php
     * [
     *     'mail' => [
     *         'class' => 'MailChannel',
     *     ],
     * ]
     * ```
     */
    public $channels = [];

    /*
     * Error handling stategies
     */
    const ON_ERROR_FAIL = 0;
	const ON_ERROR_IGNORE = 1;
	const ON_ERROR_THROW = 2;
	const ON_ERROR_STORE_ERRORS = 3;

    /**
     * Sends the given notifications through available channels to the given notifiable entities.
     * You may pass an array in order to send multiple notifications to multiple recipients.
     *
     * @param array|NotifiableInterface $recipients the recipients that can receive given notifications.
     * @param array|NotificationInterface $notifications the notification that should be delivered.
     * @return void
     * @throws InvalidConfigException
     */
    public function send($recipients, $notifications, string $sender_account = null)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }
        if (!is_array($notifications)){
            $notifications = [$notifications];
        }
        foreach ($notifications as $notification) {
            foreach ($recipients as $recipient) {
                if (!$recipient->shouldReceiveNotification($notification)) {
                    continue;
                }
                $channels = array_intersect($recipient->viaChannels(), array_keys($this->channels));
                $channels = array_intersect($channels, $notification->broadcastOn());
                foreach ($channels as $channel) {
                    $channelInstance = $this->getChannelInstance($channel);
					\Yii::info("Sending notification " . get_class($notification) . " to " . get_class($recipient) . " via {$channel}", __METHOD__);
					$response = $channelInstance->send($recipient, $notification, $sender_account);
					if ($response !== true || $notification->hasNotificationErrors()) {
						// $response = implode("\n",$notification->notificationErrors());
                        \Yii::error("Error sending `$channel` notification " . get_class($notification) . " to " . get_class($recipient) . "\n" . $response,  __METHOD__);
						switch($notification->onError) {
							case self::ON_ERROR_FAIL:
							case self::ON_ERROR_THROW:
								if (!YII_ENV_DEV) {
									throw new NotificationException($response);
								}
								break;
							case self::ON_ERROR_IGNORE:
                                if (!YII_ENV_DEV) {
                                    $notification->clearNotificationErrors();
                                }
								break;
							case self::ON_ERROR_STORE_ERRORS:
                                break;
						}
                    }
                    $this->trigger(self::EVENT_AFTER_SEND, new NotificationEvent([
                        'notification' => $notification,
                        'recipient' => $recipient,
                        'channel' => $channel,
                        'response' => $response
                    ]));
                }
            }
        }
    }

    /**
     * Returns channel instance
     * @param string $channel the channel name
     * @return ChannelInterface
     * @throws InvalidConfigException
     */
    protected function getChannelInstance($channel)
    {
        if (!isset($this->channels[$channel])) {
            throw new InvalidConfigException("Notification channel `{$channel}` is not available or configuration is missing");
        }
        if (!$this->channels[$channel] instanceof ChannelInterface) {
            $this->channels[$channel] = \Yii::createObject($this->channels[$channel]);
        }
        return $this->channels[$channel];
    }
}
