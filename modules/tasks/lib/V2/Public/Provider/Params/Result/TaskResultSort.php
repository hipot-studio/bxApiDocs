<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result;

use Bitrix\Main\Provider\Params\SortInterface;
use Bitrix\Tasks\V2\Public\Provider\Map\Result\TaskResultFieldToColumnMap;

class TaskResultSort implements SortInterface
{
	private const ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

	public function __construct(
		private readonly array $conditions = [],
	)
	{
	}

	public function prepareSort(): array
	{
		$result = [];

		foreach ($this->conditions as $field => $direction)
		{
			$direction = strtoupper($direction);
			if (!in_array($direction, self::ALLOWED_ORDER_DIRECTIONS, true))
			{
				continue;
			}

			$column = TaskResultFieldToColumnMap::RELATIONS[$field] ?? null;
			if ($column === null)
			{
				continue;
			}

			if (is_array($column))
			{
				$column = reset($column);
			}

			$result[$column] = $direction;
		}

		return $result;
	}
}
