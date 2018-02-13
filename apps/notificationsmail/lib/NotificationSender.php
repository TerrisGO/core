<?php
/**
 * @author Juan Pablo Villafáñez <jvillafanez@solidgear.es>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\NotificationsMail;

use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Mail\IMailer;
use OCP\IConfig;
use OCP\L10N\IFactory;

class NotificationSender {
	/** @var IMailer */
	private $mailer;

	/** @var IManager */
	private $manager;

	/** @var IConfig */
	private $config;

	/** @var IFactory */
	private $l10nFactory;

	public function __construct(IManager $manager, IMailer $mailer, IConfig $config, IFactory $l10nFactory) {
		$this->manager = $manager;
		$this->mailer = $mailer;
		$this->config = $config;
		$this->l10nFactory = $l10nFactory;
	}

	/**
	 * Send a notification via email to the list of email addresses passed as parameter
	 * @param INotification $notification the notification to be sent
	 * @param string $serverUrl the url of the server so the user can access to his instance from the
	 * email. Make sure the url is safe to be used as a clickable link (in case encoding is needed)
	 * @param string[] $emailAddresses the list of email addresses where the notification should be
	 * sent. Normally only one email is needed. Note that in case of several emails, the same email
	 * message will be sent to each of them.
	 * @return \OC\Mail\Message|bool the message sent, or false if the mail isn't sent
	 */
	public function sendNotification(INotification $notification, $serverUrl, array $emailAddresses) {
		if (!$this->willSendNotification($notification)) {
			return false;
		}

		$targetUser = $notification->getUser();
		$language = $this->config->getUserValue($targetUser, 'core', 'lang', null);

		$notification = $this->manager->prepare($notification, $language);

		$emailMessage = $this->mailer->createMessage();
		$emailMessage->setTo($emailAddresses);

		$l10n = $this->l10nFactory->get('notificationsmail', $language);

		$notificationObjectType = $notification->getObjectType();
		$notificationObjectId = $notification->getObjectId();
		$generatedId = "$notificationObjectType#$notificationObjectId";

		$subject = 'You\'ve received a new notification in %s : "%s"';
		$translatedSubject = (string)$l10n->t($subject, [$serverUrl, $generatedId]);
		$emailMessage->setSubject($translatedSubject);

		$body = 'Go to %s to check the notification';
		$translatedPlainBody = (string)$l10n->t($body, [$serverUrl]);
		$serverUrlLink = "<a href=\"$serverUrl\">$serverUrl<a/>";
		$translatedHtmlBody = (string)$l10n->t($body, [$serverUrlLink]);

		$parsedSubject = $notification->getParsedSubject();
		$parsedMessage = $notification->getParsedMessage();
		$plainText = "$parsedSubject\n\n$parsedMessage\n\n$translatedPlainBody";
		$htmlText = "$parsedSubject</br></br>$parsedMessage</br></br>$translatedHtmlBody";
		$emailMessage->setPlainBody($plainText);
		$emailMessage->setHtmlBody($htmlText);

		$this->mailer->send($emailMessage);

		return $emailMessage;
	}

	/**
	 * Validate the list of emails and split the list into valid and invalid emails.
	 * @param array $emails the list of emails that needs to be verified
	 * @return array an array with 2 keys: "valid" for the list of valid email and "invalid" for
	 * the invalid ones as follows:
	 * ['valid' => ['a@example.com', 'b@example.com'], 'invalid' => ['foo', 'bar@bar@bar']]
	 */
	public function validateEmails(array $emails) {
		$result = ['valid' => [], 'invalid' => []];
		foreach ($emails as $email) {
			if ($this->mailer->validateMailAddress($email)) {
				$result['valid'][] = $email;
			} else {
				$result['invalid'][] = $email;
			}
		}
		return $result;
	}

	/**
	 * Check if the notification will be sent according to the configuration set. This will be checked
	 * here to enforce the behaviour, but it should be also checked upwards to fail faster.
	 * The checks of this function shouldn't consider the notification as prepared in order to use
	 * this function as soon as possible
	 * @param INotification $notification the notification that will be checked
	 * @return true if the notification will be sent by the sendNotification method, false otherwise
	 */
	public function willSendNotification(INotification $notification) {
		$option = $this->config->getUserValue($notification->getUser(), 'notificationsmail', 'email_sending_option', 'never');
		switch ($option) {
			case 'never':
				return false;
			case 'always':
				return true;
			case 'action':
				return !empty($notification->getActions());
			default:
				return false;
		}
	}
}
