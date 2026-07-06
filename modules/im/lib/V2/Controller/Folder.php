<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Create\FolderFields;
use Bitrix\Im\V2\Folder\Detail\Enricher;
use Bitrix\Im\V2\Folder\FolderChatService;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\FolderService;
use Bitrix\Im\V2\Folder\Sort\FolderSortService;
use Bitrix\Im\V2\Folder\Update\UpdateFields;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;

class Folder extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new ValidationParameter(
					FolderFields::class,
					fn ($className, array $fields): FolderFields => FolderFields::fromArray($fields),
					fn () => 'fields',
				),
				new ValidationParameter(
					UpdateFields::class,
					fn ($className, array $fields): UpdateFields => UpdateFields::fromArray($fields),
					fn () => 'fields',
				),
			],
		);
	}
	/**
	 * @restMethod im.v2.Folder.list
	 */
	public function listAction(CurrentUser $user, FolderProvider $folderProvider): array
	{
		$userId = (int)$user->getId();
		$collection = $folderProvider->getByUser($userId)->onlyAvailable($userId);

		return $this->toRestFormat($collection);
	}

	/**
	 * @restMethod im.v2.Folder.get
	 */
	public function getAction(
		\Bitrix\Im\V2\Folder\Folder $folder,
		Enricher $enricher,
		CurrentUser $user,
	): ?array
	{
		$result = $enricher->enrich($folder, (int)$user->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->toRestFormat($result->getDetail());
	}

	/**
	 * @restMethod im.v2.Folder.add
	 */
	public function addAction(
		FolderFields $fields,
		CurrentUser $user,
		FolderService $folderService,
	): ?array
	{
		$result = $folderService->create($fields, (int)$user->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$folder = $result->getFolder();

		return $folder !== null ? $this->toRestFormat($folder) : null;
	}

	/**
	 * @restMethod im.v2.Folder.update
	 */
	public function updateAction(
		\Bitrix\Im\V2\Folder\Folder $folder,
		UpdateFields $fields,
		FolderService $folderService,
	): ?array
	{
		$result = $folderService->update($folder, $fields);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$updated = $result->getFolder();

		return $updated !== null ? $this->toRestFormat($updated) : null;
	}

	/**
	 * @restMethod im.v2.Folder.delete
	 */
	public function deleteAction(\Bitrix\Im\V2\Folder\Folder $folder, FolderService $folderService): ?array
	{
		$result = $folderService->delete($folder);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Folder.sort
	 */
	public function sortAction(CurrentUser $user, FolderSortService $folderSortService, array $folderIds): ?array
	{
		$normalizedIds = Normalizer::toUniquePositiveIntegers($folderIds);

		$result = $folderSortService->saveSortOrder((int)$user->getId(), $normalizedIds);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Folder.addChats
	 */
	public function addChatsAction(\Bitrix\Im\V2\Folder\Folder $folder, FolderChatService $folderChatService, array $chatIds): ?array
	{
		$result = $folderChatService->addChats(
			$folder,
			Normalizer::toUniquePositiveIntegers($chatIds)
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Folder.deleteChats
	 */
	public function deleteChatsAction(\Bitrix\Im\V2\Folder\Folder $folder, FolderChatService $folderChatService, array $chatIds): ?array
	{
		$result = $folderChatService->deleteChats(
			$folder,
			Normalizer::toUniquePositiveIntegers($chatIds)
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Folder.setChatFolders
	 */
	public function setChatFoldersAction(
		\Bitrix\Im\V2\Chat $chat,
		FolderChatService $folderChatService,
		CurrentUser $user,
		array $folderIds = [],
	): ?array
	{
		$result = $folderChatService->setChatFolders(
			(int)$user->getId(),
			$chat->getChatId(),
			Normalizer::toUniquePositiveIntegers($folderIds)
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
