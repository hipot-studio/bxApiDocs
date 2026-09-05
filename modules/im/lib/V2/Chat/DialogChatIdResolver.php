<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Common\Normalizer;

/**
 * Resolves dialogIds to chat ids. Private (numeric) dialogIds are resolved in a
 * single batch query; chatNNN is parsed without a query; crm|/sg fall back to
 * per-id legacy resolution.
 */
final class DialogChatIdResolver
{
	/**
	 * Unions raw chatIds with resolved dialogIds into a unique, positive set.
	 *
	 * @param array $chatIds raw chat ids
	 * @param string[] $dialogIds
	 * @param bool $createMissing create a private chat for a user dialogId that has none yet (default)
	 */
	public function resolve(array $chatIds, array $dialogIds, int $userId, bool $createMissing = true): ChatIds
	{
		$ids = array_merge(
			array_map('intval', $chatIds),
			array_values($this->resolveDialogIds($dialogIds, $userId, $createMissing)),
		);

		return new ChatIds(Normalizer::toUniquePositiveIntegers($ids));
	}

	/**
	 * @param string[] $dialogIds
	 * @param bool $createMissing create a private chat for a user dialogId that has none yet (default)
	 * @return array<string, int> dialogId => chatId (>0); unresolved skipped
	 */
	public function resolveDialogIds(array $dialogIds, int $userId, bool $createMissing = true): array
	{
		$chatIds = [];
		$privateUserIds = [];

		foreach ($dialogIds as $dialogId)
		{
			if (!is_scalar($dialogId))
			{
				continue;
			}

			$dialogId = (string)$dialogId;

			if (preg_match('/^chat\d+$/i', $dialogId))
			{
				$chatId = (int)mb_substr($dialogId, 4);
				if ($chatId > 0)
				{
					$chatIds[$dialogId] = $chatId;
				}
			}
			elseif (preg_match('/^\d+$/', $dialogId))
			{
				$privateUserIds[$dialogId] = (int)$dialogId;
			}
			else
			{
				$chatId = (int)Dialog::getChatId($dialogId, $userId);
				if ($chatId > 0)
				{
					$chatIds[$dialogId] = $chatId;
				}
			}
		}

		if ($privateUserIds !== [])
		{
			$existing = Dialog::getChatIds(array_values($privateUserIds), $userId);
			foreach ($privateUserIds as $dialogId => $privateUserId)
			{
				if (isset($existing[$privateUserId]) && (int)$existing[$privateUserId] > 0)
				{
					$chatIds[$dialogId] = (int)$existing[$privateUserId];
				}
				elseif ($createMissing)
				{
					// No existing private chat — getChatId creates it (createIfNotExists).
					$chatId = (int)Dialog::getChatId($dialogId, $userId);
					if ($chatId > 0)
					{
						$chatIds[$dialogId] = $chatId;
					}
				}
			}
		}

		return $chatIds;
	}
}
