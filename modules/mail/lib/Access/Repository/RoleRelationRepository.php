<?php

namespace Bitrix\Mail\Access\Repository;

use Bitrix\Mail\Internals\Access\AccessRoleRelationTable;

class RoleRelationRepository
{
	/**
	 * @param array<string> $relationCode
	 * @return array<int>
	 */
	public function getRolesByRelationCodes(array $relationCode): array
	{
		$rolesIds = [];
		$roles =
			AccessRoleRelationTable::query()
				->addSelect('ROLE_ID')
				->whereIn('RELATION', $relationCode)
				->fetchAll()
		;

		foreach ($roles as $role)
		{
			$rolesIds[] = (int)$role['ROLE_ID'];
		}

		return $rolesIds;
	}

	/**
	 * @return array<array{RELATION: string}>
	 */
	public function getRelationsByRoleId(int $roleId): array
	{
		return AccessRoleRelationTable::query()
			->setSelect(['RELATION'])
			->where('ROLE_ID', $roleId)
			->fetchAll()
		;
	}

	public function add(int $roleId, string $accessCode): void
	{
		AccessRoleRelationTable::add([
			'ROLE_ID' => $roleId,
			'RELATION' => $accessCode,
		]);
	}

	public function deleteRelationsForRoles(array $roleIds): void
	{
		if (empty($roleIds))
		{
			return;
		}

		AccessRoleRelationTable::deleteList(['@ID' => $roleIds]);
	}
}
