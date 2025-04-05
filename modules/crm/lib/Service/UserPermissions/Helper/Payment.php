<?php

namespace Bitrix\Crm\Service\UserPermissions\Helper;

class Payment
{
	public static function getOrderIdByPaymentId(int $paymentId): int
	{
		if ($paymentId > 0)
		{
			$result = \Bitrix\Crm\Order\Payment::getList(array(
				'filter' => [
					'=ID' => $paymentId,
				],
				'limit' => 1
			));

			$paymentData = $result->fetch();
			$orderId = $paymentData['ORDER_ID'];
			if ($orderId <= 0)
			{
				return 0;
			}

			return $orderId;
		}

		return 0;
	}
}
