<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Structure\FieldsValidator;

/**
 * Refuses an input whose shape contradicts the type it is declared as, before anything is built
 * from it.
 *
 * The platform is lenient here on purpose: a value that is not iterable does not fail the
 * collection branch of the field structure, the `foreach` simply does not run and the property
 * still gets an empty collection ({@see \Bitrix\Rest\V3\Structure\FieldsStructure}, the comment at
 * the DtoCollection branch states the leniency as a contract). For this surface that leniency is
 * data loss with an answer of 200: an empty collection is how the collection rule spells "the
 * collection is now nothing", so `{"payments": "bad"}` would empty the payments of the order. The
 * exchange meets the same input earlier still, as plain fields, and a non-structural element there
 * reaches the resolver and raises a raw error instead of a refusal.
 *
 * The check therefore stands at the boundary of the layer, on both paths, and it is driven by the
 * data type metadata rather than by a list of keys: a nested type or a collection added to the
 * data type later is covered without touching this class.
 *
 * A scalar is measured as well, and by the platform's own rule rather than by one of this layer:
 * the contract of V3 is the JSON type of the value, so a number spelled as a string, a code spelled
 * as a number or a flag spelled as `0`/`1` is refused, and nothing here repairs it. On the ordinary
 * write path the platform would refuse the same value a moment later, with the same exception and
 * under the same name. The exchange is why the check is made here all the same: it reads its codes
 * and the numbers of its positions out of the payload and replaces them with internal identifiers
 * before the platform sees the fields, so a value those walks consume is measured here or nowhere.
 *
 * What it does NOT do: nullability, required fields, ranges and enum membership — those are
 * answers about a value that is already of the right shape, and the platform gives them.
 *
 * `null` and an empty collection pass through untouched — telling a conscious emptying from a
 * mistyped payload is the whole point.
 */
final class FieldShapeSupport
{
	/**
	 * Scalar property types measured against the JSON type of the value, the same set the platform
	 * checks before its own assignment. A union type is reported by the platform as `mixed` and
	 * stays out of the list: a property that declares several shapes has no single one to be
	 * measured against.
	 */
	private const SCALAR_TYPES = [
		'bool' => true,
		'int' => true,
		'float' => true,
		'string' => true,
	];

	/** @var array<string, DtoFieldsCollection> data type class => its fields, metadata only */
	private static array $fieldsByDto = [];

	/**
	 * @param array<string, mixed> $items input keyed by data type properties
	 * @param class-string<Dto> $dtoClass
	 * @param string|null $path qualified name of the parent, for the message of a nested refusal
	 * @throws InvalidRequestFieldTypeException
	 */
	public static function ensureShape(array $items, string $dtoClass, ?string $path = null): void
	{
		$fields = self::fieldsOf($dtoClass);

		foreach ($items as $property => $value)
		{
			$field = $fields[(string)$property] ?? null;
			// An unknown key is the platform's refusal to make, and it makes it by itself.
			if ($field === null || $value === null)
			{
				continue;
			}

			$name = $path === null ? (string)$property : $path . '.' . $property;
			$propertyType = $field->getPropertyType();

			if (isset(self::SCALAR_TYPES[$propertyType]))
			{
				// A field of a dynamic enumeration takes its value converted, and the platform
				// measures it after the conversion, by the enumeration.
				if (
					$field->getDynamicEnumProvider() === null
					&& !FieldsValidator::validateTypeAndValue($propertyType, $value)
				)
				{
					throw new InvalidRequestFieldTypeException($name, $propertyType);
				}

				continue;
			}

			if (TypeHelper::isDto($propertyType))
			{
				if (!is_array($value))
				{
					throw new InvalidRequestFieldTypeException($name, $propertyType);
				}

				self::ensureShape($value, $propertyType, $name);

				continue;
			}

			if ($propertyType === DtoCollection::class)
			{
				self::ensureCollectionShape($field, $value, $name);
			}
		}
	}

	private static function ensureCollectionShape(DtoField $field, mixed $value, string $name): void
	{
		$elementType = $field->getElementType();
		if (!TypeHelper::isDto($elementType))
		{
			return;
		}

		if (!is_array($value))
		{
			throw new InvalidRequestFieldTypeException($name, 'array');
		}

		foreach ($value as $index => $element)
		{
			$elementName = $name . '.' . $index;
			if (!is_array($element))
			{
				throw new InvalidRequestFieldTypeException($elementName, $elementType);
			}

			self::ensureShape($element, $elementType, $elementName);
		}
	}

	/**
	 * @param class-string<Dto> $dtoClass
	 */
	private static function fieldsOf(string $dtoClass): DtoFieldsCollection
	{
		return self::$fieldsByDto[$dtoClass] ??= $dtoClass::create()->getFields();
	}
}
