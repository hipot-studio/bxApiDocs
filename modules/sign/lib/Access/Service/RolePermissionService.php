<?php

namespace Bitrix\Sign\Access\Service;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Access\Permission\PermissionTable;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Access\Permission\PermissionWriteLock;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\SectionDictionary;
use Bitrix\Sign\Debug;
use Bitrix\Sign\Service\Container;

Loc::loadMessages(__FILE__);

Loader::includeModule('crm');

class RolePermissionService extends \Bitrix\Crm\Integration\Sign\Access\Service\RolePermissionService
{
	public const DEFAULT_ROLE_EMPLOYEE_CODE = 'SIGN_SMART_DOCUMENT_EMPLOYMENT';
	public const DEFAULT_ROLE_CHIEF_CODE = 'SIGN_SMART_DOCUMENT_CHIEF';
	public const DEFAULT_ROLES_CODE = [
		self::DEFAULT_ROLE_EMPLOYEE_CODE,
		self::DEFAULT_ROLE_CHIEF_CODE,
	];

	/**
	 * Columns of the unique index of b_sign_permission, the conflict target of the upsert both write paths of the
	 * table use {@see \Bitrix\Sign\Access\Install\AccessInstaller::installMissingPermissions()}. An upsert runs no
	 * ORM event, so it skips the cascade the parent entity hangs on a write; the cascade matches nothing here
	 * anyway, as sign permission ids carry no hierarchy delimiter.
	 */
	public const PERMISSION_UNIQUE_FIELDS = ['ROLE_ID', 'PERMISSION_ID'];

	/**
	 * Values of role permissions already read from {@see PermissionTable} within this request, keyed by the
	 * role set and the permission id {@see self::buildPermissionValueCacheKey()}. A null is an answer of its
	 * own and is remembered like any value: the pair has no stored row. A read that failed is never stored -
	 * the caller cannot tell it from "no permission", and remembering it would deny for the rest of the
	 * request. Nothing else lives here: applying the value to an item (its owner, the departments) stays in
	 * the rule.
	 *
	 * The lifetime is this instance and so one request at most; there is no persistent cache behind it.
	 *
	 * Reset by every write path of the table: {@see self::saveRolePermissions()}, {@see self::deleteRole()}
	 * and {@see self::applyPermissionChanges()} here, and
	 * {@see \Bitrix\Sign\Access\Install\AccessInstaller} where it writes the rows itself. A new write path
	 * has to reset it as well - {@see self::resetPermissionValueCache()} is safe to call at any point, at
	 * worst it costs a repeated read.
	 *
	 * Acknowledged boundaries, both leaving the answer at the value the pair had before the write: a write
	 * made by another process, and a row written straight through the ORM table, as the test helpers do.
	 *
	 * @var array<string, string|null>
	 */
	private array $permissionValueCache = [];

