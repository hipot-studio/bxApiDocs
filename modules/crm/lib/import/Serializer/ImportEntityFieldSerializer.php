<?php

namespace Bitrix\Crm\Import\Serializer;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Main\Type\Contract\Arrayable;

final class ImportEntityFieldSerializer
{
	public function serialize(ImportEntityFieldInterface $field): array
	{
		if ($field instanceof Arrayable)
		{
			return $field->toArray();
		}

		return [
			'id' => $field->getId(),
			'name' => $field->getCaption(),
			'readonly' => $field->isReadonly(),
		];
	}

	/**
	 * @param ImportEntityFieldInterface[] $fields
	 * @return array<array>
	 */
	public function serializeList(array $fields): array
	{
		return array_map($this->serialize(...), $fields);
	}
}
