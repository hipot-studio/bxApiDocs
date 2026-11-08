<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\GetUnreadCountersDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolFormatter;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterAggregationOptions;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterCollection;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatCounter;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Main\DI\ServiceLocator;

class GetUnreadCountersTool extends BaseImTool
{
	private const DEFAULT_LIMIT = 100;
	private const MAX_LIMIT = 200;

	protected function getScope(): ToolScope
	{
		return ToolScope::Read;
	}

	public function getName(): string
	{
		return 'get_unread_counters';
	}

	public function getDescription(): string
	{
		return 'Returns unread message counters. With a dialogId, returns the counter for that single chat: '
			. 'its own unread messages only, matching the chat badge in the UI; unread messages in its child chats '
			. '(threads/comments) are not included. Without a dialogId, returns an all-chats summary: authoritative '
			. 'totals (chatsTotal, messagesTotal, mutedMessagesTotal) plus chatsWithUnread — a limited, '
			. 'deterministically ordered sample of root (top-level, non-thread) chats that have unread messages, '
			. 'where each counter aggregates the root chat together with all of its descendants. The summary also '
			. 'returns mutedChatsTotal and, when includeNotifications is true, notificationsTotal. The sample is '
			. 'capped by limit and is not a full paginated list; rely on chatsTotal and messagesTotal for '
			. 'authoritative counts. The totals cover the most recently active unread chats up to a portal-wide cap '
			. '(1000 chats by default, shared with the messenger UI counters); when the cap is reached, '
			. 'totalsMayBeTruncated is true and the totals may undercount. Note that the type vocabularies differ: '
			. 'in the single-chat response, chat.type '
			. 'is a one-letter chat type code (e.g. "C" for a group chat, "P" for a private chat), while in '
			. 'chatsWithUnread items type is a camelCase extended chat type (e.g. "chat", "private", "collab") and '
			. 'messageType holds the one-letter code.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'Optional dialog identifier. If omitted, an all-chats summary is returned.',
					'type' => 'string',
					'pattern' => '^(chat\d+|\d+)$',
				],
				'includeNotifications' => [
					'description' => 'Whether the notification counter should be included in the all-chats summary.',
					'type' => 'boolean',
					'default' => true,
				],
				'limit' => [
					'description' => 'Maximum number of chats to include in the chatsWithUnread sample of the '
						. 'all-chats summary. The sample is ordered by unread counter descending, then chatId '
						. 'ascending; authoritative totals are chatsTotal and messagesTotal.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'default' => self::DEFAULT_LIMIT,
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();
		$formatter = new ImToolFormatter();

		$dto = GetUnreadCountersDto::createFromParams($args);
		$context->validateDto($dto);

		if (isset($dto->dialogId))
		{
			return $this->getChatCounter($userId, $dto->dialogId, $context, $formatter);
		}

		return $this->getSummary($userId, $dto, $formatter);
	}

	private function getChatCounter(
		int $userId,
		string $dialogId,
		ImToolContext $context,
		ImToolFormatter $formatter,
	): array
	{
		$chat = $context->resolveChatByDialogId($userId, $dialogId);
		$context->assertChatAccess($chat, $userId);

		/** @var CountersProvider $counterProvider */
		$counterProvider = ServiceLocator::getInstance()->get(CountersProvider::class);

		$chatId = (int)$chat->getChatId();
		$relation = $chat->getRelationByUserId($userId);

		return [
			'scope' => 'chat',
			'chat' => $formatter->formatChat($chat, $userId),
			// The chat's own counter (UI parity: the chat list badge shows own unread messages only);
			// descendants (threads/comments) are aggregated only in the all-chats summary.
			'counter' => $counterProvider->getForUser($chatId, $userId),
			// Point reads over the same raw sources UserCountersCollector aggregates from
			// (RELATION.NOTIFY_BLOCK, RECENT.UNREAD); the full collector serves the summary only.
			'isMuted' => (bool)($relation?->getNotifyBlock() ?? false),
			'isMarkedAsUnread' => $counterProvider->isChatMarkedAsUnread($chatId, $userId),
		];
	}

