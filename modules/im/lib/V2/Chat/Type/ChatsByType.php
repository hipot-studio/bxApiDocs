<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type;

use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\Normalize;

final class ChatsByType
{
	/** @var array<int, int> */
	private array $chatIds = [];

	public function __construct(
		public readonly Type $type,
	) {}

	public function addChatId(int $chatId): void
	{
		$this->addChatIds([$chatId]);
	}

	/**
	 * @param int[] $chatIds
	 */
	public function setChatIds(array $chatIds): void
	{
		$this->chatIds = [];
		$this->addChatIds($chatIds);
	}

	/**
	 * @param int[] $chatIds
	 */
	public function addChatIds(array $chatIds): void
	{
		foreach (Normalize::uniquePositiveIntegers($chatIds) as $chatId)
		{
			$this->chatIds[$chatId] = $chatId;
		}
	}

	/**
	 * @return int[]
	 */
	public function getChatIds(): array
	{
		return array_values($this->chatIds);
	}
}
