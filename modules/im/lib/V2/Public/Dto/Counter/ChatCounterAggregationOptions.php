<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\Counter;

final class ChatCounterAggregationOptions
{
	public function __construct(
		public readonly bool $includeMutedDescendants = true,
		public readonly bool $includeMutedRoots = true,
	) {}

	public static function withoutMutedDescendants(): self
	{
		return new self(
			includeMutedDescendants: false,
		);
	}

	public static function withoutMuted(): self
	{
		return new self(
			includeMutedDescendants: false,
			includeMutedRoots: false,
		);
	}
}
