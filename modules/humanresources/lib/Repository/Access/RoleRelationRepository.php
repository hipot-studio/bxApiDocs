<?php

namespace Bitrix\HumanResources\Repository\Access;

use Bitrix\Main\DB\Result;
use Bitrix\Main;
use Bitrix\HumanResources\Model\Access\AccessRoleRelationTable;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\ORM\Fields\ExpressionField;

class RoleRelationRepository
{
	public function getRolesByRelationCodes(array $relationCode): array
	{
		$rolesIds = [];
		$roles =
			AccessRoleRelationTable::query()
				->addSelect('ROLE_ID')
				->whereIn('RELATION', $relationCode)
				->setCacheTtl(86400)
				->fetchAll()
		;

		foreach ($roles as $role)
		{
			$rolesIds[] = (int)$role['ROLE_ID'];
		}

		return $rolesIds;
	}

	/**
	 * Returns relation codes (access codes) of the given roles.
	 *
	 * @param int[] $roleIds
	 * @return string[]
	 */
	public function getRelationCodesByRoleIds(array $roleIds): array
	{
		if (empty($roleIds))
		{
			return [];
		}

		$codes = [];
		foreach (
			AccessRoleRelationTable::query()
				->addSelect('RELATION')
				->whereIn('ROLE_ID', $roleIds)
				->setCacheTtl(86400)
				->fetchAll() as $row
		)
		{
			$codes[(string)$row['RELATION']] = (string)$row['RELATION'];
		}

		return array_values($codes);
	}

	/**
	 * @inheritDoc
	 */
	public function deleteRelationsByRoleIds(array $roleIds): void
	{
		AccessRoleRelationTable::deleteList(["@ROLE_ID" => $roleIds]);
	}

	public function deleteRelationsByRoleId(int $roleId): Result
	{
		return AccessRoleRelationTable::deleteList(["=ROLE_ID" => $roleId]);
	}

	public function getRelationList(array $parameters = []): array
	{
		return AccessRoleRelationTable::getList($parameters)->fetchAll();
	}

	public function getRoleRelationsCountByRoleId($roleId): int
	{
		$result = AccessRoleRelationTable::query()
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->where('ROLE_ID', $roleId)
			->exec()
			->fetchRaw()
		;

		return (int)$result['CNT'];
	}
}