	private function getSummary(int $userId, GetUnreadCountersDto $dto, ImToolFormatter $formatter): array
	{
		/** @var UserCountersCollector $collector */
		$collector = ServiceLocator::getInstance()->get(UserCountersCollector::class);
		$userCounters = $collector->get($userId);
		$totalsMayBeTruncated = $this->totalsMayBeTruncated($userCounters, $collector->getChatsLimit());

		$rootChatIds = $this->collectRootChatIds($userCounters);
		$collection = ChatCounterCollection::fromUserCounters($userCounters, $rootChatIds);

		// Mirror the UI totals: default options count muted roots/descendants exactly once.
		$messagesTotal = $collection->getTotalCount();
		$unmutedTotal = $collection->getTotalCount(
			new ChatCounterAggregationOptions(includeMutedDescendants: false, includeMutedRoots: false),
		);
		$mutedMessagesTotal = $messagesTotal - $unmutedTotal;

		$unreadRoots = $this->collectUnreadRoots($userCounters, $collection, $rootChatIds);
		$chatsTotal = count($unreadRoots);
		$mutedChatsTotal = 0;
		foreach ($unreadRoots as $root)
		{
			if ($root['counter']->isMuted)
			{
				$mutedChatsTotal++;
			}
		}

		usort(
			$unreadRoots,
			static fn (array $a, array $b): int =>
				($b['aggregated'] <=> $a['aggregated']) ?: ($a['counter']->chatId <=> $b['counter']->chatId),
		);

		$sample = array_slice($unreadRoots, 0, $dto->limit);
		$sampleCounters = [];
		$aggregatedByChatId = [];
		foreach ($sample as $root)
		{
			$sampleCounters[] = $root['counter'];
			$aggregatedByChatId[$root['counter']->chatId] = $root['aggregated'];
		}

		return [
			'scope' => 'all',
			'includeNotifications' => $dto->includeNotifications,
			'messagesTotal' => $messagesTotal,
			'notificationsTotal' => $dto->includeNotifications ? $userCounters->getNotificationCounter() : 0,
			'chatsTotal' => $chatsTotal,
			'mutedChatsTotal' => $mutedChatsTotal,
			'mutedMessagesTotal' => $mutedMessagesTotal,
			'totalsMayBeTruncated' => $totalsMayBeTruncated,
			'chatsWithUnread' => $formatter->formatChatCounterList($sampleCounters, $aggregatedByChatId, $userId),
			'pagination' => [
				'limit' => $dto->limit,
			],
		];
	}

	/**
	 * The collector caps the per-chat message counters at getChatsLimit() most recently active chats,
	 * so for a user with unread messages in more chats the summary totals undercount (same cap as the
	 * messenger UI counters). Once the cap is visibly reached, the truncation is reported to the caller.
	 * The notification (system) chat competes for a slot of the capped selection but is routed to
	 * notificationCounter instead of the iterable chat counters, so it is accounted for separately.
	 * Best-effort: entries dropped after the capped selection (e.g. stale non-member residue) may hide
	 * an exactly-at-cap state, and an exactly-at-cap state without truncation still raises the flag.
	 */
	private function totalsMayBeTruncated(UserCounters $userCounters, int $chatsLimit): bool
	{
		$chatsWithMessageCounter = $userCounters->getNotificationCounter() > 0 ? 1 : 0;
		foreach ($userCounters as $counter)
		{
			if ($counter->counter > 0)
			{
				$chatsWithMessageCounter++;
			}
		}

		return $chatsWithMessageCounter >= $chatsLimit;
	}

	/**
	 * A counter whose parent is absent from UserCounters is a root too: the collector keeps such
	 * "orphans" only for types with requiresParentMembership=false (e.g. calendar event chats),
	 * where the user legitimately reads the chat without being a member of its parent. Otherwise
	 * their unread messages would vanish from both the totals and the chatsWithUnread sample.
	 *
	 * @return int[]
	 */
	private function collectRootChatIds(UserCounters $userCounters): array
	{
		$rootChatIds = [];
		foreach ($userCounters as $counter)
		{
			if (
				$counter->parentChatId === 0
				|| $userCounters->getByChatId($counter->parentChatId) === null
			)
			{
				$rootChatIds[] = $counter->chatId;
			}
		}

		return $rootChatIds;
	}

	/**
	 * @param int[] $rootChatIds
	 * @return array<int, array{counter: ChatCounter, aggregated: int}>
	 */
	private function collectUnreadRoots(
		UserCounters $userCounters,
		ChatCounterCollection $collection,
		array $rootChatIds,
	): array
	{
		$roots = [];
		foreach ($rootChatIds as $chatId)
		{
			$counter = $userCounters->getByChatId($chatId);
			if ($counter === null)
			{
				continue;
			}

			$aggregated = $collection->getTotalCountForChat($chatId) ?? 0;
			if ($aggregated <= 0 && !$counter->isMarkedAsUnread)
			{
				continue;
			}

			$roots[] = ['counter' => $counter, 'aggregated' => $aggregated];
		}

		return $roots;
	}
}
