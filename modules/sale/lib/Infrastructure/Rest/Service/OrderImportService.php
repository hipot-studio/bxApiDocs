<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\ImportRequest;
use Bitrix\Sale\Infrastructure\Rest\Support\ExternalIdResolver;
use Bitrix\Sale\Infrastructure\Rest\Support\SyncEventSuppression;

/**
 * Exchange with the accounting system: the order is addressed by its external identifier.
 *
 * The exchange owns no write path of its own. It trims the payload to the exchange contract, turns
 * every external identifier into an internal one and hands the result to the ordinary write
 * service, adding only the suppression of the outgoing synchronization events.
 */
final class OrderImportService
{
	/**
	 * The exchange contract, section by section. Every other field of the data types the payload
	 * carries is dropped without a word: a real payload of the accounting system also brings trade
	 * bindings and clients, and refusing those would break the exchange over fields it has always
	 * sent. A key that is no field of its data type at all is refused on every level before the
	 * contract is applied ({@see refuseUnknownFields()}): the platform measures the top level only.
	 *
	 * The basket position keeps `id`: it is the number the accounting system knows the position by,
	 * and the positions of a shipment address it. The states a shipment moves through by actions of
	 * the domain (`canceled`, `reasonUndoDeducted`) stay out: V1 took them and applied nothing, and
	 * the data type refuses them, so passing them on would refuse the whole document.
	 */
	public const ORDER_FIELDS = [
		'xmlId',
		'currency',
		'personTypeXmlId',
		'statusXmlId',
		'canceled',
		'reasonCanceled',
		'comments',
	];

	public const PROPERTY_VALUE_FIELDS = ['name', 'code', 'orderPropsXmlId', 'value'];

	public const BASKET_ITEM_FIELDS = [
		'id',
		'xmlId',
		'productXmlId',
		'name',
		'measureCode',
		'measureName',
		'quantity',
		'currency',
		'price',
		'vatRate',
		'vatIncluded',
	];

	public const BASKET_ITEM_PROPERTY_FIELDS = ['name', 'code', 'value', 'xmlId'];

	public const PAYMENT_FIELDS = [
		'xmlId',
		'paySystemXmlId',
		'paid',
		'sum',
		'comments',
		'payVoucherNum',
		'payVoucherDate',
		'isReturn',
		'payReturnNum',
		'payReturnDate',
		'payReturnComment',
	];

	public const SHIPMENT_FIELDS = [
		'xmlId',
		'deliveryXmlId',
		'statusXmlId',
		'basePriceDelivery',
		'priceDelivery',
		'allowDelivery',
		'deducted',
		'deliveryDocNum',
		'deliveryDocDate',
		'trackingNumber',
		'comments',
	];

	public const SHIPMENT_ITEM_FIELDS = ['basketId', 'quantity', 'xmlId'];

	/**
	 * The fields of a scope that let the exchange write the status of a known order. Not a section of
	 * the contract, so the name does not end in `_FIELDS` as the names of the sections do.
	 */
	private const FIELDS_GRANTING_THE_STATUS = ['statusId', 'statusXmlId'];

	public function __construct(
		private readonly AccessSupport $accessSupport,
		private readonly OrderService $orderService,
		private readonly ExternalIdResolver $resolver,
	)
	{
	}

	/**
	 * The request travels with the payload because the data type is filled through the platform's
	 * own field structure: the resolution works on plain fields, and the resolved fields become a
	 * data type again by the same route the ordinary write actions use.
	 */
	public function import(ImportRequest $request): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$configured = $this->resolver->ensureConfigured();
		if (!$configured->isSuccess())
		{
			return $configured;
		}

		$resolved = $this->resolver->resolve($this->applyAllowlist($request->fields->getItems()));
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		$orderId = (int)$resolved->getData()['orderId'];
		$fields = $this->withoutTheDefaultStatusBeyondTheScope($resolved->getData()['fields'], $orderId, $request);
		$dto = $this->buildDto($fields, $request, $orderId);

