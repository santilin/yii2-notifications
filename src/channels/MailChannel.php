<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 *
 * Modified by santilín <z@zzzzz.es> on sep 2023 to add proper handling of errors.
 */
namespace tuyakhov\notifications\channels;

use Yii;
use yii\db\BaseActiveRecord;
use tuyakhov\notifications\messages\MailMessage;
use tuyakhov\notifications\{NotifiableInterface,NotificationInterface};
use yii\base\{Component,InvalidConfigException};
use yii\di\Instance;
use yii\mail\MailerInterface;

class MailChannel extends Component implements ChannelInterface
{
    /**
     * @var $mailer MailerInterface|array|string the mailer object or the application component ID of the mailer object.
     */
    public $mailer = 'mailer';

	/** @var string custom views path for the mailer component */
	public $viewsPath = null; // '@Da/User/resources/views';

    /**
     * The message sender accounts.
     * @var string
     */
    public $senderAccounts = [];

	/** @var string A prefix to prepend to all mail subject messages */
	public $subjectPrefix = null;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->mailer = Instance::ensure($this->mailer, 'yii\mail\MailerInterface');
    }

    public function send(NotifiableInterface $recipient, NotificationInterface $notification, string $sender_account = null, &$response): bool
    {
        /**
         * @var $message MailMessage
         */
        $message = $notification->exportFor('mail');
		if (array_key_exists('subject', $message->viewData)) {
			$message->subject = $message->viewData['subject'];
		}
		$message_views = $message->view;
		// Let the message decide if it wants text email bodies
		if (!is_array($message_views)) {
			$message_views = [ 'html' => $message_views, 'text' => $message_views ];
		}
		$sent = false;
		$mailer_error = $mailer_error_debug = '';
		Yii::$app->mailer->on(\yii\mail\BaseMailer::EVENT_AFTER_SEND,
			function(\yii\mail\MailEvent $event) use ($mailer_error, $sent) {
				$sent = $event->isSuccessful;
				if (!$sent) {
					$mailer_error = "Error";
				}
			}
		);
		if (!$sender_account) {
			$sender_account = $message->sender_account??'admin';
		}
		if (isset($this->senderAccounts[$sender_account])) {
			$sender_data = $this->senderAccounts[$sender_account];
			if (is_string($sender_data)) {
				$sender_data = [
					'from' => $sender_data,
				];
			}
		} else if (!$sender_account || $sender_account == 'admin') {
			$sender_data = [
				'from' => Yii::$app->params['adminEmail']??null,
			];
		} else {
			throw new InvalidConfigException("No settings found for $sender_account mail sender account");
		}

		if (empty($message->from)) {
			$message->from = $sender_data['from'];
		}
		if (empty($message->from)) {
            throw new InvalidConfigException('neither from nor senderAccount found in mail message');
        }
		$data = $message->viewData;
		$data['mailParams'] = [
			'recipient' => $recipient,
			'from' => $message->from,
			'notification' => $notification,
			'message' => $message,
			'channel' => 'mail',
		];
		$to = (array)$recipient->routeNotificationFor('mail');
		if (empty($to)) {
			if (in_array('ModelInfoTrait', class_uses($recipient))) {
				throw new \Exception("Notification recipient `" . $recipient->recordDesc() . "`s `to` is empty");
			} else if (is_a($recipient, 'yii\db\BaseActiveRecord')) {
				throw new \Exception("Notification recipient `" . $recipient->recordDesc() . "`s `to` is empty");
			} else {
				throw new \Exception("Notification recipient's `to` is empty");
			}
		}
		$subject = $message->subject;
		if ($this->subjectPrefix) {
			$subject = $this->subjectPrefix . $subject;
		}
		foreach ((array)($sender_data['addTo']??[]) as $add_to) {
			$to[] = $add_to;
		}
		if( YII_ENV_DEV ) {
			// if (!isset(Yii::$app->params['develEmailFrom']) && !isset(Yii::$app->params['develEmailTo'])) {
			// 	throw new \Exception("Please, define \$app->params['develEmailTo'] && \$app->params['develEmailFrom']");
			// }
			$subject = '[dev:to:' . reset($to). "]{$subject}";
			// $message->from = Yii::$app->params['develEmailFrom'];
			$to = (array)Yii::$app->params['develEmailTo'];
			$message->from = Yii::$app->params['develEmailFrom']??Yii::$app->params['develEmail'];
		}
		if ($this->viewsPath) {
			$save_view_path = Yii::$app->mailer->getViewPath();
			Yii::$app->mailer->setViewPath($this->viewsPath);
		}
		$composed = Yii::$app->mailer
			->compose($message_views, $data)
			->setFrom($message->from)
			->setTo($to)
			->setSubject($subject);
		if (isset($sender_data['replyTo'])) {
			$composed->setReplyTo($sender_data['replyTo']);
		}
		try {
			if ($this->viewsPath) {
				Yii::$app->mailer->setViewPath($save_view_path);
			}
			$sent = $composed->send();
		} catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
			$mailer_error = $e->getMessage();
			if (YII_ENV_DEV) {
				$mailer_error_debug = $e->getDebug();
			}
		} catch (\Swift_TransportException $e ) {
			$mailer_error = $e->getMessage();
		} catch (\Swift_RfcComplianceException $e ) {
			$mailer_error = $e->getMessage();
		} catch (\Exception $e) {
			throw $e;
		}
		if (!$sent) {
			if (count($to) > 1) {
				$error_message = Yii::t('churros', 'Unable to send email to {email} and other {ndest} recipients from {from}', ['email' => array_pop($to), 'ndest' => count($to), 'from' => $message->from]);
			} else {
				$error_message = Yii::t('churros', 'Unable to send email to {email} from {from}', ['email' => array_pop($to), 'from' => $message->from ]);
			}
			if (strpos($mailer_error, 'php_network_getaddresses: getaddrinfo failed') !== FALSE) {
				$notification->addError('sendmail_network_error', $error_message);
				if( YII_ENV_DEV ) {
					$mail_message_parts = $composed->getSwiftMessage()->getChildren();
					$html_mail = $mail_message_parts[0];
					$notification->addError('mailbody',
						"$mailer_error\n\nView: {$message_views['html']}\nSubject: $subject\nBody: "
						. trim(strip_tags($html_mail->getBody())));
					return true;
				}
			} else {
				$notification->addError('sendmail', $error_message);
				if( YII_ENV_DEV ) {
					$notification->addError('transport', $e->getMessage());
				} else {
					Yii::error($error_message . "\n" . $e->getMessage());
				}
			}
			if ($mailer_error_debug) {
				$notification->addError('debug', $mailer_error_debug);
			}
			$response = $error_message;
			return false;
		}
		return true;
	}

}
