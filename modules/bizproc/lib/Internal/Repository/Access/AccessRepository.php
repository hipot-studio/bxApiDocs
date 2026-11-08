<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Access;

use Bitrix\Bizproc\Internal\Entity\Access\Permission;
use Bitrix\Bizproc\Internal\Entity\Access\PermissionCollection;
use Bitrix\Bizproc\Internal\Entity\Access\Role;
use Bitrix\Bizproc\Internal\Entity\Access\RoleCollection;
use Bitrix\Bizproc\Internal\Model\EO_Permission;
use Bitrix\Bizproc\Internal\Model\EO_Role;
use Bitrix\Bizproc\Internal\Model\PermissionTable;
use Bitrix\Bizproc\Internal\Model\RoleRelationTable;
use Bitrix\Bizproc\Internal\Model\RoleTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\AccessPermissionMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\AccessRoleMapper;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Access\Exception\RoleRelationSaveException;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\UserAccessTable;

class AccessRepository implements AccessRepositoryInterface
{
	private const INSERT_BATCH_SIZE = 500;

	/**
	 * Chat codes are left out of the membership read, exactly as the model of the framework leaves them out: a
	 * role relation never carries such a code, while a portal holds a great many rows of them.
	 */
	private const CHAT_ACCESS_PROVIDER = 'imchat';

	public function __construct(
		private readonly AccessRoleMapper $roleMapper,
		private readonly AccessPermissionMapper $permissionMapper,
	)
	{
	}

	public function getRoleIdsByAccessCodes(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		$rows = RoleRelationTable::query()
			->addSelect('ROLE_ID')
			->whereIn('RELATION', $accessCodes)
			->exec()
			->fetchAll()
		;

		return array_values(array_unique(array_map('intval', array_column($rows, 'ROLE_ID'))));
	}

	public function getAllRoles(): RoleCollection
	{
		$ormRoles = RoleTable::query()
			->setSelect(['ID', 'NAME'])
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
		;

		$roles = [];
		/** @var EO_Role $ormRole */
		foreach ($ormRoles as $ormRole)
		{
			$roles[] = $this->roleMapper->convertFromOrm($ormRole);
		}

		return new RoleCollection(...$roles);
	}

	public function findRoleIdByName(string $name): ?int
	{
		$row = RoleTable::query()
			->addSelect('ID')
			->where('NAME', $name)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row ? (int)$row['ID'] : null;
	}

	public function getRoleNameById(int $roleId): ?string
	{
		$row = RoleTable::query()
			->addSelect('NAME')
			->where('ID', $roleId)
			->exec()
			->fetch()
		;

		return $row ? (string)$row['NAME'] : null;
	}

	public function roleExistsByName(string $name): bool
	{
		return $this->findRoleIdByName($name) !== null;
	}

	public function getPermissionsByRoleIds(array $roleIds): PermissionCollection
	{
		if (empty($roleIds))
		{
			return new PermissionCollection();
		}

		$ormPermissions = PermissionTable::query()
			->setSelect(['ID', 'ROLE_ID', 'PERMISSION_ID', 'VALUE'])
			->whereIn('ROLE_ID', $roleIds)
			->fetchCollection()
		;

		return $this->convertPermissionsFromOrm($ormPermissions);
	}

	public function getPermissionsByRoleId(int $roleId): PermissionCollection
	{
		$ormPermissions = PermissionTable::query()
			->setSelect(['ID', 'ROLE_ID', 'PERMISSION_ID', 'VALUE'])
			->where('ROLE_ID', $roleId)
			->fetchCollection()
		;

		return $this->convertPermissionsFromOrm($ormPermissions);
	}

	public function getRoleIdsByPermissionValues(array $permissionIds, array $values): array
	{
		if (empty($permissionIds) || empty($values))
		{
			return [];
		}

		$rows = PermissionTable::query()
			->addSelect('ROLE_ID')
			->whereIn('PERMISSION_ID', array_map('strval', $permissionIds))
			->whereIn('VALUE', array_map('intval', $values))
			->exec()
			->fetchAll()
		;

		return array_values(array_unique(array_map('intval', array_column($rows, 'ROLE_ID'))));
	}

	public function getAccessCodesByRoleIds(array $roleIds): array
	{
		if (empty($roleIds))
		{
			return [];
		}

		$rows = RoleRelationTable::query()
			->setSelect(['ROLE_ID', 'RELATION'])
			->whereIn('ROLE_ID', $roleIds)
			->exec()
		;

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ROLE_ID']][] = (string)$row['RELATION'];
		}

