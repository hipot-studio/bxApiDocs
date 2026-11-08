<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\Requisite;

use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\ColumnExpression;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\LengthExpression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Schema\Scope;

final class ListRequestMapper
{
	private const FIELDS = [
		'requisiteId',
		'typeId',
		'address1',
		'address2',
		'city',
		'postalCode',
		'region',
		'province',
		'country',
		'countryCode',
	];

	/**
	 * @param array<int, Address> $addresses
	 * @return array<int, Address>
	 */
	public function map(array $addresses, int $requisiteId, ListRequest $request): array
	{
		$this->assertScopeAllowsRequestFields($request);
		$addresses = $this->applyFilter($addresses, $requisiteId, $request->filter);
		$this->applyOrder($addresses, $requisiteId, $request);

		if ($request->pagination !== null)
		{
			$addresses = array_slice(
				$addresses,
				$request->pagination->getOffset(),
				$request->pagination->getLimit(),
				true,
			);
		}

		return $addresses;
	}

	private function assertScopeAllowsRequestFields(ListRequest $request): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		if (!$scope instanceof Scope || $scope->fields === [])
		{
			return;
		}

		$this->assertFilterFieldsAreAvailable($request->filter, $scope);
		foreach ($request->order?->getItems() ?? [] as $orderItem)
		{
			$this->assertFieldIsAvailable($orderItem->getProperty(), $scope);
		}
	}

	private function assertFilterFieldsAreAvailable(?FilterStructure $filter, Scope $scope): void
	{
		if ($filter === null)
		{
			return;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$this->assertFilterFieldsAreAvailable($condition, $scope);

				continue;
			}

			$this->assertConditionFieldsAreAvailable($condition, $scope);
		}
	}

	private function assertConditionFieldsAreAvailable(Condition $condition, Scope $scope): void
	{
		$this->assertOperandFieldsAreAvailable($condition->getLeftOperand(), $scope, true);
		$this->assertOperandFieldsAreAvailable($condition->getRightOperand(), $scope);
	}

	private function assertOperandFieldsAreAvailable(mixed $operand, Scope $scope, bool $isLeftOperand = false): void
	{
		if ($isLeftOperand && is_string($operand))
		{
			$this->assertFieldIsAvailable($operand, $scope);

			return;
		}

		if ($operand instanceof ColumnExpression || $operand instanceof LengthExpression)
		{
			$this->assertFieldIsAvailable($operand->getProperty(), $scope);
		}
	}

	private function assertFieldIsAvailable(string $field, Scope $scope): void
	{
		if ($field !== 'requisiteId' && !in_array($field, $scope->fields, true))
		{
			throw new InvalidFilterException([$field]);
		}
	}

	/**
	 * @param array<int, Address> $addresses
	 * @return array<int, Address>
	 */
	private function applyFilter(array $addresses, int $requisiteId, ?FilterStructure $filter): array
	{
		if ($filter === null || !$this->hasEffectiveConditions($filter))
		{
			return $addresses;
		}

		return array_filter(
			$addresses,
			fn(Address $address, int $addressTypeId): bool => $this->matchesFilter(
				$this->getValues($address, $requisiteId, $addressTypeId),
				$filter,
			),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function matchesFilter(array $values, FilterStructure $filter): bool
	{
		$conditions = $filter->getConditions();
		$result = $filter->logic() === Logic::And;
		foreach ($conditions as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				if (!$this->hasEffectiveConditions($condition))
				{
					continue;
				}
				$matches = $this->matchesFilter($values, $condition);
			}
			else
			{
				$matches = $this->matchesCondition($values, $condition);
			}

			if ($filter->logic() === Logic::And && !$matches)
			{
				$result = false;

				break;
			}
			if ($filter->logic() === Logic::Or && $matches)
			{
				$result = true;

				break;
			}
		}

		return $filter->isNegative() ? !$result : $result;
	}

	private function hasEffectiveConditions(FilterStructure $filter): bool
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				return true;
			}
			if ($condition instanceof FilterStructure && $this->hasEffectiveConditions($condition))
			{
				return true;
			}
		}

		return false;
	}

	private function matchesCondition(array $values, Condition $condition): bool
	{
		$left = $this->resolveOperand($values, $condition->getLeftOperand(), true);
		$right = $this->resolveOperand($values, $condition->getRightOperand());

		return match ($condition->getOperator())
		{
			Operator::Equal => $left === $right,
			Operator::NotEqual => $left !== $right,
			Operator::Greater => $left > $right,
			Operator::GreaterOrEqual => $left >= $right,
			Operator::Less => $left < $right,
			Operator::LessOrEqual => $left <= $right,
			Operator::In => in_array($left, $this->getArrayOperand(Operator::In, $right), true),
			Operator::Between => $this->matchesBetween($left, $right),
		};
	}

	private function matchesBetween(mixed $left, mixed $right): bool
	{
		$range = $this->getArrayOperand(Operator::Between, $right, 2);

		return $left >= $range[0] && $left <= $range[1];
	}

	private function getArrayOperand(Operator $operator, mixed $operand, ?int $expectedCount = null): array
	{
		if (!is_array($operand) || ($expectedCount !== null && count($operand) !== $expectedCount))
		{
			throw new InvalidFilterException([$operator->value, $operand]);
		}

		return $operand;
	}

	private function resolveOperand(array $values, mixed $operand, bool $isLeftOperand = false): mixed
	{
		if ($operand instanceof ColumnExpression)
		{
			return $this->getFieldValue($values, $operand->getProperty());
		}
		if ($operand instanceof LengthExpression)
		{
			return mb_strlen((string)$this->getFieldValue($values, $operand->getProperty()));
		}
		if ($isLeftOperand && is_string($operand))
		{
			return $this->getFieldValue($values, $operand);
		}

		return $operand;
	}

	/** @param array<int, Address> $addresses */
	private function applyOrder(array &$addresses, int $requisiteId, ListRequest $request): void
	{
		$items = [];
		foreach ($addresses as $addressTypeId => $address)
		{
			$items[] = ['typeId' => $addressTypeId, 'address' => $address];
		}

		usort($items, function (array $left, array $right) use ($request, $requisiteId): int {
			foreach ($request->order?->getItems() ?? [] as $orderItem)
			{
				$leftValues = $this->getValues($left['address'], $requisiteId, $left['typeId']);
				$rightValues = $this->getValues($right['address'], $requisiteId, $right['typeId']);
				$result = $this->getFieldValue($leftValues, $orderItem->getProperty())
					<=> $this->getFieldValue($rightValues, $orderItem->getProperty());
				if ($result !== 0)
				{
					return $orderItem->getOrder() === Order::Desc ? -$result : $result;
				}
			}

			return 0;
		});

		$addresses = [];
		foreach ($items as $item)
		{
			$addresses[$item['typeId']] = $item['address'];
		}
	}

	private function getValues(Address $address, int $requisiteId, int $addressTypeId): array
	{
		return [
			'requisiteId' => $requisiteId,
			'typeId' => $addressTypeId,
		] + $address->toArray();
	}

	private function getFieldValue(array $values, string $field): int|string
	{
		if (!in_array($field, self::FIELDS, true) || !array_key_exists($field, $values))
		{
			throw new InvalidFilterException([$field]);
		}

		return $values[$field];
	}
}
