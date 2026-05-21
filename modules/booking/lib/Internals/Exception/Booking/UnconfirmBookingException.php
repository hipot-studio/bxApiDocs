<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception\Booking;

use Bitrix\Booking\Internals\Exception\Exception;

class UnconfirmBookingException extends Exception
{
	public function __construct($message = '', $code = self::CODE_BOOKING_UNCONFIRMATION_FAILED)
	{
		$message = $message === '' ? 'Unconfirmation failed' : $message;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
