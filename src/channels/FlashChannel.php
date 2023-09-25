<?php
/**
 * @copyright Anton Tuyakhov <atuyakhov@gmail.com>
 *
 * Modified by santilín <z@zzzzz.es> on sep 2023 to add proper handling of errors.
 */
namespace tuyakhov\notifications\channels;

use tuyakhov\notifications\NotifiableInterface;
use tuyakhov\notifications\NotificationInterface;
use yii\base\Component;
use yii\di\Instance;

class FlashChannel extends Component implements ChannelInterface
{
    /**
     * @var $mailer MailerInterface|array|string the mailer object or the application component ID of the mailer object.
     */
    public $session = 'session';


    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->session= Instance::ensure($this->session, 'yii\web\Session');
    }

    public function send(NotifiableInterface $recipient, NotificationInterface $notification)
    {
        /**
         * @var $message MailMessage
         */
        $message = $notification->exportFor('flash');
        $this->session->addFlash($message->category, $message->message);
		return true;
	}

}
