<?php

namespace Bitrix\Sign\Service\Sign\Document\Safe;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\DB\Order;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Rule\SafeFolderRule;
use Bitrix\Sign\Access\Service\RolePermissionService;
use Bitrix\Sign\Item\Document\SafeFolderCollection;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Service\Container;

/**
 * Level listing for the company safe ("My Safe"), shared by the sign.document.list component and
 * the MySafe REST surface (single source of truth). The grid shows two row types — folders and
 * documents — for the current level (folderId), mirroring the templates grid: there is no folder
 * column, no cross-folder filter and no cross-folder search.
 *
 * NORMATIVE ALG-02 (level listing):
 *   listLevel(user, folderId):
 *     if not hasSafeAccess(user): denied
 *     if folderId is root (0/null):
 *         folders  = safeFoldersByOwnerScope(user, SIGN_B2E_MY_SAFE_FOLDER_READ)
 *         members  = members where FOLDER_ID is null AND ownerScope(user, SIGN_B2E_MY_SAFE_DOCUMENTS)
 *     else:
 *         if not canAccessSafeFolder(user, folderId, READ): denied
 *         folders  = []                          # single level, no nesting
 *         members  = members where FOLDER_ID = folderId
 *
 * Folder membership lives on the member (grid row), not on the document: one company document has
 * two members (representative + employee) and each may sit in a different folder. A folder gates its
 * content: inside a folder the members are not owner-scoped again — the folder read gate governs
 * access. Filters are built against the member query context (Bitrix\Sign\Internal\MemberTable): the
 * level uses the runtime FOLDER_ID field from the member-to-folder relation, while the documents
 * owner-scope reuses the legacy grid semantics (creator OR company-representative branch through
 * the DOCUMENT reference). Folder sets are resolved batch-wise, never per row.
 */
final class ListService
{
	private const FOLDER_COLUMN = 'SAFE_FOLDER_RELATION.PARENT_ID';
	private const CREATED_BY_COLUMN = 'CREATED_BY_ID';
	private const INITIATED_BY_COLUMN = 'DOCUMENT.INITIATED_BY_TYPE';
	private const REPRESENTATIVE_COLUMN = 'DOCUMENT.REPRESENTATIVE_ID';
	private const ENTITY_TYPE_COLUMN = 'ENTITY_TYPE';
	private const FOLDER_OWNER_COLUMN = 'SAFE_FOLDER.CREATED_BY_ID';

	private readonly RolePermissionService $rolePermissionService;
	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly SafeFolderRule $safeFolderRule;
	private readonly OwnerScopeService $ownerScopeService;

	public function __construct(
		?RolePermissionService $rolePermissionService = null,
		?SafeFolderRepository $safeFolderRepository = null,
		?SafeFolderRule $safeFolderRule = null,
		?OwnerScopeService $ownerScopeService = null,
	)
	{
		$container = Container::instance();
		$this->rolePermissionService = $rolePermissionService ?? $container->getRolePermissionService();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->safeFolderRule = $safeFolderRule
			?? new SafeFolderRule($this->rolePermissionService, $this->safeFolderRepository)
		;
		$this->ownerScopeService = $ownerScopeService ?? new OwnerScopeService($this->rolePermissionService);
	}

	/**
	 * ALG-02 gate: whether the user may list the given level. Root (0/null) requires safe access;
	 * a folder (>0) requires the folder read owner-scope.
	 */
	public function canAccessLevel(UserModel $user, ?int $folderId): bool
	{
		if ($user->getUserId() <= 0)
		{
			return false;
		}

		if ($this->isRoot($folderId))
		{
			return $this->hasSafeAccess($user);
		}

		return $this->safeFolderRule->canAccessSafeFolder(
			$user,
			$folderId,
			ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ,
		);
	}

	/**
	 * Folder rows for the level (DTO-01). Root: folders the user may read (folder-read owner-scope).
	 * Inside a folder: an empty collection (the safe is a single level, folders are not nested).
	 */
	public function getLevelFolders(UserModel $user, ?int $folderId): SafeFolderCollection
	{
		if (!$this->isRoot($folderId))
		{
			return new SafeFolderCollection();
		}

		$ownerIds = $this->resolveFolderOwnerIds($user);

		return match (true)
		{
			$ownerIds === null => $this->safeFolderRepository->getAll(),
			$ownerIds === [] => new SafeFolderCollection(),
			default => $this->safeFolderRepository->getByOwnerIds($ownerIds),
		};
	}

