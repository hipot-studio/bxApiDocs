<?php

namespace Bitrix\Crm\Security\Role\Queries;

use Bitrix\Crm\Security\Role\Model\RoleTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Web\Json;

class QueryRoles
{
	private ?array $entityToPermTypes = null;
	private string|null|false $strictGroupCode = false; // false - dont filter by group code

	public function filterByGroup(?string $groupCode): self
	{
		$this->strictGroupCode = $groupCode;

		return $this;
	}

	/**
	 * Return only roles that have some perms for this entity. Roles without perms of type '$permTypes' for entity
	 * '$entity' will be excluded.
	 */
	public function addNotEmptyEntityPermsFilter(string $entity, array $permTypes): self
	{
		$this->entityToPermTypes[$entity] = $permTypes;

		return $this;
	}

	public function fetchAll(): array
	{
		$query = RoleTable::query();

		$query->setDistinct();

		$this->buildSelect($query);

		$this->buildFilter($query);

		return $query->fetchAll();
	}

	private function buildSelect(Query $query): void
	{
		$query->setSelect(['ID', 'NAME', 'IS_SYSTEM', 'CODE', 'GROUP_CODE']);
	}

	private function buildFilter(Query $query): void
	{
		$query->where('IS_SYSTEM', 'N');

		if ($this->strictGroupCode !== false)
		{
			if ($this->strictGroupCode === null)
			{
				$query->whereNull('GROUP_CODE');
			}
			else
			{
				$query->where('GROUP_CODE', $this->strictGroupCode);
			}
		}

		if (!empty($this->entityToPermTypes))
		{
			$notEmptyPermsSubfilter = $query::filter()
				->logic($query::filter()::LOGIC_OR)
			;

			$emptySettingsValues = [Json::encode(null), Json::encode(''), Json::encode([])];
			foreach ($this->entityToPermTypes as $entity => $permTypes)
			{
				$notEmptyValueSubfilter = $query::filter()
					->logic($query::filter()::LOGIC_OR)
					->whereNot('PERMISSIONS.ATTR', '')
					->whereNotIn('PERMISSIONS.SETTINGS', $emptySettingsValues)
				;

				$singleEntitySubfilter = $query::filter()
					->where('PERMISSIONS.ENTITY', $entity)
					->whereIn('PERMISSIONS.PERM_TYPE', $permTypes)
					->where($notEmptyValueSubfilter)
				;

				$notEmptyPermsSubfilter->where($singleEntitySubfilter);
			}

			$query->where($notEmptyPermsSubfilter);
		}
	}
}
