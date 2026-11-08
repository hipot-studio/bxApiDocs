<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CustomFieldConfigDto;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class CustomFieldConfigMapper extends Mapper
{
	private const FIELD_MAPPING = [
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

	private const ADD_FIELDS = [
		'name',
		'userTypeId',
		'xmlId',
		'sort',
		'isMultiple',
		'isMandatory',
		'showFilter',
		'showInList',
		'editInList',
		'isSearchable',
		'settings',
		'editFormLabel',
		'listColumnLabel',
		'listFilterLabel',
		'errorMessage',
		'helpMessage',
	];

	private const UPDATE_FIELDS = [
		'xmlId',
		'sort',
		'isMandatory',
		'showFilter',
		'showInList',
		'editInList',
		'isSearchable',
		'settings',
		'editFormLabel',
		'listColumnLabel',
		'listFilterLabel',
		'errorMessage',
		'helpMessage',
	];

	private const BOOLEAN_SETTINGS = [
		'address' => ['SHOW_MAP'],
		'url' => ['POPUP'],
		'datetime' => ['USE_SECOND', 'USE_TIMEZONE'],
		'enumeration' => ['SHOW_NO_VALUE'],
		'iblock_section' => ['ACTIVE_FILTER', 'IB_ACTIVE_FILTER'],
		'iblock_element' => ['ACTIVE_FILTER', 'IB_ACTIVE_FILTER'],
		'crm' => ['LEAD', 'CONTACT', 'COMPANY', 'DEAL'],
	];

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(CustomFieldConfigDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapItem($item, $fields));
		}

		return $collection;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValuesForAdd(CustomFieldConfigDto $dto, string $entityId, string $language): array
	{
		$values = $this->getValues($dto, self::ADD_FIELDS, $language);
		$values['ENTITY_ID'] = $entityId;

		return $values;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValuesForUpdate(CustomFieldConfigDto $dto, string $language): array
	{
		return $this->getValues($dto, self::UPDATE_FIELDS, $language);
	}

	private function mapItem(array $itemData, array $selectedFields): CustomFieldConfigDto
	{
		$dto = new CustomFieldConfigDto();
		$allFieldsSelected = $selectedFields === [];

		$userTypeId = (string)($itemData['USER_TYPE_ID'] ?? '');

		foreach (self::FIELD_MAPPING as $dtoField => $dataField)
		{
			if (!$allFieldsSelected && !in_array($dtoField, $selectedFields, true))
			{
				continue;
			}

			if (!array_key_exists($dataField, $itemData))
			{
				continue;
			}

			$dto->{$dtoField} = $this->mapReadValue($dtoField, $itemData[$dataField], $userTypeId);
		}

		return $dto;
	}

	private function mapReadValue(string $dtoField, mixed $value, string $userTypeId): mixed
	{
		return match ($dtoField)
		{
			'id' => (int)$value,
			'isMultiple', 'isMandatory', 'showInList', 'editInList', 'isSearchable'
				=> $value === 'Y',
			'sort' => (int)$value,
			'settings' => $this->mapSettings($value, $userTypeId),
			'xmlId' => $value === null ? null : (string)$value,
			default => $value,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mapSettings(mixed $settings, string $userTypeId): array
	{
		if (!is_array($settings))
		{
			return [];
		}

		$booleanSettings = self::BOOLEAN_SETTINGS[$userTypeId] ?? [];
		$result = [];
		foreach ($settings as $key => $value)
		{
			if (!is_string($key) && !is_int($key))
			{
				continue;
			}

			$key = (string)$key;
			if ($userTypeId === 'crm' && str_starts_with($key, 'DYNAMIC'))
			{
				$booleanSettings[] = $key;
			}

			if (in_array($key, $booleanSettings, true) && ($value === 'Y' || $value === 'N'))
			{
				$value = $value === 'Y';
			}

			$result[StringHelper::snake2camel($key, true)] = $value;
		}

		return $result;
	}

	/**
	 * @param list<string> $fields
	 * @return array<string, mixed>
	 */
	private function getValues(CustomFieldConfigDto $dto, array $fields, string $language): array
	{
		$values = [];
		foreach ($fields as $dtoField)
		{
			$property = new \ReflectionProperty($dto, $dtoField);
			if (!$property->isInitialized($dto))
			{
				continue;
			}

			$values[self::FIELD_MAPPING[$dtoField]] = $this->mapWriteValue(
				$dtoField,
				$dto->{$dtoField},
				$language,
			);
		}

		return $values;
	}

	private function mapWriteValue(string $dtoField, mixed $value, string $language): mixed
	{
		return match ($dtoField)
		{
			'isMultiple', 'isMandatory', 'showInList', 'editInList', 'isSearchable'
				=> $value ? 'Y' : 'N',
			'sort' => (int)$value,
			'settings' => $this->mapWriteSettings($value),
			'editFormLabel', 'listColumnLabel', 'listFilterLabel', 'errorMessage', 'helpMessage'
				=> is_string($value) ? [$this->getLanguage($language) => $value] : $value,
			default => $value,
		};
	}

	private function getLanguage(string $language): string
	{
		if ($language !== '')
		{
			return $language;
		}

		return defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mapWriteSettings(array $settings): array
	{
		$result = [];
		foreach ($settings as $key => $value)
		{
			$mappedKey = mb_strtoupper(StringHelper::camel2snake((string)$key));
			$result[$mappedKey] = $mappedKey === 'DEFAULT_VALUE' && is_bool($value)
				? (int)$value
				: (is_bool($value) ? ($value ? 'Y' : 'N') : $value);
		}

		return $result;
	}
}
