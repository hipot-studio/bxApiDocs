<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Access\Table\PermissionCacheResetNotifier;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Encoding;

final class RolePermissionService
{
	private const DB_ERROR_KEY = 'BICONNECTOR_CONFIG_PERMISSIONS_DB_ERROR';

	/**
	 * Id the matrix gives a table group it has drawn but nobody has created yet.
	 */
	private const TABLE_GROUP_PLACEHOLDER_PREFIX = 'new_T';

	/**
	 * Attempts the search of a free number for a default name is given.
	 */
	private const DEFAULT_NAME_ATTEMPTS = 10;

	/**
	 * Name of a group nobody named on a portal the phrase of the default name never reached.
	 */
	private const DEFAULT_NAME_FALLBACK = 'New group';

	private RoleRelationService $roleRelationService;

	public function __construct()
	{
		$this->roleRelationService = new RoleRelationService();
	}

	/**
	 * Saves the matrix: the groups it mentions, the rights of the roles and the groups it dropped.
	 *
	 * The groups the person removed are deleted here rather than by the caller before the call, and
	 * the order inside the transaction is what makes the ordinary case work: the composition of the
	 * groups is written first, so a table moved out of a removed group is already in its new group
	 * by the time the deletion asks whether the removal would move anything. A refusal of any of
	 * these steps rolls the whole save back, the rights of the removed group included.
	 *
	 * @param array $permissionSettings Roles with their rights.
	 * @param array $accessRights Cells of the matrix, the groups of both axes among them.
	 * @param int[] $deletedTableGroupIds Custom table groups the person removed from the matrix.
	 * @param bool $isRolesKept The roles of the payload are proven to be the stored ones (the person may
	 *        not manage roles and RoleManagementGuard has passed): their names and members are not
	 *        written again, only their rights.
	 *
	 * @return Result
	 */
	public function saveRolePermissions(
		array $permissionSettings,
		array $accessRights = [],
		array $deletedTableGroupIds = [],
		bool $isRolesKept = false,
	): Result
	{
		$result = new Result();
		$db = Application::getConnection();

		try
		{
			$db->startTransaction();

			$groupPermissionIdList = array_reduce($accessRights, static function ($carry, $permission) {
				if (PermissionDictionary::isDashboardGroupPermission($permission['id']))
				{
					$carry[] = $permission['additionalRightData']['group'];
				}

				return $carry;
			}, []);

			if (!empty($groupPermissionIdList))
			{
				$dashboardGroupMap = [];
				foreach ($groupPermissionIdList as $group)
				{
					$groupInfo = [
						'id' => str_starts_with($group['id'], 'new_')
							? null
							: PermissionDictionary::getDashboardGroupIdFromPermission($group['id']),
						'name' => $group['name'],
					];
					$scopeList = isset($group['scopes'])
						? array_column($group['scopes'], 'code')
						: [];
					$dashboards = $group['dashboards'] ?? [];

					$saveResult = DashboardGroupService::saveGroup(
						$groupInfo,
						$scopeList,
						$dashboards
					);
					if (!$saveResult->isSuccess())
					{
						$result->addErrors($saveResult->getErrors());
						$db->rollbackTransaction();

						return $result;
					}
					$dashboardGroupMap[$group['id']] = $saveResult->getData()['id'];
				}

				$permissionSettings = array_map(static function ($userGroup) use ($dashboardGroupMap) {
					foreach ($userGroup['accessRights'] as &$accessRight)
					{
						if (str_starts_with($accessRight['id'], 'new_G'))
						{
							$accessRight['id'] = PermissionDictionary::getDashboardGroupPermissionId(
								$dashboardGroupMap[$accessRight['id']]
							);
						}
					}

					return $userGroup;
				}, $permissionSettings);
			}

			// While the axis is off the matrix shows neither its general right nor the table groups, so
			// whatever of them is stored is neither replaced nor taken from the payload: a save of a matrix
			// that did not show them must not take them from the roles or change the groups.
			$isTableAxisHidden = !Feature::isTablePermissionsEnabled();
			if ($isTableAxisHidden)
			{
				$accessRights = self::dropTableAxisCells($accessRights);
				$deletedTableGroupIds = [];
			}

			$createGroupsResult = $this->createDeclaredTableGroups($accessRights);
			if (!$createGroupsResult->isSuccess())
			{
				$result->addErrors($createGroupsResult->getErrors());
				$db->rollbackTransaction();

				return $result;
			}

			// the placeholder passes no recognizer of the axis, so the rewriting stands before both
			// the filter and the application of the payload: after them the name and the composition
			// of the created group would be lost without a word
			$tableGroupMap = $createGroupsResult->getData()['map'];
			$permissionSettings = self::rewriteTableGroupPlaceholdersInSettings($permissionSettings, $tableGroupMap);
			$accessRights = self::rewriteTableGroupPlaceholders($accessRights, $tableGroupMap);

			$permissionSettings = self::dropUnresolvedTableGroupRights($permissionSettings);

			$tableGroupResult = $this->applyTableGroupChanges($accessRights);
			if (!$tableGroupResult->isSuccess())
			{
				$result->addErrors($tableGroupResult->getErrors());
				$db->rollbackTransaction();

				return $result;
			}

			// read without a fallback on purpose: a successful result of that method always names
			// the key, and a missing one has to be loud rather than quietly turn into "unchanged"
			$areTableGroupsChanged = (bool)$tableGroupResult->getData()['isChanged'];

			// the coverage is checked only on a save that claims a composition - a portal whose
			// table axis is off sends no group at all and must keep its save of the rights - and it
			// is checked here, inside the transaction, where the bindings just written are visible
			if ($tableGroupResult->getData()['isCompositionClaimed'])
			{
				$coverageResult = TableGroupService::check();
				if (!$coverageResult->isSuccess())
				{
					$result->addErrors($coverageResult->getErrors());
					$db->rollbackTransaction();

					return $result;
				}
			}

			$rolePermissions = new RolePermissionChanges();
			foreach ($permissionSettings as &$setting)
			{
				$roleId = (int)($setting['id'] ?? 0);
				$roleTitle = (string)($setting['title'] ?? '');

				if (!$isRolesKept)
				{
					$saveRoleResult = $this->saveRole($roleTitle, $roleId);
					if (!$saveRoleResult->isSuccess())
					{
						$result->addErrors($saveRoleResult->getErrors());
						$db->rollbackTransaction();

						return $result;
					}
					$roleId = $saveRoleResult->getData()['id'];
				}

				$setting['id'] = $roleId;

				if (!isset($setting['accessRights']) || !is_array($setting['accessRights']))
				{
					continue;
				}

				if ($isTableAxisHidden)
				{
					$setting['accessRights'] = array_values(array_filter(
						$setting['accessRights'],
						static fn($permission): bool => !is_array($permission)
							|| !self::isTableAxisCell((string)($permission['id'] ?? '')),
					));
				}

				$rolePermissions->add($roleId, $setting['accessRights']);
			}
			unset($setting);

			if (!$rolePermissions->apply())
			{
				$errorMessage = Loc::getMessage('BICONNECTOR_APACHESUPERSET_CONFIG_PERMISSIONS_DB_ERROR');
				$result->addError(new UserFacingError($errorMessage));
				$db->rollbackTransaction();

				return $result;
			}

			if (\Bitrix\Main\Loader::includeModule('intranet'))
			{
				\CIntranetUtils::clearMenuCache();
			}

			// The composition of a role is not a consequence of a changed right: the popup edits it on
			// its own, and a section whose rows the payload does not carry still sends its roles.
			if (!$isRolesKept)
			{
				$this->roleRelationService->saveRoleRelation($permissionSettings);
			}

			if (!empty($deletedTableGroupIds))
			{
				// the composition of the groups is already written above, so a table that has left
				// the removed group is counted in its new group and no longer holds the deletion
				$deleteResult = TableGroupService::deleteCustomGroupListWithinTransaction($deletedTableGroupIds);
				if (!$deleteResult->isSuccess())
				{
					$result->addErrors($deleteResult->getErrors());
					$db->rollbackTransaction();

					return $result;
				}

				$areTableGroupsChanged = true;
			}

			$db->commitTransaction();

			// the groups were written inside the transaction that has just been committed, so the
			// caches of the axis are emptied here: once for the whole save, not for every group
			if ($areTableGroupsChanged)
			{
				TableGroupService::resetGroupCaches();
			}

			$result->setData(['permissionSettings' => $permissionSettings]);

			// After the commit and never inside the transaction: the rights are saved in one, and an
			// outbound call has no business there.
			PermissionCacheResetNotifier::notifyChanged();
		}
		catch (\Exception $e)
		{
			$db->rollbackTransaction();
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	private static function isTableAxisCell(string $permissionId): bool
	{
		return
			$permissionId === (string)PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS
			|| PermissionDictionary::isTableGroupPermission($permissionId)
			|| str_starts_with($permissionId, self::TABLE_GROUP_PLACEHOLDER_PREFIX)
		;
	}

	/**
	 * @param array $accessRights Cells of the matrix.
	 *
	 * @return array The cells without the ones of the table axis, placeholders of new groups included.
	 */
	private static function dropTableAxisCells(array $accessRights): array
	{
		return array_values(array_filter(
			$accessRights,
			static fn($accessRight): bool => !is_array($accessRight)
				|| !self::isTableAxisCell((string)($accessRight['id'] ?? '')),
		));
	}


	/**
	 * Drops the rights granted on a table group that stayed unresolved.
	 *
	 * Everything the creation branch above resolved arrives here under a real id and is left alone;
	 * what remained a placeholder is what the branch does not take apart - a right whose group nobody
	 * declared. Such a right is dropped, because a placeholder must not reach PERMISSION_ID as a
	 * string of its own, and the rest of the role is left as it came.
	 *
	 * @param array $permissionSettings
	 *
	 * @return array
	 */
	private static function dropUnresolvedTableGroupRights(array $permissionSettings): array
	{
		foreach ($permissionSettings as &$setting)
		{
			if (!isset($setting['accessRights']) || !is_array($setting['accessRights']))
			{
				continue;
			}

			// the type of an element is not declared on purpose: a payload where it is not an array
			// would raise a TypeError, and that is an Error, not an Exception the save catches
			$setting['accessRights'] = array_values(array_filter(
				$setting['accessRights'],
				static fn($accessRight): bool => !is_array($accessRight) || !self::isTableGroupPlaceholder(
					(string)($accessRight['id'] ?? ''),
				),
			));
		}
		unset($setting);

		return $permissionSettings;
	}

	/**
	 * Tells whether the id is one the matrix gave a table group it has drawn but nobody has created.
	 *
	 * The creation branch and the filter share the recognizer so that their areas meet without a gap:
	 * whatever the branch resolved is no placeholder by the time the filter looks at it.
	 *
	 * @param string $permissionId
	 *
	 * @return bool
	 */
	private static function isTableGroupPlaceholder(string $permissionId): bool
	{
		return str_starts_with($permissionId, self::TABLE_GROUP_PLACEHOLDER_PREFIX);
	}

	/**
	 * Creates the table groups the matrix declared under a placeholder.
	 *
	 * Called from inside the transaction of the save and opens none of its own: a rollback of a
	 * nested transaction returns to a savepoint instead of undoing the whole save. A refusal of the
	 * creation is returned for the caller to roll back by - a group of a save that failed later must
	 * not stay behind.
	 *
	 * Neither the type nor the id of the payload is read: only a custom group is born this way, and
	 * an existing group has nothing to be taken over by, since the creation always adds a row.
	 *
	 * @param array $accessRights
	 *
	 * @return Result Data: ['map' => array<string, array{id: string, name: string}>] - permission id
	 *                and name of the created group, by the placeholder it was declared under.
	 */
	private function createDeclaredTableGroups(array $accessRights): Result
	{
		$result = new Result();

		$map = [];
		foreach (self::collectDeclaredTableGroups($accessRights) as $placeholderId => $group)
		{
			$name = self::resolveDeclaredGroupName($group);

			$createResult = TableGroupService::createCustomGroup($name);
			if (!$createResult->isSuccess())
			{
				return $result->addErrors($createResult->getErrors());
			}

			$groupId = (int)$createResult->getData()['id'];
			$map[$placeholderId] = [
				'id' => PermissionDictionary::getTableGroupPermissionId($groupId),
				'name' => $name,
			];
		}

		return $result->setData(['map' => $map]);
	}

	/**
	 * Collects the groups the matrix declared along with the rights of the table axis.
	 *
	 * A declaration is a placeholder right carrying a group object; the name is no condition of one,
	 * since a group the administrator has added is created whatever the matrix called it. The
	 * collection goes into a map by the placeholder, so a placeholder declared by several rights at
	 * once gives one group and not one per row.
	 *
	 * @param array $accessRights
	 *
	 * @return array<string, array> Group objects by the placeholder they were declared under.
	 */
	private static function collectDeclaredTableGroups(array $accessRights): array
	{
		$groups = [];
		foreach ($accessRights as $permission)
		{
			$permissionId = (string)($permission['id'] ?? '');
			if (!self::isTableGroupPlaceholder($permissionId))
			{
				continue;
			}

			$group = $permission['additionalRightData']['group'] ?? null;
			if (!is_array($group))
			{
				continue;
			}

			$groups[$permissionId] = $group;
		}

		return $groups;
	}

	/**
	 * Tells the name the declared group is created with.
	 *
	 * A declaration that named no group is not a reason to refuse: the creation rejects an empty
	 * name, and a refusal would undo the whole matrix because of a single cell.
	 *
	 * @param array $group
	 *
	 * @return string
	 */
	private static function resolveDeclaredGroupName(array $group): string
	{
		$name = is_string($group['name'] ?? null) ? trim($group['name']) : '';

		return $name === '' ? self::generateDefaultTableGroupName() : $name;
	}

	/**
	 * Builds a default name for a group the matrix declared without one.
	 *
	 * The number is the first one free among the names the groups hold right now, and it is free at
	 * the moment of the creation and not forever: names of table groups are unique neither in the
	 * schema nor by their meaning. Several unnamed declarations of one save still get numbers of
	 * their own, because every group is written before the next name is built.
	 *
	 * The number of attempts is bound on purpose: a phrase whose translation lost the substitution
	 * repeats one and the same name, and an endless search would hold the transaction of the save
	 * for as long as the worker lives. The name the search ends with is taken by some group already,
	 * which costs a name shared by two groups and no more - the id is what tells them apart.
	 *
	 * @return string
	 */
	private static function generateDefaultTableGroupName(): string
	{
		$rows = TableGroupTable::getList(['select' => ['NAME']])->fetchAll();
		$takenNames = array_fill_keys(array_column($rows, 'NAME'), true);

		$name = '';
		for ($number = 1; $number <= self::DEFAULT_NAME_ATTEMPTS; $number++)
		{
			$name = self::buildDefaultTableGroupName($number);
			if (!isset($takenNames[$name]))
			{
				break;
			}
		}

		return $name;
	}

	/**
	 * Builds the name of the number given, of the phrase or of the last resort.
	 *
	 * The creation rejects an empty name and its refusal undoes the whole matrix, so the name must
	 * not depend on the phrase being there: the portal whose language it never reached answers with
	 * nothing at all.
	 *
	 * @param int $number
	 *
	 * @return string
	 */
	private static function buildDefaultTableGroupName(int $number): string
	{
		$name = trim((string)Loc::getMessage(
			'BICONNECTOR_ACCESS_TABLE_GROUP_DEFAULT_NAME',
			['#NUMBER#' => $number],
		));

		return $name === '' ? self::DEFAULT_NAME_FALLBACK . ' ' . $number : $name;
	}

	/**
	 * Puts the id and the name of the created group in place of the placeholder it was declared under.
	 *
	 * A right the map says nothing about is left as it came: a placeholder nobody declared is no key
	 * of the map, and reading the map by it would be an access to a missing one.
	 *
	 * The name goes back into the payload because the application below reads it from there and
	 * renames the group by it. Left as it came, a declaration that named no group would be renamed to
	 * that very emptiness - and the refusal of the rename would undo the whole save.
	 *
	 * @param array $rights
	 * @param array<string, array{id: string, name: string}> $map
	 *
	 * @return array
	 */
	private static function rewriteTableGroupPlaceholders(array $rights, array $map): array
	{
		foreach ($rights as &$right)
		{
			$permissionId = (string)($right['id'] ?? '');
			if (!isset($map[$permissionId]))
			{
				continue;
			}

			$right['id'] = $map[$permissionId]['id'];

			if (is_array($right['additionalRightData']['group'] ?? null))
			{
				$right['additionalRightData']['group']['name'] = $map[$permissionId]['name'];
			}
		}
		unset($right);

		return $rights;
	}

	/**
	 * Rewrites the placeholders on the side of the request the permission rows are built from.
	 *
	 * @param array $permissionSettings
	 * @param array<string, array{id: string, name: string}> $map
	 *
	 * @return array
	 */
	private static function rewriteTableGroupPlaceholdersInSettings(array $permissionSettings, array $map): array
	{
		foreach ($permissionSettings as &$setting)
		{
			if (!isset($setting['accessRights']) || !is_array($setting['accessRights']))
			{
				continue;
			}

			$setting['accessRights'] = self::rewriteTableGroupPlaceholders($setting['accessRights'], $map);
		}
		unset($setting);

		return $permissionSettings;
	}

	/**
	 * Applies the name and the composition the matrix sends along with the rights of the table axis.
	 *
	 * Called from inside the transaction of the save and opens none of its own: a rollback of a
	 * nested transaction returns to a savepoint instead of undoing the whole save. Caches are not
	 * reset here either - that belongs to the write boundary the changes go through.
	 *
	 * A group the storage knows nothing about is passed over in silence: the id arrives from the
	 * matrix and may outlive the group itself, and an error here would bring down the save of the
	 * whole matrix because of a row the client sent along unchanged. A rename stays the privilege of
	 * a custom group, so the name of a system group is passed over the same way, while its
	 * composition is applied.
	 *
	 * The composition of a group the matrix mentions is always rewritten from the payload, whatever
	 * type the group is of, the same way the dashboard axis does it above.
	 *
	 * @param array $accessRights
	 *
	 * @return Result Data: ['isCompositionClaimed' => bool, 'isChanged' => bool].
	 *                isCompositionClaimed - whether the payload claims a composition, that is
	 *                whether a right of the table axis arrived with a declared group. The claim
	 *                stands on the declared group and not on the tableCodes key: the matrix travels
	 *                as a form body, where an empty composition has no shape of its own and takes
	 *                the key away with it.
	 *                isChanged - whether a rename or a bindings replacement actually changed a
	 *                declared group, used by the caller to decide whether the caches need a reset.
	 */
	private function applyTableGroupChanges(array $accessRights): Result
	{
		// the data of the result is written by one setData() at the end of the method and nowhere
		// else: setData() replaces the whole array, so a second one in an early return would drop
		// the keys of the first - including the keys a neighbouring piece may add to this result

		$result = new Result();

		$groups = [];
		foreach ($accessRights as $permission)
		{
			$permissionId = (string)($permission['id'] ?? '');
			if (!PermissionDictionary::isTableGroupPermission($permissionId))
			{
				continue;
			}

			$group = $permission['additionalRightData']['group'] ?? null;
			if (!is_array($group))
			{
				continue;
			}

			$groups[PermissionDictionary::getTableGroupIdFromPermission($permissionId)] = $group;
		}

		$isChanged = false;
		$groupTypes = empty($groups) ? [] : self::getTableGroupTypes(array_keys($groups));

		foreach ($groups as $groupId => $group)
		{
			if (!isset($groupTypes[$groupId]))
			{
				continue;
			}

			$isCustomGroup = $groupTypes[$groupId] === TableGroupTable::GROUP_TYPE_CUSTOM;
			if ($isCustomGroup && isset($group['name']) && is_string($group['name']))
			{
				$renameResult = TableGroupService::renameCustomGroupWithinTransaction($groupId, $group['name']);
				if (!$renameResult->isSuccess())
				{
					return $result->addErrors($renameResult->getErrors());
				}

				$isChanged = $isChanged || $renameResult->getData()['isChanged'];
			}

			// the group object carries its whole composition, so a missing key is an empty one:
			// the matrix travels as a form body, where an empty array has no representation at all
			$tableCodes = is_array($group['tableCodes'] ?? null) ? $group['tableCodes'] : [];

			// the save holds a transaction already, and a second one under it would be nested: a
			// savepoint on MySQL, but a plain commit of everything on Oracle and MSSQL
			$bindingResult = TableGroupService::replaceGroupBindingsWithinTransaction($groupId, $tableCodes);
			if (!$bindingResult->isSuccess())
			{
				return $result->addErrors($bindingResult->getErrors());
			}

			$isChanged = $isChanged || $bindingResult->getData()['isChanged'];
		}

		return $result->setData(['isCompositionClaimed' => !empty($groups), 'isChanged' => $isChanged]);
	}

	/**
	 * Reads the type of every group the storage holds among the given ones.
	 *
	 * The type comes from the storage and never from the payload: a client calling a system group
	 * custom would otherwise rename it. A group the answer leaves out is one the storage knows
	 * nothing about.
	 *
	 * @param int[] $groupIds
	 *
	 * @return array<int, string> Type of the group, by its id.
	 */
	private static function getTableGroupTypes(array $groupIds): array
	{
		$rows = TableGroupTable::getList([
			'select' => ['ID', 'TYPE'],
			'filter' => ['@ID' => $groupIds],
		])->fetchAll();

		return array_column($rows, 'TYPE', 'ID');
	}

	/**
	 * @param string $name Role name.
	 * @param int|null $roleId Role identification number.
	 *
	 * @return Result
	 */
	public function saveRole(string $name, ?int $roleId = null): Result
	{
		$result = new Result();

		if (empty($name))
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_ACCESS_SAVE_ROLE_ERROR_NO_NAME');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}
		$nameField = [
			'NAME' => Encoding::convertEncodingToCurrent($name),
		];

		try
		{
			$role = $roleId
				? RoleTable::update($roleId, $nameField)
				: RoleTable::add($nameField)
			;
		}
		catch (\Exception $e)
		{
			$result->addError(new Error('Role adding failed'));
			\CEventLog::add([
				'SEVERITY' => 'ERROR',
				'AUDIT_TYPE_ID' => self::DB_ERROR_KEY,
				'MODULE_ID' => 'biconnector',
				'DESCRIPTION' => "Error role adding. Role id: {$roleId}. Role name: {$name}. Exception: {$e->getMessage()}",
			]);

			return $result;
		}

		$result->setData(['id' => $role->getId()]);

		return $result;
	}

	/**
	 * Deletes a role by id.
	 * @param int $roleId
	 *
	 * @return Result
	 * @throws SqlQueryException
	 */
	public function deleteRole(int $roleId): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		try
		{
			$connection->startTransaction();

			PermissionTable::deleteList(['=ROLE_ID' => $roleId]);
			$this->roleRelationService->deleteRoleRelations($roleId);
			RoleTable::delete($roleId);

			$connection->commitTransaction();

			PermissionCacheResetNotifier::notifyChanged();
		}
		catch (\Exception $e)
		{
			$connection->rollbackTransaction();
			\CEventLog::add([
				'SEVERITY' => 'ERROR',
				'AUDIT_TYPE_ID' => self::DB_ERROR_KEY,
				'MODULE_ID' => 'biconnector',
				'DESCRIPTION' => "Error role deleting. Role id: {$roleId}. Exception: {$e->getMessage()}",
			]);
			$result->addError(new Error('Role deleting failed.'));

			return $result;
		}

		return $result;
	}

	public function deletePermissionsByDashboard(int $dashboardId): void
	{
		if ($dashboardId > 0)
		{
			PermissionTable::deleteList([
				'=VALUE' => $dashboardId,
				'@PERMISSION_ID' => array_values(ActionDictionary::getDashboardPermissionsMap()),
			]);
		}
	}

	/**
	 * Deletes the rights granted on a table group, whatever role holds them.
	 *
	 * No transaction of its own: the deletion of a group and the deletion of its rights make one
	 * change, and the transaction that holds them together belongs to the caller.
	 *
	 * @param int $groupId
	 *
	 * @return void
	 */
	public function deletePermissionsByTableGroup(int $groupId): void
	{
		if ($groupId > 0)
		{
			PermissionTable::deleteList([
				'=PERMISSION_ID' => PermissionDictionary::getTableGroupPermissionId($groupId),
			]);
		}
	}
}
