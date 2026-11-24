<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

/**
 * Which stores of the unread state hold anything for a user: message counters, recent rows marked
 * unread, anchors. Lets a read of several recent sections skip the per-section lookups of a store
 * that is empty for the user.
 */
final readonly class UnreadSources
{
	public function __construct(
		public bool $counters,
		public bool $recent,
		public bool $anchors,
	) {}

	public static function all(): self
	{
		return new self(true, true, true);
	}

	public function isEmpty(): bool
	{
		return !$this->counters && !$this->recent && !$this->anchors;
	}
}
