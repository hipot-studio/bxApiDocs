<?php

namespace Bitrix\Sign\Access\Rule;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService;
use Bitrix\Sign\Item\Document\SafeFolder;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\Safe\OwnerScopeService;

/**
 * Company safe access composition (NORMATIVE ALG-01): safe -> folder -> record (member).
 *
 * Folder membership lives on the safe record (member/grid row), not on the document. The section
 * toggle SIGN_B2E_MY_SAFE is a hard gate: without it nothing under the safe is accessible. A folder
 * gates its content — a record in a folder is reachable only through the folder read permission; the
 * record's own owner-scope is not considered. A folderless record ("No folder") falls back to the
 * SIGN_B2E_MY_SAFE_DOCUMENTS owner-scope by the record owner. There is no third state: no access to
 * the folder means the record is denied.
 */
class SafeFolderRule
{
	private readonly RolePermissionService $rolePermissionService;
	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly OwnerScopeService $ownerScopeService;
	private array $permissionValueCache = [];

	public function __construct(
		?RolePermissionService $rolePermissionService = null,
		?SafeFolderRepository $safeFolderRepository = null,
		?OwnerScopeService $ownerScopeService = null,
	)
	{
		$container = Container::instance();
		$this->rolePermissionService = $rolePermissionService ?? $container->getRolePermissionService();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->ownerScopeService = $ownerScopeService ?? new OwnerScopeService($this->rolePermissionService);
	}

	/**
	 * Whether the action targets a company safe folder.
	 */
	public static function isSafeFolderAction(string $action): bool
	{
		return in_array($action, [
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ,
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_CREATE,
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE,
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_DELETE,
		], true);
	}

	/**
	 * canAccessSafeFolder(user, folderId, level) from ALG-01: load the folder and check the
	 * folder permission owner-scope for the given action, behind the safe access gate.
	 */
	public function canAccessSafeFolder(
		UserModel $user,
		int $folderId,
		string $action = ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ,
	): bool
	{
		if (!$this->hasValidUserAndSafeAccess($user))
		{
			return false;
		}

		$folder = $this->safeFolderRepository->getById($folderId);
		if ($folder === null)
		{
			return false;
		}

		return $this->canAccessSafeFolderItem($user, $folder, $action);
	}

	/**
	 * Same as canAccessSafeFolder but for an already loaded folder item.
	 */
	public function canAccessSafeFolderItem(UserModel $user, SafeFolder $folder, string $action): bool
	{
		return $this->canAccessSafeFolderByOwner($user, $folder->getOwnerId(), $action);
	}

	/**
	 * Pipeline entry point: the folder owner is already resolved from the accessible item. A null
	 * owner is valid only for folder creation; all item-aware actions fail closed without a target.
	 */
	public function canAccessSafeFolderByOwner(UserModel $user, ?int $folderOwnerId, string $action): bool
	{
		if (!$this->hasValidUserAndSafeAccess($user))
		{
			return false;
		}

		$permissionId = $this->folderPermissionIdByAction($action);
		if ($permissionId === null)
		{
			return false;
		}

		if ($folderOwnerId === null)
		{
			return $action === ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_CREATE
				&& $this->isPermissionGranted($user, $permissionId)
			;
		}

		return $this->ownerScopeService->canAccessOwner($user, $permissionId, $folderOwnerId);
	}

	/**
	 * canAccessSafeRecord(user, folderId, recordOwnerId) from ALG-01: the folder gates the record; a
	 * folderless record ("No folder") uses the safe documents owner-scope by the record owner.
	 */
	public function canAccessSafeRecord(UserModel $user, ?int $folderId, int $recordOwnerId): bool
	{
		if (!$this->hasValidUserAndSafeAccess($user))
		{
			return false;
		}

		if ($folderId === null || $folderId <= 0)
		{
			return $this->ownerScopeService->canAccessOwner(
				$user,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
				$recordOwnerId,
			);
		}

		return $this->canAccessSafeFolder($user, $folderId, ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ);
	}

	private function hasValidUserAndSafeAccess(UserModel $user): bool
	{
		return $user->getUserId() > 0 && $this->hasSafeAccess($user);
	}

	private function hasSafeAccess(UserModel $user): bool
	{
		return $this->isPermissionGranted($user, SignPermissionDictionary::SIGN_B2E_MY_SAFE);
	}

	private function folderPermissionIdByAction(string $action): ?int
	{
		if (!self::isSafeFolderAction($action))
		{
			return null;
		}

		$permissionId = ActionDictionary::getPermissionIdByAction($action);

		return is_int($permissionId) ? $permissionId : null;
	}

	private function isPermissionGranted(UserModel $user, int $permissionId): bool
	{
		if ($user->isAdmin())
		{
			return true;
		}

		return $this->isValueGranted($this->getPermissionValue($user, $permissionId));
	}

	private function getPermissionValue(UserModel $user, int $permissionId): ?string
	{
		$roles = $user->getRoles();
		sort($roles, SORT_NUMERIC);
		$cacheKey = $user->getUserId() . ':' . implode(',', $roles) . ':' . $permissionId;

		if (!array_key_exists($cacheKey, $this->permissionValueCache))
		{
			$this->permissionValueCache[$cacheKey] = $this->rolePermissionService->getValueForPermission(
				$roles,
				(string)$permissionId,
			);
		}

		return $this->permissionValueCache[$cacheKey];
	}

	private function isValueGranted(?string $value): bool
	{
		return $value !== null
			&& $value !== '0'
			&& $value !== UserPermissions::PERMISSION_NONE;
	}
}
