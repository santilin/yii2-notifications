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
use yii\httpclient\Exception as TelegramException;
use yii\di\Instance;
use yii\helpers\Json;
use yii\httpclient\Client;

/**
 * See an example flow of sending notifications in Telegram
 * @see https://core.telegram.org/bots#deep-linking-example
 */
class TelegramChannelMock extends Component implements ChannelInterface
{

    static private $_messages = [];

    /**
     * Each bot is given a unique authentication token when it is created.
     * The token looks something like 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
     * @var string
     */
    public $botToken;

    /**
     * The botToken for devel environment
     * @var string
     */
    public $develBotToken;

    /**
     * @var string
     */
    public $parseMode = self::PARSE_MODE_MARKDOWN;

    const PARSE_MODE_HTML = "HTML";

    const PARSE_MODE_MARKDOWN = "Markdown";

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        parent::init();

        if(!isset($this->botToken)){
            throw new InvalidConfigException('Bot token is undefined');
        }

    }

    /**
     * @inheritDoc
     */
    public function send(NotifiableInterface $recipient, NotificationInterface $notification)
    {
        /** @var TelegramMessage $message */
        $message = $notification->exportFor('telegram');
        $text = $message->body;
        if (!empty($message->subject)) {
            $text = "*{$message->subject}*\n{$message->body}";
        }
        $chatId = $recipient->routeNotificationFor('telegram');
        if(!$chatId){
            $notification->addError('telegram_chat_id', 'No chat ID provided');
            return null;
        }

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'subject' => $message->subject,
            'body' => $message->body,
            'message' => $message,
        ];

        if(isset($this->parseMode)){
            $data['parse_mode'] = $this->parseMode;
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
