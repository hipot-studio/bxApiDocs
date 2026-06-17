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
	public function deleteAction(array $ids, bool $deleteImmediately = false): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::delete($ids, $deleteImmediately);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param string[] $ids
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function markAsSpamAction(array $ids): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param string[] $ids
	 * @return array
	 * @throws \Exception
	 */
	public function restoreFromSpamAction(array $ids): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::restoreFromSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param array $ids
	 * @return array
	 * @throws \Exception
	 */
	public function moveToFolderAction(array $ids, string $folderPath): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::moveToFolder($ids, $folderPath, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param array $ids
	 * @return array
	 */
	public function markAsUnseenAction(array $ids): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsUnseen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param array $ids
	 * @param bool $isRead
	 * @return array
	 */
	public function changeReadStatusAction(array $ids, int $isRead): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markMessages($ids, (int)$isRead);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
	}

	/**
	 * @param array $ids
	 * @return array
	 */
	public function markAsSeenAction(array $ids): array
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSeen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}

		return [
			'processedIds' => $result->getData()['processedIds'] ?? [],
		];
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