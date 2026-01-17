<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Exception\CatalogService;

use Bitrix\Booking\Internals\Exception\Exception;

class CreateCatalogServiceException extends Exception
{
	public function __construct($message = '', int $code = 0)
	{
		$message = $message === '' ? 'Failed creating catalog service' : $message;
		$code = $code === 0 ? self::CODE_CATALOG_SERVICE_CREATE : $code;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
