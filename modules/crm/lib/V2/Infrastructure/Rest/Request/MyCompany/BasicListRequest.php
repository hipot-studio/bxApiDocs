<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\MyCompany;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class BasicListRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?ItemListFilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if ($request->filter !== null)
		{
			$scopeFields = $options['scope']?->fields ?? [];
			self::validateFilter($request->filter, $scopeFields);
		}

		return $request;
	}

	/** @param string[] $scopeFields */
	private static function validateFilter(ItemListFilterStructure $filter, array $scopeFields): void
	{
		if ($filter->isNegative())
		{
			throw new InvalidFilterException(['negative' => true]);
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof ItemListFilterStructure)
			{
				self::validateFilter($condition, $scopeFields);

				continue;
			}

			if (
				$condition instanceof Condition
				&& (
					!in_array($condition->getOperator(), [Operator::Equal, Operator::In], true)
					|| (
						$scopeFields !== []
						&& !in_array(
							strtok($condition->getLeftOperand(), '.'),
							$scopeFields,
							true,
						)
					)
				)
			)
			{
				throw new InvalidFilterException([
					$condition->getLeftOperand(),
					$condition->getOperator()->value,
					$condition->getRightOperand(),
				]);
			}
		}
	}
}
