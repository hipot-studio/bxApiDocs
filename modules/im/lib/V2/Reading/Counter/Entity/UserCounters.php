<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Entity;

use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Mute\UserMuteState;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
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

	/**
	 * A non-answered session only adds the flag on top of what the chat itself already contributes,
	 * so an already collected unit keeps its own data. The counter is raised to one only when the
	 * chat shows nothing, otherwise the indicator would have nothing to appear on.
	 */
	public function markNonAnswered(int $chatId, array $recentSections, Type $type): void
	{
		$existing = $this[$chatId] ?? null;

		$this->addCounter(new ChatCounter(
			chatId: $chatId,
			counter: max($existing?->counter ?? 0, 1),
			parentChatId: $existing?->parentChatId ?? 0,
			isMuted: $existing?->isMuted ?? false,
			isMarkedAsUnread: $existing?->isMarkedAsUnread ?? false,
			recentSections: $existing?->recentSections ?? $recentSections,
			type: $existing?->type ?? $type,
			requiresParentMembership: $existing?->requiresParentMembership ?? $type->requiresParentMembership,
			isNonAnswered: true,
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

	/**
	 * A copy of the set with the rules of the folders applied (TPL-01): a row is muted when its chat
	 * was silenced by hand or when a rule of its owner covers it.
	 *
	 * A copy and not a mutation on purpose: this very object is what the cache of the unprocessed
	 * representation holds ({@see \Bitrix\Im\V2\Reading\Counter\Internal\CountersCache}), and the
	 * client is the one who applies the rules to it itself.
	 */
	public function withFolderMuteApplied(
		UserMuteState $muteState,
		RecentConfigManager $recentConfig,
		TypeRegistry $typeRegistry,
	): self
	{
		if ($muteState->isEmpty())
		{
			return $this;
		}

		$processed = new self();
		$processed->setNotificationCounter($this->notificationCounter);

		foreach ($this as $counter)
		{
			$covered = !$counter->isMuted
				&& $muteState->covers(
					$counter->chatId,
					self::resolveRecentSections(
						$counter->type,
						$counter->parentChatId,
						$recentConfig,
						$typeRegistry,
					),
					$counter->parentChatId,
				)
			;

			$processed->addCounter($covered ? $counter->with(isMuted: true) : $counter);
		}

		return $processed;
	}

	/**
	 * Sections of a counter row, derived the way the collector derives them
	 * ({@see \Bitrix\Im\V2\Reading\Counter\UserCountersCollector::getRecentSections()}) and not read
	 * from the row: the collector deliberately stores empty sections for a parent hidden from the
	 * recent list, and a rule of a system folder still covers such a parent.
	 *
	 * Derived the same way includes the exclusions: a chat kept out of a section is not in the
	 * folder of that section, so a rule of that folder must not silence it. Without the filter this
	 * answer would be wider than the one the counter row carries, and a chat would be silenced by a
	 * folder whose badge never counted it.
	 *
	 * @return string[]
	 */
	public static function resolveRecentSections(
		Type $type,
		int $parentChatId,
		RecentConfigManager $recentConfig,
		TypeRegistry $typeRegistry,
	): array
	{
		$sections = $parentChatId > 0
			? $recentConfig->getAllRecentSectionsByType($type)
			: $recentConfig->getBaseRecentSections($type)
		;

		return array_values(array_filter(
			$sections,
			static fn(string $section): bool => !$typeRegistry->isTypeExcludedFromSection($type, $section),
		));
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
