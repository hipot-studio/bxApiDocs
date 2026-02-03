<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Chat\NotifyChat;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Message\ReadService;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\AutoWire\Parameter;

class Notify extends BaseController
{
	/**
	 * @return Parameter[]
	 */
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				MessageCollection::class,
				'notifications',
				function ($className, array $ids) {
					return $this->getMessagesByIds($ids);
				},
			),
			new ExactParameter(
				MessageCollection::class,
				'excludeNotifications',
				function ($className, array $ids) {
					return $this->getMessagesByIds($ids);
				},
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod im.v2.Notify.deleteAll
	 */
	public function deleteAllAction(): ?array
	{
		$notifyChat = NotifyChat::getByUser();

		if ($notifyChat !== null)
		{
			$notifyChat->dropAll();
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Notify.read
	 */
	public function readAction(MessageCollection $notifications): ?array
	{
		$notifyChat = NotifyChat::getByUser();
		if (!$notifyChat)
		{
			return null;
		}

		$chatId = $notifyChat->getChatId();
		if ($notifications->count() === 0)
		{
			$this->addError(new Error(
				MessageError::NOTIFY_NOT_FOUND,
			));

			return null;
		}

		$readService = new ReadService();
		$readResult = $readService->readUserNotifications($notifications, $chatId);

		if (!$readResult->isSuccess())
		{
			$this->addErrors($readResult->getErrors());

			return null;
		}

		return $this->convertKeysToCamelCase($readResult->getResult());
	}

	/**
	 * @restMethod im.v2.Notify.readAll
	 */
	public function readAllAction(?MessageCollection $excludeNotifications = null): ?array
	{
		$notifyChat = NotifyChat::getByUser();
		if (!$notifyChat)
		{
			return null;
		}

		$chatId = $notifyChat->getChatId();
		$readService = new ReadService();
		$readResult = $readService->readAllNotifications($chatId, $excludeNotifications);

		if (!$readResult->isSuccess())
		{
			$this->addErrors($readResult->getErrors());

			return null;
		}

		return $this->convertKeysToCamelCase($readResult->getResult());
	}
}
