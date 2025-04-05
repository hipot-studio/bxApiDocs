<?php

namespace Bitrix\Crm\Service\UserPermissions\Helper;

class Shipment
{
	public static function getOrderIdByShipmentId(int $shipmentId): int
	{
		if ($shipmentId > 0)
		{
			$result = \Bitrix\Crm\Order\Shipment::getList([
				'filter' => [
					'=ID' => $shipmentId,
				],
				'select' => [
					'ID',
					'ORDER_ID',
				],
				'limit' => 1
			]);

			$shipmentData = $result->fetch();
			$orderId = $shipmentData['ORDER_ID'];
			if ($orderId <= 0)
			{
				return 0;
			}

			return $orderId;
		}

		return 0;
	}
}
