<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\notifications\channels;


use tuyakhov\notifications\messages\DatabaseMessage;
use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotificationInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\helpers\Json;

class DatabaseChannel extends Component implements ChannelInterface
{
    /**
     * @var BaseActiveRecord|string
     */
    public $model = 'tuyakhov\notifications\models\Notification';

    public function send(NotifiableInterface $recipient, NotificationInterface $notification, string $sender_account = null, &$response): bool
    {
        $model = \Yii::createObject($this->model);

        if (!$model instanceof BaseActiveRecord) {
            throw new InvalidConfigException('Model class must extend from \\yii\\db\\BaseActiveRecord');
        }

        /** @var DatabaseMessage $message */
        $message = $notification->exportFor('database');
        list($notifiableType, $notifiableId) = $recipient->routeNotificationFor('database');
        $data = [
            'level' => $message->level,
            'subject' => $message->subject,
            'body' => $message->body,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'data' => Json::encode($message->data),
            'sender_account' => $sender_account
        ];

        if ($model->load($data, '')) {
            if (!$model->insert()) {
                $response = $model->getErrors();
                foreach ($response as $errors) {
                    $notification->addError('Database', $errors[0]);
                }
                return false;
            } else {
                return true;
            }
        }

        return false;
    }
}
