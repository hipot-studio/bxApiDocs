<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

final class FolderPinState
{
	/**
	 * @param array<int, int> $pinSortMap chatId → PIN_SORT, in insertion order
	 */
	public function __construct(
		private readonly array $pinSortMap = [],
	)
	{
	}

	/**
	 * @return int[]
	 */
	public function getPinnedChatIds(): array
	{
		return array_keys($this->pinSortMap);
	}

	/**
	 * @return array<int, int> chatId → PIN_SORT
	 */
	public function getPinSortMap(): array
	{
		return $this->pinSortMap;
	}

	public function hasChat(int $chatId): bool
	{
		return isset($this->pinSortMap[$chatId]);
	}

	public function getPinnedChatCount(): int
	{
		return count($this->pinSortMap);
	}
}