	/**
	 * @param list<array{id: string, title: string, accessRights: list<array{id: string, value: string|int}>}> $permissionSettings permission settings array
	 */
	public function saveRolePermissions(array &$permissionSettings): void
	{
		foreach ($permissionSettings as &$setting)
		{
			foreach ($setting['accessRights'] ?? [] as $accessRight)
			{
				if ($accessRight['id'] === PermissionDictionary::SIGN_CRM_CONTACT_READ)
				{
					$setting['accessRights'][] = ['id' => PermissionDictionary::SIGN_CRM_CONTACT_ADD, 'value' => $accessRight['value']];
					$setting['accessRights'][] = ['id' => PermissionDictionary::SIGN_CRM_CONTACT_WRITE, 'value' => $accessRight['value']];
					$setting['accessRights'][] = ['id' => PermissionDictionary::SIGN_CRM_CONTACT_DELETE, 'value' => $accessRight['value']];
					$setting['accessRights'][] = ['id' => PermissionDictionary::SIGN_CRM_CONTACT_IMPORT, 'value' => $accessRight['value']];
					$setting['accessRights'][] = ['id' => PermissionDictionary::SIGN_CRM_CONTACT_EXPORT, 'value' => $accessRight['value']];
				}
			}
			$setting['signAccessRights'] = $setting['accessRights'] ?? [];
		}

		$isLocked = false;
		try
		{
			// The lock is taken before the CRM half, not just around the sign rows: refused after the parent has
			// stored the CRM permissions, the save would leave the two halves apart, and a role the parent had
			// just created would be created a second time by the retry - the new id never reaches the client.
			// The section is longer for it: the payloads here are whole-portal batches
			// {@see \Bitrix\Sign\Service\Sign\PermissionsService::copyPermissionValuesForAllRoles()} and the CRM
			// half costs a read plus a write per role. Measured on this contour: 10 roles - 64 queries, 0.154 s,
			// which puts a portal of 50 roles around 0.8 s. That is what the timeout leaves room for, so a writer
			// that finds the lock taken waits for it instead of being refused.
			$isLocked = PermissionWriteLock::acquire();
			if (!$isLocked)
			{
				// The delta would otherwise be computed from an unserialized snapshot: two full saves of the same
				// role could each drop the permissions the other one keeps, leaving the role with a set neither
				// caller asked for. The write is abandoned instead, and the failure is loud on purpose - a silent
				// return would lose an administrator's change without a trace.
				$this->getLogger()->error(
					'Sign permissions are not written: the lock ' . PermissionWriteLock::NAME . ' was not acquired',
				);

				throw new SqlQueryException(
					'Unable to acquire the lock ' . PermissionWriteLock::NAME . ' to write sign permissions',
				);
			}

			parent::saveRolePermissions($permissionSettings);
			$this->updateSignPermissions($permissionSettings);
		}
		finally
		{
			if ($isLocked)
			{
				PermissionWriteLock::release();
			}

			// Unconditional and after the commit: a write that broke halfway still leaves part of the rows
			// changed, so what the readers remember is untrustworthy either way. After the release of the
			// lock, not before it: this call can build the service of the locator and throw, and a throw
			// here would leave the lock held until the connection closes.
			$this->resetPermissionValueCacheOfEveryReader();
		}
	}

	/**
	 * @param int $roleId role identification number
	 * @throws SqlQueryException when the write lock of the permission table could not be acquired
	 */
	public function deleteRole(int $roleId): Result
	{
		// Third write path of the table, under the same lock as the other two: the rows of the role are dropped
		// here, and a writer running at the same time could otherwise store rows for a role that no longer
		// exists. The lock is taken before the CRM half for the same reason as in the save above - refused after
		// it, the role would be gone from CRM while its sign permissions stayed behind.
		$isLocked = PermissionWriteLock::acquire();
		if (!$isLocked)
		{
			$this->getLogger()->error(
				'The role ' . $roleId . ' is not deleted: the lock ' . PermissionWriteLock::NAME
				. ' was not acquired',
			);

			throw new SqlQueryException(
				'Unable to acquire the lock ' . PermissionWriteLock::NAME . ' to delete sign permissions',
			);
		}

		try
		{
			$deleteResult = parent::deleteRole($roleId);
			if ($deleteResult->isSuccess())
			{
				PermissionTable::deleteList(['=ROLE_ID' => $roleId]);
			}

			return $deleteResult;
		}
		finally
		{
			PermissionWriteLock::release();
			$this->resetPermissionValueCacheOfEveryReader();
		}
	}

	/**
	 * @return array
	 */
	public function getUserGroups(): array
	{
		$res = $this->getRoleList();

		$roles = [];
		foreach ($res as $row)
		{
			$roleId = (int)$row['ID'];

			$roles[] = [
				'id' => $roleId,
				'title' => $row['NAME'],
				'accessRights' => $this->getRoleAccessRights($roleId),
				'members' => $this->getRoleMembers($roleId),
			];
		}

		return $roles;
	}

