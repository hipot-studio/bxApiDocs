<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Exception;

use Bitrix\Rest\V3\Exception\InvalidSelectException;

class InvalidSelectFieldException extends InvalidSelectException
{
	public function __construct(string $field)
	{
		parent::__construct($field);
	}

	/**
	 * Stable machine-readable code published as part of the public REST contract.
	 * Default RestException::getRegistryCode() would emit
	 * "BITRIX_CALL_CONTROLLER_EXCEPTION_INVALIDSELECTFIELDEXCEPTION" which is not
	 * documented and would break client error-handling.
	 */
	public function getRegistryCode(): string
	{
		return 'invalid_select_field';
	}
}
