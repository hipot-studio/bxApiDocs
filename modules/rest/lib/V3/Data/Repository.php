<?php

namespace Bitrix\Rest\V3\Data;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * Repository for base actions get, list
 */
abstract class Repository
{
	abstract public function getAll(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $page = null,
	): DtoCollection;

	final public function getOneWith(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $sort = null,
	): ?Dto {
		$page = PaginationStructure::create(['limit' => 1]);
		$collection = $this->getAll($select, $filter, $sort, $page);

		return $collection->first();
	}
}
