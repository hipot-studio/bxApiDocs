<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\List;

use Bitrix\Main\ArgumentException;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Order as RepositoryOrder;
use Bitrix\Tasks\V2\Public\Provider\Params\SortDirection;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;

class Sort
{
	private array $sort = [];

	/**
	 * @throws ArgumentException
	 */
	public function addSort(SortItem $item, bool $throwOnError = false): self
	{
		if (!Field::allowedForSort($item->field))
		{
			if ($throwOnError)
			{
				throw new ArgumentException(sprintf('Field "%s" is not allowed for sort', $item->field->value));
			}

			return $this;
		}

		if (!$this->alreadyInSort($item))
		{
			$this->sort[] = $item;
		}

		return $this;
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createFromArray(array $sort, bool $throwOnError = false): static
	{
		$instance = new static();
		foreach ($sort as [$field, $direction])
		{
			$enumField = $field instanceof Field ? $field : Field::tryFrom($field);
			$enumDirection = $direction instanceof SortDirection ? $direction : SortDirection::tryFrom($direction);
			if ($enumField === null || $enumDirection === null)
			{
				if ($throwOnError)
				{
					throw new ArgumentException('Invalid field or direction in sort');
				}

				continue;
			}

			$instance->addSort(new SortItem($enumField, $enumDirection), $throwOnError);
		}

		return $instance;
	}

	/**
	 * @return SortItem[]
	 */
	public function getSort(): array
	{
		return $this->sort;
	}

	private function alreadyInSort(SortItem $item): bool
	{
		foreach ($this->sort as $existingItem)
		{
			if ($existingItem->field === $item->field)
			{
				return true;
			}
		}

		return false;
	}

	public function mapToRepository(): RepositoryOrder
	{
		$map = Field::getDefaultMapToRepositoryField();
		$directionMap = SortDirection::getDefaultMapToRepositoryField();

		$list = array_map(
			fn (SortItem $item) => [
				$map[$item->field->value],
				$directionMap[$item->direction->value],
			],
			$this->getSort()
		);

		return new RepositoryOrder($list);
	}
}