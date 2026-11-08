<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The trace is bound to more items than one request takes; nothing is changed.
 *
 * @internal
 */
final class TraceTooManyBindingsException extends RuntimeException
{
	public function __construct(public readonly int $maxBindings)
	{
		parent::__construct("The trace is bound to more than {$maxBindings} items.");
	}
}
