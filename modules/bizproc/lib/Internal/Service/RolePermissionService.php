<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\PermissionCache;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\Access\AccessRepositoryInterface;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Transactional persistence of the role/permission matrix. The single write path: callers go through
 * {@see \Bitrix\Bizproc\Public\Command\SaveRolePermissionsCommand}, never straight to the ORM.
 *
 * The {@see DelegationScopeValidator} runs BEFORE any write (so a rejected save never starts a transaction):
 * only a CONFIGURE_RIGHTS holder or a portal administrator may configure rights, and such a user has full
 * access. The whole matrix is then written in one transaction with rollback on failure as a full REPLACE
 * per role (delete-by-role + bulk insert, catalog-style). A portable advisory lock serializes the
 * read+write so a concurrent revoke cannot interleave.
 *
 * The replace is full, and stays full for every caller by default. Only a caller that says so explicitly
 * ($preserveInexpressibleValues) gets the one exception: a value the page that produced this payload showed
 * nowhere is carried over from the committed rows instead of being dropped
 * {@see self::withUnexpressedValues()}. That way a programmatic full-state write can still revoke the value,
 * while a save from a page that could not show it does not delete it. The caller answers for its own payload;
 * this service does not look at the portal state behind it, which would make a switch flipped while the page
 * was open part of the request.
 *
 * The tagged {@see PermissionCache} is invalidated after commit: touched roles by their role tag, and any
 * structural change (role create/delete, membership change) by the global tag.
 */
final class RolePermissionService
{
	private DelegationScopeValidator $validator;
	private AccessRepositoryInterface $repository;

	public function __construct(
		?DelegationScopeValidator $validator = null,
		private readonly PermissionCache $permissionCache = new PermissionCache(),
	)
	{
		$this->validator = $validator ?? new DelegationScopeValidator();
		$this->repository = Container::getAccessRepository();
	}

