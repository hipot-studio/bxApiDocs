<?php

namespace Bitrix\DocumentGenerator\Repository;

use Bitrix\DocumentGenerator\Model\RoleCollection;
use Bitrix\DocumentGenerator\Model\RoleTable;
use Bitrix\Main\Type\Collection;

final class RoleRepository
{
	public function __construct(
		/** @var class-string<RoleTable> */
		private readonly string $roleTable = RoleTable::class,
	)
	{
	}

	public function fetchAll(): RoleCollection
	{
		$roleCollection = $this->roleTable::query()
			->setSelect(['*'])
			->fetchCollection();

		return $this->fillRelations($roleCollection);
	}

	/**
	 * @param int[] $ids
	 * @return RoleCollection
	 */
	public function fetchByIds(array $ids): RoleCollection
	{
		Collection::normalizeArrayValuesByInt($ids);

		if (empty($ids))
		{
			return new RoleCollection();
		}

		$roleCollection = $this->roleTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->fetchCollection();

		return $this->fillRelations($roleCollection);
	}

	private function fillRelations(RoleCollection $roleCollection): RoleCollection
	{
		$roleCollection->fillPermissions();
		$roleCollection->fillAccesses();

		return $roleCollection;
	}
}
