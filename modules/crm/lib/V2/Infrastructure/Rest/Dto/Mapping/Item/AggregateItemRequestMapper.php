<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AggregateItemRequest;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Structure\Structure;

final class AggregateItemRequestMapper
{
	public function __construct(private readonly ItemFilterMapper $filterMapper)
	{
	}

	public function mapSelect(AggregateItemRequest $request): void
	{
		if ($request->order !== null)
		{
			throw new InvalidOrderException($request->order);
		}

		if ($request->pagination !== null)
		{
			throw new InvalidPaginationException($request->pagination);
		}

		if ($request->select !== ['count' => ['id']])
		{
			throw new InvalidSelectException($request->select);
		}
	}

	public function mapFilter(AggregateItemRequest $request): ?ItemFilter
	{
		if ($request->filter === null)
		{
			return null;
		}

		/** @var Dto $dto */
		$dto = Structure::getDto($request->getDtoClass()) ?? $request->getDtoClass()::create();

		return new ItemFilter($this->filterMapper->map($request->filter, $dto));
	}
}
