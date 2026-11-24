<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Catalog\Product\Basket;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemPropertyDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ClientDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PaymentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\RequisiteLinkDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\TradeBindingDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;

/**
 * Turns the order data type into the form the domain order builder accepts.
 *
 * The form is the shape `OrderBuilder` works with after its own preparation step, so the chain
 * passes it through unchanged. Doing the V1 transformations here instead of calling the V1
 * preparers is what makes the collection rule possible: their own handling of an empty collection
 * drops the key entirely and the collection then silently survives.
 *
 * The collection rule: a collection key reaches the form when, and only when, the matching data
 * type property is initialized. An empty collection becomes an empty key, which the domain reads
 * as "the collection is now nothing".
 *
 * The rule holds for a collection as a whole; inside an element it stops at the domain. Parts of
 * the aggregate are read there unconditionally — a shipment field behind `?? ''`, the columns of a
 * requisite link the upsert always writes, the trading platform of a binding read before the
 * binding is even looked up, the quantity of a shipment position compared with zero — so an absent
 * key is not "leave it alone" but "make it empty", and for the platform "skip this element
 * entirely". An element that names an existing entity is therefore filled from that entity first,
 * and only the keys the client did not send: partial writing is the contract of the surface, and it
 * cannot rest on the domain reading sparsely. Which element that is, is read in one place for the
 * whole layer ({@see ElementIdentity}): an element with no identifier is being created inside this
 * call and has nothing to be filled from. The entity an element names is found by the same reading
 * the write service checks it with before the form is built ({@see ElementLocator}), so an element
 * naming an entity the order does not have never gets here: filled from nothing, it would reach the
 * domain without the keys the domain finds it by.
 *
 * The filling names no columns. What an element writes is what its own mapper declares, so the
 * columns are taken from that declaration ({@see currentColumns()}) and a field added to a data
 * type is filled from the day it is added. Every level does it by the same call, the positions of
 * a shipment included: the level an element lives on is not a reason for it to be filled
 * differently.
 *
 * Two parts are left out, and neither by omission. Basket positions: the domain does not merely
 * read a position, it reads meaning into the presence of a key — a position handed a price is
 * marked as priced by hand (`BasketBuilderRest::setItemsFields()`). Handing back the price the
 * position already has would therefore not preserve the position but change it, so the basket keeps
 * the sparse reading the domain gives it. Clients: a client names no existing binding at all, for
 * the reason written down at {@see ClientDto}, so there is nothing here to fill from. A few columns
 * are left out of a filled element as well, the ones the domain keeps by itself: the sum, the
 * markup, the status description and the stamps of the changes of a payment
 * ({@see self::PAYMENT_COLUMNS_KEPT_BY_THE_DOMAIN}), the delivery flag, the status and the dates of
 * the changes of a shipment ({@see self::SHIPMENT_COLUMNS_KEPT_BY_THE_DOMAIN}).
 *
 * The identifier of an element reaches the form only when it names an entity: an element whose
 * identifier names none is a new one for the whole layer, and the domain would read the number as
 * an address all the same ({@see withoutIdentifierOfANewElement()}).
 *
 * An explicit null of an existing entity reaches the form as the empty value the domain spells,
 * where the domain would not write the null itself ({@see ExplicitNullSupport}).
 */
final class OrderFormBuilder
{
	/** Order properties assembled by hand below, or owned by the service (`id`). */
	private const ORDER_OWN_KEYS = [
		'id',
		'basketItems',
		'propertyValues',
		'payments',
		'shipments',
		'tradeBindings',
		'clients',
		'requisiteLink',
	];

	/**
	 * The positions of a shipment are the one part of the aggregate the domain reads under keys of
	 * its own rather than under the columns of the entity, so they name the pairs here. `basketId`
	 * is deliberately absent: it addresses a position of the basket being created in this same call
	 * and an existing position has nothing to be filled from.
	 */
	private const SHIPMENT_ITEM_COLUMNS = [
		'id' => 'ORDER_DELIVERY_BASKET_ID',
		'quantity' => 'AMOUNT',
		'xmlId' => 'XML_ID',
	];

