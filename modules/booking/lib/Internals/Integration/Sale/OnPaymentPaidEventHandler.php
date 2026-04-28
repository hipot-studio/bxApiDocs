<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Sale;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ORM\BookingPaymentRepository;
use Bitrix\Sale\Payment;

class OnPaymentPaidEventHandler
{
	private BookingPaymentRepository $bookingPaymentRepository;

	public function __construct()
	{
		$this->bookingPaymentRepository = Container::getBookingPaymentRepository();
	}

	public static function onPaymentPaid(Payment $payment): void
	{
		return;

		(new self())->processEvent($payment);
	}

	private function processEvent(Payment $payment): void
	{
		$paymentId = $payment->getId();
		if (!$paymentId)
		{
			return;
		}

		if (!$this->bookingPaymentRepository->checkExistence($paymentId))
		{
			return;
		}

		$this->bookingPaymentRepository->setIsPaid($paymentId, $payment->isPaid());
	}
}
