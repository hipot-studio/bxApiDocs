<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,FormField>
 * @method FormField offsetGet($key)
 */
class FormFieldCollection extends Registry implements \JsonSerializable
{
	public static function create(array $fieldsData): self
	{
		$collection = new self();

		foreach ($fieldsData as $fieldData)
		{
			$field = FormField::create($fieldData);
			if ($field !== null)
			{
				$collection->append($field);
			}
		}

		return $collection;
	}

	public function jsonSerialize(): array
	{
		$result = [];
		foreach ($this as $field)
		{
			$result[] = $field->jsonSerialize();
		}

		return $result;
	}

	public function toArray(): array
	{
		$result = [];
		foreach ($this as $field)
		{
			$result[] = $field->toArray();
		}

		return $result;
	}
}
