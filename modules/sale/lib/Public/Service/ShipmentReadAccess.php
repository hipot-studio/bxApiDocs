<?php

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\Internal\Access;

final class ShipmentReadAccess
{
	private readonly Access\ShipmentAccess $access;

	/**
	 * Creates read access for the server-side user.
	 *
	 * @param int $userId
	 * @param string $saleModulePermission Sale module permission for the same userId.
	 */
	public function __construct(int $userId, string $saleModulePermission)
	{
		$this->access = new Access\ShipmentAccess($userId, $saleModulePermission);
	}

	/**
	 * Checks the user's ACL for a shipment selected by the caller.
	 * The caller must verify that the shipment exists and is not a system shipment;
	 * this method does not validate these conditions.
	 *
	 * @param int $shipmentId
	 * @return bool
	 */
	public function canRead(int $shipmentId): bool
	{
		return $this->access->canRead($shipmentId);
	}

	/**
	 * Returns mandatory ACL conditions for a shipment query.
	 *
	 * @return array
	 */
	public function getReadFilter(): array
	{
		return $this->access->getReadFilter();
	}
}
