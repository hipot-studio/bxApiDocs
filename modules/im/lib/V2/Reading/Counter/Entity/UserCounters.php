<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Entity;

use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * @implements Registry<ChatCounter>
 * @method ChatCounter offsetGet($key)
 */
final class UserCounters extends Registry implements RestConvertible
{
	protected int $notificationCounter = 0;

	public function addCounter(ChatCounter $counter): void
	{
		$this[$counter->chatId] = $counter;
	}

	public static function getRestEntityName(): string
	{
		return 'userCounters';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$rest = [];
		foreach ($this as $chatCounter)
		{
			$rest[] = $chatCounter->toRestFormat($option);
		}

		return $rest;
	}

	public function addMessageCounter(array $counter, array $recentSections, Type $type): void
	{
		$chatId = (int)$counter['CHAT_ID'];

		$this->addCounter(new ChatCounter(
			chatId: $chatId,
			counter: (int)$counter['COUNT'],
			parentChatId: (int)($counter['PARENT_ID'] ?? 0),
			isMuted: ($counter['IS_MUTED'] ?? 'N') === 'Y',
			isMarkedAsUnread: $this[$chatId]?->isMarkedAsUnread ?? false,
			recentSections: $recentSections,
			type: $type,
			requiresParentMembership: $type->requiresParentMembership,
		));
	}

	public function addUnreadChat(array $counter, array $recentSections, Type $type): void
	{
		$chatId = (int)$counter['CHAT_ID'];
		$existing = $this[$chatId] ?? null;

		$this->addCounter(new ChatCounter(
			chatId: $chatId,
			counter: $existing?->counter ?? 0,
			parentChatId: (int)($counter['PARENT_ID'] ?? 0) ?: ($existing?->parentChatId ?? 0),
			isMuted: ($counter['IS_MUTED'] ?? 'N') === 'Y',
			isMarkedAsUnread: true,
			recentSections: $recentSections,
			type: $type,
			requiresParentMembership: $type->requiresParentMembership,
		));
	}

	public function addParentChat(array $chat, array $recentSections, Type $type): void
	{
		$chatId = (int)$chat['CHAT_ID'];
		$existing = $this[$chatId] ?? null;
		$parentChatId = (int)($chat['PARENT_ID'] ?? 0);
		$isMuted = array_key_exists('IS_MUTED', $chat)
			? ($chat['IS_MUTED'] ?? 'N') === 'Y'
			: ($existing?->isMuted ?? false)
		;

		$this->addCounter(new ChatCounter(
			chatId: $chatId,
			counter: $existing?->counter ?? 0,
			parentChatId: $parentChatId,
			isMuted: $isMuted,
			isMarkedAsUnread: $existing?->isMarkedAsUnread ?? false,
			recentSections: $recentSections,
			type: $type,
			requiresParentMembership: $type->requiresParentMembership,
		));
	}

	public function setNotificationCounter(int $counter): void
	{
		$this->notificationCounter = $counter;
	}

	public function getNotificationCounter(): int
	{
		return $this->notificationCounter;
	}

	public function addAdditionalOpenLineCounter(int $chatId, array $recentSections, Type $type): void
	{
		$this->addCounter(new ChatCounter(
			chatId: $chatId,
			counter: 1,
			parentChatId: 0,
			isMuted: false,
			isMarkedAsUnread: false,
			recentSections: $recentSections,
			type: $type,
			requiresParentMembership: $type->requiresParentMembership,
		));
	}

	public function removeOrphaned(): void
	{
		$toRemove = [];
		foreach ($this as $counter)
		{
			if (
				$counter->parentChatId > 0
				&& $counter->requiresParentMembership
				&& !$this->isAncestorChainComplete($counter)
			)
			{
				$toRemove[] = $counter->chatId;
			}
		}

		foreach ($toRemove as $chatId)
		{
			unset($this[$chatId]);
		}
	}

	private function isAncestorChainComplete(ChatCounter $counter): bool
	{
		$current = $counter;
		$maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH;

		for ($depth = 0; $current->parentChatId > 0 && $depth < $maxDepth; $depth++)
		{
			if (!$current->requiresParentMembership)
			{
				return true;
			}

			$parent = $this[$current->parentChatId] ?? null;
			if ($parent === null)
			{
				return false;
			}
			$current = $parent;
		}

		return $current->parentChatId === 0;
	}

	public function getByChatId(int $chatId): ?ChatCounter
	{
		return $this[$chatId] ?? null;
	}

	/** @return int[] */
	public function getChatIds(): array
	{
		$result = [];

		foreach ($this as $counter)
		{
			$result[] = $counter->chatId;
		}

		return $result;
	}
}
