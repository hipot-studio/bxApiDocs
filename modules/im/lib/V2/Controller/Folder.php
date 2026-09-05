<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Chat\DialogChatIdResolver;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Create\FolderFields;
use Bitrix\Im\V2\Folder\Detail\Enricher;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Error\IneligibleChatsException;
use Bitrix\Im\V2\Folder\FolderChatService;
use Bitrix\Im\V2\Folder\FolderLimits;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\FolderService;
use Bitrix\Im\V2\Folder\Sort\FolderSortService;
use Bitrix\Im\V2\Folder\Update\UpdateFields;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
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
					fn ($className, array $fields): FolderFields => $this->buildFolderFields($fields),
					fn () => 'fields',
				),
				new ValidationParameter(
					UpdateFields::class,
					fn ($className, array $fields): UpdateFields => $this->buildUpdateFields($fields),
					fn () => 'fields',
				),
			],
		);
	}

	private function buildFolderFields(array $fields): FolderFields
	{
		try
		{
			return FolderFields::fromArray(
				$fields,
				$this->getDialogChatIdResolver(),
				(int)$this->getCurrentUser()?->getId(),
			);
		}
		catch (IneligibleChatsException $exception)
		{
			throw new BinderArgumentException(
				$exception->getMessage(),
				'',
				[new Error($exception->getMessage(), FolderError::FOLDER_CHAT_NOT_ELIGIBLE)],
			);
		}
	}

	private function buildUpdateFields(array $fields): UpdateFields
	{
		try
		{
			return UpdateFields::fromArray(
				$fields,
				$this->getDialogChatIdResolver(),
				(int)$this->getCurrentUser()?->getId(),
			);
		}
		catch (IneligibleChatsException $exception)
		{
			throw new BinderArgumentException(
				$exception->getMessage(),
				'',
				[new Error($exception->getMessage(), FolderError::FOLDER_CHAT_NOT_ELIGIBLE)],
			);
		}
	}

	private function getDialogChatIdResolver(): DialogChatIdResolver
	{
		return ServiceLocator::getInstance()->get(DialogChatIdResolver::class);
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
	public function addChatsAction(
		\Bitrix\Im\V2\Folder\Folder $folder,
		FolderChatService $folderChatService,
		DialogChatIdResolver $resolver,
		CurrentUser $user,
		array $chatIds = [],
		array $dialogIds = [],
	): ?array
	{
		if (FolderLimits::exceedsChatComposition($chatIds, $dialogIds))
		{
			$this->addError(new Error(
				'Folder chat composition exceeds the per-folder limit.',
				FolderError::FOLDER_CHATS_LIMIT_EXCEEDED,
			));

			return null;
		}

		$composition = $resolver->resolve($chatIds, $dialogIds, (int)$user->getId());

		$result = $folderChatService->addChats($folder, $composition->values);
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
	public function deleteChatsAction(
		\Bitrix\Im\V2\Folder\Folder $folder,
		FolderChatService $folderChatService,
		DialogChatIdResolver $resolver,
		CurrentUser $user,
		array $chatIds = [],
		array $dialogIds = [],
	): ?array
	{
		// Removal must not create chats: resolving a user dialogId here only needs to match
		// existing membership, so creating a private chat would be a pointless side effect.
		$composition = $resolver->resolve($chatIds, $dialogIds, (int)$user->getId(), createMissing: false);

		$result = $folderChatService->deleteChats($folder, $composition->values);
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
