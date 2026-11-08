<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The source does not exist or is archived, so nothing can be attributed to it.
 *
 * @internal
 */
final class TrackingSourceInvalidException extends RuntimeException
{
	public function __construct(
		public readonly int $sourceId,
	)
	{
		parent::__construct(sprintf('Tracking source `%d` does not exist or is archived.', $sourceId));
	}
}
