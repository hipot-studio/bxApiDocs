<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The single answer to "does this element of the order name an entity that already exists?".
 *
 * The write path asks it in five places at once — the fields fixed at creation are dropped from an
 * existing element ({@see EditableFieldsFilter}), the fields required at creation are demanded of a
 * new one ({@see CreationFieldsValidator}), the entity an element names has to be one of the order
 * ({@see ElementLocator}), an existing element is filled from that entity ({@see OrderFormBuilder}),
 * and the exchange resolves the codes of a new position only ({@see ExternalIdResolver}). Answers
 * written separately is how they came apart: the same element counted as new in one place and as
 * existing in another.
 *
 * The answer is the identifier and nothing else, because the identifier is the only address this
 * surface gives an element. A data type that declares no identifier at all is not addressed
 * separately: it is one row of the order it hangs on, it exists exactly when the order does, and
 * the caller has no way to name a second one.
 *
 * The value read here has already been through the data type, so it is an `int` or nothing: what
 * the client actually sent is answered for earlier, at the boundary of the layer
 * ({@see FieldShapeSupport}). Reading it through {@see IdentifierSupport} is therefore a question
 * about the number — is it an entity number at all — and not about the shape of the input.
 */
final class ElementIdentity
{
	private const IDENTIFIER = 'id';

	/**
	 * @return int|null the entity this element names, or null when the element is being created
	 *                  inside this call
	 */
	public static function existingEntityId(Dto $element): ?int
	{
		if (!self::isAddressedByIdentifier($element))
		{
			return null;
		}

		return IdentifierSupport::readPositive(
			DtoFieldsConverter::getInitializedValues($element)[self::IDENTIFIER] ?? null,
		);
	}

	/**
	 * Whether the element stands on an entity that already exists. An element addressed by an
	 * identifier answers by its own identifier; a data type that declares none is a row of its
	 * parent and exists exactly when the parent does. Every place that sorts elements into "being
	 * changed" and "being created" asks it this way: reading the identifier alone took the
	 * requisite link of an existing order for a new one.
	 */
	public static function isExisting(Dto $element, bool $parentExists): bool
	{
		if (!self::isAddressedByIdentifier($element))
		{
			return $parentExists;
		}

		return self::existingEntityId($element) !== null;
	}

	/**
	 * Whether the data type addresses an entity of its own at all. Told apart from an element that
	 * simply carries no identifier yet: the first inherits what the order is doing, the second is
	 * being created.
	 */
	public static function isAddressedByIdentifier(Dto $element): bool
	{
		foreach ($element->getFields() as $field)
		{
			if ($field->getPropertyName() === self::IDENTIFIER)
			{
				return true;
			}
		}

		return false;
	}
}
