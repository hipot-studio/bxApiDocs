<?php

namespace Bitrix\Rpa;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rpa\Model\EO_Timeline;
use Bitrix\Rpa\Model\Item;
use Bitrix\Rpa\Model\PermissionTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ModuleManager;
use Bitrix\Rpa\Model\Stage;
use Bitrix\Rpa\Model\Timeline;
use Bitrix\Rpa\Model\Type;
use Bitrix\UI\Util;

class UserPermissions
{
	public const ENTITY_STAGE = 'STAGE';
	public const ENTITY_TYPE = 'TYPE';

	public const ACTION_ITEMS_CREATE = 'ITEMS_CREATE';
	public const ACTION_CREATE = 'CREATE';
	public const ACTION_VIEW = 'VIEW';
	public const ACTION_MODIFY = 'MODIFY';
	public const ACTION_MOVE = 'MOVE';
	public const ACTION_DELETE = 'DELETE';

	public const PERMISSION_NONE = '';
	public const PERMISSION_SELF = 'A';
	public const PERMISSION_DEPARTMENT = 'D';
	public const PERMISSION_ANY = 'X';
	public const PERMISSION_ALLOW = 'X';

	public const ACCESS_CODE_ALL_USERS = 'UA';

	protected $isAdmin;
	protected $isExtranet;
	protected $userId;
	protected $permissions;
	protected $accessCodes;
	protected $availableNextStages = [];
	protected $incompleteTaskItemIds = [];

	public function __construct(int $userId)
	{
		$this->userId = $userId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function canViewAtLeastOneType(): bool
	{
		return static::canViewAnyType();
	}

	public function canViewType(int $typeId): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if(static::canViewAnyType())
		{
			return true;
		}

		return $this->canPerform(self::ENTITY_TYPE, $typeId, self::ACTION_VIEW);
	}

