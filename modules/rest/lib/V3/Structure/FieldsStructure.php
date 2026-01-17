<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;

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

				if ($request instanceof UpdateRequest && !$fields[$item]->isEditable())
				{
					throw new DtoFieldRequiredAttributeException($dto->getShortName(), $item, Editable::class);
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

	protected function fillDto(Dto $dto, array $items, ?string $parentField = null): void
	{
		foreach ($items as $propertyName => $value)
		{
			if (!isset($dto->getFields()[$propertyName]))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), ($parentField ? $parentField . '.' . $propertyName : $propertyName));
			}

			if (is_subclass_of($dto->getFields()[$propertyName]->getPropertyType(), Dto::class))
			{
				$subDto = Structure::getDto($dto->getFields()[$propertyName]->getPropertyType());
				if ($subDto === null)
				{
					$subDto = $dto->getFields()[$propertyName]->getPropertyType()::create();
					Structure::addDto($dto);
				}

				if (!is_array($value))
				{
					throw new InvalidRequestFieldTypeException(($parentField ? $parentField . '.' . $propertyName : $propertyName), $dto->getFields()[$propertyName]->getPropertyType());
				}

				$this->fillDto($subDto, $value, $propertyName);
				$dto->{$propertyName} = $subDto;

				continue;
			}

			if ($dto->getFields()[$propertyName]->getPropertyType() === DtoCollection::class)
			{
				$elementType = $dto->getFields()[$propertyName]->getElementType();
				if ($elementType !== null && is_subclass_of($elementType, Dto::class))
				{
					$collection = new DtoCollection($elementType);
					if (Structure::getDto($elementType) === null)
					{
						Structure::addDto($elementType::create());
					}
					foreach ($value as $itemIndex => $itemValue)
					{
						$subDto = $elementType::create();
						$this->fillDto($subDto, $itemValue, $propertyName . '.' . $itemIndex);
						$collection->add($subDto);
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
