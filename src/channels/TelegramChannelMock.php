<?php
/**
 * @copyleft santilin <z@zzzzz.es>
 */

namespace tuyakhov\notifications\channels;

use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotificationInterface;
use tuyakhov\notifications\messages\TelegramMessage;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\helpers\Json;

/**
 * See an example flow of sending notifications in Telegram
 * @see https://core.telegram.org/bots#deep-linking-example
 */
class TelegramChannelMock extends TelegramChannel
{
    static private $_messages = [];

    /**
     * @inheritDoc
     */
    public function send(NotifiableInterface $recipient, NotificationInterface $notification, string $sender_account = null, &$response): bool
    {
        /** @var TelegramMessage $message */
        $message = $notification->exportFor('telegram');
        if ($message->parseMode == TelegramMessage::PARSE_MODE_MARKDOWN) {
            if (!empty($message->subject)) {
                $text = "*" . TelegramChannel::cleanHtml($message->subject) . "*\n\n";
            }
        } else {
            $text = '';
        }
        $body = \Yii::$app->controller->renderPartial($message->view,
            array_merge(['recipient' => $recipient, 'notification' => $notification], $message->viewData));
        $chatId = $recipient->routeNotificationFor('telegram');
        if(!$chatId){
            $notification->addError('telegram_chat_id', 'No chat ID provided');
            return null;
        }

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_notification' => $message->silentMode,
            'parse_mode' => $message->parseMode,
            'disable_web_page_preview' => $message->withoutPagePreview,
        ];

        if ($message->replyToMessageId) {
            $data['reply_to_message_id'] = $message->replyToMessageId;
        }

        if ($message->replyMarkup) {
            $data['reply_markup'] = Json::encode($message->replyMarkup);
        }

        self::$_messages[] = $data;

        return true;
    }

    static public function getMessages(): array
    {
        return self::$_messages;
    }

    static public function clearMessages()
    {
        self::$_messages = [];
    }

    static public function getMessagesTo(string $chat_id): array
    {
        return array_filter(self::$_messages, function($item) use ($chat_id) {
            return ($item['chat_id']??false) === $chat_id;
        });
    }

}
