<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\notifications;

use tuyakhov\notifications\messages\AbstractMessage;
use yii\helpers\Inflector;

trait NotificationTrait
{
    /** @var all the errors produced by the notification channels */
    private $_notification_errors;

    /**
     * @return array
     */
    public function broadcastOn()
    {
        $channels = [];
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strpos($method, 'exportFor') === false) {
                continue;
            }
            $channel = str_replace('exportFor', '', $method);
            if (!empty($channel)) {
                $channels[] = Inflector::camel2id($channel);
            }
        }
        return $channels;
    }

    /**
     * Determines on which channels the notification will be delivered.
     * ```php
     * public function exportForMail() {
     *      return Yii::createObject([
     *          'class' => 'tuyakhov\notifications\messages\MailMessage',
     *          'view' => ['html' => 'welcome'],
     *          'viewData' => [...]
     *      ])
     * }
     * ```
     * @param $channel
     * @return AbstractMessage
     * @throws \InvalidArgumentException
     */
    public function exportFor($channel)
    {
        if (method_exists($this, $method = 'exportFor'.Inflector::id2camel($channel))) {
            return $this->{$method}();
        }
        throw new \InvalidArgumentException("Can not find message export for chanel `{$channel}`");
    }


    /**
     * Adds a new error produced by the specified channel.
     * @param string $channel channel name
     * @param string $error new error message
     */
    public function addError(string $channel, string $err_message)
    {
		$this->_notification_errors[$channel] = $err_message;
    }

    /**
     * Returns the errors for all channels or a specified channel
     * @param string|null $channel channel name. Use null to retrieve errors for all channels.
     * @return array errors for all channels or the specified channel. Empty array is returned if no error.
     */
    public function notificationErrors(string $channel = null): array
    {
        if ($channel === null) {
            return $this->_notification_errors === null ? [] : $this->_notification_errors;
        }
        return isset($this->_notification_errors[$channel]) ? $this->_notification_errors[$channel] : [];
    }

    /**
     * Returns a value indicating whether there is any message sending error.
     * @param string|null $channel channel name. Use null to check all channels.
     * @return bool whether there is any error.
     */
	public function hasErrors(string $channel = null): bool
    {
        return $channel === null ? !empty($this->_notification_errors) : isset($this->_notification_errors[$channel]);
    }

    /**
     * Removes errors for all channels or a single channel.
     * @param string|null $channel channel name. Use null to remove errors for all channels.
     */
    public function clearNotificationErrors($channel = null)
    {
        if ($channel === null) {
            $this->_notification_errors = [];
        } else {
            unset($this->_notification_errors[$channel]);
        }
    }

}
