<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

/**
 * Period bounds of the annual summary expressed in message identifiers.
 *
 * A null idFrom means the period holds no messages at all; a null idTo means the upper bound is
 * still open, because the table has no message dated after the period yet.
 */
final readonly class MessageIdRange
{
	public function __construct(
		public ?int $idFrom,
		public ?int $idTo,
	)
	{
	}
}
