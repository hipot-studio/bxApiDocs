<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

final class AddedMembers
{
	/**
	 * @param int[] $addedUserIds users who became visible members of the chat
	 * @param int[] $notAddedUserIds users im did not let into the chat
	 */
	public function __construct(
		public readonly array $addedUserIds = [],
		public readonly array $notAddedUserIds = [],
	) {}

	public function merge(self $other): self
	{
		return new self(
			array_values(array_unique(array_merge($this->addedUserIds, $other->addedUserIds))),
			array_values(array_unique(array_merge($this->notAddedUserIds, $other->notAddedUserIds))),
		);
	}
}
