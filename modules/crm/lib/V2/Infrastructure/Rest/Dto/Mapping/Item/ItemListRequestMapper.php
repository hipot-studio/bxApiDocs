<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ListItemRequest;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Structure\Structure;

final class ItemListRequestMapper
{
	public function __construct(
		private readonly ItemDtoMapper $itemDtoMapper,
		private readonly ItemFilterMapper $filterMapper,
	)
	{
	}

	public function mapSelect(ListItemRequest $request): ItemSelect
	{
		return $this->itemDtoMapper->getItemSelectFromDtoFieldNames($request);
	}

	public function mapFilter(ListItemRequest $request): ?ItemFilter
	{
		if ($request->filter === null)
		{
			return null;
		}

		/** @var Dto $dto */
		$dto = Structure::getDto($request->getDtoClass()) ?? $request->getDtoClass()::create();

		return new ItemFilter($this->filterMapper->map($request->filter, $dto));
	}

	public function mapSort(ListItemRequest $request): ItemSort
	{
		if ($request->order === null || $request->order->getList() === [])
		{
			return new ItemSort(['id' => 'ASC']);
		}

		$order = [];
		foreach ($request->order->getList() as $fieldName => $direction)
		{
			$order[$this->itemDtoMapper->mapDtoFieldNameToItemForSort($fieldName) ?? $fieldName] = $direction;
		}
		if (!array_key_exists('id', $order))
		{
			$order['id'] = 'ASC';
		}

		return new ItemSort($order);
	}

	public function mapPager(ListItemRequest $request): Pager
	{
		if ($request->pagination === null)
		{
			return new Pager();
		}

		$limit = $request->pagination->getLimit();
		$offset = $request->pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}
}
