<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internal\Service\FavoritesService;
use Bitrix\Mail\MailMessageUidTable;
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
	 * The controller allows GET and drops Csrf from the default prefilters,
	 * so a state-changing action has to ask for both back.
	 *
	 * @param string $id Composite grid id "{uidId}-{mailboxId}"
	 * @param string $isFavorite Target state, 'Y' to add, otherwise remove
	 * @return array{id: string, isFavorite: bool}|null
	 */
	#[Main\Engine\ActionFilter\Attribute\Rule\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST])]
	#[Main\Engine\ActionFilter\Attribute\Rule\Csrf]
	public function setFavoriteStateAction(string $id, string $isFavorite): ?array
	{
		if (!\Bitrix\Mail\Helper\Config\Feature::isMailListImprovementsAvailable())
		{
			$this->addError(new Main\Error('Feature is not available.', 'MAIL_LIST_IMPROVEMENTS_DISABLED'));

			return null;
		}

		$target = $this->resolveFavoriteTarget($id);
		if ($target === null)
		{
			$this->addError(new Main\Error('Invalid message id.', 'MAIL_FAVORITE_INVALID_ID'));

			return null;
		}

		[$mailboxId, $messageId] = $target;
		$userId = (int)$this->getCurrentUser()->getId();
		$isFavorite = $isFavorite === 'Y';

		$service = new FavoritesService();
		$result = $isFavorite
			? $service->addToFavorites($userId, $mailboxId, $messageId)
			: $service->removeFromFavorites($userId, $mailboxId, $messageId);

		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return null;
		}

		return [
			'id' => $id,
			'isFavorite' => $isFavorite,
		];
	}

	/**
	 * @return array{0: int, 1: int}|null [mailboxId, messageId], or null when unresolved
	 */
	private function resolveFavoriteTarget(string $rawId): ?array
	{
		$parts = explode('-', $rawId, 2);
		if (count($parts) !== 2)
		{
			return null;
		}

		[$uidId, $mailboxId] = $parts;
		$mailboxId = (int)$mailboxId;
		if ($uidId === '' || $mailboxId <= 0)
		{
			return null;
		}

		$row = MailMessageUidTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'MESSAGE_ID'],
			'filter' => array_merge(
				QueryBuilder::VISIBLE_UID_FILTERS_DRIVER,
				[
					'=ID' => $uidId,
					'=MAILBOX_ID' => $mailboxId,
				],
			),
			'limit' => 1,
		])->fetch();

		if (!$row)
		{
			return null;
		}

		return [$mailboxId, (int)$row['MESSAGE_ID']];
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