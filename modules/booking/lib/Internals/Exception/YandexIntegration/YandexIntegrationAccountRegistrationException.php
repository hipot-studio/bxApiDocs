<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception\YandexIntegration;

use Bitrix\Booking\Internals\Exception\Exception;

class YandexIntegrationAccountRegistrationException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Account registration failed' : $message;
		$code = self::CODE_YANDEX_INTEGRATION_ACCOUNT_REGISTRATION;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