	/**
	 * Columns of a payment the domain keeps by itself. A change of the basket carries the new price
	 * of the order into the sum of the only unpaid payment while the basket is built, and a sum in the
	 * form is written over it afterwards (`OrderBuilder::buildPayments()`), so the sum a payment had
	 * before the change is not filled in: a payment the client named without a sum keeps whatever
	 * the domain leaves it, recalculated or not. The markup of the pay system is recalculated with
	 * the sum (`PaymentCollection::onOrderModify()`) and is not filled in for the same reason.
	 *
	 * A return through a pay system may add a note to the status description from inside the same
	 * `setFields()` (the refund of the handler), so the description is not filled in either.
	 *
	 * The rest are the stamps of a change (`Payment::onFieldModify()`): the date and the author of a
	 * mark and its reason taken away with it, the date of a new responsible, the author of a return,
	 * the payment a return takes back and the date and the author of a payment. The domain sets them
	 * inside the same `setFields()` the form is applied by, and a filled column comes after the
	 * named ones, so the value a payment had before the call would be written back over the stamp.
	 */
	private const PAYMENT_COLUMNS_KEPT_BY_THE_DOMAIN = [
		'SUM',
		'PRICE_COD',
		'PS_STATUS_DESCRIPTION',
		'PAID',
		'DATE_PAID',
		'EMP_PAID_ID',
		'EMP_RETURN_ID',
		'EMP_RESPONSIBLE_ID',
		'DATE_RESPONSIBLE_ID',
		'DATE_MARKED',
		'EMP_MARKED_ID',
		'REASON_MARKED',
	];

	/**
	 * Columns of a shipment the domain keeps by itself. A payment made in the same call allows the
	 * delivery of the shipments (`Order::onPaymentCollectionModify()`), and the allowance moves the
	 * status of a shipment (`Shipment::onFieldModify()`), before the shipments of the form are
	 * built. The domain then writes the two only when the form names them
	 * (`OrderBuilder::buildShipments()`, `OrderBuilder::prepareFieldsStatusId()`), so the values a
	 * shipment had before the call are not filled in: they would take the allowance back.
	 *
	 * The dates are the stamps of a change, the only ones the order builder hands a shipment: taking
	 * a deduction back dates it anew before the dates of the form are set, and the date the shipment
	 * had before the call would be written back over it.
	 */
	private const SHIPMENT_COLUMNS_KEPT_BY_THE_DOMAIN = [
		'ALLOW_DELIVERY',
		'STATUS_ID',
		'DATE_DEDUCTED',
		'DATE_MARKED',
		'DATE_CANCELED',
		'DATE_RESPONSIBLE_ID',
	];

	private const IDENTIFIER = 'id';

