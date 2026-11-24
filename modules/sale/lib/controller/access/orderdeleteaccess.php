<?php

namespace Bitrix\Sale\Controller\Access;

use Bitrix\Sale\OrderBase;
use Bitrix\Sale\OrderStatus;

/**
 * Object-level right of a user to delete a definite order.
 * The CRM branch repeats the order modes of \Bitrix\Crm\Service\UserPermissions\EntityPermissions\SaleEntityItem.
 */
final class OrderDeleteAccess
{
	public function __construct(
		private readonly bool $isCrmContext,
		private readonly int $userId,
	)
	{
	}

	public function canDelete(OrderBase $order): bool
	{
		return $this->isCrmContext ? $this->canDeleteInCrm($order) : $this->canDeleteByStatus($order);
	}

	private function canDeleteInCrm(OrderBase $order): bool
	{
		if ($this->userId <= 0)
		{
			return false;
		}

		$permissions = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($this->userId);

		if (\CCrmSaleHelper::isWithOrdersMode())
		{
			return $permissions->item()->canDelete(\CCrmOwnerType::Order, (int)$order->getId());
		}

		$binding = $order instanceof \Bitrix\Crm\Order\Order ? $order->getEntityBinding() : null;
		if ($binding)
		{
			return $permissions->item()->canDelete($binding->getOwnerTypeId(), $binding->getOwnerId());
		}

		return $permissions->entityType()->canDeleteItems(\CCrmOwnerType::Deal);
	}

	private function canDeleteByStatus(OrderBase $order): bool
	{
		return in_array(
			(string)$order->getField('STATUS_ID'),
			OrderStatus::getStatusesUserCanDoOperations($this->userId, ['delete']),
			true
		);
	}
}