	/**
	 * @param list<int>|null $folderIds null means no filter by member matches
	 */
	public function countLevelFolders(UserModel $user, ?int $folderId, ?array $folderIds = null): int
	{
		if (!$this->isRoot($folderId))
		{
			return 0;
		}

		return $this->safeFolderRepository->countByScope(
			$this->resolveFolderOwnerIds($user),
			$folderIds,
		);
	}

	/**
	 * @param list<int>|null $folderIds null means no filter by member matches
	 * @param Order|null $titleOrder null defaults to newest-first (folder id descending)
	 */
	public function getLevelFoldersPage(
		UserModel $user,
		?int $folderId,
		int $limit,
		int $offset,
		?array $folderIds = null,
		?Order $titleOrder = null,
	): SafeFolderCollection
	{
		if (!$this->isRoot($folderId))
		{
			return new SafeFolderCollection();
		}

		return $this->safeFolderRepository->listByScope(
			$this->resolveFolderOwnerIds($user),
			$limit,
			$offset,
			$folderIds,
			$titleOrder,
		);
	}

	public function countWritableFolders(UserModel $user): int
	{
		return $this->safeFolderRepository->countByScope(
			$this->resolveWritableFolderOwnerIds($user),
		);
	}

	public function getWritableFoldersPage(
		UserModel $user,
		int $limit,
		int $offset,
	): SafeFolderCollection
	{
		return $this->safeFolderRepository->listByScope(
			$this->resolveWritableFolderOwnerIds($user),
			$limit,
			$offset,
		);
	}

	/**
	 * Combined page slice for a level that mixes folder rows and member rows into one page budget
	 * (P5.T1), mirroring the templates grid: folders come first and overflow onto the next pages,
	 * members fill the remaining budget. Returns the folder/member offsets and limits for the
	 * requested page plus the total row count across both types.
	 *
	 * @return array{folderOffset:int, folderLimit:int, memberOffset:int, memberLimit:int, total:int}
	 */
	public function calculatePageSlice(int $folderCount, int $memberTotal, int $page, int $pageSize): array
	{
		$folderCount = max(0, $folderCount);
		$memberTotal = max(0, $memberTotal);
		$page = max(1, $page);
		$pageSize = max(1, $pageSize);

		$offset = ($page - 1) * $pageSize;

		$folderOffset = min($offset, $folderCount);
		$folderLimit = max(0, min($folderCount - $folderOffset, $pageSize));

		$memberOffset = max(0, $offset - $folderCount);
		$memberLimit = $pageSize - $folderLimit;

		return [
			'folderOffset' => $folderOffset,
			'folderLimit' => $folderLimit,
			'memberOffset' => $memberOffset,
			'memberLimit' => $memberLimit,
			'total' => $folderCount + $memberTotal,
		];
	}

	/**
	 * Restricts the level folders to those that contain at least one member matching the active
	 * filter (P5.T2). `$folderIdsWithMembers` is the batch-resolved set of folder ids that have a
	 * matching member; without an active filter callers skip this and show all accessible folders.
	 */
	public function filterFoldersByMemberFilter(
		SafeFolderCollection $folders,
		array $folderIdsWithMembers,
	): SafeFolderCollection
	{
		$allowed = [];
		foreach ($folderIdsWithMembers as $id)
		{
			$allowed[(int)$id] = true;
		}

		$filtered = new SafeFolderCollection();
		foreach ($folders as $folder)
		{
			if (isset($allowed[$folder->getId()]))
			{
				$filtered->add($folder);
			}
		}

		return $filtered;
	}

	/**
	 * Document ACL/level filter (ALG-02). Root: FOLDER_ID is null AND documents owner-scope. Inside
	 * a folder: FOLDER_ID = folderId, with no additional owner-scope (the folder read gate, checked
	 * via canAccessLevel, governs access to the whole folder content).
	 */
	public function buildLevelDocumentFilter(UserModel $user, ?int $folderId): ConditionTree
	{
		if (!$this->isRoot($folderId))
		{
			return Query::filter()->where(self::FOLDER_COLUMN, '=', $folderId);
		}

		$filter = Query::filter()->where(
			Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->whereNull(self::FOLDER_COLUMN)
				->where(self::FOLDER_COLUMN, 0),
		)
		;

		$ownerScope = $this->resolveDocumentsOwnerScope($user);
		if ($ownerScope !== null)
		{
			$filter->where($ownerScope);
		}

		return $filter;
	}

