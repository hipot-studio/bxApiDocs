<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSort;

class Order
{
	protected function __construct(
		#[Validatable(iterable: true)]
		/** @var OrderItem[] */
		public readonly ?array $list = [],
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		$preparedParameters = array_map(
			fn (string $field, string $direction) => new OrderItem($field, $direction),
			array_keys($parameters),
			$parameters,
		);

		return new self($preparedParameters);
	}

	public function prepare(): TaskListSort
	{
		$sortList = [];

		foreach ($this->list as $orderItem)
		{
			$mappedField = $this->mapField($orderItem->field);
			if ($mappedField === null)
			{
				continue;
			}

			$mappedDirection = $this->mapDirection($orderItem->order);
			if ($mappedDirection === null)
			{
				continue;
			}

			$sortList[$mappedField] = $mappedDirection;
		}

		if (!isset($sortList['id']))
		{
			$sortList['id'] = 'asc';
		}

		return new TaskListSort($sortList);
	}

	protected function mapField(string $field): ?string
	{
		return FieldsEnum::tryFrom($field)?->value;
	}

	protected function mapDirection(string $direction): ?string
	{
		$directions = array_combine(
			OrderItem::ALLOWED_DIRECTIONS,
			OrderItem::ALLOWED_DIRECTIONS,
		);

		return $directions[$direction] ?? null;
	}
}
