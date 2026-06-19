<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\ImOpenLines\V2\QuickReply\QuickReplyService;
use Bitrix\Main\Error;

class QuickReply extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.QuickReply.list
	 */
	public function listAction(int $lineId, string $search = '', int $sectionId = 0, int $offset = 0, int $limit = 50): ?array
	{
		$service = new QuickReplyService();
		$result = $service->getList($lineId, $search, $sectionId, $offset, $limit);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod imopenlines.v2.QuickReply.save
	 */
	public function saveAction(int $lineId, string $text, int $id = 0, int $sectionId = 0): ?array
	{
		$service = new QuickReplyService();
		$result = $service->save($lineId, $text, $id, $sectionId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod imopenlines.v2.QuickReply.saveFromMessage
	 */
	public function saveFromMessageAction(string $dialogId, int $messageId): ?array
	{
		$chatId = \Bitrix\Im\Dialog::getChatId($dialogId);
		if ($chatId <= 0)
		{
			$this->addError(new Error('Invalid dialog', 'INVALID_DIALOG'));

			return null;
		}

		$currentUser = $this->getCurrentUser();
		$service = new QuickReplyService();
		$result = $service->saveFromMessage($chatId, $messageId, (int)$currentUser?->getId());

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod imopenlines.v2.QuickReply.incrementRating
	 */
	public function incrementRatingAction(int $id, int $lineId): ?array
	{
		$service = new QuickReplyService();
		$result = $service->incrementRating($lineId, $id);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod imopenlines.v2.QuickReply.delete
	 */
	public function deleteAction(int $id, int $lineId): ?array
	{
		$service = new QuickReplyService();
		$result = $service->delete($lineId, $id);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
