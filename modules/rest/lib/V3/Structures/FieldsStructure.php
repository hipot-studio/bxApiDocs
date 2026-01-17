<?php

namespace Bitrix\Rest\V3\Structures;

use Bitrix\Rest\V3\Attributes\Editable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Exceptions\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exceptions\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exceptions\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;

final class FieldsStructure extends Structure
{
	use UserFieldsTrait;

	protected string $dtoClass;

	/** @var string[] $items */
	protected array $items = [];

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		$structure = new self();
		$structure->dtoClass = $dtoClass;

		$value = (array)$value;

		if (!empty($value))
		{

			/** @var Dto $dto */
			$dto = self::getDto($dtoClass);

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

		foreach ($this->items as $propertyName => $value)
		{
			try
			{
				$dto->{$propertyName} = $value;
			}
			catch (\TypeError $exception)
			{
				$property = PropertyHelper::getProperty($dtoClass, $propertyName);
				throw new InvalidRequestFieldTypeException($propertyName, $property->getType()?->getName());
			}
		}

		foreach ($this->userFields as $propertyName => $value)
		{
			$dto->{$propertyName} = $value;
		}

		return $dto;
	}
}
