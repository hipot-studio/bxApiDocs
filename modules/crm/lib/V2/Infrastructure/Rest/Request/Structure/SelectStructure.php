<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\FormDto;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Internal\Service\Item\ItemFieldMapper;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\Rest\V3\Structure\Structure;

final class SelectStructure extends Structure
{
	private const MULTIFIELD_FIELDS = ['phone', 'email', 'web', 'im'];

	private const UTM_FIELDS = ['source', 'medium', 'campaign', 'content', 'term'];

	private const LAST_COMMUNICATION_FIELDS = [
		'communicationTime',
		'callTime',
		'emailTime',
		'imolTime',
		'webformTime',
	];

	/** @var string[] */
	private array $items = [];

	/** @var string[] */
	private array $userFields = [];

	/** @var string[] */
	private array $relationFields = [];

	/** @var array<string, string[]> */
	private array $nestedItems = [];

	/** @var array<string, true> */
	private array $fullRelationFields = [];

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || !array_is_list($value))
		{
			throw new InvalidSelectException($value);
		}

		$structure = new self();
		$dto = self::getOrCreateDto($dtoClass);
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];

		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				throw new InvalidSelectException($item);
			}

			[$fieldName, $nestedFieldName] = array_pad(explode('.', $item, 2), 2, null);
			$field = $dto->getFields()[$fieldName] ?? null;
			if ($field === null && self::isCustomFieldDataField($fieldName))
			{
				$field = self::addGeneratedCustomFieldDataField($dto, $fieldName);
			}
			if (
				$field === null
				|| ($availableFields !== [] && !in_array($fieldName, $availableFields, true))
			)
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
			}

			if ($nestedFieldName === null)
			{
				self::processTopLevelField($fieldName, $field, $structure);

				continue;
			}

			self::processNestedField($fieldName, $nestedFieldName, $field, $dto, $structure, $availableFields);
		}

		return $structure;
	}

	private static function processTopLevelField(string $fieldName, DtoField $field, self $structure): void
	{
		if (str_starts_with($fieldName, 'UF_'))
		{
			self::appendUnique($structure->userFields, $fieldName);

			return;
		}

		$childDtoClass = self::resolveChildDtoClass($field);
		if ($childDtoClass === null)
		{
			self::appendUnique($structure->items, $fieldName);

			return;
		}

		$childDto = self::getOrCreateDto($childDtoClass);
		self::appendRelationField($structure, $fieldName, $field);
		if (self::isCrmRelationField($field))
		{
			self::appendNestedItem($structure, $fieldName, 'id');

			return;
		}

		$structure->fullRelationFields[$fieldName] = true;
		foreach (self::getScalarPropertyFieldNames($childDto) as $childFieldName)
		{
			self::appendNestedItem($structure, $fieldName, $childFieldName);
		}
	}

	private static function processNestedField(
		string $fieldName,
		string $nestedFieldName,
		DtoField $field,
		Dto $dto,
		self $structure,
		array $availableFields,
	): void
	{
		if (str_starts_with($fieldName, 'UF_') && !str_ends_with($fieldName, '_data'))
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName . '.' . $nestedFieldName);
		}

		$childDtoClass = self::resolveChildDtoClass($field);
		if ($childDtoClass === null)
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName . '.' . $nestedFieldName);
		}

		$childDto = self::getOrCreateDto($childDtoClass);
		$nestedPath = explode('.', $nestedFieldName);
		if (count($nestedPath) > 1)
		{
			self::processWhitelistedSecondLevelField(
				$fieldName,
				$nestedPath,
				$field,
				$childDto,
				$dto,
				$structure,
				$availableFields,
			);

			return;
		}

		$nestedField = $childDto->getFields()[$nestedFieldName] ?? null;
		if ($nestedField === null && self::isCustomFieldDataField($nestedFieldName))
		{
			$nestedField = self::addGeneratedCustomFieldDataField($childDto, $nestedFieldName);
		}
		if (
			$nestedField === null
			|| (
				$availableFields !== []
				&& self::isCrmRelationField($field)
				&& !self::isBaseItemField($nestedFieldName)
			)
			|| (
				self::resolveChildDtoClass($nestedField) !== null
				&& !($dto instanceof FormDto)
				&& !(
					(
						self::isCrmRelationField($field)
						&& (
							self::isAllowedWholeSecondLevelField($nestedFieldName, $nestedField)
							|| self::isSupportedRelatedItemField($nestedFieldName, $nestedField)
						)
					)
					|| self::isAllowedSecondLevelField($fieldName, $nestedFieldName, $field)
				)
			)
		)
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $nestedFieldName);
		}

		self::appendRelationField($structure, $fieldName, $field);
		self::appendNestedItem($structure, $fieldName, $nestedFieldName);
	}

	private static function isBaseItemField(string $fieldName): bool
	{
		return self::getOrCreateDto(ItemDto::class)->getFields()[$fieldName] !== null;
	}

	/**
	 * @param string[] $nestedPath
	 */
	private static function processWhitelistedSecondLevelField(
		string $fieldName,
		array $nestedPath,
		DtoField $field,
		Dto $childDto,
		Dto $parentDto,
		self $structure,
		array $availableFields,
	): void
	{
		if (!self::isCrmRelationField($field) || count($nestedPath) !== 2)
		{
			throw new UnknownDtoPropertyException($parentDto->getShortName(), $fieldName . '.' . implode('.', $nestedPath));
		}

		[$nestedFieldName, $secondLevelFieldName] = $nestedPath;
		$nestedField = $childDto->getFields()[$nestedFieldName] ?? null;
		if (
			$nestedField === null
			|| !self::isSelectableSecondLevelField($nestedFieldName, $secondLevelFieldName, $nestedField)
		)
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), implode('.', $nestedPath));
		}
		if ($availableFields !== [] && !self::isBaseItemField($nestedFieldName))
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), implode('.', $nestedPath));
		}

		self::appendRelationField($structure, $fieldName, $field);
		self::appendNestedItem($structure, $fieldName, implode('.', $nestedPath));
	}

	private static function isSelectableSecondLevelField(
		string $fieldName,
		string $nestedFieldName,
		DtoField $field,
	): bool
	{
		if (self::isAllowedSecondLevelField($fieldName, $nestedFieldName, $field))
		{
			return true;
		}

		return self::isFileDtoField($field)
			&& in_array($nestedFieldName, ['id', 'name', 'url', 'downloadUrl'], true);
	}

	private static function isSupportedRelatedItemField(string $fieldName, DtoField $field): bool
	{
		return ItemFieldMapper::isDictionaryRelationField($fieldName)
			|| self::resolveChildDtoClass($field) === EmployeeDto::class
			|| self::isFileDtoField($field)
		;
	}

	private static function isFileDtoField(DtoField $field): bool
	{
		return self::resolveChildDtoClass($field) === FileDto::class;
	}

	private static function isAllowedSecondLevelField(
		string $fieldName,
		string $nestedFieldName,
		DtoField $field,
	): bool
	{
		if (in_array($fieldName, self::MULTIFIELD_FIELDS, true))
		{
			return $field->getPropertyType() === DtoCollection::class
				&& in_array($nestedFieldName, ['id', 'valueTypeId', 'valueType', 'value'], true);
		}

		if ($fieldName === 'utm')
		{
			return in_array($nestedFieldName, self::UTM_FIELDS, true);
		}

		if ($fieldName === 'lastCommunication')
		{
			return in_array($nestedFieldName, self::LAST_COMMUNICATION_FIELDS, true);
		}

		return false;
	}

	private static function isAllowedWholeSecondLevelField(string $fieldName, DtoField $field): bool
	{
		return (
			in_array($fieldName, self::MULTIFIELD_FIELDS, true)
			&& $field->getPropertyType() === DtoCollection::class
		)
			|| self::isCustomFieldDataField($fieldName)
			|| $fieldName === 'utm'
			|| $fieldName === 'lastCommunication';
	}

	private static function isCustomFieldDataField(string $fieldName): bool
	{
		return str_starts_with($fieldName, 'UF_') && str_ends_with($fieldName, '_data');
	}

	private static function addGeneratedCustomFieldDataField(Dto $dto, string $fieldName): ?DtoField
	{
		$dtoClass = $dto::class;
		$generator = new ItemDtoGenerator();
		if (!$generator->isGeneratedDtoClass($dtoClass))
		{
			return null;
		}

		$entityType = $generator->getEntityTypeByGeneratedDtoClass($dtoClass);
		if ($entityType === null)
		{
			return null;
		}

		$generatedDto = (new ItemDtoGenerator())->generate($entityType->getId());
		foreach ($generatedDto->fields as $field)
		{
			if ($field->getPropertyName() !== $fieldName)
			{
				continue;
			}

			$dto->getFields()->add($field);

			return $field;
		}

		return null;
	}

	private static function isCrmRelationField(DtoField $field): bool
	{
		$childDtoClass = self::resolveChildDtoClass($field);
		if ($childDtoClass === null)
		{
			return false;
		}

		return is_a($childDtoClass, ItemDto::class, true)
			|| (new ItemDtoGenerator())->isGeneratedDtoClass($childDtoClass);
	}

	private static function resolveChildDtoClass(DtoField $field): ?string
	{
		$propertyType = $field->getPropertyType();
		if (is_subclass_of($propertyType, Dto::class))
		{
			return $propertyType;
		}

		if ($propertyType !== DtoCollection::class)
		{
			return null;
		}

		$elementType = $field->getElementType();
		if ($elementType === null || !is_subclass_of($elementType, Dto::class))
		{
			return null;
		}

		return $elementType;
	}

	private static function getOrCreateDto(string $dtoClass): Dto
	{
		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

		return $dto;
	}

	/** @return string[] */
	private static function getScalarPropertyFieldNames(Dto $dto): array
	{
		$result = [];
		foreach ($dto->getFields() as $field)
		{
			if (
				$field->getType() === DtoField::DTO_FIELD_TYPE_PROPERTY
				&& self::resolveChildDtoClass($field) === null
			)
			{
				$result[] = $field->getPropertyName();
			}
		}

		return $result;
	}

	private static function appendRelationField(self $structure, string $fieldName, DtoField $field): void
	{
		$relationFieldName = $field->getRelation()?->thisField ?? $fieldName;
		self::appendUnique($structure->relationFields, $relationFieldName);
	}

	private static function appendNestedItem(self $structure, string $fieldName, string $nestedFieldName): void
	{
		$structure->nestedItems[$fieldName] ??= [];
		self::appendUnique($structure->nestedItems[$fieldName], $nestedFieldName);
	}

	/** @param string[] $items */
	private static function appendUnique(array &$items, string $item): void
	{
		if (!in_array($item, $items, true))
		{
			$items[] = $item;
		}
	}

	/** @return string[] */
	public function getList(): array
	{
		return $this->items;
	}

	/** @return string[] */
	public function getUserFields(): array
	{
		return $this->userFields;
	}

	/** @return string[] */
	public function getRelationFields(): array
	{
		return $this->relationFields;
	}

	/** @return array<int|string, string|string[]> */
	public function getStructuredList(): array
	{
		$result = $this->items;
		foreach ($this->nestedItems as $fieldName => $nestedItems)
		{
			$result[$fieldName] = $nestedItems;
		}

		return $result;
	}

	public function isFullRelationSelected(string $fieldName): bool
	{
		return isset($this->fullRelationFields[$fieldName]);
	}
}
