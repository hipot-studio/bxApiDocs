<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

use Bitrix\Im\V2\Chat\ChatDialogIdResolver;

/**
 * Personal-folder composition: an explicit ordered list of chat ids. REST
 * serialization pairs each id with its dialog id; an optional pre-resolved map
 * lets the caller batch dialog ids (avoids N+1), otherwise the group dialog id
 * is used as fallback.
 */
final class ChatListDefinition implements FolderDefinition
{
	/** @var int[] */
	private readonly array $chatIds;
	/** @var array<int, string> chatId => dialogId */
	private readonly array $dialogIdMap;

	/**
	 * @param int[] $chatIds ordered chat ids (drive the output order)
	 * @param array<int, string> $dialogIdMap chatId => dialogId; missing ids fall back to the group dialog id
	 */
	public function __construct(array $chatIds, array $dialogIdMap = [])
	{
		$this->chatIds = array_values($chatIds);
		$this->dialogIdMap = $dialogIdMap;
	}

	public function toRestFormat(): array
	{
		$chats = [];
		foreach ($this->chatIds as $chatId)
		{
			$chatId = (int)$chatId;
			$chats[] = [
				'chatId' => $chatId,
				'dialogId' => (string)($this->dialogIdMap[$chatId] ?? ChatDialogIdResolver::getGroupDialogId($chatId)),
			];
		}

		return ['chats' => $chats];
	}

	public function toSelection(): Selection
	{
		return new Selection(new SelectionBranch(
			typeCondition: null,
			parentChatId: null,
			chatIds: $this->chatIds,
		));
	}

	public function supportsTail(): bool
	{
		return true;
	}

	public function containsChat(int $chatId): bool
	{
		return in_array($chatId, $this->chatIds, true);
	}
}
