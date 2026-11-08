<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The trace or the item is missing or hidden from the user: both look the same to the caller.
 *
 * @internal
 */
final class TraceNotFoundException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Tracking trace was not found.');
	}
}
