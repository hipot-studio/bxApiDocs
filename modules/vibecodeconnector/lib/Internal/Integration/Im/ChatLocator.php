<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;

final class ChatLocator
{
	public function find(int $chatId): ?Chat
	{
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);

		return ($chat->getChatId() ?? 0) > 0 ? $chat : null;
	}

	/**
	 * @param int[] $chatIds
	 */
	public function prefetch(array $chatIds): void
	{
		$ids = array_values(array_filter(
			array_map('intval', $chatIds),
			static fn (int $chatId): bool => $chatId > 0,
		));
		if ($ids === [])
		{
			return;
		}

		ChatFactory::getInstance()->getChatsByIds($ids);
	}
}
