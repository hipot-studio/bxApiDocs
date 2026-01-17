<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception\Yandex;

class BookingUpdateForbiddenException extends YandexException
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Booking update forbidden' : $message;
		$code = self::CODE_YANDEX_BOOKING_UPDATE_FORBIDDEN;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}

	public function getExternalCode(): string
	{
		return 'YANDEX_UPDATE_FORBIDDEN';
	}
}
