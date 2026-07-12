<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Project;

use Bitrix\Main\Provider\Params\SelectInterface;

class ProjectSelect implements SelectInterface
{
	public function __construct(
		private readonly array $select = [],
		public readonly bool $members = false,
		public readonly bool $owner = false,
	)
	{
	}

	public function prepareSelect(): array
	{
		if ($this->select === [])
		{
			$prepared = $this->getDefaultSelect();
		}
		else
		{
			$allowedFields = array_flip(array_map(
				static fn(FieldsEnum $field) => $field->value,
				FieldsEnum::allowedForSelectList(),
			));

			$prepared = [];
			foreach ($this->select as $field)
			{
				$preparedField = FieldsEnum::tryFrom($field);
				if ($preparedField === null || !isset($allowedFields[$preparedField->value]))
				{
					continue;
				}

				$prepared[] = $preparedField->toOrmField();
			}

			if ($prepared === [])
			{
				$prepared = $this->getDefaultSelect();
			}
		}

		$prepared[] = FieldsEnum::ImageId->toOrmField();

		if ($this->owner)
		{
			$prepared[] = FieldsEnum::OwnerId->toOrmField();
		}

		return array_values(array_unique($prepared));
	}

	private function getDefaultSelect(): array
	{
		return array_map(
			static fn(FieldsEnum $field) => $field->toOrmField(),
			FieldsEnum::allowedForSelectList(),
		);
	}
}
