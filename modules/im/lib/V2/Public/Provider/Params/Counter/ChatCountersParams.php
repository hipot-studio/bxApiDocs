<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Params\Counter;

use Bitrix\Im\V2\Common\Normalizer;

final class ChatCountersParams
{
	private function __construct(
		public readonly int $userId,
		public readonly array $chatIds,
	) {}

	public static function forChats(int $userId, array $chatIds): ChatCountersParams
	{
		return new self($userId, Normalizer::toUniquePositiveIntegers($chatIds));
	}

	public function isEmpty(): bool
	{
		return $this->chatIds === [];
	}
}
