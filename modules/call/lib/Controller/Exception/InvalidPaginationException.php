<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Exception;

use Bitrix\Rest\V3\Exception\InvalidPaginationException as BaseInvalidPaginationException;

/**
 * Raised by call.followup.list when `pagination` is malformed: unknown keys,
 * non-positive `limit`, or a structurally invalid `afterCursor`.
 */
class InvalidPaginationException extends BaseInvalidPaginationException
{
	public function getRegistryCode(): string
	{
		return 'invalid_pagination';
	}
}
