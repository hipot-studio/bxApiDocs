<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest as BaseListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

final class ListRequest extends BaseListRequest
{
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		$requisiteId = $request->getRequisiteId();
		if ($requisiteId === null)
		{
			throw new RequiredFieldsInRequestFilterPropertyException('filter', ['requisiteId']);
		}
		if (!is_int($requisiteId) || $requisiteId <= 0)
		{
			throw new InvalidRequestFieldTypeException('filter.requisiteId', 'positive integer');
		}

		$order = $request->order?->getList() ?? [];
		if (!array_key_exists('typeId', $order))
		{
			$order['typeId'] = 'ASC';
			$request->order = OrderStructure::create($order, $dtoClass, $request);
		}

		$pagination = $httpRequest->getJsonList()->getValues()['pagination'] ?? [];
		self::validatePagination($pagination);
		$request->pagination = PaginationStructure::create($pagination, $dtoClass, $request);

		return $request;
	}

	public function getRequisiteId(): mixed
	{
		$ids = $this->extractRequisiteIds($this->filter);
		if (count($ids) !== 1)
		{
			return null;
		}

		return reset($ids);
	}

	private function extractRequisiteIds(?FilterStructure $filter): array
	{
		if ($filter === null || $filter->isNegative() || $filter->logic() !== Logic::And)
		{
			return [];
		}

		$ids = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$ids = array_merge($ids, $this->extractRequisiteIds($condition));
			}
			elseif (
				$condition instanceof Condition
				&& $condition->getLeftOperand() === 'requisiteId'
				&& $condition->getOperator() === Operator::Equal
			)
			{
				$ids[] = $condition->getRightOperand();
			}
		}

		return $ids;
	}

	private static function validatePagination(mixed $pagination): void
	{
		if (!is_array($pagination) || ($pagination !== [] && array_is_list($pagination)))
		{
			throw new InvalidPaginationException($pagination);
		}

		$limit = $pagination['limit'] ?? PaginationStructure::DEFAULT_LIMIT;
		if (!is_int($limit) || $limit <= 0 || $limit > PaginationStructure::MAX_LIMIT)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}

		$offset = $pagination['offset'] ?? 0;
		if (!is_int($offset) || $offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}
	}

}
