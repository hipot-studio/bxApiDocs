<?php

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Event\EventDescriptor;
use Bitrix\Rest\V3\Event\EventRegistry;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\SubscribableEventMapper;
use Bitrix\Rest\V3\Realisation\Dto\SubscribableEventDto;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(SubscribableEventDto::class)]
final class Event extends RestController
{
	public function listAction(ListRequest $request): ListResponse
	{
		/** @var EventRegistry $registry */
		$registry = ServiceLocator::getInstance()->get(EventRegistry::class);
		$events = $registry->all();

		$events = $this->applyFilters($events, $request->filter);

		$pagination = $request->pagination;
		$offset = $pagination?->getOffset() ?? 0;
		$limit = $pagination?->getLimit()  ?? PaginationStructure::DEFAULT_LIMIT;
		$events = array_slice($events, $offset, $limit, preserve_keys: true);

		$mapper = new SubscribableEventMapper($this->getResponseLanguage());
		$collection = $mapper->mapCollection(
			$events,
			$request->select?->getList() ?? []
		);

		return new ListResponse($collection);
	}

	private function applyFilters(array $events, ?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return $events;
		}

		$this->assertSimpleEqualityFilter($filter);

		$conditions = $filter->getSimpleFilterConditions();
		if (empty($conditions))
		{
			return $events;
		}

		return array_filter(
			$events,
			static function (EventDescriptor $event) use ($conditions): bool {
				if (isset($conditions['module']) && $event->module !== $conditions['module'])
				{
					return false;
				}
				if (isset($conditions['name']) && $event->name !== $conditions['name'])
				{
					return false;
				}
				if (isset($conditions['type']) && $event->payload->getType()->value !== $conditions['type'])
				{
					return false;
				}

				return true;
			}
		);
	}

	private function assertSimpleEqualityFilter(FilterStructure $filter): void
	{
		if (!$this->containsOnlySimpleEquality($filter))
		{
			throw new InvalidFilterException($filter->getList());
		}
	}

	private function containsOnlySimpleEquality(FilterStructure $filter): bool
	{
		if ($filter->isNegative() || $filter->logic() !== Logic::And)
		{
			return false;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$inner = $condition->getConditions();
				if (
					count($inner) !== 1
					|| !($inner[0] instanceof Condition)
					|| $inner[0]->getOperator() !== Operator::Equal
					|| $condition->isNegative()
					|| $condition->logic() !== Logic::And
				)
				{
					return false;
				}

				continue;
			}

			if (!($condition instanceof Condition) || $condition->getOperator() !== Operator::Equal)
			{
				return false;
			}
		}

		return true;
	}
}