	/**
	 * @param Order|null $order the order being changed; a create has none
	 * @param bool $newPositionsAreCatalogProducts every position created by this write names a product
	 *        of the catalogue, so the position is bound to the catalogue and its provider
	 * @return array<string, mixed>
	 */
	public static function build(
		OrderDto $dto,
		?Order $order = null,
		bool $newPositionsAreCatalogProducts = false,
	): array
	{
		$columnMap = AbstractOrderMapper::forDto(OrderDto::class)->getColumnMap();
		$form = DtoFieldsConverter::toDomainFields($dto, $columnMap, self::ORDER_OWN_KEYS);

		// The domain writes a field of the order only when the key holds a value.
		foreach (ExplicitNullSupport::emptiedValues($dto, $order) as $property => $empty)
		{
			$form[$columnMap[$property]] = $empty;
		}

		$values = DtoFieldsConverter::getInitializedValues($dto);

		if (array_key_exists('propertyValues', $values))
		{
			$form['PROPERTIES'] = self::buildPropertyValues($values['propertyValues']);
		}

		if (array_key_exists('basketItems', $values))
		{
			$form['PRODUCT'] = self::buildBasketItems($values['basketItems'], $order, $newPositionsAreCatalogProducts);
		}

		if (array_key_exists('payments', $values))
		{
			$form['PAYMENT'] = array_map(
				self::withTheReturnLast(...),
				self::buildElements(
					$values['payments'],
					PaymentDto::class,
					static fn(int $id): ?array => self::withoutColumns(
						ElementLocator::find($order, 'payments', $id)?->getFieldValues(),
						self::PAYMENT_COLUMNS_KEPT_BY_THE_DOMAIN,
					),
				),
			);
		}

		if (array_key_exists('shipments', $values))
		{
			$form['SHIPMENT'] = self::buildShipments($values['shipments'], $order);
		}

		if (array_key_exists('tradeBindings', $values))
		{
			$form['TRADE_BINDINGS'] = self::buildElements(
				$values['tradeBindings'],
				TradeBindingDto::class,
				static fn(int $id): ?array => ElementLocator::find($order, 'tradeBindings', $id)?->getFieldValues(),
			);
		}

		if (array_key_exists('clients', $values))
		{
			// No filling: a client names no existing binding, because its data type keeps the
			// identifier read-only for a reason of the domain ({@see ClientDto}).
			$form['CLIENTS'] = self::buildElements($values['clients'], ClientDto::class);
		}

		if (array_key_exists('requisiteLink', $values))
		{
			$link = $values['requisiteLink'] instanceof Dto
				? self::buildRequisiteLink($values['requisiteLink'], $order)
				: self::clearedRequisiteLink($order);
			if ($link !== null)
			{
				$form['REQUISITE_LINK'] = $link;
			}
		}

		return $form;
	}

	/**
	 * Order property values collapse into "property id => value" pairs: that is the shape the
	 * domain property collection is filled from, and the element identifier plays no part in it.
	 *
	 * The property is read by the reader of the layer rather than by a cast of its own: an element
	 * that names no property at all addresses nothing the domain could fill, and a key of zero is
	 * how a cast used to spell that.
	 *
	 * @return array<int, mixed>
	 */
	private static function buildPropertyValues(?DtoCollection $collection): array
	{
		$values = [];
		foreach ($collection ?? [] as $propertyValue)
		{
			$element = DtoFieldsConverter::getInitializedValues($propertyValue);

			$propertyId = IdentifierSupport::readPositive($element['orderPropsId'] ?? null);
			if ($propertyId !== null)
			{
				$values[$propertyId] = $element['value'] ?? null;
			}
		}

		return $values;
	}

