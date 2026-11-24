<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PaymentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Order;

/**
 * What an explicit null of a field means for an entity that already exists.
 *
 * The data type tells a null from an absent key ({@see DtoFieldsConverter}), but the domain does
 * not always: it writes a field of the order only when the key holds a value
 * (`OrderBuilder::setFields()`), and a few fields of a shipment only when the value is not empty.
 * A null there used to be accepted with an answer of 200 and to change nothing. A payment, a
 * position of the basket and the deduction and the delivery price of a shipment hand the null to
 * the domain as it is, and the domain reads it as a value: a null of the return mark of a paid
 * payment returned its money to the inner account of the buyer, a null of a price priced the
 * position at zero, a null of the deduction took a deducted shipment back. So a null of an existing
 * entity either empties the field — the value the domain itself spells empty with, an empty string
 * for a text and zero for a reference — or, for a field with no empty value, is refused by its path.
 *
 * A field that holds nothing already takes the null as what it is, both ways: an entity sent back as
 * it was read carries such nulls, and asking for what the entity already is must neither be refused
 * nor be written as a change, with its entry in the history and its events. A null the domain
 * answers by itself is left to it: a text of a payment is written null, and a text of a shipment is
 * emptied.
 *
 * An entity being created has nothing to empty, so there a null is a field not given, as before.
 */
final class ExplicitNullSupport
{
	/** Fields a null empties, with the empty value the domain takes for each. */
	private const EMPTY_VALUES = [
		OrderDto::class => [
			'reasonCanceled' => '',
			'reasonMarked' => '',
			'userDescription' => '',
			'additionalInfo' => '',
			'comments' => '',
			'orderTopic' => '',
			'xmlId' => '',
			'id1c' => '',
			'version1c' => '',
			'empStatusId' => 0,
			'empCanceledId' => 0,
			'empMarkedId' => 0,
			'responsibleId' => 0,
			'companyId' => 0,
			'affiliateId' => 0,
			'recurringId' => 0,
			'lockedBy' => 0,
		],
		// The basket hands a text to the domain as it is, and the name of a position is kept in a
		// column that takes no null.
		BasketItemDto::class => [
			'name' => '',
			'dimensions' => '',
			'measureName' => '',
			'xmlId' => '',
		],
		// The domain writes the code of a position whenever the key is there, a null included; the
		// layer reads the key itself and fills the rest of the position from the one it names.
		ShipmentItemDto::class => [
			'xmlId' => '',
		],
	];

	/**
	 * Fields with no empty value: a status, an amount, a flag, a date. A shipment has two kinds of
	 * them: the fields the domain writes only when they hold something, so neither a null nor an
	 * empty value would reach it, and the deduction and the price of the delivery, whose null the
	 * domain reads as a value (`OrderBuilder::buildShipments()`): `N` for a flag, which takes a
	 * deducted shipment back or a custom price off, zero for the price, and the price for the base
	 * price (`OrderBuilderExist::setShipmentPriceFields()`). A payment and a position of the basket
	 * hand the null to the domain as it is: a sum, a price and a sort order are rounded to zero there,
	 * a flag moves the payment (`Payment::onFieldModify()`) or is refused only when the basket is
	 * saved, after the order. The answer of the pay system is not among them: null is its empty value,
	 * the one a payment the pay system has not answered holds, and the domain writes it as it is.
	 */
	private const NO_EMPTY_VALUE = [
		OrderDto::class => [
			'price',
			'discountValue',
			'statusId',
			'canceled',
			'marked',
			'dateInsert',
			'recountFlag',
			'updated1c',
			'externalOrder',
		],
		ShipmentDto::class => [
			'deliveryId',
			'statusId',
			'allowDelivery',
			'deliveryDocDate',
			'xmlId',
			'deducted',
			'priceDelivery',
			'basePriceDelivery',
			'customPriceDelivery',
		],
		PaymentDto::class => [
			'paySystemId',
			'sum',
			'paid',
			'isReturn',
			'marked',
			'priceCod',
			'updated1c',
		],
		BasketItemDto::class => [
			'quantity',
			'price',
			'basePrice',
			'discountPrice',
			'vatIncluded',
			'canBuy',
			'sort',
		],
	];

	/** Collections of the order whose existing elements answer a null by the tables above. */
	private const COLLECTIONS = ['basketItems', 'payments', 'shipments'];

	/**
	 * @param Order|null $order the order being changed; a create has none
	 * @return list<Error> one per null of an existing entity that has no empty value and holds one
	 *         now, under its path
	 */
	public static function validate(OrderDto $dto, ?Order $order): array
	{
		if ($order === null)
		{
			return [];
		}

		$errors = self::refuse($dto, $order, '');

		$values = DtoFieldsConverter::getInitializedValues($dto);
		foreach (self::COLLECTIONS as $property)
		{
			$index = 0;
			foreach ($values[$property] ?? [] as $element)
			{
				$path = $property . '.' . $index++ . '.';
				$id = $element instanceof Dto ? ElementIdentity::existingEntityId($element) : null;
				// An element naming an entity the order does not have is refused by its identifier elsewhere.
				$current = $id === null ? null : ElementLocator::find($order, $property, $id);
				if ($current !== null)
				{
					$errors = array_merge($errors, self::refuse($element, $current, $path));
				}
			}
		}

		return $errors;
	}

	/**
	 * @param object|null $entity the entity the data type stands on; null when it is being created
	 * @return array<string, mixed> property => the empty value its null stands for, for the fields
	 *         that hold something now
	 */
	public static function emptiedValues(Dto $dto, ?object $entity): array
	{
		if ($entity === null)
		{
			return [];
		}

		$values = DtoFieldsConverter::getInitializedValues($dto);
		$columnMap = AbstractOrderMapper::forDto($dto::class)->getColumnMap();

		$emptied = [];
		foreach (self::tableFor(self::EMPTY_VALUES, $dto) as $property => $empty)
		{
			if (
				array_key_exists($property, $values)
				&& $values[$property] === null
				&& self::holdsSomething($entity, $columnMap[$property])
			)
			{
				$emptied[$property] = $empty;
			}
		}

		return $emptied;
	}

	/**
	 * @param object $entity the order or the element of the order the data type stands on
	 * @return list<Error>
	 */
	private static function refuse(Dto $dto, object $entity, string $path): array
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);
		$columnMap = AbstractOrderMapper::forDto($dto::class)->getColumnMap();

		$errors = [];
		foreach (self::tableFor(self::NO_EMPTY_VALUE, $dto) as $property)
		{
			if (
				array_key_exists($property, $values)
				&& $values[$property] === null
				&& self::holdsSomething($entity, $columnMap[$property])
			)
			{
				$errors[] = new Error(
					'Field "' . $path . $property . '" has no empty value and cannot be set to null',
					$path . $property,
				);
			}
		}

		return $errors;
	}

	private static function holdsSomething(object $entity, string $column): bool
	{
		return !in_array($entity->getField($column), [null, ''], true);
	}

	/**
	 * @param array<class-string, array<mixed>> $table
	 * @return array<mixed>
	 */
	private static function tableFor(array $table, Dto $dto): array
	{
		foreach ($table as $dtoClass => $entries)
		{
			if ($dto instanceof $dtoClass)
			{
				return $entries;
			}
		}

		return [];
	}
}
