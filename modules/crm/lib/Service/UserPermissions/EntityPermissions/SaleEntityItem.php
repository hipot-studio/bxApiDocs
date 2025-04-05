<?php

namespace Bitrix\Crm\Service\UserPermissions\EntityPermissions;

use Bitrix\Crm\Service\UserPermissions\Helper\Payment;
use Bitrix\Crm\Service\UserPermissions\Helper\Shipment;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Crm\Service\UserPermissions\Helper\Check;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->saleEntityItem()
 */

final class SaleEntityItem
{
	private const SALE_ENTITIES = [\CCrmOwnerType::OrderShipment, \CCrmOwnerType::OrderPayment, \CCrmOwnerType::OrderCheck];

	public static function isSaleEntity(int $entityTypeId): bool
	{
		return in_array($entityTypeId, self::SALE_ENTITIES, true);
	}

	public function canAddItems(Type $entityType, int $entityTypeId): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		return $entityType->canAddItems(\CCrmOwnerType::Order); // always check permissions for order
	}

	public function canUpdate(Item $item, int $entityTypeId, int $id): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		$orderId = $this->getOrderIdByEntityId($entityTypeId, $id);
		if ($orderId <= 0)
		{
			return false;
		}

		return $item->canUpdate(\CCrmOwnerType::Order, $orderId);
	}

	public function canUpdateItems(Type $entityType, int $entityTypeId): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		return $entityType->canUpdateItems(\CCrmOwnerType::Order);
	}

	public function canRead(Item $item, int $entityTypeId, int $id): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		$orderId = $this->getOrderIdByEntityId($entityTypeId, $id);
		if ($orderId <= 0)
		{
			return false;
		}

		return $item->canRead(\CCrmOwnerType::Order, $orderId);
	}

	public function canReadItems(Type $entityType, int $entityTypeId): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		return $entityType->canReadItems(\CCrmOwnerType::Order);
	}

	public function canDelete(Item $item, int $entityTypeId, int $id): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		$orderId = $this->getOrderIdByEntityId($entityTypeId, $id);
		if ($orderId <= 0)
		{
			return false;
		}

		return $item->canDelete(\CCrmOwnerType::Order, $orderId);
	}

	public function canDeleteItems(Type $entityType, int $entityTypeId): bool
	{
		if (!self::isSaleEntity($entityTypeId))
		{
			throw new ArgumentOutOfRangeException('entityTypeId', self::SALE_ENTITIES);
		}

		return$entityType->canDeleteItems(\CCrmOwnerType::Order);
	}

	private function getOrderIdByEntityId(int $entityTypeId, int $id): int
	{
		return match($entityTypeId)
		{
			\CCrmOwnerType::OrderPayment => Payment::getOrderIdByPaymentId($id),
			\CCrmOwnerType::OrderShipment => Shipment::getOrderIdByShipmentId($id),
			\CCrmOwnerType::OrderCheck => Check::getOrderIdByCheckId($id),
			default => 0,
		};
	}
}
