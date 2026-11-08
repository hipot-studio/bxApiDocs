<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The attribution of the item kept changing under parallel writes, and the source was not assigned;
 * repeating the request is safe.
 *
 * @internal
 */
final class TraceRebindConflictException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The tracking of the item was changed by a parallel request, the source was not assigned.');
	}
}
