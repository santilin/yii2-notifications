<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 *
 * Modified by santilín <z@zzzzz.es> on sep 2023 to add proper handling of errors.
 */
namespace tuyakhov\notifications\channels;

use Yii;
use tuyakhov\notifications\messages\MailMessage;
use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotificationInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\mail\MailerInterface;

class MailChannel extends Component implements ChannelInterface
{
    /**
     * @var $mailer MailerInterface|array|string the mailer object or the application component ID of the mailer object.
     */
    public $mailer = 'mailer';

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

    public function send(NotifiableInterface $recipient, NotificationInterface $notification)
    {
        /**
         * @var $message MailMessage
         */
        $message = $notification->exportFor('mail');
		$message_views = $message->view;
		// Let the message decide if it wants text email bodies
		if (!is_array($message_views)) {
			$message_views = [ 'html' => $message_views, 'text' => $message_views ];
		}
		$sent = false;
		$mailer_error = '';
		Yii::$app->mailer->on(\yii\mail\BaseMailer::EVENT_AFTER_SEND,
			function(\yii\mail\MailEvent $event) use ($mailer_error, $sent) {
				$sent = $event->isSuccessful;
				if (!$sent) {
					$mailer_error = "Error";
				}
			}
		);
		if (empty($message->from)) {
			$message->from = $this->senderAccounts[$message->senderAccount]??null;
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
		$subject = $message->subject;
		if ($this->subjectPrefix) {
			$subject = $this->subjectPrefix . $subject;
		}
		if( YII_ENV_DEV ) {
			$subject = '[dev:to:' . reset($to). "]{$subject}";
			$to = [Yii::$app->params['develEmail']??'example@example.org'];
		}
		$composed = Yii::$app->mailer
			->compose($message_views, $data)
			->setFrom($message->from)
			->setTo($to)
			->setSubject($subject);
		try {
			$sent = $composed->send();
		} catch (\Swift_TransportException $e ) {
			$mailer_error = $e->getMessage();
		} catch (\Swift_RfcComplianceException $e ) {
			$mailer_error = $e->getMessage();
		} catch (\Exception $e) {
			throw $e;
		}
		if( !$sent ) {
			if( count($to) > 1 ) {
				$error_message = Yii::t('app', 'Unable to send email to {email} and other {ndest} recipients', ['email' => array_pop($to), 'ndest' => count($to)]);
			} else {
				$error_message = Yii::t('app', 'Unable to send email to {email}', ['email' => array_pop($to) ]);
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
				}
			}
			return false;
		}
		return true;
	}

}