	public function canCreateType(): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}

		return true;
	}

	public function canModifyType(int $typeId): bool
	{
		return $this->canPerform(self::ENTITY_TYPE, $typeId, self::ACTION_MODIFY);
	}

	public function canDeleteType(int $typeId): bool
	{
		return $this->canModifyType($typeId);
	}

	public function canViewItemsInStage(Type $type, int $stageId): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if(static::canViewOnAnyStage())
		{
			return true;
		}

		return ($this->canModifyType($type->getId()) || $this->hasExplicitStageRow($stageId));
	}

	public function canModifyItemsInStage(Type $type, int $stageId): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if(static::canEditItemOnAnyStage())
		{
			return $this->canAddItemsToType($type->getId());
		}

		return $this->canPerform(self::ENTITY_STAGE, $stageId, self::ACTION_MODIFY);
	}

	public function canModifyItem(Item $item): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}

		$type = $item->getType();
		// an unsaved item has no saved stage, so only the right to create items of the type applies
		if ($item->getId() === null)
		{
			return $this->canAddItemsToType($type->getId());
		}

		// the saved stage is checked: a stage change in the same request does not change the subject of the check
		$actualStageId = $item->remindActualStageId();
		if ($this->canModifyItemsInStage($type, $actualStageId))
		{
			return true;
		}

		return (
			$this->userId > 0
			&& (int)$item->remindActualCreatedBy() === $this->userId
			&& $this->canAddItemsToType($type->getId())
		);
	}

	public function canAddItemsToType(int $typeId): bool
	{
		return $this->canPerform(self::ENTITY_TYPE, $typeId, static::ACTION_ITEMS_CREATE);
	}

	public function canViewItem(Item $item): bool
	{
		return $this->canViewItemWithTaskParticipants($item, null);
	}

	/**
	 * The same rule as canViewItem() and getFilterForViewableItems().
	 *
	 * @param Item $item
	 * @param int[]|null $incompleteParticipantIds users with not answered tasks of the item, if already known.
	 * If null, the items with not answered tasks of this user are requested.
	 * @return bool
	 */
	public function canViewItemWithTaskParticipants(Item $item, ?array $incompleteParticipantIds): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if (static::canViewOnAnyStage())
		{
			return true;
		}
		if ($this->hasAdminAccess())
		{
			return true;
		}
		// the saved state is checked: changes of the same request do not change the subject of the check
		if (
			(int)$item->remindActualCreatedBy() === $this->userId
			|| (int)$item->remindActualMovedBy() === $this->userId
		)
		{
			return true;
		}
		$type = $item->getType();
		if (
			$this->canModifyType($type->getId())
			|| $this->hasExplicitStageRow((int)$item->remindActualStageId())
		)
		{
			return true;
		}

		if ($incompleteParticipantIds !== null)
		{
			return in_array($this->userId, $incompleteParticipantIds, false);
		}

		return in_array((int)$item->getId(), $this->getIncompleteTaskItemIds($type), true);
	}

	public function canMoveFromStage(Type $type, int $stageId): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if($this->canAddItemsToType($type->getId()))
		{
			return true;
		}

		return $this->canPerform(self::ENTITY_STAGE, $stageId, self::ACTION_MOVE);
	}

	/**
	 * Returns true if the user can start moving the item from the stage (by default - from its saved stage).
	 *
	 * @param Item $item
	 * @param int|null $fromStageId
	 * @return bool
	 */
	public function canMoveItemFromStage(Item $item, ?int $fromStageId = null): bool
	{
		return $this->canStartMovingItem($item, $fromStageId ?? (int)$item->remindActualStageId(), true);
	}

	public function canMoveItem(Item $item, int $fromStageId, int $toStageId): bool
	{
		$result = $this->canStartMovingItem($item, $fromStageId, false);

		if($result && static::canMoveAnywhere())
		{
			return true;
		}

		if($result)
		{
			$result = $this->isMovingFromStageToStageAvailable($item->getType(), $fromStageId, $toStageId);
		}
		// move back
		if(
			!$result
			&& !$this->isAccessDeniedToEverything()
			&& $this->isMoverAlwaysCanMoveBack()
			&& $item->getMovedBy() === $this->userId
		)
		{
			$result = $this->isMovingBackAvailable($item->getType(), $fromStageId, $toStageId);
		}

		return $result;
	}

	protected function canStartMovingItem(Item $item, int $fromStageId, bool $isMoverAllowed): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if ($this->canPerform(self::ENTITY_STAGE, $fromStageId, self::ACTION_MOVE))
		{
			return true;
		}

		$type = $item->getType();
		// the launch right moves only own items
		$isAuthor = ((int)$item->remindActualCreatedBy() === $this->userId);
		if ($isAuthor && $this->canAddItemsToType($type->getId()))
		{
			return true;
		}
		if ($isAuthor && $this->isSimplePermissionsForTheFirstStage())
		{
			$firstStage = $type->getFirstStage();
			if ($firstStage && $firstStage->getId() === $fromStageId)
			{
				return true;
			}
		}

		return (
			$isMoverAllowed
			&& $this->isMoverAlwaysCanMoveBack()
			&& (int)$item->remindActualMovedBy() === $this->userId
		);
	}

	public function canDeleteItem(Item $item): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if($item->getCreatedBy() === $this->getUserId())
		{
			return true;
		}

		if($this->hasAdminAccess())
		{
			return true;
		}

		$taskManager = Driver::getInstance()->getTaskManager();
		if($taskManager && $taskManager->getUserItemIncompleteCounter($item) > 0)
		{
			return false;
		}

		return $this->canModifyItem($item);
	}

	public function canViewComment(?Item $item = null, ?EO_Timeline $timelineRecord = null): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if ($item && !$this->canViewItem($item))
		{
			return false;
		}
		if ($timelineRecord && $timelineRecord->getUserId() === $this->getUserId())
		{
			return true;
		}

		if ($item)
		{
			return $this->canModifyItem($item);
		}

		return $this->hasAdminAccess();
	}

	public function canAddComment(Item $item): bool
	{
		return $this->canViewType($item->getType()->getId()) && $this->canViewItem($item);
	}

	public function canUpdateComment(Timeline $timeline): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if($this->hasAdminAccess())
		{
			return true;
		}

		$canViewItem = true;

		$item = $timeline->getItem();
		if($item)
		{
			$canViewItem = $this->canViewItem($item);
		}

		return ($canViewItem && ($timeline->getUserId() === $this->getUserId()));
	}

	public function canDeleteComment(Timeline $timeline): bool
	{
		return $this->canUpdateComment($timeline);
	}

	protected function isMovingFromStageToStageAvailable(Type $type, int $fromStageId, int $toStageId): bool
	{
		$fromStage = $type->getStages()->getByPrimary($fromStageId);
		return ($fromStage && isset($fromStage->getPossibleNextStageIds()[$toStageId]));
	}

	protected function isMovingBackAvailable(Type $type, int $fromStageId, int $toStageId): bool
	{
		$toStage = $type->getStages()->getByPrimary($toStageId);

		return $toStage && isset($toStage->getPossibleNextStageIds()[$fromStageId]);
	}

	protected function getNextStageId(Type $type, int $stageId): ?int
	{
		$stages = clone $type->getStages();
		reset($stages);
		$isPreviousStage = false;
		foreach($stages as $stage)
		{
			if($isPreviousStage)
			{
				return $stage->getId();
			}
			if($stage->getId() === $stageId)
			{
				$isPreviousStage = true;
			}
		}

		return null;
	}

	/**
	 * Returns true if an item can be moved from this stage from at least one another stage.
	 *
	 * @param Stage $stageTo
	 * @return bool
	 * @throws \Bitrix\Main\SystemException
	 */
	public function canMoveToStage(Stage $stageTo): bool
	{
		if ($this->isAccessDeniedToEverything())
		{
			return false;
		}
		if(static::canMoveAnywhere())
		{
			return true;
		}

		$type = $stageTo->getType();
		$typeId = $type->getId();

		if(!isset($this->availableNextStages[$typeId]))
		{
			$this->availableNextStages[$typeId] = [];
			$stages = clone $type->getStages();
			reset($stages);
			foreach($stages as $stage)
			{
				if($this->canMoveFromStage($type, $stage->getId()))
				{
					foreach($stage->getPossibleNextStageIds() as $nextStageId)
					{
						$this->availableNextStages[$typeId][$nextStageId] = $nextStageId;
					}
				}
			}
		}

		return isset($this->availableNextStages[$typeId][$stageTo->getId()]);
	}

	/**
	 * Returns the ORM filter of items that canViewItem() allows to see.
	 *
	 * @param Type $type
	 * @return array
	 */
	public function getFilterForViewableItems(Type $type): array
	{
		if ($this->isAccessDeniedToEverything())
		{
			return [
				[
					'=ID' => 0,
				],
			];
		}
		if ($this->hasAdminAccess() || $this->canModifyType($type->getId()))
		{
			return [];
		}

		$filter = [
			'LOGIC' => 'OR',
			[
				'=CREATED_BY' => $this->userId,
			],
			[
				'=MOVED_BY' => $this->userId,
			],
		];
		// empty IN is not supported by every query of items
		$stageIds = $this->getViewableStageIds($type);
		if (!empty($stageIds))
		{
			$filter[] = [
				'@STAGE_ID' => $stageIds,
			];
		}
		if (!static::canViewOnAnyStage())
		{
			$taskItemIds = $this->getIncompleteTaskItemIds($type);
			if (!empty($taskItemIds))
			{
				$filter[] = [
					'@ID' => $taskItemIds,
				];
			}
		}

		return [$filter];
	}

	protected function getViewableStageIds(Type $type): array
	{
		if(static::canViewOnAnyStage())
		{
			return $type->getStages()->getIdList();
		}

		$viewableStageIds = [];
		foreach($this->getPermissions() as $entity => $permission)
		{
			if(preg_match('/^'.static::ENTITY_STAGE.'(\d+)$/', $entity, $matches))
			{
				$stageId = (int)$matches[1];
				if ($this->hasExplicitStageRow($stageId))
				{
					$viewableStageIds[] = $stageId;
				}
			}
		}

		return $viewableStageIds;
	}

	/**
	 * Returns true if the user has a row of the stage with an action that opens its items, admin rights aside.
	 *
	 * @param int $stageId
	 * @return bool
	 */
	protected function hasExplicitStageRow(int $stageId): bool
	{
		$stagePermissions = $this->getPermissions()[self::ENTITY_STAGE . $stageId] ?? [];
		foreach ([self::ACTION_VIEW, self::ACTION_MODIFY, self::ACTION_MOVE] as $action)
		{
			if (isset($stagePermissions[$action]) && $stagePermissions[$action] > self::PERMISSION_NONE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops the cached items with not answered tasks of the user, e.g. after the user answered a task.
	 */
	public function clearIncompleteTaskCache(): void
	{
		$this->incompleteTaskItemIds = [];
	}

	/**
	 * @param Type $type
	 * @return int[]
	 */
	protected function getIncompleteTaskItemIds(Type $type): array
	{
		$typeId = $type->getId();
		if (!isset($this->incompleteTaskItemIds[$typeId]))
		{
			$taskManager = Driver::getInstance()->getTaskManager();
			$this->incompleteTaskItemIds[$typeId] = $taskManager
				? $taskManager->getUserIncompleteTaskItemIds($typeId, $this->userId)
				: []
			;
		}

		return $this->incompleteTaskItemIds[$typeId];
	}

	public function getFilterForViewableTypes(): array
	{
		$viewableTypeIds = $this->getViewableTypeIds();

		if(empty($viewableTypeIds))
		{
			return [
				'=ID' => 0,
			];
		}

		return [
			'@ID' => $viewableTypeIds,
		];
	}

	protected function getViewableTypeIds(): array
	{
		$viewableTypeIds = [];
		foreach($this->getPermissions() as $entity => $permission)
		{
			if(preg_match('/^'.static::ENTITY_TYPE.'(\d+)$/', $entity, $matches))
			{
				$viewableTypeIds[] = (int) $matches[1];
			}
		}

		return $viewableTypeIds;
	}

	public function getFilterForEditableTypes(): array
	{
		if($this->hasAdminAccess())
		{
			return [];
		}

		$editableTypeIds = $this->getEditableTypeIds();

		if(empty($editableTypeIds))
		{
			return [
				'=ID' => 0,
			];
		}

		return [
			'@ID' => $editableTypeIds,
		];
	}

	protected function getEditableTypeIds(): array
	{
		$editableTypeIds = [];
		foreach($this->getPermissions() as $entity => $permissions)
		{
			if(preg_match('/^'.static::ENTITY_TYPE.'(\d+)$/', $entity, $matches))
			{
				if(isset($permissions[static::ACTION_MODIFY]) && $permissions[static::ACTION_MODIFY] > static::PERMISSION_NONE)
				{
					$editableTypeIds[] = (int) $matches[1];
				}
			}
		}

		return $editableTypeIds;
	}

	public static function filterUserIdsWhoCanViewItem(Item $item, array $userIds): array
	{
		$result = [];
		// tasks of the item are read once for all users
		$incompleteParticipantIds = null;
		foreach($userIds as $userId)
		{
			$userId = (int)$userId;
			if($userId > 0)
			{
				if ($incompleteParticipantIds === null)
				{
					$taskManager = Driver::getInstance()->getTaskManager();
					$incompleteParticipantIds = $taskManager
						? $taskManager->getItemIncompleteParticipantIds($item)
						: []
					;
				}
				$userPermissions = Driver::getInstance()->getUserPermissions($userId);
				if($userPermissions->canViewItemWithTaskParticipants($item, $incompleteParticipantIds))
				{
					$result[$userId] = $userId;
				}
			}
		}

		return $result;
	}

	public static function filterUserIdsWhoCanViewType(int $typeId, array $userIds): array
	{
		$result = [];
		foreach($userIds as $userId)
		{
			$userId = (int)$userId;
			if($userId > 0)
			{
				$userPermissions = Driver::getInstance()->getUserPermissions($userId);
				if($userPermissions->canViewType($typeId))
				{
					$result[$userId] = $userId;
				}
			}
		}

		return $result;
	}

	public static function getPermissionsMap(): array
	{
		return [
			self::PERMISSION_SELF => Loc::getMessage('RPA_PERMISSION_SELF'),
			self::PERMISSION_DEPARTMENT => Loc::getMessage('RPA_PERMISSION_DEPARTMENT'),
			self::PERMISSION_ANY => Loc::getMessage('RPA_PERMISSION_ANY'),
		];
	}

	public function getAccessCodes(): array
	{
		if($this->accessCodes === null)
		{
			$this->accessCodes = \CAccess::getUserCodesArray($this->userId);
			$this->accessCodes = $this->extendAccessCodes($this->accessCodes);
		}

		return $this->accessCodes;
	}

	protected function extendAccessCodes(array $accessCodes): array
	{
		$result = [];

		foreach($accessCodes as $accessCode)
		{
			$result[] = $accessCode;
			if(preg_match('/^(SG[\d]+)_[A|E|K]/', $accessCode, $matches))
			{
				$result[] = $matches[1];
			}
		}

		$result = array_unique($result);

		$result[] = static::ACCESS_CODE_ALL_USERS;

		return $result;
	}

	/** @noinspection ReturnTypeCanBeDeclaredInspection */
	public function loadUserPermissions()
	{
		$this->permissions = [];
		$this->availableNextStages = [];

		$userAccessCodes = $this->getAccessCodes();
		if(!is_array($userAccessCodes) || count($userAccessCodes) === 0)
		{
			return;
		}
		if ($this->isExtranetUser())
		{
			return;
		}

		foreach ($this->fetchPermissionRows($userAccessCodes) as $permission)
		{
			$entityCode = $permission['ENTITY'].$permission['ENTITY_ID'];
			if (
				!isset($this->permissions[$entityCode][$permission['ACTION']]) ||
				$this->permissions[$entityCode][$permission['ACTION']] < $permission['PERMISSION']
			)
			{
				$this->permissions[$entityCode][$permission['ACTION']] = $permission['PERMISSION'];
			}
		}
	}

	protected function fetchPermissionRows(array $accessCodes): iterable
	{
		return PermissionTable::getList([
			'select' => ['ENTITY', 'ENTITY_ID', 'ACTION', 'PERMISSION'],
			'filter' => [
				'@ACCESS_CODE' => $accessCodes,
			],
		]);
	}

	protected function getPermissions(): array
	{
		if ($this->permissions === null)
		{
			$this->loadUserPermissions();
		}

		return $this->permissions ?? [];
	}

	public function isAdmin(): bool
	{
		return $this->hasAdminAccess();
	}

	protected function hasAdminAccess(): bool
	{
		if($this->isAdmin === null)
		{
			$this->isAdmin = false;

			if($this->userId > 0 && Driver::getInstance()->getUserId() === $this->userId)
			{
				$currentUser = CurrentUser::get();
				if(ModuleManager::isModuleInstalled('bitrix24'))
				{
					$this->isAdmin = $currentUser->canDoOperation('bitrix24_config');
				}
				else
				{
					$this->isAdmin = $currentUser->isAdmin();
				}
			}
			elseif ($this->userId > 0)
			{
				$this->isAdmin = $this->isPortalAdmin($this->userId);
			}
		}

		return $this->isAdmin;
	}

	protected function isPortalAdmin(int $userId): bool
	{
		if (ModuleManager::isModuleInstalled('bitrix24') && Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::IsPortalAdmin($userId);
		}

		return in_array(1, \CUser::GetUserGroup($userId), false);
	}

	protected function canPerform(string $entity, int $entityId, string $action): bool
	{
		if($this->hasAdminAccess())
		{
			return true;
		}
		$entityCode = $entity.$entityId;
		$permissions = $this->getPermissions();

		return (
			isset($permissions[$entityCode][$action]) &&
			$permissions[$entityCode][$action] > self::PERMISSION_NONE
		);
	}

	protected function isAccessDeniedToEverything(): bool
	{
		if ($this->hasAdminAccess())
		{
			return false;
		}

		return $this->isExtranetUser();
	}

	protected function isExtranetUser(): bool
	{
		if ($this->isExtranet === null)
		{
			$this->isExtranet = false;
			if (Loader::includeModule('intranet') && Loader::includeModule('extranet'))
			{
				$this->isExtranet = !(\Bitrix\Intranet\Util::isIntranetUser($this->getUserId()));
			}
		}
		return $this->isExtranet;
	}

	// region rules
	protected function isSimplePermissionsForTheFirstStage(): bool
	{
		return true;
	}

	protected function isMoverAlwaysCanMoveBack(): bool
	{
		return false;
	}

	public static function isAlwaysCanMoveToTheNextStage(): bool
	{
		return true;
	}

	public static function canMoveAnywhere(): bool
	{
		return true;
	}

	public static function canViewOnAnyStage(): bool
	{
		return true;
	}

	public static function canEditItemOnAnyStage(): bool
	{
		return false;
	}

	public static function canViewAnyType(): bool
	{
		return true;
	}
	//endregion
}