	/**
	 * Flat document ACL filter for the safe when folder grouping is enabled: a single, backward
	 * compatible flat document list that unions the folderless bucket (documents with no folder,
	 * gated by the documents owner-scope) with the documents living in folders the user may read
	 * (the folder read gate governs their content, regardless of the record owner). An admin sees
	 * everything (no restriction). Folder sets are resolved batch-wise, never per row.
	 */
	public function buildFlatDocumentFilter(UserModel $user): ConditionTree
	{
		if ($user->isAdmin())
		{
			return Query::filter();
		}

		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);

		// Folderless bucket: documents with no folder, gated by the documents owner-scope.
		$folderless = Query::filter()->where(
			Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->whereNull(self::FOLDER_COLUMN)
				->where(self::FOLDER_COLUMN, 0),
		)
		;
		$ownerScope = $this->resolveDocumentsOwnerScope($user);
		if ($ownerScope !== null)
		{
			$folderless->where($ownerScope);
		}
		$filter->where($folderless);

		// Documents inside folders the user may read; no owner-scope is re-applied to their content.
		$folderScope = $this->buildReadableFolderScopeFilter($user);
		if ($folderScope !== null)
		{
			$filter->where($folderScope);
		}

		return $filter;
	}

	/**
	 * Folder branch for member queries. `null` means that the user cannot read any folder.
	 */
	public function buildReadableFolderScopeFilter(UserModel $user): ?ConditionTree
	{
		if ($user->isAdmin())
		{
			return Query::filter()->where(self::FOLDER_COLUMN, '>', 0);
		}

		$ownerIds = $this->resolveFolderOwnerIds($user);
		if ($ownerIds === null)
		{
			return Query::filter()->where(self::FOLDER_COLUMN, '>', 0);
		}
		if ($ownerIds === [])
		{
			return null;
		}

		return Query::filter()
			->where(self::FOLDER_COLUMN, '>', 0)
			->whereIn(self::FOLDER_OWNER_COLUMN, $ownerIds)
		;
	}

	/**
	 * `null` means unrestricted owner scope; an empty list means no readable folders.
	 *
	 * @return list<int>|null
	 */
	public function resolveFolderOwnerIds(UserModel $user): ?array
	{
		return $this->ownerScopeService->resolveOwnerIds(
			$user,
			SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
		);
	}

	/**
	 * `null` means unrestricted owner scope; an empty list means no writable folders.
	 *
	 * @return list<int>|null
	 */
	private function resolveWritableFolderOwnerIds(UserModel $user): ?array
	{
		return $this->ownerScopeService->resolveOwnerIds(
			$user,
			SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
		);
	}

	/**
	 * Legacy safe document filter used when folder grouping is disabled: documents owner-scope only,
	 * with no FOLDER_ID condition. Preserves the pre-folder safe behavior.
	 */
	public function buildOwnerScopeFilter(UserModel $user): ConditionTree
	{
		$ownerScope = $this->resolveDocumentsOwnerScope($user);

		return $ownerScope ?? Query::filter();
	}

	/**
	 * Documents owner-scope. `null` means no restriction (admin / ALL). Preserves the legacy grid
	 * semantics: a document belongs to the user either as its creator or as the company
	 * representative of an employee-initiated document.
	 */
	private function resolveDocumentsOwnerScope(UserModel $user): ?ConditionTree
	{
		return $this->ownerScopeService->buildDocumentCondition(
			$user,
			SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
			self::CREATED_BY_COLUMN,
			self::INITIATED_BY_COLUMN,
			self::REPRESENTATIVE_COLUMN,
			self::ENTITY_TYPE_COLUMN,
		);
	}

	private function isRoot(?int $folderId): bool
	{
		return $folderId === null || $folderId <= 0;
	}

	private function hasSafeAccess(UserModel $user): bool
	{
		if ($user->isAdmin())
		{
			return true;
		}

		$value = $this->getPermissionValue($user, SignPermissionDictionary::SIGN_B2E_MY_SAFE);

		return $value !== null && $value !== '0' && $value !== UserPermissions::PERMISSION_NONE;
	}

	private function getPermissionValue(UserModel $user, int $permissionId): ?string
	{
		return $this->rolePermissionService->getValueForPermission($user->getRoles(), (string)$permissionId);
	}
}
