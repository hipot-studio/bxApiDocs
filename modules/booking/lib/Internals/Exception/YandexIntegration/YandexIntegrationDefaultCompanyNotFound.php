<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception\YandexIntegration;

use Bitrix\Booking\Internals\Exception\Exception;

class YandexIntegrationDefaultCompanyNotFound extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Default company not found' : $message;
		$code = self::CODE_YANDEX_INTEGRATION_DEFAULT_COMPANY_NOT_FOUND;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