	/**
	 * @param array<int, array{
	 *     id?: int|string,
	 *     title?: string,
	 *     accessRights?: array<int, array{id: int|string, value: int|string}>,
	 *     members?: array<string, mixed>,
	 *     accessCodes?: array<string, mixed>,
	 * }> $userGroups full desired state of the matrix
	 * @param array<int, int|string> $deletedUserGroups role ids to delete
	 */
	public function saveRolePermissions(
		int $userId,
		array $userGroups,
		array $deletedUserGroups = [],
		bool $preserveInexpressibleValues = false,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$lockName = 'bizproc_access_permissions_' . $userId;
		// Portable advisory lock (MySQL GET_LOCK / PgSQL advisory lock), never raw MySQL-only SQL.
		if (!$connection->lock($lockName, 10))
		{
			$result->addError(new Error('Unable to acquire access permissions lock'));

			return $result;
		}

		try
		{
			$validation = $this->validator->validate($userId, $userGroups, $deletedUserGroups);
			if (!$validation->isSuccess())
			{
				return $validation;
			}

			$affectedRoleIds = [];
			$structural = !empty($deletedUserGroups);
			$connection->startTransaction();
			$existingRoleIds = array_values(array_unique(array_filter(
				array_map(static fn (array $userGroup): int => (int)($userGroup['id'] ?? 0), $userGroups),
				static fn (int $roleId): bool => $roleId > 0,
			)));
			$currentRoleNames = [];
			$currentPermissions = [];
			$currentRelations = [];
			if (!empty($existingRoleIds))
			{
				$existingRoleIdMap = array_fill_keys($existingRoleIds, true);
				foreach ($this->repository->getAllRoles() as $role)
				{
					$roleId = (int)$role->getId();
					if (isset($existingRoleIdMap[$roleId]))
					{
						$currentRoleNames[$roleId] = (string)$role->getName();
					}
				}

				foreach ($this->repository->getPermissionsByRoleIds($existingRoleIds) as $permission)
				{
					$currentPermissions[(int)$permission->getRoleId()][] = $permission;
				}
				$currentRelations = $this->repository->getAccessCodesByRoleIds($existingRoleIds);
			}

			foreach ($deletedUserGroups as $deletedRoleId)
			{
				$deletedRoleId = (int)$deletedRoleId;
				if ($deletedRoleId > 0)
				{
					$this->deleteRole($deletedRoleId);
					$affectedRoleIds[$deletedRoleId] = $deletedRoleId;
				}
			}

			foreach ($userGroups as $userGroup)
			{
				$roleId = (int)($userGroup['id'] ?? 0);
				$name = trim((string)($userGroup['title'] ?? ''));
				if ($name === '')
				{
					$connection->rollbackTransaction();
					$result->addError(new Error((string)Loc::getMessage('BIZPROC_ACCESS_ROLE_NAME_REQUIRED')));

					return $result;
				}
				$isNewRole = $roleId <= 0;
				$saveRole = $isNewRole
					? $this->saveRole($name, $roleId)
					: new Result()
				;
				if (!$saveRole->isSuccess())
				{
					$connection->rollbackTransaction();
					$result->addErrors($saveRole->getErrors());

					return $result;
				}

				if ($isNewRole)
				{
					$roleId = (int)$saveRole->getData()['id'];
					$affectedRoleIds[$roleId] = $roleId;
				}
				elseif (($currentRoleNames[$roleId] ?? null) !== $name)
				{
					$saveRole = $this->saveRole($name, $roleId);
					if (!$saveRole->isSuccess())
					{
						$connection->rollbackTransaction();
						$result->addErrors($saveRole->getErrors());

						return $result;
					}
					$affectedRoleIds[$roleId] = $roleId;
				}
				$structural = $structural || $isNewRole;

				$desired = $this->desiredByPermission($userGroup);
				if ($preserveInexpressibleValues)
				{
					$desired = $this->withUnexpressedValues(
						$desired,
						$isNewRole ? [] : ($currentPermissions[$roleId] ?? []),
					);
				}

				if ($isNewRole || $this->permissionsRequireUpdate($currentPermissions[$roleId] ?? [], $roleId, $desired))
				{
					$this->replacePermissions($roleId, $desired);
					$affectedRoleIds[$roleId] = $roleId;
				}

				// PermissionMatrixProvider::decodeUserGroups emits members under 'members'; a direct API caller
				// may still pass 'accessCodes'. Accept either; memberRelations reads both.
				if (array_key_exists('members', $userGroup) || array_key_exists('accessCodes', $userGroup))
				{
					$relations = $this->memberRelations($userGroup);
					if ($this->relationsRequireUpdate($currentRelations[$roleId] ?? [], $relations))
					{
						$this->repository->replaceRoleRelations($roleId, $relations);
						$affectedRoleIds[$roleId] = $roleId;
						$structural = true;
					}
				}
			}

			$connection->commitTransaction();
			$this->invalidateCache($affectedRoleIds, $structural);

			return $result;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			$result->addError(new Error($e->getMessage()));

			return $result;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function saveRole(string $name, int $roleId): Result
	{
		$result = new Result();
		if ($name === '')
		{
			$result->addError(new Error((string)Loc::getMessage('BIZPROC_ACCESS_ROLE_NAME_REQUIRED')));

			return $result;
		}

		$saveResult = $roleId > 0
			? $this->repository->updateRoleName($roleId, $name)
			: $this->repository->addRole($name)
		;
		if (!$saveResult->isSuccess())
		{
			$result->addErrors($saveResult->getErrors());

			return $result;
		}

		$result->setData(['id' => (int)$saveResult->getId()]);

		return $result;
	}

	private function deleteRole(int $roleId): void
	{
		$this->repository->deleteRole($roleId);
	}

	/**
	 * Full replace of the role matrix (a configuring user has full access, so no per-area delta applies).
	 *
	 * @param array<int, int[]> $desired permission id => values
	 */
	private function replacePermissions(int $roleId, array $desired): void
	{
		$this->repository->deletePermissionsByRole($roleId);
		$this->repository->insertPermissions($this->buildRows($roleId, $desired));
	}

	/**
	 * No-op optimization: write the role matrix only when the committed rows differ from the desired ones,
	 * so an identical payload does not invalidate the role cache.
	 *
	 * @param array<int, int[]> $desired
	 */
	private function permissionsRequireUpdate(iterable $current, int $roleId, array $desired): bool
	{
		$current = $this->permissionsByPermission($current);
		$desired = $this->permissionsByPermission($this->buildRows($roleId, $desired));

		return $current !== $desired;
	}

	/**
	 * @param iterable<\Bitrix\Bizproc\Internal\Entity\Access\Permission|array{PERMISSION_ID: int|string, VALUE: int|string}> $permissions
	 * @return array<int, int[]>
	 */
	private function permissionsByPermission(iterable $permissions): array
	{
		$result = [];
		foreach ($permissions as $permission)
		{
			$permissionId = is_array($permission)
				? (int)$permission['PERMISSION_ID']
				: (int)$permission->getPermissionId()
			;
			$value = is_array($permission) ? (int)$permission['VALUE'] : (int)$permission->getValue();
			$result[$permissionId][] = $value;
		}
		ksort($result);
		foreach ($result as &$values)
		{
			sort($values);
		}
		unset($values);

		return $result;
	}

	/**
	 * @param array<string, string> $relations
	 */
	private function relationsRequireUpdate(array $current, array $relations): bool
	{
		$current = array_values(array_unique(array_map('strval', $current)));
		$desired = array_values(array_unique(array_keys($relations)));
		sort($current);
		sort($desired);

		return $current !== $desired;
	}

	/**
	 * @param array<int, int[]> $desired
	 * @return array<int, array{ROLE_ID: int, PERMISSION_ID: string, VALUE: int}>
	 */
	private function buildRows(int $roleId, array $desired): array
	{
		$rows = [];
		foreach ($desired as $permissionId => $values)
		{
			$isMultivariables = $this->isMultivariables($permissionId);
			foreach ($values as $value)
			{
				$value = (int)$value;
				if ($isMultivariables)
				{
					// Keep only a sentinel scope value or a real (positive) template id; drop 0 and bogus
					// negatives. The dictionary answers what a sentinel is, so a new one is admitted here
					// by adding it there and nowhere else.
					if (!PermissionDictionary::isSentinelValue($value) && $value <= 0)
					{
						continue;
					}
				}
				else
				{
					// Toggler stores a clean 0/1; any crafted value collapses to grant (>=1) or deny.
					$value = $value >= PermissionDictionary::VALUE_YES
						? PermissionDictionary::VALUE_YES
						: PermissionDictionary::VALUE_NO
					;
				}

				$rows[] = [
					'ROLE_ID' => $roleId,
					'PERMISSION_ID' => (string)$permissionId,
					'VALUE' => $value,
				];
			}
		}

		return $rows;
	}

	/**
	 * Committed values the page that sent this payload could not express, put back into the desired state: what
	 * a page never showed, a save from that page must not delete. The rights page has exactly one such value -
	 * the "own templates" sentinel of a template scope, and it is expressible only when the source mode is usable
	 * AND the portal switch of the value is on. Under either condition unmet it is neither an element of the
	 * right nor a
	 * value of a cell
	 * ({@see \Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\AccessRightsProvider::loadUserGroupModels()}),
	 * so its absence from the payload carries no intent of the user at all.
	 *
	 * Whether that was the case is decided by the caller and NOT re-read from the switches here. The question
	 * belongs to the render that produced the payload, and the pair of switches is portal state that moves
	 * independently of an open page: read a second time at save it made a switch flipped in between part of the
	 * request. Off and on again around one page - the documented emergency use of the switch - deleted a granted
	 * value nobody saw, and off between a deliberate deselect and its save restored the value that was just
	 * revoked. The page answers about itself
	 * ({@see \Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\AccessRightsProvider::isOwnSentinelExpressible()}).
	 *
	 * Being a sentinel is not what saves a value either: "all" is offered in every mode, so a payload without it
	 * removes it as before. The one condition is that the page could not express the value.
	 *
	 * @param array<int, int[]> $desired
	 * @param iterable<\Bitrix\Bizproc\Internal\Entity\Access\Permission> $current committed rows of the role
	 * @return array<int, int[]>
	 */
	private function withUnexpressedValues(array $desired, iterable $current): array
	{
		foreach ($current as $permission)
		{
			$value = (int)$permission->getValue();
			$permissionId = (int)$permission->getPermissionId();
			if ($value !== PermissionDictionary::VALUE_VARIATION_OWN || !$this->isMultivariables($permissionId))
			{
				continue;
			}

			$desired[$permissionId] ??= [];
			if (!in_array($value, $desired[$permissionId], true))
			{
				$desired[$permissionId][] = $value;
			}
		}

		return $desired;
	}

	/**
	 * @return array<int, int[]> permission id => values
	 */
	private function desiredByPermission(array $userGroup): array
	{
		$desired = [];
		foreach (($userGroup['accessRights'] ?? []) as $right)
		{
			$permissionId = (int)($right['id'] ?? 0);
			if ($permissionId <= 0)
			{
				continue;
			}

			$desired[$permissionId] ??= [];
			$desired[$permissionId][] = (int)($right['value'] ?? 0);
		}

		return $desired;
	}

	/**
	 * @return array<string, string> access code => member type (relation update format)
	 */
	private function memberRelations(array $userGroup): array
	{
		$source = $userGroup['members'] ?? $userGroup['accessCodes'] ?? [];
		if (!is_array($source))
		{
			return [];
		}

		$relations = [];
		foreach ($source as $code => $member)
		{
			if (!is_string($code))
			{
				continue;
			}

			$type = '';
			if (is_string($member))
			{
				$type = $member;
			}
			elseif (is_array($member) && is_string($member['type'] ?? null))
			{
				$type = $member['type'];
			}

			$relations[trim($code)] = $type;
		}

		return $relations;
	}

	private function isMultivariables(int $permissionId): bool
	{
		$descriptor = PermissionDictionary::getPermission((string)$permissionId);

		return ($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES;
	}

	/**
	 * @param array<int, int> $affectedRoleIds
	 */
	private function invalidateCache(array $affectedRoleIds, bool $structural): void
	{
		$cache = $this->permissionCache;
		foreach ($affectedRoleIds as $roleId)
		{
			$cache->clearByRole($roleId);
		}

		if ($structural)
		{
			$cache->clearGlobal();
		}
	}
}
