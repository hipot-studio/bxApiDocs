<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\List;

use Bitrix\Main\ArgumentException;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Select as RepositorySelect;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;

class Select
{
	private array $select = [];

	/**
	 * @throws ArgumentException
	 */
	public function addSelect(Field $field, bool $throwOnError = false): self
	{
		if (!Field::allowedForSelect($field))
		{
			if ($throwOnError)
			{
				throw new ArgumentException(sprintf('Field "%s" is not allowed for select', $field->value));
			}

			return $this;
		}

		$this->select[$field->value] = $field;

		return $this;
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createFromArray(array $select, bool $throwOnError = false): static
	{
		$instance = new static();
		foreach ($select as $field)
		{
			$enumField = $field instanceof Field ? $field : Field::tryFrom($field);
			if ($enumField === null)
			{
				if ($throwOnError)
				{
					throw new ArgumentException(sprintf('Invalid field in select: %s' , $field));
				}

				continue;
			}

			$instance->addSelect($enumField, $throwOnError);
		}

		return $instance;
	}

	public function getSelect(): array
	{
		return $this->select;
	}

	public function mapToRepository(): RepositorySelect
	{
		$map = Field::getDefaultMapToRepositoryField();

		$list = array_map(
			fn (Field $field) => $map[$field->value],
			$this->getSelect()
		);

		return new RepositorySelect($list);
	}
}