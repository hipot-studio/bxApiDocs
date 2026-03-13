<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Realisation\Dto\DtoFieldDto;

final class FieldMapper extends Mapper
{
	private static array $fieldMapping = [
		'name' => 'propertyName',
		'type' => 'propertyType',
		'title' => 'title',
		'description' => 'description',
		'validationRules' => 'validationRules',
		'requiredGroups' => 'requiredGroups',
		'filterable' => 'filterable',
		'sortable' => 'sortable',
		'editable' => 'editable',
		'multiple' => 'multiple',
		'elementType' => 'elementType',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DtoFieldDto::class);

		foreach ($items as $item)
		{
			$dto = $this->mapDtoField($item, $fields);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapDtoField(array $dtoFieldData, array $fields): DtoFieldDto
	{
		$emptyFields = empty($fields);

		$dto = new DtoFieldDto();

		foreach (self::$fieldMapping as $dtoField => $dataField)
		{
			if ($emptyFields || in_array($dtoField, $fields, true))
			{
				switch ($dtoField)
				{
					case 'name':
						$dto->name = $dtoFieldData['propertyName'];
						break;
					case 'type':
						if (in_array($dtoFieldData['propertyType'], ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array'], true))
						{
							$dto->type = $dtoFieldData['propertyType'];
						}
						else
						{
							$dto->type = 'object';
						}
						break;
					case 'elementType':
						$dto->elementType = $dtoFieldData[$dtoField] ? 'object' : null;
						break;
					case 'multiple':
					case 'filterable':
					case 'sortable':
					case 'editable':
						$dto->{$dtoField} = (bool)$dtoFieldData[$dataField];
						break;
					default:
						$dto->{$dtoField} = $dtoFieldData[$dtoField];
						break;
				}
			}
		}

		return $dto;
	}
}
