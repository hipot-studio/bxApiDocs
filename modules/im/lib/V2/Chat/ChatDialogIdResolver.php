<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Common\Normalizer;

/**
 * Resolves chat ids to dialogIds — the inverse of {@see DialogChatIdResolver}.
 *
 * Private (one-to-one) chats resolve to the companion user id in a single batch
 * query; every other chat resolves to the "chatNNN" group dialogId without an
 * extra query. The returned dialogId is always a string.
 */
final class ChatDialogIdResolver
{
	private const GROUP_DIALOG_ID_PREFIX = 'chat';

	/**
	 * The dialogId of a non-private (group and every other) chat. Single source of the "chatNNN"
	 * convention — callers must not hardcode the prefix.
	 */
	public static function getGroupDialogId(int $chatId): string
	{
		return self::GROUP_DIALOG_ID_PREFIX . $chatId;
	}

	/**
	 * @param int[] $chatIds mixed group and private chat ids
	 * @param int $userId context user against whom private companions are resolved
	 * @return array<int, string> chatId => dialogId
	 */
	public function resolve(array $chatIds, int $userId): array
	{
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if ($chatIds === [])
		{
			return [];
		}

		$privateCompanions = PrivateChat::getDialogIds($chatIds, $userId);

		$result = [];
		foreach ($chatIds as $chatId)
		{
			$result[$chatId] = isset($privateCompanions[$chatId])
				? (string)$privateCompanions[$chatId]
				: self::getGroupDialogId($chatId)
			;
		}

		return $result;
	}
}
