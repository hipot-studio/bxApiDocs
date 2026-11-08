<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * @internal
 */
final class TraceAccessDeniedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Access to change the tracking of the item is denied.');
	}
}