	/**
	 * Basket positions are keyed by their identifier, and a new position gets a generated code: the
	 * domain basket builder maps a position of the form to a position of the basket by that key.
	 *
	 * The order a position belongs to is the server's and never the client's, so `orderId` is left
	 * out of the form here rather than trusted to stay unwritable somewhere else.
	 *
	 * The module of a position is given only when the position is created, and nothing afterwards
	 * binds it to the catalogue: without the module and the provider the domain keeps a custom
	 * position, whose stock is never checked, reserved or written off.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildBasketItems(
		?DtoCollection $collection,
		?Order $order,
		bool $newPositionsAreCatalogProducts,
	): array
	{
		$columnMap = AbstractOrderMapper::forDto(BasketItemDto::class)->getColumnMap();
		$propertyColumnMap = AbstractOrderMapper::forDto(BasketItemPropertyDto::class)->getColumnMap();

		$items = [];
		$newItemIndex = 0;
		foreach ($collection ?? [] as $basketItem)
		{
			$element = DtoFieldsConverter::getInitializedValues($basketItem);
			$item = DtoFieldsConverter::toDomainFields(
				$basketItem,
				$columnMap,
				['id', 'orderId', 'productId', 'properties', 'reservations'],
			);

			// A text is handed to the domain as it is; the empty string stands for its null.
			$id = ElementIdentity::existingEntityId($basketItem);
			$current = $id === null ? null : ElementLocator::find($order, 'basketItems', $id);
			foreach (ExplicitNullSupport::emptiedValues($basketItem, $current) as $property => $empty)
			{
				$item[$columnMap[$property]] = $empty;
			}

			if (array_key_exists('productId', $element))
			{
				$item['OFFER_ID'] = $element['productId'];
			}

			// The collection rule one level down: properties nobody named do not reach the form at
			// all, and the domain basket builder reads an absent key as "leave them alone".
			if (array_key_exists('properties', $element))
			{
				$item['PROPS'] = self::mapElements($element['properties'], $propertyColumnMap);
			}

			if ($id === null && $newPositionsAreCatalogProducts && array_key_exists('productId', $element))
			{
				$item += self::catalogProductBinding();
			}

			$items[$id ?? 'n' . ++$newItemIndex] = $item;
		}

		return $items;
	}

	/**
	 * @return array<string, string>
	 */
	private static function catalogProductBinding(): array
	{
		if (!Loader::includeModule('catalog'))
		{
			return [];
		}

		return [
			'MODULE' => 'catalog',
			'PRODUCT_PROVIDER_CLASS' => Basket::getDefaultProviderName(),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function buildShipments(?DtoCollection $collection, ?Order $order): array
	{
		$columnMap = AbstractOrderMapper::forDto(ShipmentDto::class)->getColumnMap();

		$shipments = [];
		foreach ($collection ?? [] as $shipment)
		{
			$element = DtoFieldsConverter::getInitializedValues($shipment);
			$id = ElementIdentity::existingEntityId($shipment);
			$item = self::withoutIdentifierOfANewElement(
				DtoFieldsConverter::toDomainFields($shipment, $columnMap, ['shipmentItems']),
				$id,
				$columnMap,
			);

			$current = self::findShipment($order, $id);
			$item += self::currentColumns(
				self::withoutColumns($current?->getFieldValues(), self::SHIPMENT_COLUMNS_KEPT_BY_THE_DOMAIN) ?? [],
				ShipmentDto::class,
			);

			// Positions nobody named do not reach the form, and the domain reads the absent key by
			// the shipment: an existing one keeps its positions, a new one takes what the basket has
			// not shipped yet. Any value in the key, `false` included, reads as positions passed and
			// leaves a new shipment without goods; an empty list takes the positions away.
			if (array_key_exists('shipmentItems', $element))
			{
				$item['PRODUCT'] = self::buildShipmentItems($element['shipmentItems'], $current);
			}

			$shipments[] = $item;
		}

		return $shipments;
	}

	private static function findShipment(?Order $order, ?int $id): ?Shipment
	{
		$shipment = $id === null ? null : ElementLocator::find($order, 'shipments', $id);

		return $shipment instanceof Shipment ? $shipment : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildRequisiteLink(Dto $link, ?Order $order): array
	{
		$columnMap = AbstractOrderMapper::forDto(RequisiteLinkDto::class)->getColumnMap();

		// The link is the one part of the order addressed by no identifier of its own: it is a row
		// of the order, and the order names the only one there is. The row is written whole, so a
		// column nobody named is a column zeroed — hence the zeros behind the current row: an order
		// that never had a link still has to name every column the caller was free to leave out,
		// or the upsert of `crm` reads an undefined key. The branch is only ever reached with the
		// module installed: the write service refuses the field without it.
		return DtoFieldsConverter::toDomainFields($link, $columnMap)
			+ self::currentColumns(self::currentRequisiteLink($order), RequisiteLinkDto::class)
			+ self::zeroedColumns($link, $columnMap);
	}

	/**
	 * A link set to null is a link to nothing. The builder of `crm` only adds the columns it is
	 * given to the row the order holds (`Crm\Order\Order::setRequisiteLink()`), so an empty form
	 * would leave the row as it is: the row is cleared by naming every column zero, which is how
	 * `crm` itself writes "no requisite" (`Requisite\EntityLink::unregisterByRequisite()`).
	 *
	 * An order with nothing to clear — a create, or a row that already names nothing — gets no
	 * write at all: the builder of `crm` refuses a zero requisite to an order with clients, and a
	 * request asking for what the order already is must not be refused for it.
	 *
	 * @return array<string, int>|null null when there is nothing to write
	 */
	private static function clearedRequisiteLink(?Order $order): ?array
	{
		$zeroed = self::zeroedColumns(
			RequisiteLinkDto::create(),
			AbstractOrderMapper::forDto(RequisiteLinkDto::class)->getColumnMap(),
		);
		$current = array_intersect_key(self::currentRequisiteLink($order), $zeroed);

		return array_filter($current) === [] ? null : $zeroed;
	}

	/**
	 * The columns of a whole-row write nobody is obliged to answer: a column the type declares
	 * required at creation is always named by the caller or filled from the row it already has.
	 *
	 * @param array<string, string> $columnMap
	 * @return array<string, int>
	 */
	private static function zeroedColumns(Dto $dto, array $columnMap): array
	{
		$columns = [];
		foreach ($dto->getFields() as $field)
		{
			$column = $columnMap[$field->getPropertyName()] ?? null;
			if ($column !== null && !CreationFieldsValidator::isRequiredIn($field, RequiredGroup::Add->value))
			{
				$columns[$column] = 0;
			}
		}

		return $columns;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function currentRequisiteLink(?Order $order): array
	{
		$link = $order !== null && method_exists($order, 'getRequisiteLink')
			? $order->getRequisiteLink()
			: [];

		return is_array($link) ? $link : [];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function buildShipmentItems(?DtoCollection $collection, ?Shipment $shipment): array
	{
		$items = [];
		foreach ($collection ?? [] as $shipmentItem)
		{
			$id = ElementIdentity::existingEntityId($shipmentItem);
			$current = $id === null ? null : ElementLocator::find($shipment, 'shipmentItems', $id);
			$element = array_replace(
				DtoFieldsConverter::getInitializedValues($shipmentItem),
				ExplicitNullSupport::emptiedValues($shipmentItem, $current),
			);
			$item = [];

			// The position of the basket a new shipment position stands on is an address as much as
			// the identifier above it, so it is read the same way: a zero names no position, and
			// the presence of the key alone used to be enough to let one through.
			$basketId = IdentifierSupport::readPositive($element['basketId'] ?? null);
			if ($id !== null)
			{
				$item['ORDER_DELIVERY_BASKET_ID'] = $id;
			}
			elseif ($basketId !== null)
			{
				$item['BASKET_CODE'] = $basketId;
			}

			if (isset($element['xmlId']))
			{
				$item['XML_ID'] = $element['xmlId'];
			}

			if (array_key_exists('quantity', $element))
			{
				$item['AMOUNT'] = $element['quantity'];
			}

			$item += self::currentColumns(
				$current?->getFieldValues() ?? [],
				ShipmentItemDto::class,
				self::SHIPMENT_ITEM_COLUMNS,
			);

			// The domain compares the quantity with zero without looking whether it was passed, so
			// the key is named even when nothing answers it: an element that names no quantity and
			// no existing position is refused for the quantity, not for the shape of the array.
			$item['AMOUNT'] ??= null;

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * An element of a collection, with the entity it names answering for the keys it left out.
	 *
	 * @param class-string $dtoClass
	 * @param (callable(int): (array<string, mixed>|null))|null $currentValues where the entity an
	 *        element names lives; a create names no entities at all
	 * @return list<array<string, mixed>>
	 */
	private static function buildElements(
		?DtoCollection $collection,
		string $dtoClass,
		?callable $currentValues = null,
	): array
	{
		$columnMap = AbstractOrderMapper::forDto($dtoClass)->getColumnMap();

		$elements = [];
		foreach ($collection ?? [] as $element)
		{
			$id = ElementIdentity::existingEntityId($element);
			$item = self::withoutIdentifierOfANewElement(
				DtoFieldsConverter::toDomainFields($element, $columnMap),
				$id,
				$columnMap,
			);

			if ($id !== null && $currentValues !== null)
			{
				$item += self::currentColumns($currentValues($id) ?? [], $dtoClass);
			}

			$elements[] = $item;
		}

		return $elements;
	}

	/**
	 * The values an element keeps when the client did not name them.
	 *
	 * Nothing here names a column: the pairs come from the mapper of the very same data type, so
	 * the element is filled with exactly what it is able to carry — no more, and never less than
	 * the type declares today. The domain column an element reads from and the key it writes into
	 * are the same everywhere but in the positions of a shipment, and there the two are joined by
	 * the property they both name.
	 *
	 * @param array<string, mixed> $current domain field values of the entity behind the element
	 * @param class-string $dtoClass
	 * @param array<string, string>|null $writeMap property => form key, when the form of the
	 *        element is not keyed by the columns of its entity
	 * @return array<string, mixed>
	 */
	private static function currentColumns(array $current, string $dtoClass, ?array $writeMap = null): array
	{
		if ($current === [])
		{
			return [];
		}

		$readMap = AbstractOrderMapper::forDto($dtoClass)->getColumnMap();

		$columns = [];
		foreach ($writeMap ?? $readMap as $property => $column)
		{
			$source = $readMap[$property] ?? null;
			if ($source !== null && array_key_exists($source, $current))
			{
				$columns[$column] = $current[$source];
			}
		}

		return $columns;
	}

	/**
	 * A new element carries no identifier into the form. An identifier of zero or below names no
	 * entity, so the layer takes the element for a new one ({@see ElementIdentity}); the domain,
	 * though, reads any identifier it is handed as an address and asks its collection for it, and
	 * the collection throws for a number below one (`EntityCollection::getItemById()`). A new
	 * position of the basket is keyed by a generated code for the same reason.
	 *
	 * @param array<string, mixed> $item
	 * @param int|null $id the entity the element names ({@see ElementIdentity::existingEntityId()})
	 * @param array<string, string> $columnMap
	 * @return array<string, mixed>
	 */
	private static function withoutIdentifierOfANewElement(array $item, ?int $id, array $columnMap): array
	{
		$column = $columnMap[self::IDENTIFIER] ?? null;
		if ($id === null && $column !== null)
		{
			unset($item[$column]);
		}

		return $item;
	}

	/**
	 * A return is carried out inside the `setFields()` the form is applied by: setting the flag asks
	 * the pay system for the refund at once (`Payment::onFieldModify()`), and the handler reads the
	 * comment of the return as its reason. The domain applies the keys in the order of the array and
	 * reorders only the payment flag (`Payment::onBeforeSetFields()`), so the return flag is moved
	 * behind everything the return is described with, the same way the domain moves the payment flag.
	 * The only exception is the author of the return: starting a return stamps it, and the refund does
	 * not read it, so an author the client names follows the flag and is not written over.
	 *
	 * @param array<string, mixed> $payment
	 * @return array<string, mixed>
	 */
	private static function withTheReturnLast(array $payment): array
	{
		if (array_key_exists('IS_RETURN', $payment))
		{
			foreach (['IS_RETURN', 'EMP_RETURN_ID'] as $column)
			{
				if (array_key_exists($column, $payment))
				{
					$value = $payment[$column];
					unset($payment[$column]);
					$payment[$column] = $value;
				}
			}
		}

		return $payment;
	}

	/**
	 * @param array<string, mixed>|null $values
	 * @param list<string> $columns
	 * @return array<string, mixed>|null
	 */
	private static function withoutColumns(?array $values, array $columns): ?array
	{
		return $values === null ? null : array_diff_key($values, array_flip($columns));
	}

	/**
	 * @param array<string, string> $columnMap
	 * @return list<array<string, mixed>>
	 */
	private static function mapElements(?DtoCollection $collection, array $columnMap): array
	{
		$elements = [];
		foreach ($collection ?? [] as $element)
		{
			$elements[] = DtoFieldsConverter::toDomainFields($element, $columnMap);
		}

		return $elements;
	}
}
