<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Main\Engine\Controller;

/**
 * Class Message
 * Processing email actions, such as deleting, moving to folders, etc.
 *
 * @package Bitrix\Mail\Controller
 */
class Message extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
				),
				new Intranet\ActionFilter\IntranetUser(),
			];
	}

	/**
	 * @param string[] $ids
	 * @param boolean $deleteImmediately
	 * @throws \Exception
	 */
	public function deleteAction(array $ids, bool $deleteImmediately = false): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::delete($ids, $deleteImmediately);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param string[] $ids
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function markAsSpamAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param string[] $ids
	 * @return void
	 * @throws \Exception
	 */
	public function restoreFromSpamAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::restoreFromSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 * @throws \Exception
	 */
	public function moveToFolderAction(array $ids, string $folderPath): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::moveToFolder($ids, $folderPath, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 */
	public function markAsUnseenAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsUnseen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @param bool $isRead
	 * @return void
	 */
	public function changeReadStatusAction(array $ids, int $isRead): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markMessages($ids, (int)$isRead);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 */
	public function markAsSeenAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSeen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param int $messageId
	 * @param int $iteration
	 * @return void
	 */
	public function createCrmActivityAction(int $messageId, int $iteration = 1): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::createCrmActivity($messageId, $iteration);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return bool
	 */
	public function createCrmActivitiesAction(array $ids): bool
	{
		foreach ($ids as $id)
		{
			$result = \Bitrix\Mail\Helper\Message\MessageActions::createCrmActivity($id, 1);

			if (!$result->isSuccess())
			{
				$errors = $result->getErrors();
				$this->addError($errors[0]);

				return false;
			}
		}

		return true;
	}
}