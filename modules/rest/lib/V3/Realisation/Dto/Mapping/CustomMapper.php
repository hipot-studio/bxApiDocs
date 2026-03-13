<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping;

use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Realisation\Dto\Field\CustomDto;

final class CustomMapper extends Mapper
{
	private static array $fieldMapping = [
		'id' => 'ID',
		'entityId' => 'ENTITY_ID',
		'name' => 'FIELD_NAME',
		'userTypeId' => 'USER_TYPE_ID',
		'xmlId' => 'XML_ID',
		'sort' => 'SORT',
		'isMultiple' => 'MULTIPLE',
		'isMandatory' => 'MANDATORY',
		'showFilter' => 'SHOW_FILTER',
		'showInList' => 'SHOW_IN_LIST',
		'editInList' => 'EDIT_IN_LIST',
		'isSearchable' => 'IS_SEARCHABLE',
		'settings' => 'SETTINGS',
		'editFormLabel' => 'EDIT_FORM_LABEL',
		'listColumnLabel' => 'LIST_COLUMN_LABEL',
		'listFilterLabel' => 'LIST_FILTER_LABEL',
		'errorMessage' => 'ERROR_MESSAGE',
		'helpMessage' => 'HELP_MESSAGE',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(CustomDto::class);
		foreach ($items as $item)
		{
			$dto = $this->mapCustomDto($item, $fields);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapCustomDto(array $itemData, array $fields): CustomDto
	{
		$dto = new CustomDto();
		if (isset($itemData['ID']))
		{
			$dto->id = (int)$itemData['ID']; // id always present
		}

		$emptyFields = empty($fields);

		foreach (self::$fieldMapping as $dtoField => $dataField)
		{
			if ($emptyFields || in_array($dtoField, $fields, true))
			{
				switch ($dtoField)
				{
					case 'isMultiple':
					case 'isMandatory':
					case 'showInList':
					case 'editInList':
					case 'isSearchable':
						$dto->{$dtoField} = $itemData[$dataField] === 'Y';
						break;
					case 'sort':
						$dto->{$dtoField} = (int)$itemData[$dataField];
						break;
					case 'settings':
						$dto->settings = [];
						foreach ($itemData[$dataField] as $settingKey => $settingValue)
						{
							if (in_array($settingValue, ['Y', 'N'], true))
							{
								$settingValue = $settingValue === 'Y';
							}
							$dto->settings[StringHelper::snake2camel($settingKey, true)] = $settingValue;
						}
						break;
					case 'xmlId':
						$dto->{$dtoField} = isset($itemData[$dataField]) ? (string)$itemData[$dataField] : null;
						break;
					default:
						$dto->{$dtoField} = $itemData[$dataField];
						break;
				}
			}
		}

		return $dto;
	}

	public function getValuesForAdd(CustomDto $dto): array
	{
		$values = [];

		foreach (self::$fieldMapping as $dtoField => $dataField)
		{
			if (!isset($dto->{$dtoField}))
			{
				continue;
			}

			$value = $dto->{$dtoField};

			switch ($dtoField)
			{
				case 'isMultiple':
				case 'isMandatory':
				case 'showInList':
				case 'editInList':
				case 'isSearchable':
					$values[$dataField] = $value ? 'Y' : 'N';
					break;

				case 'id':
				case 'sort':
					$values[$dataField] = (int)$value;
					break;

				case 'settings':
					$values[$dataField] = [];
					foreach ($value as $key => $valueItem)
					{
						$mappedKey = StringHelper::camel2snake((string)$key);
						$values[$dataField][$mappedKey] = is_bool($valueItem) ? ($valueItem ? 'Y' : 'N') : $valueItem;
					}
					break;

				default:
					$values[$dataField] = $value;
					break;
			}
		}

		return $values;
	}

	public function getValuesForUpdate(CustomDto $dto): array
	{
		$values = $this->getValuesForAdd($dto);
		unset($values['ENTITY_ID'], $values['FIELD_NAME'], $values['USER_TYPE_ID']);

		return $values;
	}
}
