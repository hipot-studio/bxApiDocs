<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\Counter;

use Bitrix\Im\V2\Reading\Counter\Entity\ChatCounter;

final class ChatCounterItem
{
	private readonly ChatCounterCollection $children;

	public function __construct(
		public readonly int $chatId,
		public readonly int $parentChatId,
		public readonly int $ownCounter,
		public readonly bool $isMuted = false,
		?ChatCounterCollection $children = null,
	) {
		$this->children = $children ?? ChatCounterCollection::empty();
	}

	/**
	 * @internal
	 */
	public static function fromInternalCounter(
		ChatCounter $counter,
		?ChatCounterCollection $children = null,
	): self
	{
		return new self(
			chatId: $counter->chatId,
			parentChatId: $counter->parentChatId,
			ownCounter: $counter->counter,
			isMuted: $counter->isMuted,
			children: $children,
		);
	}

	public function getSum(?ChatCounterAggregationOptions $options = null): int
	{
		$options ??= new ChatCounterAggregationOptions();

		$total = $this->ownCounter;
		foreach ($this->children as $child)
		{
			if (!$options->includeMutedDescendants && $child->isMuted)
			{
				continue;
			}

			$total += $child->getSum($options);
		}

		return $total;
	}
}
