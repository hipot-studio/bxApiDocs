<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Repository;

use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Rest\V3\Data\OrmRepository;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * OrmRepository with support for AND-composed extra array filters (ACL / DOC_TYPE).
 */
final class CatalogOrmRepository extends OrmRepository
{
	/**
	 * @param list<array|null> $extraArrayFilters classic ORM filter arrays ANDed into the query
	 */
	public function getCollectionWithExtraFilters(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $page = null,
		array $extraArrayFilters = [],
	): Collection {
		$query = $this->getQuery($select, $filter, $order, $page);
		$this->applyExtraArrayFilters($query, $extraArrayFilters);

		return $query->fetchCollection();
	}

	public function getEntityById(int $id, ?SelectStructure $select = null): ?EntityObject
	{
		$filter = (new FilterStructure())->where('id', $id);

		$query = $this->getQuery($select, $filter, null, PaginationStructure::create(['limit' => 1]));
		foreach ($query->fetchCollection() as $object)
		{
			return $object;
		}

		return null;
	}

	public function mapCollection(Collection $collection): DtoCollection
	{
		return $this->mapCollectionToDto($collection);
	}

	public function mapEntity(EntityObject $object): Dto
	{
		return $this->mapObjectToDto($object, $this->dtoClass);
	}

	/**
	 * @param list<array|null> $extraArrayFilters
	 */
	private function applyExtraArrayFilters(Query $query, array $extraArrayFilters): void
	{
		foreach ($extraArrayFilters as $arrayFilter)
		{
			if (!is_array($arrayFilter) || $arrayFilter === [])
			{
				continue;
			}

			// Nested group keeps client OR from bypassing ACL (Engine modifyListActionParameters).
			$query->addFilter(null, $arrayFilter);
		}
	}
}