		// The resolver refuses a new position whose product it did not find in the catalogue, so every
		// position the exchange creates is a product of the catalogue.
		return SyncEventSuppression::run(
			fn(): Result => $orderId > 0
				? $this->orderService->updateOrder($orderId, $dto, newPositionsAreCatalogProducts: true)
				: $this->orderService->addOrder($dto, newPositionsAreCatalogProducts: true),
		);
	}

	public function deleteFromImport(string $xmlId): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$configured = $this->resolver->ensureConfigured();
		if (!$configured->isSuccess())
		{
			return $configured;
		}

		$xmlId = trim($xmlId);
		if ($xmlId === '')
		{
			// The same three shapes of empty as in the resolver, and the same reason: a lookup by an
			// empty value finds the oldest order of the portal and deletes an order nobody named.
			return ServiceResult::validation(
				new Error('Field "xmlId" is required and must not be empty'),
			);
		}

		$orderId = $this->resolver->findOrderIdByExternalId($xmlId);
		if ($orderId === null)
		{
			return ServiceResult::notFound(0, 'Order with the external identifier was not found');
		}

		return SyncEventSuppression::run(fn(): Result => $this->orderService->deleteOrder($orderId));
	}

	/**
	 * The default status the resolution puts on a known order that names none is a write of the
	 * status, and the platform measures the grant against the raw input only. So under a scope naming
	 * fields and neither of the status fields the known order keeps its status. A new order takes the
	 * default as its initial status under any scope, as it takes the other values of creation.
	 *
	 * The fields of the scope are compared by name: for a field of the top level that is the rule of
	 * the platform's guard of the grant, where a path is allowed by itself or by its root.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function withoutTheDefaultStatusBeyondTheScope(array $fields, int $orderId, ImportRequest $request): array
	{
		if ($orderId <= 0)
		{
			return $fields;
		}

		$scope = $request->getOptions()['scope'] ?? null;
		if (
			!$scope instanceof Scope
			|| $scope->fields === []
			|| array_intersect(self::FIELDS_GRANTING_THE_STATUS, $scope->fields) !== []
		)
		{
			return $fields;
		}

		unset($fields['statusId']);

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function buildDto(array $fields, ImportRequest $request, int $orderId): OrderDto
	{
		$group = $orderId > 0 ? RequiredGroup::Update->value : RequiredGroup::Add->value;

		$dto = FieldsStructure::create($fields, OrderDto::class, $request)->convertToDto($group);
		if (!$dto instanceof OrderDto)
		{
			throw new \LogicException('The exchange payload did not become an order');
		}

		return $this->markDeliveryPriceAsCustom($dto);
	}

	/**
	 * Matching the delivery services by external identifier means the accounting system sends the
	 * sum rather than asking for a calculation. Without the mark the domain recalculates
	 * and the exchange zeroes the delivery it was just told about. The field is read-only in the
	 * data type, so the exchange sets it here instead of accepting it from the input.
	 */
	private function markDeliveryPriceAsCustom(OrderDto $dto): OrderDto
	{
		foreach ($dto->shipments ?? [] as $shipment)
		{
			if ($shipment instanceof ShipmentDto)
			{
				$shipment->customPriceDelivery = true;
			}
		}

		return $dto;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 * @throws UnknownDtoPropertyException
	 */
	private function applyAllowlist(array $fields): array
	{
		$this->refuseUnknownFields($fields, OrderDto::class);

		$allowed = $this->keepAllowed($fields, self::ORDER_FIELDS);

		$allowed = $this->keepCollection(
			$fields,
			$allowed,
			'propertyValues',
			fn(array $element): array => $this->keepAllowed($element, self::PROPERTY_VALUE_FIELDS),
		);

		$allowed = $this->keepCollection(
			$fields,
			$allowed,
			'basketItems',
			function (array $element): array
			{
				$item = $this->keepAllowed($element, self::BASKET_ITEM_FIELDS);

				return $this->keepCollection(
					$element,
					$item,
					'properties',
					fn(array $property): array => $this->keepAllowed($property, self::BASKET_ITEM_PROPERTY_FIELDS),
				);
			},
		);

		$allowed = $this->keepCollection(
			$fields,
			$allowed,
			'payments',
			fn(array $element): array => $this->keepAllowed($element, self::PAYMENT_FIELDS),
		);

		return $this->keepCollection(
			$fields,
			$allowed,
			'shipments',
			function (array $element): array
			{
				$shipment = $this->keepAllowed($element, self::SHIPMENT_FIELDS);

				return $this->keepCollection(
					$element,
					$shipment,
					'shipmentItems',
					fn(array $item): array => $this->keepAllowed($item, self::SHIPMENT_ITEM_FIELDS),
				);
			},
		);
	}

	/**
	 * The refusal an ordinary write gives a key that is no field of its data type, made where the
	 * platform does not see the key: it measures the keys of the top level when the request is read,
	 * and the contract trims every level below before the fields become a data type, so a misspelt
	 * field of a position was dropped without a word. The exception and the path are the platform's
	 * own (`FieldsStructure::fillDto()`). Whether a value has the shape of its field is not asked
	 * here: the resolver answers that ({@see ExternalIdResolver::resolve()}).
	 *
	 * @param array<string, mixed> $fields
	 * @param class-string<Dto> $dtoClass
	 * @param string|null $path qualified name of the parent, for a nested key
	 * @throws UnknownDtoPropertyException
	 */
	private function refuseUnknownFields(array $fields, string $dtoClass, ?string $path = null): void
	{
		$dto = $dtoClass::create();
		$known = $dto->getFields();

		foreach ($fields as $property => $value)
		{
			$name = $path === null ? (string)$property : $path . '.' . $property;
			$field = $known[(string)$property] ?? null;
			if ($field === null)
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $name);
			}

			if (!is_array($value))
			{
				continue;
			}

			$propertyType = $field->getPropertyType();
			if (TypeHelper::isDto($propertyType))
			{
				$this->refuseUnknownFields($value, $propertyType, $name);

				continue;
			}

			$elementType = $field->getElementType();
			if ($propertyType !== DtoCollection::class || !TypeHelper::isDto($elementType))
			{
				continue;
			}

			foreach ($value as $index => $element)
			{
				if (is_array($element))
				{
					$this->refuseUnknownFields($element, $elementType, $name . '.' . $index);
				}
			}
		}
	}

	/**
	 * A collection reaches the result exactly when the client named it, whatever it holds. That is
	 * what keeps the exchange from touching a collection nobody mentioned. The allowlist judges
	 * membership and nothing else: whether what it holds has the shape of a collection is answered
	 * right after, at the entry of the resolver ({@see ExternalIdResolver::resolve()}).
	 *
	 * @param array<string, mixed> $source
	 * @param array<string, mixed> $target
	 * @param callable(array<string, mixed>): array<string, mixed> $trim
	 * @return array<string, mixed>
	 */
	private function keepCollection(array $source, array $target, string $property, callable $trim): array
	{
		if (!array_key_exists($property, $source))
		{
			return $target;
		}

		if (!is_array($source[$property]))
		{
			$target[$property] = $source[$property];

			return $target;
		}

		$elements = [];
		foreach ($source[$property] as $index => $element)
		{
			$elements[$index] = is_array($element) ? $trim($element) : $element;
		}

		$target[$property] = $elements;

		return $target;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param list<string> $allowed
	 * @return array<string, mixed>
	 */
	private function keepAllowed(array $fields, array $allowed): array
	{
		return array_intersect_key($fields, array_flip($allowed));
	}
}
