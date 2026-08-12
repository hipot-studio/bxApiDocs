<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Exception;

use Bitrix\Rest\V3\Exception\InvalidOrderException as BaseInvalidOrderException;

/**
 * Raised by call.followup.list when the `order` argument is not strictly
 * `{ startDate: 'asc' | 'desc' }`. Includes unknown order keys, non-string
 * values and directions other than `asc` / `desc`.
 */
class InvalidOrderException extends BaseInvalidOrderException
{
	public function getRegistryCode(): string
	{
		return 'invalid_order';
	}
}
