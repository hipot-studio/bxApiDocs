<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoValidatorHelper;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class FieldsStructure extends Structure
{
	use UserFieldsTrait;

	protected string $dtoClass;

	protected array $items = [];

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		$structure = new self();
		$structure->dtoClass = $dtoClass;

		$value = (array)$value;

		if (!empty($value))
		{
			/** @var Dto $dto */
			$dto = $dtoClass::create();
			Structure::addDto($dto);

			$fields = $dto->getFields();

			foreach ($value as $item => $itemValue)
			{
				if (!isset($fields[$item]))
				{
					throw new UnknownDtoPropertyException($dto->getShortName(), $item);
				}

				if (str_starts_with($item, 'UF_'))
				{
					$structure->userFields[$item] = $itemValue;

					continue;
				}

				$itemValue = FieldsConverter::convertValueByType($fields[$item]->getPropertyType(), $itemValue);

				$structure->items[$item] = $itemValue;
			}
		}

		return $structure;
	}

	public function getItems(): array
	{
		return $this->items;
	}

	/**
	 * @deprecated Use convertToDto instead
	 */
	public function getAsDto(): Dto
	{
		/** @var Dto $dtoClass */
		$dtoClass = $this->dtoClass;
		$dto = $dtoClass::create();
		Structure::addDto($dto);
		$this->fillDto($dto, $this->items);

		foreach ($this->userFields as $propertyName => $value)
		{
			$dto->{$propertyName} = $value;
		}

		return $dto;
	}

	public function convertToDto(mixed $group = null): Dto
	{
		/** @var Dto $dtoClass */
		$dtoClass = $this->dtoClass;
		$dto = $dtoClass::create();
		Structure::addDto($dto);
		$this->fillDto($dto, $this->items);

		foreach ($this->userFields as $propertyName => $value)
		{
			$dto->{$propertyName} = $value;
		}

		$validationResult = DtoValidatorHelper::validate($dto, ValidationGroup::create($group));
		if (!$validationResult->isSuccess())
		{
			throw new DtoValidationException($validationResult->getErrors());
		}

		return $dto;
	}

	protected function fillDto(Dto $dto, array $items, ?string $parentField = null): void
	{
		foreach ($items as $propertyName => $value)
		{
			$field = $dto->getFields()[$propertyName] ?? null;
			if ($field === null)
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), ($parentField ? $parentField . '.' . $propertyName : $propertyName));
			}

			$propertyType = $field->getPropertyType();
			$qualifiedName = $parentField ? $parentField . '.' . $propertyName : $propertyName;

			// Null shortcut for nested-structure types. The Dto and
			// DtoCollection branches below both ASSUME `$value` is iterable
			// (`is_array` check, then `foreach`). Letting null reach them
			// either throws InvalidRequestFieldTypeException for a legitimate
			// nullable assignment (Dto branch) or raises a raw TypeError on
			// `foreach(null)` under PHP 8+ (DtoCollection branch). Both are
			// wrong — nullable nested properties must accept null cleanly.
			//
			// We honour the property's PHP nullability flag exactly:
			//  - nullable + null → assign null and skip the structural branch
			//  - non-nullable + null → fall through; the Dto branch will hit
			//    the existing `is_array` guard and surface
			//    InvalidRequestFieldTypeException with the proper field name,
			//    while the scalar/collection branches let PHP's TypeError
			//    rethrow as InvalidRequestFieldTypeException via the catch
			//    block at the bottom.
			if ($value === null && $field->isNullable())
			{
				$dto->{$propertyName} = null;

				continue;
			}

			if (is_subclass_of($propertyType, Dto::class))
			{
				$subDto = Structure::getDto($propertyType);
				if ($subDto === null)
				{
					$subDto = $propertyType::create();
					Structure::addDto($dto);
				}

				if (!is_array($value))
				{
					throw new InvalidRequestFieldTypeException($qualifiedName, $propertyType);
				}

				$this->fillDto($subDto, $value, $propertyName);
				$dto->{$propertyName} = $subDto;

				continue;
			}

			if ($propertyType === DtoCollection::class)
			{
				$elementType = $field->getElementType();
				if ($elementType !== null && is_subclass_of($elementType, Dto::class))
				{
					$collection = new DtoCollection($elementType);
					if (Structure::getDto($elementType) === null)
					{
						Structure::addDto($elementType::create());
					}
					// Lenient on non-iterable values by design — the existing
					// test contract (see FileCest "SUCCESS AVATAR WITH WRONG
					// IMAGES") relies on `foreach` over a non-array (string,
					// null, int) producing a PHP Warning and silently leaving
					// the collection empty. Adding an `is_array` guard here
					// would tighten that to a typed exception and break
					// production callers that depend on the lenient coercion.
					// Nullable + null is already handled by the null shortcut
					// above, so this only ever runs for non-nullable fields
					// where the application has consciously chosen to accept
					// "whatever, give me an empty collection".
					if (is_iterable($value))
					{
						foreach ($value as $itemIndex => $itemValue)
						{
							$subDto = $elementType::create();
							$this->fillDto($subDto, $itemValue, $propertyName . '.' . $itemIndex);
							$collection->add($subDto);
						}
					}
					$dto->{$propertyName} = $collection;
				}
				continue;
			}

			try
			{
				$dto->{$propertyName} = $value;
			}
			catch (\TypeError $exception)
			{
				$property = PropertyHelper::getProperty($dto, $propertyName);
				throw new InvalidRequestFieldTypeException($propertyName, $property->getType()?->getName());
			}
		}
	}
}
