<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider\UsageStat\Params;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\PrepareQueryInterface;

/**
 * Wraps a raw legacy ORM-filter array (the same shape that
 * b_biconnector_log was queried with before the migration) and
 * applies it directly through {@see Query::setFilter()} so that all
 * historical filter formats (operator prefixes, LOGIC sub-arrays,
 * referenced-field paths) keep working.
 */
final class UsageStatFilter implements FilterInterface, PrepareQueryInterface
{
	/**
	 * @param array<string|int, mixed> $filter
	 */
	public function __construct(private readonly array $filter = [])
	{
	}

	public function prepareFilter(): ConditionTree
	{
		return new ConditionTree();
	}

	public function prepareQuery(Query $query): void
	{
		if ($this->filter === [])
		{
			return;
		}

		foreach ($this->filter as $key => $value)
		{
			if (is_int($key))
			{
				$query->addFilter(null, $value);
			}
			else
			{
				$query->addFilter($key, $value);
			}
		}
	}

	/**
	 * @return array<string|int, mixed>
	 */
	public function getRaw(): array
	{
		return $this->filter;
	}
}
