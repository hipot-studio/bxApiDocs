<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception;

class InvalidSkuException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'One or more SKUs do not exist or access denied' : $message;
		$code = self::CODE_SKU_RELATION_INVALID;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
