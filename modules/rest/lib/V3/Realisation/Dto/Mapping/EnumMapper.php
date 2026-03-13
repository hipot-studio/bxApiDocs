<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Realisation\Dto\Field\Custom\EnumDto;

final class EnumMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(EnumDto::class);
		foreach ($items as $item)
		{
			$dto = $this->mapEnumDto($item, $fields);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapEnumDto(array $itemData, array $fields): EnumDto
	{
		$dto = new EnumDto();
		if (isset($itemData['ID']))
		{
			$dto->id = (int)$itemData['ID']; // id always present
		}

		foreach ($itemData as $field => $fieldData)
		{
			switch ($field)
			{
				case 'USER_FIELD_ID':
					if (empty($fields) || in_array('fieldId', $fields, true))
					{
						$dto->fieldId = (int) $fieldData;
					}
					break;
				case 'XML_ID':
					if (empty($fields) || in_array('xmlId', $fields, true))
					{
						$dto->xmlId = (string) $fieldData;
					}
					break;
				case 'SORT':
					if (empty($fields) || in_array('sort', $fields, true))
					{
						$dto->sort = (int) $fieldData;
					}
					break;
				case 'VALUE':
					if (empty($fields) || in_array('value', $fields, true))
					{
						$dto->value = (string) $fieldData;
					}
					break;
				case 'DEF':
					if (empty($fields) || in_array('isDefault', $fields, true))
					{
						$dto->isDefault = $fieldData === 'Y';
					}
					break;
			}
		}
		return $dto;
	}

	public function getValuesForAdd(EnumDto $dto): array
	{
		$values = [];

		if (isset($dto->xmlId))
		{
			$values['XML_ID'] = $dto->xmlId;
		}
		if (isset($dto->sort))
		{
			$values['SORT'] = $dto->sort;
		}
		if (isset($dto->value))
		{
			$values['VALUE'] = $dto->value;
		}
		if (isset($dto->isDefault))
		{
			$values['DEF'] = $dto->isDefault ? 'Y' : 'N';
		}

		return $values;
	}
}
