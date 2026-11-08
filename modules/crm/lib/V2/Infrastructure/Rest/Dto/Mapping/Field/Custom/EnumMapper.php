<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\Custom;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\Custom\EnumDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class EnumMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(EnumDto::class);
		foreach ($items as $item)
		{
			$collection->add($this->mapEnum($item, $fields));
		}

		return $collection;
	}

	private function mapEnum(array $item, array $fields = []): EnumDto
	{
		$dto = new EnumDto();
		$allFieldsSelected = $fields === [];
		$values = [
			'id' => (int)($item['ID'] ?? 0),
			'fieldId' => (int)($item['USER_FIELD_ID'] ?? 0),
			'value' => (string)($item['VALUE'] ?? ''),
			'isDefault' => ($item['DEF'] ?? 'N') === 'Y',
			'sort' => (int)($item['SORT'] ?? 0),
			'xmlId' => isset($item['XML_ID']) ? (string)$item['XML_ID'] : null,
		];

		foreach ($values as $field => $value)
		{
			if ($allFieldsSelected || in_array($field, $fields, true))
			{
				$dto->{$field} = $value;
			}
		}

		return $dto;
	}

	public function getValuesForWrite(EnumDto $dto): array
	{
		$values = [];
		if (isset($dto->value))
		{
			$values['VALUE'] = $dto->value;
		}
		if (isset($dto->sort))
		{
			$values['SORT'] = $dto->sort;
		}
		if (isset($dto->isDefault))
		{
			$values['DEF'] = $dto->isDefault ? 'Y' : 'N';
		}
		if (isset($dto->xmlId))
		{
			$values['XML_ID'] = $dto->xmlId;
		}

		return $values;
	}
}