	/**
	 * returns access rights list
	 * @return array
	 */
	public function getAccessRights(): array
	{
		$sections = SectionDictionary::getMap();

		$res = [];

		foreach ($sections as $sectionId => $permissions)
		{

			$rights = [];
			foreach ($permissions as $permissionId)
			{
				$type = PermissionDictionary::getType($permissionId)
					?: SignPermissionDictionary::getType($permissionId);
				$title = PermissionDictionary::getTitle($permissionId)
					?: SignPermissionDictionary::getTitle($permissionId);

				$rights[] = [
					'id' => $permissionId,
					'type' => $type,
					'title' => $title,
					'enableSearch' => true,
					'variables' => $this->getAbleOptions(!is_int($permissionId)),
				];
			}

			$res[] = [
				'sectionTitle' => SectionDictionary::getTitle($sectionId),
				'rights' => $rights,
			];
		}

		return $res;
	}

	protected function getAbleOptions(bool $showOpen = true): array
	{
		return array_merge([
			[
				'id' => 0,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_NONE'),
			],
			[
				'id' => \Bitrix\Crm\Service\UserPermissions::PERMISSION_ALL,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_ALL'),
			],
			[
				'id' => \Bitrix\Crm\Service\UserPermissions::PERMISSION_SELF,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_SELF'),
			],
			[
				'id' => \Bitrix\Crm\Service\UserPermissions::PERMISSION_DEPARTMENT,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_SELF_DEPARTMENT'),
			],
			[
				'id' => \Bitrix\Crm\Service\UserPermissions::PERMISSION_SUBDEPARTMENT,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_SELF_DEPARTMENT_ALL'),
			],
		],$showOpen ? [
			[
				'id' => \Bitrix\Crm\Service\UserPermissions::PERMISSION_OPENED,
				'title' => Loc::getMessage('SIGN_ACCESS_ROLE_OPENED'),
			],
		] : []);
	}

	/**
	 * @return array<int, array<string|int, array{VALUE: string|int}>>
	 */
	public function getSettings(): array
	{
		if (!empty(static::$settings))
		{
			return static::$settings;
		}
		$res = $this->getRoleList();

		$roles = [];
		foreach ($res as $row)
		{
			$roles[] = (int)$row['ID'];
		}

		$permissions = $this->getSavedPermissions($roles);
		static::$settings = $this->mapCurrentPermissions($permissions);

		return static::$settings;
	}

	protected function mapCurrentPermissions($permissions): array
	{
		$preparedPermissions = parent::mapCurrentPermissions($permissions);
		try
		{
			$signPermissions = PermissionTable::getList()->fetchAll();
		}
		catch (ObjectPropertyException|ArgumentException|SystemException $e)
		{
			return $preparedPermissions;
		}

		foreach ($signPermissions as $signPermission)
		{
			$preparedPermissions[$signPermission['ROLE_ID']][$signPermission['PERMISSION_ID']] = [
				'VALUE' => $signPermission['VALUE'],
			];
		}

		return $preparedPermissions;
	}

	/**
	 * Full replace of the sign permissions of every role of the payload, written as a delta: the target rows
	 * are inserted, changed ones updated and only the rows the payload dropped are deleted. A payload equal to
	 * the stored state executes no modifying query at all - that is what the migration agents do on most runs.
	 *
	 * Runs inside the locked section of {@see self::saveRolePermissions()}, which also binds the master, so the
	 * delta is read and written against a state no other writer of the table can change in between.
	 *
	 * @param list<array{id?: int|string, signAccessRights?: list<array{id?: int|string, value?: mixed}>}> $permissionSettings
	 */
	private function updateSignPermissions(array $permissionSettings): void
	{
		$targetValuesByRole = [];
		foreach ($permissionSettings as $setting)
		{
			$roleId = (int)($setting['id'] ?? 0);
			if ($roleId <= 0)
			{
				continue;
			}

			$targetValuesByRole[$roleId] = $this->extractSignPermissionValues($setting['signAccessRights'] ?? []);
		}

		if (empty($targetValuesByRole))
		{
			return;
		}

		$changes = $this->calculatePermissionChanges($targetValuesByRole);
		if (empty($changes))
		{
			return;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->applyPermissionChanges($changes);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollbackTransaction($connection);
			$this->getLogger()->error('Unable to write sign permissions: ' . $exception->getMessage());

			throw $exception;
		}
	}

