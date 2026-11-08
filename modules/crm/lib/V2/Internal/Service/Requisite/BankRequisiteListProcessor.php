<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteCollection;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteError;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Crm\V2\Public\Provider\Requisite\BankRequisiteProvider;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilter;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilterCondition;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilterOperator;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteSort;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/** @internal */
final class BankRequisiteListProcessor
{
	private const DEFAULT_PAGE_SIZE = 50;
	private const MAX_CANDIDATES = 10_000;

	private readonly int $offset;
	private readonly int $limit;
	private readonly bool $isNaturalOrder;

	public function __construct(
		private readonly ?BankRequisiteFilter $filter,
		private readonly BankRequisiteSort $sort,
		?PagerInterface $pager,
	)
	{
		$this->offset = $pager === null ? 0 : max(0, $pager->getOffset());
		$this->limit = $pager?->getLimit() ?? self::DEFAULT_PAGE_SIZE;
		$this->isNaturalOrder = $this->sort->getOrder() === ['id' => 'ASC'];
	}

	public function usesNaturalOrder(): bool
	{
		return $this->isNaturalOrder;
	}

	public function getWindowSize(): int
	{
		return $this->offset + $this->limit;
	}

	/** @param iterable<BankRequisite> $candidates */
	public function process(iterable $candidates, int $readAheadSize): Result
	{
		if ($this->limit <= 0)
		{
			return $this->result([]);
		}
		if ($this->getWindowSize() > self::MAX_CANDIDATES)
		{
			return self::listLimitExceeded();
		}

		$entities = [];
		$matchedCount = 0;
		$scannedCount = 0;
		foreach ($candidates as $entity)
		{
			if ($this->filter !== null || !$this->isNaturalOrder)
			{
				$scannedCount++;
				if ($scannedCount > self::MAX_CANDIDATES)
				{
					return self::listLimitExceeded();
				}
			}

			if ($this->filter !== null && !$this->matches($entity, $this->filter))
			{
				continue;
			}

			if ($this->isNaturalOrder)
			{
				$matchedCount++;
				if ($matchedCount <= $this->offset)
				{
					continue;
				}
				$entities[] = $entity;
				if (count($entities) >= $this->limit)
				{
					break;
				}

				continue;
			}

			$entities[] = $entity;
			if (count($entities) >= $this->getWindowSize() + $readAheadSize)
			{
				$this->sort($entities);
				$entities = array_slice($entities, 0, $this->getWindowSize());
			}
		}

		if (!$this->isNaturalOrder)
		{
			$this->sort($entities);
			$entities = array_slice($entities, $this->offset, $this->limit);
		}

		return $this->result($entities);
	}

	/** @param BankRequisite[] $entities */
	private function result(array $entities): Result
	{
		$collection = new BankRequisiteCollection();
		foreach ($entities as $entity)
		{
			$collection->add($entity);
		}

		return (new Result())->setData([BankRequisiteProvider::DATA_BANK_REQUISITES => $collection]);
	}

	private function matches(BankRequisite $entity, BankRequisiteFilter $filter): bool
	{
		$results = [];
		foreach ($filter->getConditions() as $condition)
		{
			$results[] = $condition instanceof BankRequisiteFilter
				? $this->matches($entity, $condition)
				: $this->matchesCondition($entity, $condition);
		}

		$matched = $filter->isOr()
			? in_array(true, $results, true)
			: !in_array(false, $results, true);

		return $filter->isNegative() ? !$matched : $matched;
	}

	private function matchesCondition(BankRequisite $entity, BankRequisiteFilterCondition $condition): bool
	{
		[$exists, $actual] = $this->fieldValue($entity, $condition->field);
		if (!$exists)
		{
			return $condition->operator === BankRequisiteFilterOperator::NotEqual;
		}

		return match ($condition->operator)
		{
			BankRequisiteFilterOperator::Equal => $actual === $condition->value,
			BankRequisiteFilterOperator::NotEqual => $actual !== $condition->value,
			BankRequisiteFilterOperator::In => in_array($actual, (array)$condition->value, true),
			BankRequisiteFilterOperator::Between => $this->compare($actual, $condition->value[0] ?? null) >= 0
				&& $this->compare($actual, $condition->value[1] ?? null) <= 0,
			BankRequisiteFilterOperator::Greater => $this->compare($actual, $condition->value) > 0,
			BankRequisiteFilterOperator::GreaterOrEqual => $this->compare($actual, $condition->value) >= 0,
			BankRequisiteFilterOperator::Less => $this->compare($actual, $condition->value) < 0,
			BankRequisiteFilterOperator::LessOrEqual => $this->compare($actual, $condition->value) <= 0,
		};
	}

	/** @param BankRequisite[] $entities */
	private function sort(array &$entities): void
	{
		usort($entities, function (BankRequisite $left, BankRequisite $right): int {
			foreach ($this->sort->getOrder() as $field => $direction)
			{
				[$leftExists, $leftValue] = $this->fieldValue($left, $field);
				[$rightExists, $rightValue] = $this->fieldValue($right, $field);
				if ($leftExists !== $rightExists)
				{
					return $leftExists ? -1 : 1;
				}

				$compared = $this->compare($leftValue, $rightValue);
				if ($compared !== 0)
				{
					return $direction === 'DESC' ? -$compared : $compared;
				}
			}

			return 0;
		});
	}

	/** @return array{bool, int|string|null} */
	private function fieldValue(BankRequisite $entity, string $field): array
	{
		if ($field === 'id')
		{
			return [true, $entity->getId()];
		}
		if ($field === 'name')
		{
			return [true, $entity->getName()];
		}
		if ($field === 'comments')
		{
			return [true, $entity->getComments()];
		}

		$bankField = BankRequisiteField::tryFrom($field);
		if ($bankField === null || $entity->getTemplate() === null || !$entity->getTemplate()->has($bankField))
		{
			return [false, null];
		}

		return [true, $entity->getField($bankField)];
	}

	private function compare(int|string|null $left, int|string|null $right): int
	{
		if ($left === $right)
		{
			return 0;
		}
		if ($left === null)
		{
			return -1;
		}
		if ($right === null)
		{
			return 1;
		}

		return is_string($left) && is_string($right) ? strcmp($left, $right) : $left <=> $right;
	}

	private static function listLimitExceeded(): Result
	{
		return (new Result())->addError(
			new Error(
				'Bank requisite list processing limit exceeded.',
				BankRequisiteError::VALIDATION,
				['field' => 'pagination'],
			),
		);
	}
}
