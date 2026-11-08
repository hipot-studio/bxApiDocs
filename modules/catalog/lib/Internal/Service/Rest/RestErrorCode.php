<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

/**
 * Shared error codes for Rest domain services (V1 Engine + V3 Infrastructure).
 * Controllers map these to transport-specific exceptions.
 *
 * Codes are limited to rejections with their own transport exception: an error that falls through
 * to the validation response reports its code as `validation[].field`, so that slot is left
 * either empty or filled with the name of the request field.
 */
final class RestErrorCode
{
	public const ACCESS_DENIED = 'ACCESS_DENIED';
	public const NOT_FOUND = 'NOT_FOUND';
	public const ALREADY_EXISTS = 'ALREADY_EXISTS';
}