	/**
	 * Target rows of one role: the write leaves the role with exactly this set, so a permission missing from it
	 * loses its row. CRM permissions travel in the same list under non-numeric ids and stay in the CRM role
	 * settings, and an empty value means "no permission" and is not stored - neither belongs here.
	 *
	 * @param list<array{id?: int|string, value?: mixed}> $signAccessRights
	 * @return array<string, string> permission id => value; the last value of a repeated id wins
	 */
	private function extractSignPermissionValues(array $signAccessRights): array
	{
		$values = [];
		foreach ($signAccessRights as $permission)
		{
			$permissionId = (int)($permission['id'] ?? 0);
			$value = $permission['value'] ?? null;
			if ($permissionId <= 0 || !$value)
			{
				continue;
			}

			$values[(string)$permissionId] = (string)$value;
		}

		return $values;
	}

	/**
	 * @param array<int, array<string, string>> $targetValuesByRole role id => (permission id => value)
	 * @return array{
	 *     add: list<array{ROLE_ID: int, PERMISSION_ID: string, VALUE: string}>,
	 *     update: array<int, array{ROLE_ID: int, PERMISSION_ID: string, VALUE: string}>,
	 *     delete: array<int, list<string>>,
	 * } empty array when the stored state already equals the target one
	 */
	private function calculatePermissionChanges(array $targetValuesByRole): array
	{
		$rowsToAdd = [];
		$rowsToUpdateByRowId = [];
		$permissionIdsToDeleteByRole = [];

		$storedRowsByRole = $this->loadStoredPermissionRows(array_keys($targetValuesByRole));
		foreach ($targetValuesByRole as $roleId => $targetValues)
		{
			$storedRows = $storedRowsByRole[$roleId] ?? [];
			foreach ($storedRows as $permissionId => $storedRow)
			{
				if (!isset($targetValues[$permissionId]))
				{
					$permissionIdsToDeleteByRole[$roleId][] = (string)$permissionId;

					continue;
				}

				if ($storedRow['VALUE'] !== $targetValues[$permissionId])
				{
					// The columns of the unique index travel with the value: the parent entity validates the whole
					// row and reads back from the table the fields an update leaves out, which costs two selects.
					$rowsToUpdateByRowId[$storedRow['ID']] = [
						'ROLE_ID' => $roleId,
						'PERMISSION_ID' => (string)$permissionId,
						'VALUE' => $targetValues[$permissionId],
					];
				}
			}

			foreach ($targetValues as $permissionId => $value)
			{
				if (!isset($storedRows[$permissionId]))
				{
					$rowsToAdd[] = [
						'ROLE_ID' => $roleId,
						'PERMISSION_ID' => (string)$permissionId,
						'VALUE' => $value,
					];
				}
			}
		}

		if (empty($rowsToAdd) && empty($rowsToUpdateByRowId) && empty($permissionIdsToDeleteByRole))
		{
			return [];
		}

		return [
			'add' => $rowsToAdd,
			'update' => $rowsToUpdateByRowId,
			'delete' => $permissionIdsToDeleteByRole,
		];
	}

	/**
	 * @param list<int> $roleIds
	 * @return array<int, array<string, array{ID: int, VALUE: string}>> role id => (permission id => row)
	 */
	private function loadStoredPermissionRows(array $roleIds): array
	{
		$rows = PermissionWriteLock::readOnMaster(
			static fn (): array => PermissionTable::query()
				->setSelect(['ID', 'ROLE_ID', 'PERMISSION_ID', 'VALUE'])
				->whereIn('ROLE_ID', $roleIds)
				->fetchAll()
			,
		);

		$storedRowsByRole = [];
		foreach ($rows as $row)
		{
			$storedRowsByRole[(int)$row['ROLE_ID']][(string)$row['PERMISSION_ID']] = [
				'ID' => (int)$row['ID'],
				'VALUE' => (string)$row['VALUE'],
			];
		}

		return $storedRowsByRole;
	}

