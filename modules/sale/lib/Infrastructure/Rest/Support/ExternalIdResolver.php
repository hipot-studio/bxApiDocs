<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\DeliveryStatus;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Infrastructure\Rest\Service\ServiceResult;
use Bitrix\Sale\Internals\PersonTypeTable;
use Bitrix\Sale\OrderStatus;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Registry;

/**
 * Turns the external identifiers of an exchange payload into internal ones.
 *
 * The lookups repeat the exchange loaders of V1 (`Rest\Synchronization\Loader\**`) without calling
 * them. What changes: a reference that was named and did not resolve is refused instead of being
 * quietly replaced by an exchange default, and the refusal carries every unresolved reference at
 * once. A default only ever fills in for a reference nobody named.
 *
 * The codes are read for the whole payload rather than one by one, and a code read so finds what
 * a lookup of that code alone finds ({@see matchCodes()}).
 */
final class ExternalIdResolver
{
	/** Field of the input naming the order itself. */
	private const ORDER_EXTERNAL_ID = 'xmlId';

	private const BOOK_PROPERTY = 'property';
	private const BOOK_PERSON_TYPE = 'personType';
	private const BOOK_ORDER_STATUS = 'orderStatus';
	private const BOOK_DELIVERY_STATUS = 'deliveryStatus';
	private const BOOK_PAY_SYSTEM = 'paySystem';
	private const BOOK_DELIVERY = 'delivery';

	/** @var list<Error> */
	private array $unresolved = [];

	/**
	 * Basket positions the external system numbers itself: its identifier => the internal one.
	 * Only a position that resolved to an existing one gets in, see {@see resolveShipmentItems}.
	 *
	 * @var array<string, int>
	 */
	private array $basketIdMap = [];

	/**
	 * Every basket position of the payload by the number the external system gave it: the path of
	 * the position and what it resolved to, so that a number carried by two positions is no address.
	 *
	 * @var array<string, list<array{path: string, id: int|null}>>
	 */
	private array $basketIdOwners = [];

	/**
	 * The external codes of the entities of the order being updated, looked up one kind at a time
	 * instead of one element at a time. The positions of a shipment are keyed by their shipment as
	 * well: the same code may belong to two shipments of one order.
	 *
	 * @var array<string, array<string, int>> entity kind => external code => internal identifier
	 */
	private array $existingIds = [];

	/**
	 * The product of every existing basket position the payload names, read by the same query as
	 * the codes of the positions: the identifier and the code it was saved with.
	 *
	 * @var array<int, array{productId: int, productXmlId: string}> position identifier => its product
	 */
	private array $positionProducts = [];

	/**
	 * The exchange defaults, the catalogue information blocks and the product codes already looked
	 * up. None of the three changes inside one call, while `ensureConfigured()` and every element of
	 * the payload ask for them again and again.
	 *
	 * They live for one call and no longer. The resolver is a service of the container, which keeps
	 * one instance for the whole process: a batch runs many calls in it, a worker of deferred batches
	 * runs the calls of different users, and the products are looked up with the permissions of the
	 * user. A call opens with `ensureConfigured()`, which forgets the defaults, and the payload is
	 * read by `resolve()`, which forgets everything it looks up itself.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults = [];

	/** @var list<int>|null */
	private ?array $catalogIblockIds = null;

	private ?bool $catalogAvailable = null;

	/** @var array<string, int|null> external product code => internal identifier */
	private array $productIds = [];

	/**
	 * The reference books the payload points at by external code, looked up one book at a time
	 * instead of one element at a time. A book is filled before the walk that needs it, from the
	 * codes the payload names; a code that answered nothing is remembered as such, or a payload
	 * naming a missing pay system in every payment would query once per payment again.
	 *
	 * The books are read once per call: they cannot change inside one, and the next call may name a
	 * record created in between.
	 *
	 * @var array<string, array<string, int|string|null>> book => external code => identifier
	 */
	private array $referenceIds = [];

	/**
	 * The exchange has no meaningful defaults until every reference book it substitutes from
	 * points at a record that exists.
	 *
	 * Repeats `Synchronization\Manager::checkDefaultSettings()` by intent, not by call: that
	 * method counts an ORM query result with `count()` and raises a TypeError on this platform,
	 * so it is unusable. Its catalogue check is deliberately left out: an active catalogue
	 * information block is not an exchange setting, and an order referencing no catalogue product
	 * has no reason to be refused over it.
	 */
	public function ensureConfigured(): Result
	{
		$this->defaults = [];

		$errors = [];

		$siteId = (string)Option::get('sale', 'config_external_default_site_id');
		if ($siteId === '' || SiteTable::getRow(['filter' => ['=LID' => $siteId]]) === null)
		{
			$errors[] = $this->settingError('site');
		}

		if ($this->getDefaultPersonTypeId() === null)
		{
			$errors[] = $this->settingError('person type');
		}

		if ($this->getDefaultOrderStatusId() === null)
		{
			$errors[] = $this->settingError('order status');
		}

		if ($this->getDefaultDeliveryStatusId() === null)
		{
			$errors[] = $this->settingError('delivery status');
		}

		if ($this->getDefaultPaySystemId() === null)
		{
			$errors[] = $this->settingError('pay system');
		}

		if ($this->getDefaultDeliveryId() === null)
		{
			$errors[] = $this->settingError('delivery service');
		}

		return $errors === [] ? ServiceResult::ok() : ServiceResult::validation($errors);
	}

