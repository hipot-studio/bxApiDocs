<?php

namespace Bitrix\Crm\Security\Notification\Queue;

use Bitrix\Crm\Security\Notification\NotificationSender;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

final class ReadPermissionAddReceiver extends AbstractReceiver
{
	private NotificationSender $notificationSender;

	public function __construct()
	{
		Loader::includeModule('crm');

		$this->notificationSender = NotificationSender::getInstance();
	}

	protected function process(MessageInterface $message): void
	{
		$data = $message->jsonSerialize();
		$user = Container::getInstance()->getUserBroker()->getById($data['fromUserId']);

		$titlePlainCallback = static fn (?string $languageId = null) => Loc::getMessage(
			'CRM_PERMS_NOTIFICATION_TITLE_PLAIN',
			[
				'#AUTHOR#' => $user['FORMATTED_NAME'],
				'#NAME#' => $data['automatedSolutionTitle'],
			],
			$languageId,
		);

		$titleCallback = static fn (?string $languageId = null) => Loc::getMessage(
			'CRM_PERMS_NOTIFICATION_TITLE',
			[
				'#HREF#' => $data['sectionHref'],
			],
			$languageId,
		);

		$this->notificationSender->send($message->jsonSerialize(), [
			'titleCallback' => $titleCallback,
			'titlePlainCallback' => $titlePlainCallback,
		]);
	}
}
