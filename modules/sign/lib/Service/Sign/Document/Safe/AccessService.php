<?php

namespace Bitrix\Sign\Service\Sign\Document\Safe;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Rule\SafeFolderRule;
use Bitrix\Sign\Item\Document\SafeFolder;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Service\Container;

/**
 * CRUD access wrappers for company safe folders and document moves. Every check is delegated to
 * SafeFolderRule (NORMATIVE ALG-01): the SIGN_B2E_MY_SAFE toggle is a hard gate, then the folder
 * permission owner-scope applies. A folderless document falls back to the SIGN_B2E_MY_SAFE_DOCUMENTS
 * owner-scope.
 */
class AccessService
{
	private readonly SafeFolderRule $safeFolderRule;
	private readonly SafeFolderRepository $safeFolderRepository;
	private ?UserModel $currentUserAccessModel;

	/**
	 * Per-operation cache of loaded folders (folderId => folder|null), so a batch of member checks
	 * against the same source/target folder does not reload it once per member (N+1).
	 *
	 * @var array<int, ?SafeFolder>
	 */
	private array $folderById = [];

	public function __construct(
		?SafeFolderRule $safeFolderRule = null,
		?SafeFolderRepository $safeFolderRepository = null,
		?UserModel $currentUserAccessModel = null,
	)
	{
		$container = Container::instance();
		$this->safeFolderRule = $safeFolderRule ?? new SafeFolderRule();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->currentUserAccessModel = $currentUserAccessModel;
	}

	public function hasAccessToCreate(): bool
	{
		$user = $this->getCurrentUserAccessModel();
		if ($user === null)
		{
			return false;
		}

		return $this->safeFolderRule->canAccessSafeFolderByOwner(
			$user,
			null,
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_CREATE,
		);
	}

	public function hasAccessToRead(SafeFolder $folder): bool
	{
		return $this->checkFolderItem($folder, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ);
	}

	public function hasAccessToEdit(SafeFolder $folder): bool
	{
		return $this->checkFolderItem($folder, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE);
	}

	public function hasAccessToDelete(SafeFolder $folder): bool
	{
		return $this->checkFolderItem($folder, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_DELETE);
	}

	public function hasAccessToEditFolderById(int $folderId): bool
	{
		return $this->checkFolderById($folderId, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE);
	}

	/**
	 * Seeds the folder cache with an already loaded folder so the following access checks reuse it
	 * instead of reloading it from the repository (used for the shared move target folder).
	 */
	public function primeFolder(SafeFolder $folder): void
	{
		$this->folderById[$folder->getId()] = $folder;
	}

	/**
	 * A safe member (grid row) can be moved only when the user can edit it in its source location
	 * AND may place it into the target location. After the move access is governed by the target
	 * folder. Membership is per member: moving one member never touches the other members of the same
	 * document.
	 */
	public function canMoveMember(
		?int $sourceFolderId,
		?int $targetFolderId,
		int $recordOwnerId,
		bool $hasOwnerScopeAccess = false,
	): bool
	{
		$user = $this->getCurrentUserAccessModel();
		if ($user === null)
		{
			return false;
		}

		if (!$this->canEditRecordInSource($user, $sourceFolderId, $recordOwnerId, $hasOwnerScopeAccess))
		{
			return false;
		}

		return $this->canPlaceRecordIntoTarget($user, $targetFolderId, $recordOwnerId, $hasOwnerScopeAccess);
	}

	private function canEditRecordInSource(
		UserModel $user,
		?int $sourceFolderId,
		int $recordOwnerId,
		bool $hasOwnerScopeAccess,
	): bool
	{
		if ($sourceFolderId !== null && $sourceFolderId > 0)
		{
			return $this->checkFolderById($sourceFolderId, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE);
		}

		return $hasOwnerScopeAccess || $this->safeFolderRule->canAccessSafeRecord($user, null, $recordOwnerId);
	}

	private function canPlaceRecordIntoTarget(
		UserModel $user,
		?int $targetFolderId,
		int $recordOwnerId,
		bool $hasOwnerScopeAccess,
	): bool
	{
		if ($targetFolderId === null || $targetFolderId <= 0)
		{
			return $hasOwnerScopeAccess || $this->safeFolderRule->canAccessSafeRecord($user, null, $recordOwnerId);
		}

		return $this->checkFolderById($targetFolderId, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE);
	}

	private function checkFolderItem(SafeFolder $folder, string $action): bool
	{
		$user = $this->getCurrentUserAccessModel();
		if ($user === null)
		{
			return false;
		}

		return $this->safeFolderRule->canAccessSafeFolderItem($user, $folder, $action);
	}

	private function checkFolderById(int $folderId, string $action): bool
	{
		$user = $this->getCurrentUserAccessModel();
		if ($user === null)
		{
			return false;
		}

		$folder = $this->resolveFolder($folderId);
		if ($folder === null)
		{
			return false;
		}

		return $this->safeFolderRule->canAccessSafeFolderItem($user, $folder, $action);
	}

	private function resolveFolder(int $folderId): ?SafeFolder
	{
		if (!array_key_exists($folderId, $this->folderById))
		{
			$this->folderById[$folderId] = $this->safeFolderRepository->getById($folderId);
		}

		return $this->folderById[$folderId];
	}

	public function getCurrentUserAccessModel(): ?UserModel
	{
		if ($this->currentUserAccessModel !== null)
		{
			return $this->currentUserAccessModel;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return null;
		}

		return $this->currentUserAccessModel = UserModel::createFromId($currentUserId);
	}
}
