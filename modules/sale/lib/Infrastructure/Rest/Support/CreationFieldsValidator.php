<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\Validation\FieldEditableValidator;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;

/**
 * Answers for the fields a data type fixes at creation, element by element: an element being
 * created must carry the ones it requires, and an element that already exists may not carry the
 * ones marked {@see FixedAtCreation}.
 *
 * The platform validates the whole tree in the group of the call
 * (`Rest\V3\Dto\DtoValidatorHelper::validate()` hands its own group down to every nested element),
 * so on an update a new element is checked as a change: nothing is required of it, because the
 * fields it needs are required "on add". The consequence is not a poor message but a loss — a new
 * trade binding with no platform passes validation, and the domain, which addresses a binding by
 * its platform, skips the element without a word after having already removed the bindings the
 * input did not name.
 *
 * The group is therefore per element, by the same reading of "new" the rest of the layer uses
 * ({@see ElementIdentity}): an element that names an existing entity is a change, an element that
 * names none is a creation. A data type that declares no identifier at all — the requisite link —
 * is not an element but a row of the order, and it does what the order does.
 *
 * Carrying a required field is not the same as naming it. For the entity a new element of a
 * collection is created on, the key alone used to be enough, so a platform of null or zero passed
 * the rule above and the domain lost the collection all the same. Such a field is answered for by
 * its value ({@see isRequiredReference()}). The order a client creates is answered for the same way:
 * a person type of null or zero used to pass, the domain gave the order the default one of the site
 * and dropped the property values of the input unchecked.
 *
 * A field marked fixed at creation is declared editable in both groups, or the platform would refuse
 * it to an element created inside a change; the refusal an existing element gets is given here, in
 * the words and under the path the platform itself uses for a field its group may not carry. The
 * fields the mark ignores on change are not refused: they are dropped from an existing element one
 * step further on ({@see EditableFieldsFilter}), and the shape of the values is answered for over
 * the whole tree before the data type is even built ({@see FieldShapeSupport}).
 */
final class CreationFieldsValidator
{
	/**
	 * @param string $rootGroup what the call itself is doing: {@see RequiredGroup}
	 * @param bool $rootIsCreatedElement the root is being created by the client and answers for its
	 *        required references the way an element created inside a collection does
	 * @return list<Error> empty when every element carries what its own group requires and nothing
	 *         its own group may not carry
	 */
	public static function validate(Dto $dto, string $rootGroup, bool $rootIsCreatedElement = false): array
	{
		return self::walk($dto, $rootGroup, '', isCreatedElement: $rootIsCreatedElement);
	}

	/**
	 * @param bool $isCreatedElement the element is being created inside a collection of its parent
	 * @return list<Error>
	 */
	private static function walk(Dto $dto, string $group, string $path, bool $isCreatedElement): array
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);

		$errors = [];
		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			$property = $field->getPropertyName();
			if (!array_key_exists($property, $values))
			{
				if (self::isRequiredIn($field, $group))
				{
					$errors[] = new Error(
						'Field "' . $path . $property . '" is required for the element being created',
					);
				}

				continue;
			}

			if (
				$isCreatedElement
				&& self::isRequiredReference($field, $group)
				&& IdentifierSupport::readPositive($values[$property]) === null
			)
			{
				$errors[] = new Error(
					'Field "' . $path . $property . '" of the element being created has to name an existing entity',
					$path . $property,
				);

				continue;
			}

			if (
				$group === RequiredGroup::Update->value
				&& self::fixedAtCreation($dto, $field)?->ignoredOnChange === false
			)
			{
				$errors[] = self::fixedAtCreationError($path . $property, $property);

				continue;
			}

			foreach (self::elementsOf($values[$property]) as $key => $element)
			{
				$elementGroup = self::groupOf($element, $group);
				$errors = array_merge(
					$errors,
					self::walk(
						$element,
						$elementGroup,
						$path . $property . ($key === null ? '' : '.' . $key) . '.',
						isCreatedElement: ElementIdentity::isAddressedByIdentifier($element)
							&& $elementGroup === RequiredGroup::Add->value,
					),
				);
			}
		}

		return $errors;
	}

	/**
	 * @return iterable<int|null, Dto> the key names the element inside its collection, and is null
	 *         for a nested data type standing on its own
	 */
	private static function elementsOf(mixed $value): iterable
	{
		if ($value instanceof Dto)
		{
			yield null => $value;

			return;
		}

		if (!$value instanceof DtoCollection)
		{
			return;
		}

		$index = 0;
		foreach ($value as $element)
		{
			if ($element instanceof Dto)
			{
				yield $index => $element;
			}

			$index++;
		}
	}

	private static function groupOf(Dto $element, string $group): string
	{
		return ElementIdentity::isExisting($element, parentExists: $group === RequiredGroup::Update->value)
			? RequiredGroup::Update->value
			: RequiredGroup::Add->value;
	}

	/**
	 * Whether the field carries the mark, in either of its variants.
	 */
	public static function isFixedAtCreation(Dto $dto, DtoField $field): bool
	{
		return self::fixedAtCreation($dto, $field) !== null;
	}

	/**
	 * Public for the same reason as {@see isRequiredIn()}: what a client may send back from an
	 * element it read is measured by the variant of this mark.
	 *
	 * @return FixedAtCreation|null null for a field an existing element may change
	 */
	public static function fixedAtCreation(Dto $dto, DtoField $field): ?FixedAtCreation
	{
		$property = $field->getPropertyName();
		if ($field->getType() !== DtoField::DTO_FIELD_TYPE_PROPERTY || !property_exists($dto, $property))
		{
			return null;
		}

		$attributes = (new \ReflectionProperty($dto, $property))->getAttributes(FixedAtCreation::class);

		return $attributes === [] ? null : $attributes[0]->newInstance();
	}

	private static function fixedAtCreationError(string $path, string $property): Error
	{
		$message = new LocalizableMessage(
			code: 'REST_V3_DTO_VALIDATION_FIELD_EDITABLE_VALIDATOR_ERROR',
			replace: ['#FIELD#' => $property],
			phraseSrcFile: (new \ReflectionClass(FieldEditableValidator::class))->getFileName(),
		);

		return new Error($message, $path);
	}

	/**
	 * A required field declared `int` is, on every element of a collection, the entity the element
	 * is created on: the product of a position, the platform of a binding, the delivery service of
	 * a shipment, the pay system of a payment, the property of a value, the basket position of a
	 * shipment position, the CRM entity of a client. The domain places a new element by it after it
	 * has pruned the collection, and a null or a zero there reads as "nothing": the trade binding is
	 * skipped without a word. So such a field is answered for by its value, not by its key.
	 *
	 * A row of the order is left out by the caller, not here: the entity fields of a requisite link
	 * name the order itself, which a create does not have yet, and the domain writes its own.
	 */
	private static function isRequiredReference(DtoField $field, string $group): bool
	{
		return $field->getPropertyType() === 'int' && self::isRequiredIn($field, $group);
	}

	/**
	 * Whether a field has to be named in a given group. Mirrors
	 * `Rest\V3\Dto\Validation\RequiredGroupValidationTrait::isRequired()`, and is public because
	 * the same question decides which columns of a whole-row write nobody is obliged to answer
	 * ({@see OrderFormBuilder}).
	 */
	public static function isRequiredIn(DtoField $field, string $group): bool
	{
		$groups = $field->getRequiredGroups();

		return $groups !== null && ($groups === [] || in_array($group, $groups, true));
	}
}
