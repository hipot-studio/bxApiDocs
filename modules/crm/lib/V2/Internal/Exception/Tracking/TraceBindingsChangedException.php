<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The bindings of a trace changed while it was written: the write is rolled back and tried once more on
 * the bindings as they are now. It does not leave the services of the attribution.
 *
 * @internal
 */
final class TraceBindingsChangedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The bindings of the trace were changed by a parallel request.');
	}
}
