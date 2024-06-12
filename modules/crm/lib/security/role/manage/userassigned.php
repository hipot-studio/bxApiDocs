<?php

namespace Bitrix\Crm\Security\Role\Manage;

use Bitrix\Crm\Security\Role\Model\RolePermissionTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class UserAssigned
{
	public function get(int $roleId): array
	{
		$ct = new ConditionTree();
		$ct->logic(ConditionTree::LOGIC_OR);
		$ct->where('ATTR', '<>', '');
		$ct->where('FIELD_VALUE', '<>', '');


		$query = RolePermissionTable::query()
			->setSelect(['ENTITY', 'PERM_TYPE', 'FIELD', 'FIELD_VALUE', 'ATTR'])
			->where('ROLE_ID', $roleId)
			->where($ct)
		;

		return $query->fetchAll();
	}
}