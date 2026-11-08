<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Params\Counter;

use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Message\Param;

final class ChatCountersParams
{
	/**
	 * @param int[] $chatIds
	 * @param string[] $excludeByMessageParamNames
	 */
	private function __construct(
		public readonly int $userId,
		public readonly array $chatIds,
		public readonly array $excludeByMessageParamNames = [],
	) {}

	/**
	 * Exclusion assumes the own counter of a chat equals its number of unread messages. Overflowed
	 * counters are exempt from subtraction, so the assumption holds for them; the non-answered flag
	 * of an open line is a synthetic counter of 1 that subtraction can reset to zero. Chats of open
	 * lines therefore do not belong in this cut.
	 *
	 * @param int[] $chatIds
	 * @param string[] $excludeByMessageParamNames names of message params that exclude a message from counters
	 */
	public static function forChats(
		int $userId,
		array $chatIds,
		array $excludeByMessageParamNames = [],
	): ChatCountersParams
	{
		return new self(
			$userId,
			Normalizer::toUniquePositiveIntegers($chatIds),
			Normalizer::toUniqueTrimmedStrings($excludeByMessageParamNames, Param::MAX_NAME_LENGTH),
		);
	}

	public function isEmpty(): bool
	{
		return $this->chatIds === [];
	}
}
