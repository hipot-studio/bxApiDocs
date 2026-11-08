<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Structure\FieldsConverter;

final class DtoConverter
{
	public static function convertValueByDtoField(
		DtoField $dtoField,
		mixed $value,
		?string $publicPropertyName = null,
	): mixed
	{
		if ($value === null && $dtoField->isNullable())
		{
			return null;
		}

		$propertyName = $publicPropertyName ?? $dtoField->getPropertyName();
		$elementType = $dtoField->getElementType();
		if (
			$dtoField->getPropertyType() === 'array'
			&& !$dtoField->isMultiple()
			&& $elementType !== null
			&& !is_subclass_of($elementType, Dto::class)
		)
		{
			return $value;
		}

		$isMultiple = $dtoField->isMultiple() || $elementType !== null;
		if ($isMultiple)
		{
			$propertyType = $elementType ?? $dtoField->getPropertyType();
			if (!is_array($value) || !array_is_list($value))
			{
				$propertyTypeName = class_exists($propertyType)
					? (new \ReflectionClass($propertyType))->getShortName()
					: $propertyType
				;
				throw new InvalidRequestFieldTypeException($propertyName, $propertyTypeName . '[]');
			}

			$convertedValue = [];
			foreach ($value as $index => $item)
			{
				$convertedValue[] = self::convertValueByType($propertyType, $item, $propertyName . '.' . $index);
			}
			if ($elementType === null)
			{
				return $convertedValue;
			}

			$dtoCollection = new DtoCollection($elementType);
			foreach ($convertedValue as $item)
			{
				$dtoCollection->add($item);
			}

			return $dtoCollection;
		}

		return self::convertValueByType($dtoField->getPropertyType(), $value, $propertyName);
	}

	public static function convertValueByType(string $type, mixed $value, string $parentPropertyName): mixed
	{
		if (is_subclass_of($type, Dto::class))
		{
			return self::convertValueToDto($type, $value, $parentPropertyName);
		}
		if (is_string($value))
		{
			return FieldsConverter::convertValueByType($type, $value);
		}
		if (is_int($value) && $type === 'float')
		{
			return (float)$value;
		}

		return $value;
	}

	public static function convertValueToDto(string $type, mixed $value, string $parentPropertyName): Dto
	{
		$isValidDtoData = is_array($value) && (!array_is_list($value) || empty($value));
		if (!$isValidDtoData || !is_subclass_of($type, Dto::class))
		{
			throw new InvalidRequestFieldTypeException($parentPropertyName, $type);
		}

		$dto = $type::create();
		foreach ($value as $propertyName => $item)
		{
			if (!isset($dto->getFields()[$propertyName]))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $parentPropertyName . '.' . $propertyName);
			}
			try
			{
				$dto->{$propertyName} = self::convertValueByDtoField(
					$dto->getFields()[$propertyName],
					$item,
					$parentPropertyName . '.' . $propertyName,
				);
			}
			catch (\TypeError)
			{
				$property = PropertyHelper::getProperty($dto, $propertyName);
				throw new InvalidRequestFieldTypeException(
					$parentPropertyName . '.' . $propertyName,
					$property->getType()?->getName(),
				);
			}
		}

		return $dto;
	}
}
