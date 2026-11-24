<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Internals\CollectableEntity;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\ShipmentItem;

/**
 * The single answer to "which entity of this order does the element name?", and the refusal of an
 * element that names none.
 *
 * {@see ElementIdentity} tells an element that names an entity from one being created; this class
 * finds the entity. The two answers have to meet: an element that carries an identifier the order
 * does not have is neither a change nor a creation, and every place that read it as one of the two
 * lost data in its own way. The form was filled from nothing, so the domain got an element without
 * the fields it addresses by; the collection was pruned of everything the input did not name; and
 * then the domain either skipped the element in silence (a trade binding, whose platform was never
 * filled in) or recreated it from whatever the input carried (a property of a basket position).
 * Where the domain does refuse, it refuses after the pruning and without saying which element it
 * could not find.
 *
 * So the address is resolved once, before anything is built: an element that names an entity has
 * to name one of this order — for a nested element, one of the entity its parent names. An element
 * of a create, or of an element being created, has nothing to name at all.
 *
 * An entity is named once in its collection. The domain applies the elements of the form one after
 * another, so a second element naming the same entity was written over the first without a word:
 * a payment named as paid and then named again stayed unpaid. The repeat is refused, and only the
 * repeat; elements being created name no entity and repeat nothing.
 *
 * Two collections carry a second address the domain resolves on its own, and it is named once as
 * well: a new position of a shipment stands on the position of the basket it names, and a new
 * property of a basket position is found by its code, or by its name when the code is empty. The
 * domain hands back the entity it already holds under that address, so a second element naming it
 * overwrote the first one in silence. Two elements that both name their entities by identifier
 * are found by their own numbers and repeat nothing.
 *
 * The collections are named by the property that carries them, one level at a time, so an element
 * type added to the order is refused until its collection is written down here: an address this
 * layer cannot resolve is not one it may hand to the domain.
 */
final class ElementLocator
{
	private const IDENTIFIER = 'id';

	/**
	 * The collections whose elements the domain also finds by an address of its own.
	 */
	private const ADDRESS_RULES = [
		'shipmentItems' => 'a shipment holds a position of the basket once',
		'properties' => 'a position of the basket carries a property once',
	];

	/**
	 * @param Order|null $order the order being changed; a create has none
	 * @return list<Error> one per element that names an entity its parent does not have, under the
	 *         path of its identifier
	 */
	public static function validate(Dto $dto, ?Order $order): array
	{
		return self::walk($dto, $order, '');
	}

	/**
	 * The system shipment is never found: it is the bookkeeping of the domain rather than a shipment
	 * of the order, the read does not give it out, and the domain throws when it is changed.
	 *
	 * @param object|null $parent the entity the parent element names: the order, a basket position
	 *        or a shipment; null when the parent is being created
	 * @param string $property the property of the parent data type that carries the element
	 */
	public static function find(?object $parent, string $property, int $id): ?CollectableEntity
	{
		$collection = match (true)
		{
			$parent instanceof Order => match ($property)
			{
				'basketItems' => $parent->getBasket(),
				'payments' => $parent->getPaymentCollection(),
				'shipments' => $parent->getShipmentCollection(),
				'tradeBindings' => $parent->getTradeBindingCollection(),
				default => null,
			},
			$parent instanceof BasketItem => $property === 'properties' ? $parent->getPropertyCollection() : null,
			$parent instanceof Shipment => $property === 'shipmentItems' ? $parent->getShipmentItemCollection() : null,
			default => null,
		};

		$entity = $collection?->getItemById($id);
		if ($entity instanceof Shipment && $entity->isSystem())
		{
			return null;
		}

		return $entity instanceof CollectableEntity ? $entity : null;
	}

	/**
	 * @return list<Error>
	 */
	private static function walk(Dto $dto, ?object $entity, string $path): array
	{
		$errors = [];
		foreach (DtoFieldsConverter::getInitializedValues($dto) as $property => $value)
		{
			if ($value instanceof Dto)
			{
				// A nested type standing on its own is a row of its parent, not an element of it.
				$errors = array_merge($errors, self::walk($value, $entity, $path . $property . '.'));

				continue;
			}

			if (!$value instanceof DtoCollection)
			{
				continue;
			}

			$index = 0;
			$named = [];
			$addressed = [];
			foreach ($value as $element)
			{
				$elementPath = $path . $property . '.' . $index++;
				if (!$element instanceof Dto)
				{
					continue;
				}

				$id = ElementIdentity::existingEntityId($element);
				$found = $id === null ? null : self::find($entity, $property, $id);
				if ($id !== null && $found === null)
				{
					$errors[] = new Error(
						'Element "' . $elementPath . '" names ' . $id . ', which is no element of the order',
						$elementPath . '.' . self::IDENTIFIER,
					);

					continue;
				}

				if ($id !== null)
				{
					if (isset($named[$id]))
					{
						$errors[] = new Error(
							'Element "' . $elementPath . '" names ' . $id . ', which element "' . $named[$id] . '"'
							. ' names already: an element of the order is named once',
							$elementPath . '.' . self::IDENTIFIER,
						);

						continue;
					}

					$named[$id] = $elementPath;
				}

				$address = self::findDomainAddress($property, $element, $found);
				if ($address !== null)
				{
					[$key, $field] = $address;
					$first = $addressed[$key] ?? null;
					if ($first !== null && !($first['byId'] && $id !== null))
					{
						$errors[] = new Error(
							'Element "' . $elementPath . '" names ' . self::describeAddress($property, $key)
							. ', which element "' . $first['path'] . '" names already: '
							. self::ADDRESS_RULES[$property],
							$elementPath . '.' . $field,
						);

						continue;
					}

					$addressed[$key] ??= ['path' => $elementPath, 'byId' => $id !== null];
				}

				$errors = array_merge($errors, self::walk($element, $found, $elementPath . '.'));
			}
		}

		return $errors;
	}

	/**
	 * The address the domain finds an element of the collection by, past its identifier: the
	 * position of the basket a shipment position stands on, and the code of a property of a basket
	 * position — its name when the code is empty, as the domain reads it. An element named by
	 * identifier keeps the address of its entity unless it passes a new one.
	 *
	 * @return array{0: string, 1: string}|null the address and the property of the element it is
	 *         read from; null when the collection has none or the element names none
	 */
	private static function findDomainAddress(string $property, Dto $element, ?CollectableEntity $found): ?array
	{
		if (!isset(self::ADDRESS_RULES[$property]))
		{
			return null;
		}

		$values = DtoFieldsConverter::getInitializedValues($element);

		if ($property === 'shipmentItems')
		{
			if ($found instanceof ShipmentItem)
			{
				$basketId = $found->getBasketId();

				return $basketId > 0 ? [(string)$basketId, self::IDENTIFIER] : null;
			}

			$basketId = IdentifierSupport::readPositive($values['basketId'] ?? null);

			return $basketId === null ? null : [(string)$basketId, 'basketId'];
		}

		foreach (['code' => 'CODE', 'name' => 'NAME'] as $field => $column)
		{
			$passed = array_key_exists($field, $values);
			$value = (string)($passed ? $values[$field] : $found?->getField($column));
			if ($value !== '')
			{
				return [$value, $passed ? $field : self::IDENTIFIER];
			}
		}

		return null;
	}

	private static function describeAddress(string $property, string $key): string
	{
		return $property === 'shipmentItems'
			? 'position ' . $key . ' of the basket'
			: 'property "' . $key . '"';
	}
}
