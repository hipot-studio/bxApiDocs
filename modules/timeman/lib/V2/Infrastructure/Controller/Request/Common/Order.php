<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common;

use Bitrix\Main\Provider\Params\SortInterface;

final class Order
{
	/**
	 * @param array<string, string> $items
	 */
	public function __construct(
		public readonly array $items = [],
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		$items = [];

		foreach ($parameters as $field => $direction)
		{
			if (!is_string($field))
			{
				continue;
			}

			$field = trim($field);
			if ($field === '')
			{
				continue;
			}

			if (!is_scalar($direction))
			{
				continue;
			}

			$items[$field] = strtolower(trim((string)$direction));
		}

		return new self($items);
	}

	/**
	 * @param class-string<SortInterface> $sortClass
	 */
	public function prepare(string $sortClass): SortInterface
	{
		return new $sortClass($this->items);
	}
}
