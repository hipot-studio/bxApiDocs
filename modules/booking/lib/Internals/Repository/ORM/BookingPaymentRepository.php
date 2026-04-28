<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Internals\Model\BookingPaymentTable;

class BookingPaymentRepository
{
	public function checkExistence(int $paymentId): bool
	{
		$query = BookingPaymentTable::query()
			->setSelect(['ID'])
			->where('PAYMENT_ID', $paymentId)
			->setLimit(1)
		;

		return (bool)$query->fetch();
	}

	public function setIsPaid(int $paymentId, bool $isPaid): void
	{
		BookingPaymentTable::updateByFilter(
			[
				'PAYMENT_ID' => $paymentId,
			],
			[
				'IS_PAID' => $isPaid ? 'Y' : 'N',
			],
		);
	}

	public function setIsPaidManually(int $id, bool $isPaidManually): void
	{
		BookingPaymentTable::update($id, ['IS_PAID_MANUALLY' => $isPaidManually ? 'Y' : 'N']);
	}

	public function link(int $bookingId, int $paymentId): void
	{
		if (!$bookingId || !$paymentId)
		{
			return;
		}

		BookingPaymentTable::add([
			'BOOKING_ID' => $bookingId,
			'PAYMENT_ID' => $paymentId,
		]);
	}
}
