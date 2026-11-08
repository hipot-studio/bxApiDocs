<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * Who calls a builder operation. The channel is read by the journal only.
 */
final class Actor
{
	public function __construct(
		public readonly int $userId,
		public readonly Channel $channel,
	)
	{
		if ($userId <= 0)
		{
			throw new ArgumentOutOfRangeException('userId', 1);
		}
	}
}