	/**
	 * @param array{
	 *     add: list<array{ROLE_ID: int, PERMISSION_ID: string, VALUE: string}>,
	 *     update: array<int, array{ROLE_ID: int, PERMISSION_ID: string, VALUE: string}>,
	 *     delete: array<int, list<string>>,
	 * } $changes
	 */
	private function applyPermissionChanges(array $changes): void
	{
		try
		{
			foreach ($changes['delete'] as $roleId => $permissionIds)
			{
				PermissionTable::deleteList([
					'=ROLE_ID' => $roleId,
					'=PERMISSION_ID' => $permissionIds,
				]);
			}

			foreach ($changes['update'] as $rowId => $row)
			{
				$this->checkWriteResult(PermissionTable::update($rowId, $row));
			}

			foreach ($changes['add'] as $row)
			{
				// The lock is advisory and the write goes on without it, so a row the delta reports as missing may
				// already exist by the time it is written: an upsert on the unique index gives the row its target value
				// where a plain insert would break the index instead.
				PermissionTable::merge($row, ['VALUE' => $row['VALUE']], self::PERMISSION_UNIQUE_FIELDS);
			}
		}
		finally
		{
			// The authoritative reset is the one of saveRolePermissions(), which runs after the commit; this
			// one runs while the transaction is still open and is redundant on that path. It is here for the
			// callers that reach this method directly, so that no write path is left without a reset.
			$this->resetPermissionValueCacheOfEveryReader();
		}
	}

