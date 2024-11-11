<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\notifications\channels;

use Yii;
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
use yii\base\ViewContextInterface;
use yii\web\View;

/**
 * See an example flow of sending notifications in Telegram
 * @see https://core.telegram.org/bots#deep-linking-example
 */
class TelegramChannel extends Component implements ChannelInterface, ViewContextInterface
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
     * @var \yii\base\View|array view instance or its array configuration.
     */
    private $_view = [];
    /**
     * @var string the directory containing view files for composing mail messages.
     */
    private $_viewPath;

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
    public function send(NotifiableInterface $recipient, NotificationInterface $notification, string $sender_account = null, &$response): bool
    {
        $chatId = $recipient->routeNotificationFor('telegram');
        if(!$chatId){
            $notification->addError('telegram_chat_id', 'No chat ID provided');
            return null;
        }
        /** @var TelegramMessage $message */
        $message = $notification->exportFor('telegram');
        if ($message->parseMode == TelegramMessage::PARSE_MODE_MARKDOWN) {
            if (!empty($message->subject)) {
                $text = "*" . self::cleanHtml($message->subject) . "*\n\n";
            }
        } else {
            $text = '';
        }
        if ($message->body === null && $message->view) {
            try {
                $message->body = \Yii::$app->controller->renderPartial($message->view,
                array_merge(['recipient' => $recipient, 'notification' => $notification], $message->viewData));
            } catch (\yii\base\ViewNotFoundException $e) {
                $message->body = '';
            }
        }
        $text .= self::cleanHtml($message->body);

        $data = [
            'chat_id' => $chatId,
            'subject' => $message->subject,
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
            if ($recipient instanceof BaseActiveRecord) {
                $data['text'] = 'to:' . $recipient->recordDesc() . "\n\n" . $data['text'];
            } else {
                $data['text'] = 'to:' . get_class($recipient) . "\n\n" . $data['text'];
            }
            $data['chat_id'] = __DEVEL_TELEGRAM_CHAT_ID__;
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
                $response = (object)[
                    'ok' => false,
                    'description' => $e->getMessage()
                ];
            }
            if (!$response->ok) {
                $via = ($sender_account ? " via $sender_account account" : '');
                $err_message = "Error sending message to Telegram chat$via:\n{$response->description}";
                $notification->addError('request_error', $err_message);
                Yii::error($err_message);
                if (YII_ENV_DEV) {
                    Yii::error("Message content:\n$text");
                }
                return false;
            }
        }
        return true;
    }

    // https://core.telegram.org/bots/api#html-style
    static public function cleanHtml(?string $html): string
    {
        if (empty($html)) {
            return '';
        }
        // Remove all HTML tags except for a few allowed ones
        $allowedTags = '<b><strong><i><em><u><ins><s><strike><del><a><code><pre><tg-spoiler><tg-emoji><blockquote>';
        $text = strip_tags(trim($html), $allowedTags);

        // Remove extra whitespace but keep newlines
        $text = preg_replace('/[^\S\r\n]+/', ' ', $text);

        // Convert <br> tags to newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n\n", $text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text;
    }


    /**
     * @return View view instance.
     */
    public function getView()
    {
        if (!is_object($this->_view)) {
            $this->_view = $this->createView($this->_view);
        }

        return $this->_view;
    }

    /**
     * Creates view instance from given configuration.
     * @param array $config view configuration.
     * @return View view instance.
     */
    protected function createView(array $config)
    {
        if (!array_key_exists('class', $config)) {
            $config['class'] = View::className();
        }

        return Yii::createObject($config);
    }


    /**
     * Renders the specified view with optional parameters and layout.
     * The view will be rendered using the [[view]] component.
     * @param string $view the view name or the [path alias](guide:concept-aliases) of the view file.
     * @param array $params the parameters (name-value pairs) that will be extracted and made available in the view file.
     * @param string|bool $layout layout view name or [path alias](guide:concept-aliases). If false, no layout will be applied.
     * @return string the rendering result.
     */
    public function render($view, $params = [], $layout = false)
    {
        $output = $this->getView()->render($view, $params, $this);
        if ($layout !== false) {
            return $this->getView()->render($layout, ['content' => $output, 'message' => $this->_message], $this);
        }

        return $output;
    }

    /**
     * @param string $path the directory that contains the view files for composing mail messages
     * This can be specified as an absolute path or a [path alias](guide:concept-aliases).
     */
    public function setViewPath($path)
    {
        $this->_viewPath = Yii::getAlias($path);
    }


    /**
     * @return string the directory that contains the view files for composing mail messages
     * Defaults to '@app/mail'.
     */
    public function getViewPath()
    {
        if ($this->_viewPath === null) {
            $this->setViewPath('@app/views/mds');
        }

        return $this->_viewPath;
    }
}
