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
use yii\di\Instance;
use yii\mail\MailerInterface;

class MailChannel extends Component implements ChannelInterface
{
    /**
     * @var $mailer MailerInterface|array|string the mailer object or the application component ID of the mailer object.
     */
    public $mailer = 'mailer';

    /**
     * The message sender.
     * @var string
     */
    public $from;

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
			$message_views = [ 'html' => $message_views ];
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
		$from = $message->from??'no-reply@'.Url::base(true);
		$to = $recipient->routeNotificationFor('mail');
		$data = $message->viewData;
		$data['recipient'] = $recipient;
		$data['from'] = $from;
		if (!is_array($to) ) {
			$to = [ $to ];
		}
		$subject = $message->subject;
		if( YII_ENV_DEV ) {
			$subject = "[dev:to:" . reset($to) . "]{$subject}";
		}
		$composed = Yii::$app->mailer
			->compose( $message_views, $data )
			->setFrom($from)
			->setTo( YII_ENV_DEV ? Yii::$app->params['develEmail'] : $to )
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
					$notification->addError('mailbody', "View: $view_name\nSubject: $subject\n\n$mailer_error\n\n"
						. trim(strip_tags($html_mail->getBody())));
				}
			} else {
				$notification->addError('sendmail', $error_message);
			}
			return false;
		}
		return true;
	}

}
