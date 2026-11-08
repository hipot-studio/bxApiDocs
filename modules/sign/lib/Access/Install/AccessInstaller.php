<?php

namespace Bitrix\Sign\Access\Install;

use Bitrix\Crm\Integration\Sign\Access;
use Bitrix\Crm\Integration\Sign\Access\Service\RolePermissionService;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Sign\Access\Permission\PermissionTable;
use Bitrix\Sign\Access\Permission\PermissionWriteLock;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService as SignRolePermissionService;
use Bitrix\Sign\Service\Container;
use CCrmRole;
use Bitrix\Crm\Service\UserPermissions;

class AccessInstaller
{
	/**
	 * @return bool whether the rows were installed; a false means the write lock was held and the caller
	 *         has to ask for another run rather than treat the install as done
	 */
	public static function installMissingSafeFolderPermissions(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return true;
		}

		return self::installMissingPermissions(
			[
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
			],
			[
				SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => UserPermissions::PERMISSION_SELF,
				SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE => UserPermissions::PERMISSION_SUBDEPARTMENT,
			],
		);
	}

	/**
	 * @return bool whether the row was installed; a false means the write lock was held and the caller has
	 *         to ask for another run rather than treat the install as done
	 */
	public static function installMissingDocumentAnnulPermission(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return true;
		}

		return self::installMissingPermissions(
			[SignPermissionDictionary::SIGN_DOCUMENT_ANNUL],
			[
				SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => UserPermissions::PERMISSION_NONE,
				SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE => UserPermissions::PERMISSION_SUBDEPARTMENT,
			],
		);
	}

	/**
	 * Adds to the default roles only the permission rows they are missing, so values already stored
	 * (including ones an administrator has changed) stay untouched and a repeated run changes nothing.
	 * Requires the crm module to be loaded.
	 *
	 * @param list<int> $permissionIds
	 * @param array<string, string> $defaultValueByRoleCode
	 * @return bool whether the rows were installed; a false means the lock was held and nothing was written
	 */
	private static function installMissingPermissions(array $permissionIds, array $defaultValueByRoleCode): bool
	{
		// Same lock as the other write paths of the table: this method reads the roles and their stored rows
		// and then inserts what the read reported as missing, so without the lock its own read goes stale.
		// Inserting anyway is not safe for being insert-only: a role deletion holding the lock removes the
		// role together with its rows while this list is already read, and the insert puts a row back for a
		// role that no longer exists - a state no order of the two operations produces. So the install is
		// left for the next run instead.
		if (!PermissionWriteLock::acquire())
		{
			Container::instance()->getLogger('Access')->error(
				'Missing sign permissions are not installed: the lock ' . PermissionWriteLock::NAME
				. ' was not acquired',
			);

			return false;
		}

		try
		{
			$roles = PermissionWriteLock::readOnMaster(static fn () => CCrmRole::GetList(
				['ID' => 'DESC'],
				['=GROUP_CODE' => RolePermissionService::ROLE_GROUP_CODE],
			));

			$valueByRoleId = [];
			while ($role = $roles->Fetch())
			{
				$value = $defaultValueByRoleCode[$role['CODE']] ?? null;
				// PERMISSION_NONE is an empty value: RolePermissionService never stores such a row either,
				// and a missing row already reads as "no permission".
				if ($value === null || $value === UserPermissions::PERMISSION_NONE)
				{
					continue;
				}

				$valueByRoleId[(int)$role['ID']] = $value;
			}

			if (empty($valueByRoleId))
			{
				return true;
			}

			// The stored rows of every role are read at once: the lock is shared with the other writer, so the
			// duration of this section is the time an administrator write waits for it.
			$storedPermissionIds = PermissionWriteLock::readOnMaster(
				static fn (): array => self::loadStoredPermissionIds(array_keys($valueByRoleId), $permissionIds),
			);

			foreach ($valueByRoleId as $roleId => $value)
			{
				foreach ($permissionIds as $permissionId)
				{
					if (isset($storedPermissionIds[$roleId][(string)$permissionId]))
					{
						continue;
					}

					// An upsert that leaves the stored value alone: the row may exist despite the read - a
					// writer of an older revision, or an install of another portal process - and the unique
					// index stays intact while such a row is treated exactly as a row reported as present.
					// The no-op update assigns ROLE_ID its own literal rather than a column to itself: a
					// conflict is only possible on a row that already holds this ROLE_ID, while a bare column
					// name on the right side of the update is ambiguous between the stored and the proposed
					// row and PostgreSQL rejects such a merge.
					PermissionTable::merge(
						[
							'ROLE_ID' => $roleId,
							'PERMISSION_ID' => (string)$permissionId,
							'VALUE' => $value,
						],
						['ROLE_ID' => $roleId],
						SignRolePermissionService::PERMISSION_UNIQUE_FIELDS,
					);
				}
			}
		}
		finally
		{
			PermissionWriteLock::release();
			// After the release of the lock, not before it: this call can build the service of the locator and
			// throw, and a throw here would leave the lock held until the connection closes.
			self::resetPermissionValueCache();
		}

		return true;
	}

	/**
	 * The rows written here go straight to the table, so the service the readers of this request take from
	 * the locator {@see \Bitrix\Sign\Access\Rule\BaseRule} would keep answering from what it read before.
	 * Every entry point of this class checks the crm module first; the service is only typed as nullable for
	 * callers that did not, and without crm the locator raises a TypeError long before this line.
	 */
	private static function resetPermissionValueCache(): void
	{
		try
		{
			Container::instance()->getRolePermissionService()?->resetPermissionValueCache();
		}
		catch (\Throwable $exception)
		{
			// Called from the finally of a write, a throw here would replace the result of that write. There is
			// nothing stale to drop in this case either: the service of the locator failed to be built, so no
			// reader of this request holds a cache of it.
			Container::instance()->getLogger('Access')->error(
				'Unable to reset the permission value cache of the locator: ' . $exception->getMessage(),
			);
		}
	}

	/**
	 * @param list<int> $roleIds
	 * @param list<int> $permissionIds
	 * @return array<int, array<string, true>> role id => (permission id => true)
	 */
	private static function loadStoredPermissionIds(array $roleIds, array $permissionIds): array
	{
		$rows = PermissionTable::query()
			->setSelect(['ROLE_ID', 'PERMISSION_ID'])
			->whereIn('ROLE_ID', $roleIds)
			->whereIn('PERMISSION_ID', array_map('strval', $permissionIds))
			->fetchAll()
		;

		$storedPermissionIds = [];
		foreach ($rows as $row)
		{
			$storedPermissionIds[(int)$row['ROLE_ID']][(string)$row['PERMISSION_ID']] = true;
		}

		return $storedPermissionIds;
	}

	public static function install($removeAllPrevious = false): string
	{
		try
		{
			if (!Loader::includeModule('crm'))
			{
				return '';
			}
		}
		catch (LoaderException)
		{
			return '';
		}

		// The lock is taken for the whole install, the clean-up included, and the save of each role takes it
		// again from inside {@see SignRolePermissionService::saveRolePermissions()} - a repeated acquire on the
		// same connection only raises the counter of a lock this call already holds. Taken by the save alone,
		// the lock could be missing right after the rows had been deleted, and the install would leave the
		// portal with no sign permissions at all.
		$isLocked = PermissionWriteLock::acquire();
		if (!$isLocked)
		{
			Container::instance()->getLogger('Access')->error(
				'Sign permissions are not installed: the lock ' . PermissionWriteLock::NAME . ' was not acquired',
			);

			// Nothing is deleted and nothing is written; the line asks for another run later and carries the
			// argument of this call: a retry of a full reinstall that dropped the flag would leave the rows
			// of the roles the reinstall drops behind.
			return self::getRetryCall($removeAllPrevious);
		}

		try
		{
			return self::installDefaultRoles($removeAllPrevious);
		}
		finally
		{
			PermissionWriteLock::release();
		}
	}

	/**
	 * Writes the default permissions of a portal that carries none. Whether it carries none is decided here,
	 * under the same lock that guards the write: read before the lock, the answer goes stale exactly when it
	 * matters - the writer holding the lock is often an administrator saving the rights, and by the time the
	 * lock is free the table holds their choice, which the defaults would then overwrite.
	 */
	public static function installDefaultsForPortalWithoutPermissions(): PermissionInstallOutcome
	{
		try
		{
			if (!Loader::includeModule('crm'))
			{
				return PermissionInstallOutcome::NotNeeded;
			}
		}
		catch (LoaderException)
		{
			return PermissionInstallOutcome::NotNeeded;
		}

		if (!PermissionWriteLock::acquire())
		{
			Container::instance()->getLogger('Access')->error(
				'Default sign permissions are not installed: the lock ' . PermissionWriteLock::NAME
				. ' was not acquired',
			);

			return PermissionInstallOutcome::Deferred;
		}

		try
		{
			if (!self::isPermissionTableEmpty())
			{
				return PermissionInstallOutcome::NotNeeded;
			}

			// A non-empty line means the install wrote nothing - the CRM roles it reads do not exist yet.
			return self::installDefaultRoles(false) === ''
				? PermissionInstallOutcome::Installed
				: PermissionInstallOutcome::Deferred;
		}
		finally
		{
			PermissionWriteLock::release();
		}
	}

	/**
	 * Runs inside the locked section, so the answer holds until the section ends. The read is bound to the
	 * master: a replica could report a table still empty while the rows are already stored.
	 */
	private static function isPermissionTableEmpty(): bool
	{
		$anyPermission = PermissionWriteLock::readOnMaster(static fn () => PermissionTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->fetchObject()
		);

		return $anyPermission === null;
	}

	/**
	 * The full reset asked for by the resetAccessRights query of the permissions page: the CRM roles of the
	 * group are dropped and created anew, and the sign rows are written for the roles that reset has just
	 * created. Both halves run under one lock taken before the CRM one, because the caller has no way to
	 * retry the sign half alone - refused in between, the reset would leave the portal with new roles and
	 * the rows of the old ones, which no role carries any more.
	 *
	 * @throws SqlQueryException when the write lock of the permission table could not be acquired; nothing
	 *         is changed then, and the refusal is raised rather than returned because a reset that quietly
	 *         did nothing is indistinguishable from one that ran - the page shows the rights it read before
	 *         the reset either way.
	 */
	public static function reinstallWithCrmRoles(): void
	{
		try
		{
			if (!Loader::includeModule('crm'))
			{
				return;
			}
		}
		catch (LoaderException)
		{
			return;
		}

		if (!PermissionWriteLock::acquire())
		{
			Container::instance()->getLogger('Access')->error(
				'Sign permissions are not reset: the lock ' . PermissionWriteLock::NAME . ' was not acquired',
			);

			throw new SqlQueryException(
				'Unable to acquire the lock ' . PermissionWriteLock::NAME . ' to reset sign permissions',
			);
		}

		try
		{
			Access::installDefaultRoles(true);
			self::installDefaultRoles(true);
		}
		finally
		{
			PermissionWriteLock::release();
		}
	}

	/**
	 * The line an agent run is rescheduled by. The argument is kept: a full reinstall asked for again
	 * without it would install the defaults over the rows it was supposed to drop first.
	 */
	private static function getRetryCall(bool $removeAllPrevious): string
	{
		return '\Bitrix\Sign\Access\Install\AccessInstaller::install(' . ($removeAllPrevious ? 'true' : '') . ');';
	}

	/**
	 * Runs inside the locked section of {@see self::install()}.
	 */
	private static function installDefaultRoles(bool $removeAllPrevious): string
	{
		if ($removeAllPrevious)
		{
			PermissionTable::deleteList(['>ID' => 0]);
			// The only write this method makes on its own, and the widest one of the module - every row of
			// the table is gone. The saves below reset the cache themselves.
			self::resetPermissionValueCache();
		}
		$roles = CCrmRole::GetList(
			['ID' => 'DESC'],
			['=GROUP_CODE' => RolePermissionService::ROLE_GROUP_CODE],
		);

		$rolesToInstall = [
			SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => [
				[
					'accessRights' => [
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_TEMPLATES,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FIRED,
							'value' => UserPermissions::PERMISSION_NONE,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_WRITE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_CREATE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_ADD,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_EDIT,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_REFUSED,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_DOCUMENT_ANNUL,
							'value' => UserPermissions::PERMISSION_NONE,
						],
					],
				],
			],
			SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE =>[
				[
					'accessRights' => [
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_TEMPLATES,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_ACCESS_RIGHTS,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FIRED,
							'value' => UserPermissions::PERMISSION_NONE,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_WRITE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_CREATE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_ADD,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_EDIT,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_REFUSED,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_DOCUMENT_ANNUL,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
					],
				],
			],
		];

		$installed = false;
		while ($role = $roles->Fetch())
		{
			foreach ($rolesToInstall as $roleToInstall => $permission)
			{
				if ($role['CODE'] === $roleToInstall)
				{
					$permission[0]['id'] = $role['ID'];
					(new SignRolePermissionService())->saveRolePermissions($permission);
					$installed = true;
				}
			}
		}

		if ($installed)
		{
			return '';
		}

		// Whatever this call was asked to drop is dropped by now, so the run it asks for is a plain install.
		return self::getRetryCall(false);
	}
}
