<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
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
class TelegramChannel extends Component implements ChannelInterface
{
    /**
     * @var Client|array|string
     */
    public $httpClient;

    /**
     * @var string
     */
    public string $apiUrl = "https://api.telegram.org";

    /**
     * Each bot is given a unique authentication token when it is created.
     * The token looks something like 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
     * @var string
     */
    public string $botToken;

    /**
     * The botToken for devel environment
     * @var string
     */
    public string $develBotToken;

    /**
     * The accounts with members:
     *  - string botToken
     *  - string develBotToken
     */
    public array $senderAccounts = [];

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        parent::init();

        if(!isset($this->botToken)){
            throw new InvalidConfigException('Bot token is undefined');
        }

        if (!isset($this->httpClient)) {
            $this->httpClient = [
                'class' => Client::className(),
                'baseUrl' => $this->apiUrl,
            ];
        }
        $this->httpClient = Instance::ensure($this->httpClient, Client::className());
    }

    /**
     * @inheritDoc
     */
    public function send(NotifiableInterface $recipient, NotificationInterface $notification, string $sender_account = null)
    {
        /** @var TelegramMessage $message */
        $message = $notification->exportFor('telegram');
        if ($message->parseMode == TelegramMessage::PARSE_MODE_MARKDOWN) {
            $text = $message->body;
            if (!empty($message->subject)) {
                $text = "*{$message->subject}*\n$text";
            }
        } else {
            $text = $this->cleanHtml($message->body);
        }
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


        if (YII_ENV_DEV) {
            if ($sender_account != null) {
                if (isset($this->senderAccounts[$sender_account])) {
                    if (isset($this->senderAccounts[$sender_account]['develBotToken'])) {
                        $bot = $this->senderAccounts[$sender_account]['develBotToken'];
                    } else {
                        throw new InvalidConfigException("Please, define a develBotToken for the `$sender_account` telegram account");
                    }
                } else {
                    throw new InvalidConfigException("Please, define the `$sender_account` telegram account");
                }
            } else {
                $bot = $this->develBotToken;
            }
        } else {
            if ($sender_account != null) {
                if (isset($this->senderAccounts[$sender_account])) {
                    if (isset($this->senderAccounts[$sender_account]['botToken'])) {
                        $bot = $this->senderAccounts[$sender_account]['botToken'];
                    } else {
                        throw new InvalidConfigException("Please, define a botToken for the `$sender_account` telegram account");
                    }
                } else {
                    throw new InvalidConfigException("Please, define the `$sender_account` telegram account");
                }
            } else {
                $bot = $this->botToken;
            }
        }
        $bot_url = "bot$bot/sendMessage";

        $response_request = $this->httpClient->createRequest()
            ->setUrl($bot_url)
            ->setData($data);
        if (!YII_ENV_TEST) {
            try {
                $response_object = $response_request->send();
                $response = json_decode($response_object->getContent());
            } catch (TelegramException $e) {
                if (YII_ENV_DEV) {
                    \Yii::error('Telegram send: ' . $e->getMessage());
                    $notification->addError('request_error', $e->getMessage());
                    return true;
                }
                $response = [
                    'ok' => false,
                    'description' => "Error sending telegram message: " . $e->getMessage()
                ];
            }
            if (!$response->ok) {
                $notification->addError('request_error', "Error sending message to Telegram chat via `$sender_account` account:\n{$response->description}");
                if (YII_ENV_DEV) {
                    $notification->addError('devel', "\nMessage content:\n$text");
                }
            }
            return $response;
        } else {
            return true;
        }
    }

    // https://core.telegram.org/bots/api#html-style
    protected function cleanHtml(string $html): string
    {
        // Remove all HTML tags except for a few allowed ones
        $allowedTags = '<b><strong><i><em><u><ins><s><strike><del><a><code><pre><tg-spoiler><tg-emoji><blockquote>';
        $text = strip_tags($html, $allowedTags);

        // Convert <br> tags to newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim the text
        $text = trim($text);

        return $text;
    }
}
