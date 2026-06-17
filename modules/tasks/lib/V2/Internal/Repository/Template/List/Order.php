<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

class Order
{
	public const ALLOWED_DIRECTIONS = [
		'ASC',
		'DESC',
	];

	protected array $list = [];

	public function __construct(array $list = [])
	{
		foreach ($list as [$field, $direction])
		{
			if (!$field instanceof Field
				|| !in_array($direction, self::ALLOWED_DIRECTIONS, true)
			)
			{
				continue;
			}

			$this->list[$field->value] = [$field, $direction];
		}
	}

	public function getList(): array
	{
		return array_values($this->list);
	}
}