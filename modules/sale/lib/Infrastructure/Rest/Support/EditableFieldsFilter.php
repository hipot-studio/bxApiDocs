<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;

/**
 * Drops the fields an existing entity fixes at creation, over the whole data type tree.
 *
 * Every field that fixes an entity at creation is marked ignored on change
 * ({@see \Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation}): `lid`, `personTypeId`
 * and `currency` of the order, the product and the currency of a basket position, the basket
 * position behind a shipment position, the trading platform of a binding, the entity behind a client
 * and a requisite link. The platform takes them in both groups, so that an element created inside a
 * change carries them; without this they reach the domain on update and retarget an entity the
 * caller only meant to edit. V1 refused them as immutable. A field the mark refuses on change is
 * left to {@see CreationFieldsValidator}.
 *
 * Immutable means "after creation", so the rule is per entity, not per request: an element of a
 * collection that carries no identifier is being created inside this update, and its creation
 * fields are precisely what it needs. A data type that declares no identifier at all — the
 * requisite link — is a row of the order and exists when the order does. Which element is which,
 * is read in one place for the whole layer ({@see ElementIdentity::isExisting()}).
 *
 * Dropping means unsetting: an uninitialized property is what the write pipeline reads as "the
 * client did not send the key" ({@see DtoFieldsConverter}). A data type lives for one call and is
 * consumed by the write right after, so it is filtered in place.
 */
final class EditableFieldsFilter
{
	public static function dropFieldsNotEditableIn(Dto $dto, string $group): void
	{
		if ($group !== RequiredGroup::Update->value)
		{
			return;
		}

		self::filter($dto, isExisting: true);
	}

	private static function filter(Dto $dto, bool $isExisting): void
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);

		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			$property = $field->getPropertyName();
			if (!array_key_exists($property, $values))
			{
				continue;
			}

			if ($isExisting && CreationFieldsValidator::fixedAtCreation($dto, $field)?->ignoredOnChange === true)
			{
				unset($dto->{$property});

				continue;
			}

			self::descend($values[$property], $isExisting);
		}
	}

	private static function descend(mixed $value, bool $parentExists): void
	{
		if ($value instanceof Dto)
		{
			self::filter($value, ElementIdentity::isExisting($value, $parentExists));

			return;
		}

		if (!$value instanceof DtoCollection)
		{
			return;
		}

		foreach ($value as $element)
		{
			if ($element instanceof Dto)
			{
				self::filter($element, ElementIdentity::isExisting($element, $parentExists));
			}
		}
	}
}