	/**
	 * The shape of the payload is answered for before anything is read out of it, by the same
	 * check the ordinary write path runs ({@see FieldShapeSupport}). That is what lets the walks
	 * below treat an element as an array: a non-structural element used to reach them and raise a
	 * raw error, turning a mistake of the accounting system into a failure of the portal.
	 *
	 * @param array<string, mixed> $fields exchange payload keyed by data type properties
	 * @return Result data: `fields` is the payload with internal identifiers, `orderId` is the
	 *                order to update, or 0 when a new one is to be created
	 * @throws \Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException
	 */
	public function resolve(array $fields): Result
	{
		FieldShapeSupport::ensureShape($fields, OrderDto::class);

		$this->unresolved = [];
		$this->basketIdMap = [];
		$this->basketIdOwners = [];
		$this->existingIds = [];
		$this->positionProducts = [];
		$this->referenceIds = [];
		$this->productIds = [];
		$this->catalogIblockIds = null;
		$this->catalogAvailable = null;

		$externalId = $this->readExternalId($fields, self::ORDER_EXTERNAL_ID);
		if ($externalId === null)
		{
			// Three shapes of empty, one answer. A lookup by an empty value returns the oldest
			// order of the portal and the exchange overwrites an order it was never asked about;
			// V1 compares with the empty string only, so a null slips past it.
			return ServiceResult::validation(
				new Error('Field "' . self::ORDER_EXTERNAL_ID . '" is required and must not be empty'),
			);
		}

		// The lookup runs on the trimmed value, so the write has to run on it too: searched by "A"
		// and stored as " A ", the same document creates a new order on every repeat.
		$fields[self::ORDER_EXTERNAL_ID] = $externalId;

		$orderId = (int)($this->findOrderIdByExternalId($externalId) ?? 0);

		$fields = $this->resolveOrderFields($fields, $orderId);
		$fields = $this->resolvePropertyValues($fields);
		$fields = $this->resolveBasketItems($fields, $orderId);
		$fields = $this->resolvePayments($fields, $orderId);
		$fields = $this->resolveShipments($fields, $orderId);

		if ($this->unresolved !== [])
		{
			return ServiceResult::validation($this->unresolved);
		}

		return ServiceResult::ok(['fields' => $fields, 'orderId' => $orderId]);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function resolveOrderFields(array $fields, int $orderId): array
	{
		$fields['statusId'] = $this->resolveReference(
			$fields,
			'statusXmlId',
			fn(string $xmlId): ?string => $this->findOrderStatusId($xmlId),
			fn(): ?string => $this->getDefaultOrderStatusId(),
			'statusXmlId',
		);
		unset($fields['statusXmlId']);

		if ($orderId > 0)
		{
			// An existing order keeps its person type, owner and site: the exchange may not move
			// an order between them, and the write service reads all three from the database.
			unset($fields['personTypeXmlId'], $fields['personTypeId'], $fields['lid'], $fields['userId']);

			return $fields;
		}

		$fields['personTypeId'] = $this->resolveReference(
			$fields,
			'personTypeXmlId',
			fn(string $xmlId): ?int => $this->findPersonTypeId($xmlId),
			fn(): ?int => $this->getDefaultPersonTypeId(),
			'personTypeXmlId',
		);
		unset($fields['personTypeXmlId']);

		$fields['lid'] = (string)Option::get('sale', 'config_external_default_site_id');
		$fields['userId'] = (int)\CSaleUser::GetAnonymousUserID();

		return $fields;
	}

	/**
	 * A property value is addressed by its property and by nothing else, so an unresolved property
	 * leaves the value with no address at all: there is no exchange default to fall back on.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function resolvePropertyValues(array $fields): array
	{
		if (!is_array($fields['propertyValues'] ?? null))
		{
			return $fields;
		}

		$this->prefetchBook(
			self::BOOK_PROPERTY,
			$this->collectExternalIds($fields['propertyValues'], 'orderPropsXmlId'),
		);

		foreach ($fields['propertyValues'] as $index => $element)
		{
			$path = 'propertyValues[' . $index . ']';
			$externalId = $this->readExternalId($element, 'orderPropsXmlId');

			$element['orderPropsId'] = $externalId === null
				? $this->reportUnresolved($path . '.orderPropsXmlId', $externalId)
				: $this->requireResolved($path . '.orderPropsXmlId', $externalId, $this->findPropertyId($externalId));

			unset($element['orderPropsXmlId'], $element['id']);
			$fields['propertyValues'][$index] = $element;
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function resolveBasketItems(array $fields, int $orderId): array
	{
		if (!is_array($fields['basketItems'] ?? null))
		{
			return $fields;
		}

		$this->prefetchProductIds($this->collectExternalIds($fields['basketItems'], 'productXmlId'));
		$positions = $this->prefetchExistingIds(
			Registry::ENTITY_BASKET,
			$this->collectExternalIds($fields['basketItems']),
			['=ORDER_ID' => $orderId],
			$orderId,
			['PRODUCT_ID', 'PRODUCT_XML_ID'],
		);
		foreach ($positions as $position)
		{
			$this->positionProducts[(int)$position['ID']] = [
				'productId' => (int)$position['PRODUCT_ID'],
				'productXmlId' => trim((string)$position['PRODUCT_XML_ID']),
			];
		}

		foreach ($fields['basketItems'] as $index => $element)
		{
			$path = 'basketItems[' . $index . ']';

			// The identifier the external system knows the position by; it addresses nothing in
			// this portal and only serves the shipment positions of the same payload.
			$externalBasketId = isset($element['id']) ? (string)$element['id'] : null;
			unset($element['id']);

			$externalId = $this->readExternalId($element, 'xmlId');
			$basketItemId = $externalId === null ? null : $this->takeExistingId(Registry::ENTITY_BASKET, $externalId);
			if ($externalId !== null)
			{
				$element['xmlId'] = $externalId;
			}

			if ($externalBasketId !== null && $externalBasketId !== '')
			{
				$this->basketIdOwners[$externalBasketId][] = ['path' => $path . '.id', 'id' => $basketItemId];
			}

			if ($basketItemId !== null)
			{
				$element['id'] = $basketItemId;

				if ($externalBasketId !== null && $externalBasketId !== '')
				{
					$this->basketIdMap[$externalBasketId] = $basketItemId;
				}

				// The product of a position is fixed at creation, and an existing position already
				// carries it, so its external code is not required here, and it is not passed on: an
				// existing position may not carry it ({@see CreationFieldsValidator}). The ordinary
				// repeat of an exchange — a position sent as `xmlId`, quantity and price — needs
				// nothing more. A code that is sent has to name the product the position holds.
				$productXmlId = $this->readExternalId($element, 'productXmlId');
				if ($productXmlId !== null && !$this->namesProductOfPosition($basketItemId, $productXmlId))
				{
					$this->reportAnotherProduct($path, 'basketItems.' . $index, $productXmlId, $basketItemId);
				}
				unset($element['productXmlId']);
			}
			else
			{
				$productXmlId = $this->readExternalId($element, 'productXmlId');
				$element['productId'] = $productXmlId === null
					? $this->reportUnresolved($path . '.productXmlId', $productXmlId)
					: $this->requireResolved(
						$path . '.productXmlId',
						$productXmlId,
						$this->findProductId($productXmlId),
					);
			}

			$fields['basketItems'][$index] = $element;
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function resolvePayments(array $fields, int $orderId): array
	{
		if (!is_array($fields['payments'] ?? null))
		{
			return $fields;
		}

		$this->prefetchExistingIds(
			Registry::ENTITY_PAYMENT_COLLECTION,
			$this->collectExternalIds($fields['payments']),
			['=ORDER_ID' => $orderId],
			$orderId,
		);
		$this->prefetchBook(
			self::BOOK_PAY_SYSTEM,
			$this->collectExternalIds($fields['payments'], 'paySystemXmlId'),
		);

		foreach ($fields['payments'] as $index => $element)
		{
			$path = 'payments[' . $index . ']';

			unset($element['id']);
			$externalId = $this->readExternalId($element, 'xmlId');
			$paymentId = $externalId === null
				? null
				: $this->takeExistingId(Registry::ENTITY_PAYMENT_COLLECTION, $externalId);
			if ($externalId !== null)
			{
				$element['xmlId'] = $externalId;
			}

			if ($paymentId !== null)
			{
				$element['id'] = $paymentId;
			}

			$element['paySystemId'] = $this->resolveReference(
				$element,
				'paySystemXmlId',
				fn(string $xmlId): ?int => $this->findPaySystemId($xmlId),
				fn(): ?int => $this->getDefaultPaySystemId(),
				$path . '.paySystemXmlId',
			);
			unset($element['paySystemXmlId']);

			$fields['payments'][$index] = $element;
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function resolveShipments(array $fields, int $orderId): array
	{
		if (!is_array($fields['shipments'] ?? null))
		{
			return $fields;
		}

		$this->prefetchExistingIds(
			Registry::ENTITY_SHIPMENT_COLLECTION,
			$this->collectExternalIds($fields['shipments']),
			['=ORDER_ID' => $orderId],
			$orderId,
		);

		$this->prefetchBook(
			self::BOOK_DELIVERY_STATUS,
			$this->collectExternalIds($fields['shipments'], 'statusXmlId'),
		);
		$this->prefetchBook(
			self::BOOK_DELIVERY,
			$this->collectExternalIds($fields['shipments'], 'deliveryXmlId'),
		);

		$shipmentIds = $this->resolveShipmentIds($fields['shipments']);
		$this->prefetchShipmentItemIds($fields['shipments'], $shipmentIds);

		foreach ($fields['shipments'] as $index => $element)
		{
			$path = 'shipments[' . $index . ']';

			unset($element['id']);
			$externalId = $this->readExternalId($element, 'xmlId');
			if ($externalId !== null)
			{
				$element['xmlId'] = $externalId;
			}

			$shipmentId = $shipmentIds[$index] ?? null;
			if ($shipmentId !== null)
			{
				$element['id'] = $shipmentId;
			}

			$element['deliveryId'] = $this->resolveReference(
				$element,
				'deliveryXmlId',
				fn(string $xmlId): ?int => $this->findDeliveryId($xmlId),
				fn(): ?int => $this->getDefaultDeliveryId(),
				$path . '.deliveryXmlId',
			);
			unset($element['deliveryXmlId']);

			$element['statusId'] = $this->resolveReference(
				$element,
				'statusXmlId',
				fn(string $xmlId): ?string => $this->findDeliveryStatusId($xmlId),
				fn(): ?string => $this->getDefaultDeliveryStatusId(),
				$path . '.statusXmlId',
			);
			unset($element['statusXmlId']);

			$element = $this->resolveShipmentItems($element, $path, (int)($shipmentId ?? 0));

			$fields['shipments'][$index] = $element;
		}

		return $fields;
	}

	/**
	 * The shipments of the payload answered before the walk, so that the positions of every
	 * shipment can be looked up together as well.
	 *
	 * @param array<int|string, mixed> $elements
	 * @return array<int|string, int|null>
	 */
	private function resolveShipmentIds(array $elements): array
	{
		$shipmentIds = [];
		foreach ($elements as $index => $element)
		{
			$externalId = $this->readExternalId($element, 'xmlId');
			$shipmentIds[$index] = $externalId === null
				? null
				: $this->takeExistingId(Registry::ENTITY_SHIPMENT_COLLECTION, $externalId);
		}

		return $shipmentIds;
	}

	/**
	 * The positions of every shipment of the payload in one query. They are keyed by their shipment
	 * as well: one order may carry the same position code in two shipments.
	 *
	 * @param array<int|string, mixed> $elements
	 * @param array<int|string, int|null> $shipmentIds
	 */
	private function prefetchShipmentItemIds(array $elements, array $shipmentIds): void
	{
		$this->existingIds[Registry::ENTITY_SHIPMENT_ITEM_COLLECTION] = [];

		/** @var array<string, array{int, string}> $asked key of a position => its shipment and code */
		$asked = [];
		foreach ($elements as $index => $element)
		{
			$shipmentId = $shipmentIds[$index] ?? null;
			if ($shipmentId === null || !is_array($element['shipmentItems'] ?? null))
			{
				continue;
			}

			foreach ($this->collectExternalIds($element['shipmentItems']) as $externalId)
			{
				$asked[self::shipmentItemKey($shipmentId, $externalId)] = [$shipmentId, $externalId];
			}
		}

		if ($asked === [])
		{
			return;
		}

		$externalIds = array_values(array_unique(array_column($asked, 1)));
		$rows = $this->loadExistingRows(
			Registry::ENTITY_SHIPMENT_ITEM_COLLECTION,
			['ID', 'XML_ID', 'ORDER_DELIVERY_ID'],
			[
				'@XML_ID' => $externalIds,
				'@ORDER_DELIVERY_ID' => array_values(array_unique(array_column($asked, 0))),
			],
		);

		$found = [];
		/** @var array<int, list<string>> $storedCodes shipment => codes of its positions as stored */
		$storedCodes = [];
		foreach ($rows as $row)
		{
			$found[self::shipmentItemKey((int)$row['ORDER_DELIVERY_ID'], (string)$row['XML_ID'])] ??= (int)$row['ID'];
			$storedCodes[(int)$row['ORDER_DELIVERY_ID']][] = (string)$row['XML_ID'];
		}

		// The positions are keyed by their shipment, so a code is read against the codes stored in
		// its own shipment, by the rule of every other code ({@see matchCodes()}).
		$foldedBeyondTheCase = self::isFoldedBeyondTheCase(
			array_merge([], ...array_values($storedCodes)),
			$externalIds,
		);
		$storedByFold = array_map(self::groupByFold(...), $storedCodes);

		$map = [];
		foreach ($asked as $key => [$shipmentId, $externalId])
		{
			$reading = self::readCode(
				$externalId,
				$storedByFold[$shipmentId][self::fold($externalId)] ?? [],
				$foldedBeyondTheCase,
			);
			$id = match ($reading)
			{
				true => $found[$key],
				false => null,
				null => $this->findId(
					Registry::ENTITY_SHIPMENT_ITEM_COLLECTION,
					$externalId,
					['=ORDER_DELIVERY_ID' => $shipmentId],
				),
			};

			if ($id !== null)
			{
				$map[$key] = $id;
			}
		}

		$this->existingIds[Registry::ENTITY_SHIPMENT_ITEM_COLLECTION] = $map;
	}

	/**
	 * The one reference pointing inside the payload itself: a shipment position names the basket
	 * position by the identifier the external system gave it. It can only be answered with the
	 * internal identifier of an existing position: a position being created has none yet, and the
	 * data type declares the field an integer, so the synthetic code V1 puts there cannot be
	 * expressed. A shipment whose positions cannot be addressed is refused rather than assembled
	 * from a guess; a shipment that passes no positions at all is filled by the domain from the
	 * basket, which is the shape a brand new order uses.
	 *
	 * @param array<string, mixed> $element
	 * @return array<string, mixed>
	 */
	private function resolveShipmentItems(array $element, string $path, int $shipmentId): array
	{
		if (!is_array($element['shipmentItems'] ?? null))
		{
			return $element;
		}

		foreach ($element['shipmentItems'] as $index => $item)
		{
			$itemPath = $path . '.shipmentItems[' . $index . ']';

			unset($item['id']);
			$externalId = $this->readExternalId($item, 'xmlId');
			if ($externalId !== null)
			{
				$item['xmlId'] = $externalId;
			}

			$shipmentItemId = $externalId === null || $shipmentId <= 0
				? null
				: $this->takeExistingId(
					Registry::ENTITY_SHIPMENT_ITEM_COLLECTION,
					self::shipmentItemKey($shipmentId, $externalId),
				);

			if ($shipmentItemId !== null)
			{
				$item['id'] = $shipmentItemId;
			}

			$externalBasketId = isset($item['basketId']) ? (string)$item['basketId'] : null;
			$item['basketId'] = match (true)
			{
				$externalBasketId === null => $this->reportUnresolved($itemPath . '.basketId', null),
				$this->isAmbiguousBasketId($externalBasketId) => $this->reportAmbiguousBasketId(
					$itemPath . '.basketId',
					$externalBasketId,
				),
				default => $this->requireResolved(
					$itemPath . '.basketId',
					$externalBasketId,
					$this->basketIdMap[$externalBasketId] ?? null,
				),
			};

			$element['shipmentItems'][$index] = $item;
		}

		return $element;
	}

	/**
	 * A reference nobody named falls back to the exchange default; a reference that was named and
	 * did not resolve is collected and refused.
	 *
	 * @template T
	 * @param array<string, mixed> $source
	 * @param callable(string): (T|null) $lookup
	 * @param callable(): (T|null) $default
	 * @return T|null
	 */
	private function resolveReference(
		array $source,
		string $property,
		callable $lookup,
		callable $default,
		string $path,
	): mixed
	{
		if (!array_key_exists($property, $source))
		{
			return $default();
		}

		$externalId = $this->readExternalId($source, $property);
		if ($externalId === null)
		{
			return $this->reportUnresolved($path, $source[$property]);
		}

		return $this->requireResolved($path, $externalId, $lookup($externalId));
	}

	/**
	 * @template T
	 * @param T|null $resolved
	 * @return T|null
	 */
	private function requireResolved(string $path, string $externalId, mixed $resolved): mixed
	{
		if ($resolved === null)
		{
			return $this->reportUnresolved($path, $externalId);
		}

		return $resolved;
	}

	/**
	 * A number two basket positions carry addresses neither of them: the last one used to take it
	 * in silence. One position named twice is one address, and its repeat is refused further on
	 * ({@see ElementLocator}).
	 */
	private function isAmbiguousBasketId(string $externalBasketId): bool
	{
		$ids = array_column($this->basketIdOwners[$externalBasketId] ?? [], 'id');

		return count($ids) > 1 && (in_array(null, $ids, true) || count(array_unique($ids)) > 1);
	}

	private function reportAmbiguousBasketId(string $path, string $externalBasketId): null
	{
		$this->unresolved[] = new Error(
			'Ambiguous external identifier: ' . $path . ' = ' . var_export($externalBasketId, true)
			. ', which ' . implode(' and ', array_column($this->basketIdOwners[$externalBasketId], 'path'))
			. ' carry alike',
		);

		return null;
	}

	private function reportUnresolved(string $path, mixed $value): null
	{
		$this->unresolved[] = new Error(
			'Unresolved external identifier: ' . $path . ' = ' . var_export($value, true),
		);

		return null;
	}

	/**
	 * The same product is the one the position holds, whatever spelling of its code the catalogue
	 * answers it by, or the code the position was saved with: the order as it is read sends that
	 * one back, also for a product the catalogue no longer gives out.
	 */
	private function namesProductOfPosition(int $basketItemId, string $productXmlId): bool
	{
		$product = $this->positionProducts[$basketItemId] ?? null;
		if ($product === null)
		{
			return false;
		}

		return $productXmlId === $product['productXmlId']
			|| $this->findProductId($productXmlId) === $product['productId'];
	}

	/**
	 * The product of a position is fixed at creation, so a change naming another one is refused as
	 * the ordinary change refuses it, by the path of the field ({@see CreationFieldsValidator}).
	 */
	private function reportAnotherProduct(
		string $path,
		string $elementPath,
		string $productXmlId,
		int $basketItemId,
	): void
	{
		$this->unresolved[] = new Error(
			'Field "' . $path . '.productXmlId" = ' . var_export($productXmlId, true) . ' names another product'
			. ' than product ' . ($this->positionProducts[$basketItemId]['productId'] ?? 0)
			. ' the existing position ' . $basketItemId . ' holds: the product of a position is fixed at creation',
			$elementPath . '.productXmlId',
		);
	}

	/**
	 * An external identifier is empty in three shapes at once: the key is absent, the value is
	 * null, the value is an empty string. None of them addresses anything.
	 *
	 * The cast below changes nothing and holds no second rule: `resolve()` runs the shape check of
	 * the layer over the whole payload first ({@see FieldShapeSupport}), so a code that is not a
	 * string never reaches here — a numeric code included, which the exchange passes as a string
	 * like every other call of V3. Without the check the cast would repair the value instead of
	 * refusing it: an array becoming the code `"Array"`, under which every broken document of the
	 * exchange lands on one and the same order, and a number becoming its string, which the write
	 * then stores although the platform refuses a number in a text field.
	 *
	 * @param array<string, mixed> $source
	 */
	private function readExternalId(array $source, string $property): ?string
	{
		if (!array_key_exists($property, $source) || $source[$property] === null)
		{
			return null;
		}

		$value = trim((string)$source[$property]);

		return $value === '' ? null : $value;
	}

	private function settingError(string $setting): Error
	{
		return new Error('The exchange is not configured: default ' . $setting . ' is missing or invalid');
	}

	/** The exchange addresses an order by its external identifier, deletion included. */
	public function findOrderIdByExternalId(string $xmlId): ?int
	{
		return $this->findId(Registry::ENTITY_ORDER, $xmlId);
	}

	/**
	 * @param array<int|string, mixed> $elements
	 * @return list<string>
	 */
	private function collectExternalIds(array $elements, string $property = 'xmlId'): array
	{
		$externalIds = [];
		foreach ($elements as $element)
		{
			$externalId = $this->readExternalId($element, $property);
			if ($externalId !== null)
			{
				$externalIds[] = $externalId;
			}
		}

		return $externalIds;
	}

	/**
	 * One query per kind of nested entity instead of one per element, by the same route the
	 * catalogue products already take. There is nothing to look up while no order was found: every
	 * element of the payload is then being created.
	 *
	 * @param list<string> $externalIds
	 * @param array<string, mixed> $filter
	 * @param list<string> $columns read along with the code
	 * @return list<array<string, mixed>> the rows read
	 */
	private function prefetchExistingIds(
		string $entity,
		array $externalIds,
		array $filter,
		int $orderId,
		array $columns = [],
	): array
	{
		$this->existingIds[$entity] = [];

		$externalIds = array_values(array_unique($externalIds));
		if ($orderId <= 0 || $externalIds === [])
		{
			return [];
		}

		$found = [];
		$rows = $this->loadExistingRows(
			$entity,
			array_merge(['ID', 'XML_ID'], $columns),
			['@XML_ID' => $externalIds] + $filter,
		);
		foreach ($rows as $row)
		{
			$found[(string)$row['XML_ID']] ??= (int)$row['ID'];
		}

		$this->existingIds[$entity] = self::matchCodes(
			$externalIds,
			$found,
			fn(string $externalId): ?int => $this->findId($entity, $externalId, $filter),
		);

		return $rows;
	}

	/**
	 * Pairs the codes asked for with the rows the database answered them with, so that a code finds
	 * what a lookup of that code alone finds: the first row the database matches it with (`=XML_ID`,
	 * the least identifier, as V1 looks a code up).
	 *
	 * The database compares a code by its collation — on MySQL without regard to the case and to
	 * trailing spaces — and gives the code back as it is stored. Read by the exact key, a code
	 * written in another case missed a record that is there, and a code stored twice, in two cases,
	 * was answered by the row of its own case instead of the first one: the answer depended on the
	 * other codes of the payload.
	 *
	 * A single code asked is answered by every row that came back, so the first of them is its own
	 * lookup. Of several codes, one is read off the rows by itself only where no other stored code
	 * differs from it in the case alone ({@see readCode()}): the one row of its own case is then its
	 * first row, and a code without a row names nothing. Any other code is asked of the database
	 * once more, alone. The ordinary payload — every code in the case it is stored in, a new element
	 * naming nothing — costs no query beyond the reading of its kind.
	 *
	 * @template T of int|string
	 * @param list<string> $codes the codes asked for
	 * @param array<string, T> $found code as stored => the identifier of its first row
	 * @param callable(string): (T|null) $findOne the lookup of a single code
	 * @return array<string, T> code asked for => identifier; a code that names nothing is absent
	 */
	private static function matchCodes(array $codes, array $found, callable $findOne): array
	{
		if ($found === [])
		{
			return [];
		}

		if (count($codes) === 1)
		{
			return [(string)reset($codes) => reset($found)];
		}

		$storedCodes = array_map('strval', array_keys($found));
		$foldedBeyondTheCase = self::isFoldedBeyondTheCase($storedCodes, $codes);
		$storedByFold = self::groupByFold($storedCodes);

		$matched = [];
		foreach ($codes as $code)
		{
			$id = match (self::readCode($code, $storedByFold[self::fold($code)] ?? [], $foldedBeyondTheCase))
			{
				true => $found[$code],
				false => null,
				null => $findOne($code),
			};
			if ($id !== null)
			{
				$matched[$code] = $id;
			}
		}

		return $matched;
	}

	/**
	 * How a code asked among others is answered.
	 *
	 * @param array<string, true> $twins the stored codes that differ from the code in the case alone,
	 *        the code itself included
	 * @param bool $foldedBeyondTheCase the database matched a stored code with an asked one that differs
	 *        from it in more than the case ({@see isFoldedBeyondTheCase()})
	 * @return bool|null true: by the row of the code itself; false: it names nothing; null: by a lookup
	 *         of the code alone
	 */
	private static function readCode(string $code, array $twins, bool $foldedBeyondTheCase): ?bool
	{
		if ($foldedBeyondTheCase)
		{
			return null;
		}

		if ($twins === [])
		{
			return false;
		}

		return count($twins) === 1 && isset($twins[$code]) ? true : null;
	}

	/**
	 * Whether the database answered with a stored code that no asked code matches by the case alone:
	 * its collation folds more than the case and trailing spaces. Codes outside printable ASCII also require
	 * database comparisons: PHP case folding cannot reproduce the column collation.
	 *
	 * @param list<string> $storedCodes
	 * @param list<string> $codes
	 */
	private static function isFoldedBeyondTheCase(array $storedCodes, array $codes): bool
	{
		foreach (array_merge($storedCodes, $codes) as $code)
		{
			if (preg_match('/[^\x20-\x7E]/', $code) === 1)
			{
				return true;
			}
		}

		return array_diff_key(self::groupByFold($storedCodes), self::groupByFold($codes)) !== [];
	}

	/**
	 * @param list<string> $codes
	 * @return array<string, array<string, true>> folded code => the codes folded into it
	 */
	private static function groupByFold(array $codes): array
	{
		$groups = [];
		foreach ($codes as $code)
		{
			$groups[self::fold($code)][$code] = true;
		}

		return $groups;
	}

	private static function fold(string $code): string
	{
		return mb_strtolower(rtrim($code, ' '));
	}

	private function takeExistingId(string $entity, string $key): ?int
	{
		return $this->existingIds[$entity][$key] ?? null;
	}

	/**
	 * @param list<string> $select
	 * @param array<string, mixed> $filter
	 * @return list<array<string, mixed>>
	 */
	private function loadExistingRows(string $entity, array $select, array $filter): array
	{
		$entityClass = $this->getEntityClass($entity);

		return $entityClass::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	/**
	 * @return class-string
	 */
	private function getEntityClass(string $entity): string
	{
		/** @var class-string $entityClass */
		$entityClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->get($entity);

		return $entityClass;
	}

	private static function shipmentItemKey(int $shipmentId, string $externalId): string
	{
		return $shipmentId . "\n" . $externalId;
	}

	private function findPropertyId(string $xmlId): ?int
	{
		$id = $this->findInBook(self::BOOK_PROPERTY, $xmlId);

		return $id === null ? null : (int)$id;
	}

	private function findOrderStatusId(string $xmlId): ?string
	{
		$id = $this->findInBook(self::BOOK_ORDER_STATUS, $xmlId);

		return $id === null ? null : (string)$id;
	}

	private function findDeliveryStatusId(string $xmlId): ?string
	{
		$id = $this->findInBook(self::BOOK_DELIVERY_STATUS, $xmlId);

		return $id === null ? null : (string)$id;
	}

	/**
	 * The one place a reference book is read by external code. A code the walk answered for is
	 * already in the book; a code nobody collected still costs one query and never a query per
	 * element, because the answer — a miss included — stays in the book afterwards.
	 */
	private function findInBook(string $book, string $xmlId): int|string|null
	{
		if (!array_key_exists($xmlId, $this->referenceIds[$book] ?? []))
		{
			$this->prefetchBook($book, [$xmlId]);
		}

		return $this->referenceIds[$book][$xmlId] ?? null;
	}

	/**
	 * @param list<string> $codes
	 */
	private function prefetchBook(string $book, array $codes): void
	{
		$known = $this->referenceIds[$book] ?? [];
		$codes = array_values(array_unique(array_filter(
			$codes,
			static fn(string $code): bool => !array_key_exists($code, $known),
		)));

		if ($codes === [])
		{
			return;
		}

		$found = $this->loadBook($book, $codes);
		foreach ($codes as $code)
		{
			$this->referenceIds[$book][$code] = $found[$code] ?? null;
		}
	}

	/**
	 * @param list<string> $codes
	 * @return array<string, int|string> external code => the identifier of the first matching row
	 *         ({@see matchCodes()})
	 */
	private function loadBook(string $book, array $codes): array
	{
		if ($book === self::BOOK_DELIVERY)
		{
			// The delivery services have no listing by external identifier: V1 walks the active
			// ones, and so does this. The walk answers every code at once all the same, and by the
			// exact code, as V1 compares it.
			$found = [];
			foreach (Delivery\Services\Manager::getActiveList() as $service)
			{
				$found[(string)($service['XML_ID'] ?? '')] ??= (int)$service['ID'];
			}

			return $found;
		}

		return self::matchCodes(
			$codes,
			$this->collectBookRows($this->loadBookRows($book, $codes)),
			fn(string $code): int|string|null => $this->loadBook($book, [$code])[$code] ?? null,
		);
	}

	/**
	 * @param list<string> $codes
	 * @return \Bitrix\Main\DB\Result|\CDBResult
	 */
	private function loadBookRows(string $book, array $codes): mixed
	{
		$filter = ['@XML_ID' => $codes];
		$parameters = ['select' => ['ID', 'XML_ID'], 'order' => ['ID' => 'ASC']];

		return match ($book)
		{
			self::BOOK_PROPERTY => $this->getEntityClass(Registry::ENTITY_PROPERTY)::getList(
				$parameters + ['filter' => $filter + ['=ENTITY_REGISTRY_TYPE' => Registry::REGISTRY_TYPE_ORDER]],
			),
			self::BOOK_PERSON_TYPE => PersonTypeTable::getList($parameters + ['filter' => $filter]),
			self::BOOK_PAY_SYSTEM => PaySystem\Manager::getList(
				$parameters + ['filter' => $filter + ['=ENTITY_REGISTRY_TYPE' => Registry::REGISTRY_TYPE_ORDER]],
			),
			self::BOOK_ORDER_STATUS => OrderStatus::getList($parameters + ['filter' => $filter]),
			self::BOOK_DELIVERY_STATUS => DeliveryStatus::getList($parameters + ['filter' => $filter]),
		};
	}

	/**
	 * @param \Bitrix\Main\DB\Result|\CDBResult $rows
	 * @return array<string, int|string>
	 */
	private function collectBookRows(mixed $rows): array
	{
		$found = [];
		while ($row = $rows->fetch())
		{
			$found[(string)$row['XML_ID']] ??= $row['ID'];
		}

		return $found;
	}

	/**
	 * @param class-string<\Bitrix\Sale\StatusBase> $statusClass
	 * @param array<string, mixed> $filter
	 */
	private function findStatusId(string $statusClass, array $filter): ?string
	{
		$row = $statusClass::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (string)$row['ID'] : null;
	}

	private function findPersonTypeId(string $xmlId): ?int
	{
		$id = $this->findInBook(self::BOOK_PERSON_TYPE, $xmlId);

		return $id === null ? null : (int)$id;
	}

	/**
	 * @param array<string, mixed> $filter
	 */
	private function findPersonTypeIdByFilter(array $filter): ?int
	{
		$row = PersonTypeTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)$row['ID'] : null;
	}

	private function findPaySystemId(string $xmlId): ?int
	{
		$id = $this->findInBook(self::BOOK_PAY_SYSTEM, $xmlId);

		return $id === null ? null : (int)$id;
	}

	/**
	 * @param array<string, mixed> $filter
	 */
	private function findPaySystemIdByFilter(array $filter): ?int
	{
		$row = PaySystem\Manager::getList([
			'select' => ['ID'],
			'filter' => $filter + ['=ENTITY_REGISTRY_TYPE' => Registry::REGISTRY_TYPE_ORDER],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)$row['ID'] : null;
	}

	private function findDeliveryId(string $xmlId): ?int
	{
		$id = $this->findInBook(self::BOOK_DELIVERY, $xmlId);

		return $id === null ? null : (int)$id;
	}

	/**
	 * The external product code may carry the offer behind a `#`; V1 tries that part first and the
	 * whole code second. Only active elements of active catalogue information blocks take part,
	 * permissions included.
	 */
	private function findProductId(string $productXmlId): ?int
	{
		foreach (self::splitProductCode($productXmlId) as $code)
		{
			if (!array_key_exists($code, $this->productIds))
			{
				$this->productIds[$code] = $this->loadProductIds([$code])[$code] ?? null;
			}

			if ($this->productIds[$code] !== null)
			{
				return $this->productIds[$code];
			}
		}

		return null;
	}

	/**
	 * One query for the whole basket instead of up to two per position.
	 *
	 * @param list<string> $externalIds
	 */
	private function prefetchProductIds(array $externalIds): void
	{
		$codes = [];
		foreach ($externalIds as $externalId)
		{
			foreach (self::splitProductCode($externalId) as $code)
			{
				if (!array_key_exists($code, $this->productIds))
				{
					$codes[$code] = true;
				}
			}
		}

		if ($codes === [])
		{
			return;
		}

		// A numeric code used as a key comes back from it as a number.
		$codes = array_map('strval', array_keys($codes));
		$found = $this->loadProductIds($codes);
		foreach ($codes as $code)
		{
			$this->productIds[$code] = $found[$code] ?? null;
		}
	}

	/**
	 * @param list<string> $codes
	 * @return array<string, int> code => identifier of the first matching element ({@see matchCodes()})
	 */
	private function loadProductIds(array $codes): array
	{
		$iblockIds = $this->getCatalogIblockIds();
		if ($codes === [] || $iblockIds === [])
		{
			return [];
		}

		$rows = \CIBlockElement::GetList(
			[],
			['=XML_ID' => $codes, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y', 'IBLOCK_ID' => $iblockIds],
			false,
			false,
			['ID', 'XML_ID'],
		);

		$found = [];
		while ($row = $rows->Fetch())
		{
			$xmlId = (string)$row['XML_ID'];
			$found[$xmlId] ??= (int)$row['ID'];
		}

		return self::matchCodes(
			$codes,
			$found,
			fn(string $code): ?int => $this->loadProductIds([$code])[$code] ?? null,
		);
	}

	/**
	 * The offer is the second piece of the code, between the first `#` and the next one, as V1 cuts
	 * it (`Rest\Synchronization\Loader\Product::getCodeAfterDelimiter()`), not everything after the
	 * first `#`.
	 *
	 * @return list<string> the codes to try, in the order V1 tries them
	 */
	private static function splitProductCode(string $productXmlId): array
	{
		$pieces = explode('#', $productXmlId, 3);
		$codes = count($pieces) === 1
			? [$productXmlId]
			: [$pieces[1], $productXmlId];

		return array_values(array_filter($codes, static fn(string $code): bool => $code !== ''));
	}

	/**
	 * @return list<int>
	 */
	private function getCatalogIblockIds(): array
	{
		if ($this->catalogIblockIds !== null)
		{
			return $this->catalogIblockIds;
		}

		$this->catalogAvailable ??= Loader::includeModule('iblock') && Loader::includeModule('catalog');
		if (!$this->catalogAvailable)
		{
			return $this->catalogIblockIds = [];
		}

		$ids = [];
		$rows = \Bitrix\Catalog\CatalogIblockTable::getList([
			'select' => ['IBLOCK_ID'],
			'filter' => ['=IBLOCK.ACTIVE' => 'Y'],
		]);
		foreach ($rows as $row)
		{
			$ids[] = (int)$row['IBLOCK_ID'];
		}

		return $this->catalogIblockIds = $ids;
	}

	/**
	 * @param array<string, mixed> $filter
	 */
	private function findId(string $entity, string $xmlId, array $filter = []): ?int
	{
		$entityClass = $this->getEntityClass($entity);

		$row = $entityClass::getList([
			'select' => ['ID'],
			'filter' => ['=XML_ID' => $xmlId] + $filter,
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)$row['ID'] : null;
	}

	/**
	 * @template T
	 * @param callable(): (T|null) $load
	 * @return T|null
	 */
	private function getDefault(string $setting, callable $load): mixed
	{
		if (!array_key_exists($setting, $this->defaults))
		{
			$this->defaults[$setting] = $load();
		}

		return $this->defaults[$setting];
	}

	private function getDefaultPersonTypeId(): ?int
	{
		return $this->getDefault('personType', function (): ?int
		{
			$id = (int)Option::get('sale', 'config_external_default_person_type_id');

			return $id > 0 ? $this->findPersonTypeIdByFilter(['=ID' => $id]) : null;
		});
	}

	private function getDefaultPaySystemId(): ?int
	{
		return $this->getDefault('paySystem', function (): ?int
		{
			$id = (int)Option::get('sale', 'config_external_default_pay_system_id');

			return $id > 0 ? $this->findPaySystemIdByFilter(['=ID' => $id]) : null;
		});
	}

	private function getDefaultDeliveryId(): ?int
	{
		return $this->getDefault('delivery', function (): ?int
		{
			$id = (int)Option::get('sale', 'config_external_default_delivery_system_id');
			if ($id <= 0)
			{
				return null;
			}

			foreach (Delivery\Services\Manager::getActiveList() as $service)
			{
				if ((int)$service['ID'] === $id)
				{
					return $id;
				}
			}

			return null;
		});
	}

	private function getDefaultOrderStatusId(): ?string
	{
		return $this->getDefault('orderStatus', function (): ?string
		{
			$id = (string)Option::get('sale', 'config_external_default_order_status_id');

			return $id === '' ? null : $this->findStatusId(OrderStatus::class, ['=ID' => $id]);
		});
	}

	private function getDefaultDeliveryStatusId(): ?string
	{
		return $this->getDefault('deliveryStatus', function (): ?string
		{
			$id = (string)Option::get('sale', 'config_external_default_delivery_status_id');

			return $id === '' ? null : $this->findStatusId(DeliveryStatus::class, ['=ID' => $id]);
		});
	}
}