	/**
	 * The deletes of the delta are already done by the time a row is written, so a write reported as failed has to
	 * take the whole transaction with it instead of leaving the role with a part of its permissions.
	 *
	 * @throws SqlQueryException
	 */
	private function checkWriteResult(Result $result): void
	{
		if (!$result->isSuccess())
		{
			throw new SqlQueryException(implode('; ', $result->getErrorMessages()));
		}
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable $exception)
		{
			// A rollback nested into an outer transaction reverts to its savepoint and only then reports itself
			// as unsupported; the failure worth reporting is the original one, so this report is only logged.
			$this->getLogger()->error('Unable to roll back the sign permissions write: ' . $exception->getMessage());
		}
	}

	private function getLogger(): Debug\Logger
	{
		return Container::instance()->getLogger('Access');
	}

	public function isAllSignPermissionsEmpty(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$signPermissions = PermissionTable::query()
			->setSelect(['ID'])
			->whereNotIn('VALUE', ['0', \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE])
			->setLimit(1)
			->fetchAll()
		;

		return empty($signPermissions);
	}

	protected function getLocalPermissionList(): array
	{
		return PermissionDictionary::getList();
	}

	protected function getPermissionCode(string $id): ?string
	{
		return PermissionDictionary::getName($id);
	}

	public function getValueForPermission(array $roles, string $permissionId): ?string
	{
		if (empty($roles))
		{
			return null;
		}

		$cacheKey = $this->buildPermissionValueCacheKey($roles, $permissionId);
		if (array_key_exists($cacheKey, $this->permissionValueCache))
		{
			return $this->permissionValueCache[$cacheKey];
		}

		try
		{
			$permissions = $this->fetchPermissionRows($roles, $permissionId);

			$isValueFound = false;
			$max = '';
			foreach ($permissions as $permission)
			{
				if ($permission['VALUE'] > $max)
				{
					$isValueFound = true;
					$max = $permission['VALUE'];
				}
			}

			$value = !$isValueFound ? null : $max;
			$this->permissionValueCache[$cacheKey] = $value;

			return $value;
		}
		catch (ObjectPropertyException|ArgumentException|SystemException $e)
		{
			return null;
		}
	}

	/**
	 * The read of {@see self::getValueForPermission()} on its own, so that a test can make it fail: the
	 * failure of an ORM read is not reachable through the arguments, and without it the promise that a failed
	 * read is not remembered cannot be checked at all.
	 *
	 * @param list<int|string> $roles
	 * @return list<array{VALUE: string}>
	 * @throws ObjectPropertyException|ArgumentException|SystemException
	 */
	protected function fetchPermissionRows(array $roles, string $permissionId): array
	{
		return PermissionTable::query()
			->setSelect(['VALUE'])
			->whereIn('ROLE_ID', $roles)
			->where('PERMISSION_ID', $permissionId)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Injective on the set of roles: the order and the keys of the array do not matter, while two different
	 * sets can never build one string. The length before every role is what gives the second half - a plain
	 * separator lets ['1', '2,3'] and ['1,2', '3'] meet, and a met key here is another role set's permission
	 * value, not a missed cache. The sort is SORT_STRING for the same reason: a non-numeric role id compares
	 * as zero under SORT_NUMERIC and the order stops being defined.
	 *
	 * @param list<int|string> $roles
	 */
	private function buildPermissionValueCacheKey(array $roles, string $permissionId): string
	{
		$normalizedRoles = array_unique(array_map('strval', $roles));
		sort($normalizedRoles, SORT_STRING);

		$body = '';
		foreach ($normalizedRoles as $role)
		{
			$body .= strlen($role) . ':' . $role;
		}

		return count($normalizedRoles) . '|' . $body . '|' . $permissionId;
	}

	/**
	 * Drops the values this instance remembers. One-way safe: at worst it costs a repeated read, it can never
	 * hand out another role set's value. Goes nowhere near the service locator on purpose -
	 * {@see self::resetPermissionValueCacheOfEveryReader()} is the one that does, and a locator lookup from
	 * here would come back to this very object and recurse without an end.
	 */
	public function resetPermissionValueCache(): void
	{
		$this->permissionValueCache = [];
	}

	/**
	 * Resets both instances a write can leave stale: this one and the one the readers take from the locator
	 * {@see \Bitrix\Sign\Access\Rule\BaseRule}. They are different objects whenever the write goes through a
	 * service built with new, as the permission page does, and the same object otherwise - the reset is
	 * idempotent either way.
	 */
	private function resetPermissionValueCacheOfEveryReader(): void
	{
		$this->resetPermissionValueCache();

		try
		{
			Container::instance()->getRolePermissionService()?->resetPermissionValueCache();
		}
		catch (\Throwable $exception)
		{
			// Called from the finally of a write, a throw here would replace the result of that write. There is
			// nothing stale to drop in this case either: the service of the locator failed to be built, so no
			// reader of this request holds a cache of it.
			$this->getLogger()->error(
				'Unable to reset the permission value cache of the locator: ' . $exception->getMessage(),
			);
		}
	}

	/**
	 * @return array<int, array{CODE: string} role id to its code
	 */
	public function getDefaultRoles(): array
	{
		$defaultRoleIds = [];
		foreach ($this->getRoleList() as $role)
		{
			if (in_array($role['CODE'], self::DEFAULT_ROLES_CODE, true))
			{
				$defaultRoleIds[(int)$role['ID']] = ['CODE' => $role['CODE']];
			}
		}

		return $defaultRoleIds;
	}

	/**
	 * @return list<array{id: string|int, value: string, type: string, enableSearch: bool}> its not return sign permissions if its empty
	 */
	public function getFlatAccessRightsFromAllRoles(): array
	{
		$userGroups = $this->getUserGroups();
		$accessRights = array_column($userGroups, 'accessRights');
		$flatAccessRightsFromAllRoles = [];
		foreach ($accessRights as $accessRight)
		{
			$flatAccessRightsFromAllRoles = array_merge($flatAccessRightsFromAllRoles, $accessRight);
		}

		return $flatAccessRightsFromAllRoles;
	}
}