		return $result;
	}

	public function getUserAccessCodes(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		if (empty($userIds))
		{
			return [];
		}

		$codes = array_fill_keys($userIds, []);

		$rows = UserAccessTable::query()
			->setSelect(['USER_ID', 'ACCESS_CODE'])
			->whereIn('USER_ID', $userIds)
			->whereNot('PROVIDER_ID', self::CHAT_ACCESS_PROVIDER)
			->exec()
		;

		while ($row = $rows->fetch())
		{
			$userId = (int)$row['USER_ID'];
			$code = (string)$row['ACCESS_CODE'];

			// Keyed by the code itself, so a code and a signature shared by several rows are stored once - the
			// same set the model builds, where the deduplication happens at the end instead.
			$codes[$userId][$code] = $code;

			$signature = (new AccessCode($code))->getSignature();
			if ($signature)
			{
				$codes[$userId][$signature] = $signature;
			}
		}

		return array_map('array_values', $codes);
	}

	public function addRole(string $name): AddResult
	{
		return RoleTable::add(['NAME' => $name]);
	}

	public function updateRoleName(int $roleId, string $name): UpdateResult
	{
		return RoleTable::update($roleId, ['NAME' => $name]);
	}

	public function deleteRole(int $roleId): void
	{
		PermissionTable::deleteList(['=ROLE_ID' => $roleId]);
		RoleRelationTable::deleteList(['=ROLE_ID' => $roleId]);
		RoleTable::delete($roleId);
	}

	public function insertPermissions(array $rows): void
	{
		if (empty($rows))
		{
			return;
		}

		$connection = Application::getConnection();

		$values = [];
		foreach ($rows as $row)
		{
			$roleId = (int)($row['ROLE_ID'] ?? 0);
			$permissionId = (string)($row['PERMISSION_ID'] ?? '');
			$value = (int)($row['VALUE'] ?? 0);
			if ($roleId <= 0 || $permissionId === '')
			{
				continue;
			}

			$expression = new SqlExpression('(?i, ?s, ?i)', $roleId, $permissionId, $value);
			$expression->setConnection($connection);
			$values[] = $expression->compile();
		}

		if (empty($values))
		{
			return;
		}

		// Chunked: a wide selective scope produces thousands of rows, one statement would grow past the
		// DB packet limit and hold the transaction open longer than needed.
		foreach (array_chunk($values, self::INSERT_BATCH_SIZE) as $chunk)
		{
			$expression = new SqlExpression(
				'INSERT INTO ?# (ROLE_ID, PERMISSION_ID, VALUE) VALUES ' . implode(',', $chunk),
				PermissionTable::getTableName(),
			);
			$expression->setConnection($connection);

			$connection->query($expression->compile());
		}
	}

	public function deletePermissionsByRole(int $roleId): void
	{
		PermissionTable::deleteList(['=ROLE_ID' => $roleId]);
	}

	public function deletePermissions(array $filter): void
	{
		PermissionTable::deleteList($filter);
	}

	public function replaceRoleRelations(int $roleId, array $accessCodes): void
	{
		$connection = Application::getConnection();

		RoleRelationTable::deleteList(['=ROLE_ID' => $roleId]);

		$values = [];
		foreach ($accessCodes as $code => $type)
		{
			$code = (string)$code;
			if (!AccessCode::isValid($code))
			{
				throw new RoleRelationSaveException();
			}

			$expression = new SqlExpression('(?i, ?s)', $roleId, trim($code));
			$expression->setConnection($connection);
			$values[] = $expression->compile();
		}

		if (empty($values))
		{
			return;
		}

		$expression = new SqlExpression(
			'INSERT INTO ?# (ROLE_ID, RELATION) VALUES ' . implode(',', $values),
			RoleRelationTable::getTableName(),
		);
		$expression->setConnection($connection);

		try
		{
			$connection->query($expression->compile());
		}
		catch (\Exception $e)
		{
			throw new RoleRelationSaveException();
		}
	}

	public function deleteOrphanPermissionsByTemplate(int $templateId, array $permissionIds): bool
	{
		$filter = [
			'=VALUE' => $templateId,
			'@PERMISSION_ID' => $permissionIds,
		];

		$hasScopeRows = PermissionTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'limit' => 1,
		])->fetch();
		if ($hasScopeRows === false)
		{
			return false;
		}

		PermissionTable::deleteList($filter);

		return true;
	}

	public function getPermissionTableName(): string
	{
		return PermissionTable::getTableName();
	}

	private function convertPermissionsFromOrm(iterable $ormPermissions): PermissionCollection
	{
		$permissions = [];
		/** @var EO_Permission $ormPermission */
		foreach ($ormPermissions as $ormPermission)
		{
			$permissions[] = $this->permissionMapper->convertFromOrm($ormPermission);
		}

		return new PermissionCollection(...$permissions);
	}
}
