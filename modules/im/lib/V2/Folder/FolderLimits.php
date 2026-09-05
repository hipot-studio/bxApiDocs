<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

/**
 * Single source of truth for chat-folder limits (server-side validation + web config).
 */
final class FolderLimits
{
	public const MAX_FOLDERS_PER_USER = 20;
	public const MAX_CHATS_PER_FOLDER = 50;
	public const MAX_TITLE_LENGTH = 30;

	private function __construct()
	{
	}

	/**
	 * Guards the raw requested composition size BEFORE dialogId resolution, so an over-limit request is
	 * rejected without ever creating private chats for the surplus ids (a huge dialogIds list must never
	 * spawn chats it can never fit into the folder). The count is a dedup upper bound across both id forms
	 * (a chatId and its numeric dialogId twin may double-count) — it can only reject earlier, never later.
	 *
	 * @param array $chatIds raw chat ids as received from the client
	 * @param array $dialogIds raw dialog ids as received from the client
	 */
	public static function exceedsChatComposition(array $chatIds, array $dialogIds): bool
	{
		$raw = array_filter(
			array_merge($chatIds, $dialogIds),
			static fn ($value): bool => is_scalar($value),
		);

		return count(array_unique(array_map('strval', $raw))) > self::MAX_CHATS_PER_FOLDER;
	}
}
