<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\ShipmentItem;

/**
 * The quantity of a position of a deducted shipment: the domain refuses to change it, and the path to
 * the domain loses the refusal.
 *
 * The domain keeps the goods of a deducted shipment where they are (`ShipmentItem::onFieldModify()`),
 * but the order builder of REST drops its answer (`OrderBuilderRest::modifyQuantityShipmentItem()`):
 * the change answered 200 with the quantity untouched, and the other fields of the same call were
 * stored. A shipment named without its deduction keeps the one it has ({@see OrderFormBuilder}), so
 * it is still deducted while its positions are built. The change is therefore refused by the path of
 * the quantity before anything is assembled, once per position; the same quantity is no change.
 *
 * A call taking the deduction back is not refused: the domain applies the fields of such a shipment
 * before its positions (`OrderBuilder::buildShipments()`), when it is no longer deducted. A new
 * position of a deducted shipment is refused by the domain itself, and an element naming no position
 * of the shipment by its identifier ({@see ElementLocator}).
 */
final class DeductedShipmentSupport
{
	/**
	 * @param Order|null $order the order being changed; a create has no deducted shipment
	 * @return list<Error> one per existing position of a shipment staying deducted whose quantity the
	 *         input changes, under the path of the quantity
	 */
	public static function validate(OrderDto $dto, ?Order $order): array
	{
		if ($order === null)
		{
			return [];
		}

		$errors = [];
		$index = 0;
		foreach (DtoFieldsConverter::getInitializedValues($dto)['shipments'] ?? [] as $element)
		{
			$path = 'shipments.' . $index++;
			$id = $element instanceof Dto ? ElementIdentity::existingEntityId($element) : null;
			$shipment = $id === null ? null : ElementLocator::find($order, 'shipments', $id);
			if ($shipment instanceof Shipment)
			{
				$errors = array_merge($errors, self::validateShipment($element, $shipment, $path));
			}
		}

		return $errors;
	}

	/**
	 * @return list<Error>
	 */
	private static function validateShipment(Dto $element, Shipment $shipment, string $path): array
	{
		$values = DtoFieldsConverter::getInitializedValues($element);
		if (!$shipment->isShipped() || ($values['deducted'] ?? null) === false)
		{
			return [];
		}

		$errors = [];
		$index = 0;
		foreach ($values['shipmentItems'] ?? [] as $item)
		{
			$itemPath = $path . '.shipmentItems.' . $index++ . '.quantity';
			if (!$item instanceof Dto)
			{
				continue;
			}

			$quantity = DtoFieldsConverter::getInitializedValues($item)['quantity'] ?? null;
			$id = ElementIdentity::existingEntityId($item);
			$position = $quantity === null || $id === null
				? null
				: ElementLocator::find($shipment, 'shipmentItems', $id)
			;
			if (!$position instanceof ShipmentItem || (float)$quantity === $position->getQuantity())
			{
				continue;
			}

			$errors[] = new Error('Field "' . $itemPath . '" cannot change in a deducted shipment', $itemPath);
		}

		return $errors;
	}
}
