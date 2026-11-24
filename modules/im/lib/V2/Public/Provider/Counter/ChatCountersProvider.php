<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Counter;

use Bitrix\Im\V2\Chat\Tree\ChatTreeResolver;
use Bitrix\Im\V2\Chat\Tree\ResolvedChatNode;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Mute\MuteProvider;
use Bitrix\Im\V2\Folder\Mute\UserMuteState;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterCollection;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterItem;
use Bitrix\Im\V2\Public\Provider\Params\Counter\ChatCountersParams;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Im\V2\Reading\Counter\Provider\MessageParamUnreadCounterProvider;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\DI\ServiceLocator;

final class ChatCountersProvider
{
	/**
	 * The same chunk as {@see MessageParamUnreadCounterProvider} reads with: past 3000-5000 ids in one IN the
	 * plan stops using the index by chat.
	 */
	private const CHAT_IDS_PER_QUERY = 1500;

	private readonly CountersProvider $countersProvider;

	public function __construct(
		private readonly UserCountersCollector $userCountersCollector,
		private readonly ChatTreeResolver $chatTreeResolver,
		private readonly MessageParamUnreadCounterProvider $messageParamUnreadCounterProvider,
		private readonly MuteProvider $muteProvider,
		private readonly RecentConfigManager $recentConfigManager,
		private readonly TypeRegistry $typeRegistry,
		?CountersProvider $countersProvider = null,
	)
	{
		// Optional and last, so that the callers building the provider by hand keep working.
		$this->countersProvider = $countersProvider ?? ServiceLocator::getInstance()->get(CountersProvider::class);
	}

	public function get(ChatCountersParams $params): ChatCounterCollection
	{
		if ($params->userId <= 0 || $params->isEmpty())
		{
			return ChatCounterCollection::empty();
		}

		// The processed representation of the counters (TPL-01): this consumer knows nothing about the
		// rules of the folders, so the server answers it with the final state of every row.
		$counters = $this->userCountersCollector->get($params->userId);
		$muteState = $this->muteProvider->getForUser($params->userId);
		$collection = ChatCounterCollection::fromUserCounters(
			$counters->withFolderMuteApplied($muteState, $this->recentConfigManager, $this->typeRegistry),
			$params->chatIds,
		)->merge(
			$this->buildMissingRootCollection($params->userId, $params->chatIds, $counters, $muteState),
		);

		return $this->applyMessageParamExclusion($collection, $params);
	}

	/**
	 * Own counters of the requested chats, read by these chats only: no snapshot of all counters of
	 * the user, no chat tree and no folder rules, so the cost does not grow with the other chats of
	 * the user. Unlike get(), the most recent chats cap does not apply and membership is not checked:
	 * access to the chats is the caller's concern. The user is the one of the request context, and the
	 * answer is no access check: it is read from unread rows only. Open lines do not belong here: their
	 * non-answered flag is counted only when the snapshot of the user happens to be cached.
	 *
	 * @return array<int, int> chatId => own counter, a key for every requested chat
	 */
	public function getOwnCounters(ChatCountersParams $params): array
	{
		if ($params->userId <= 0 || $params->isEmpty())
		{
			return [];
		}

		$counters = [];
		foreach (array_chunk($params->chatIds, self::CHAT_IDS_PER_QUERY) as $chatIds)
		{
			$counters += $this->countersProvider->getForUserByChatIds($params->userId, $chatIds)->getRaw();
		}

		if ($params->excludeByMessageParamNames === [])
		{
			return $counters;
		}

		// A counter below the threshold is exact, so the overflow table is not asked about it again. A counter
		// at the threshold is either the overflow state or real unread rows left after a partial read has
		// dropped the overflow row; the subtraction tells them apart by the table.
		$overflowValue = CounterOverflowService::getOverflowValue();
		$belowThreshold = [];
		$atThreshold = [];
		foreach ($counters as $chatId => $counter)
		{
			if ($counter >= $overflowValue)
			{
				$atThreshold[] = $chatId;
			}
			elseif ($counter > 0)
			{
				$belowThreshold[] = $chatId;
			}
		}

		$names = $params->excludeByMessageParamNames;
		$subtrahends = [];
		if ($belowThreshold !== [])
		{
			$subtrahends += $this->messageParamUnreadCounterProvider
				->getForUserByChatIdsBelowOverflow($params->userId, $belowThreshold, $names)
				->getRaw()
			;
		}
		if ($atThreshold !== [])
		{
			$subtrahends += $this->messageParamUnreadCounterProvider
				->getForUserByChatIds($params->userId, $atThreshold, $names)
				->getRaw()
			;
		}

		foreach ($subtrahends as $chatId => $subtrahend)
		{
			$counters[$chatId] = max(0, $counters[$chatId] - $subtrahend);
		}

		return $counters;
	}

	private function applyMessageParamExclusion(
		ChatCounterCollection $collection,
		ChatCountersParams $params,
	): ChatCounterCollection
	{
		if ($params->excludeByMessageParamNames === [])
		{
			return $collection;
		}

		$chatIds = [];
		foreach ($collection->getAllItems() as $item)
		{
			if ($item->ownCounter > 0)
			{
				$chatIds[] = $item->chatId;
			}
		}

		if ($chatIds === [])
		{
			return $collection;
		}

		$subtrahends = $this->messageParamUnreadCounterProvider
			->getForUserByChatIds($params->userId, $chatIds, $params->excludeByMessageParamNames)
			->getRaw()
		;

		if ($subtrahends === [])
		{
			return $collection;
		}

		return $collection->withSubtrahends($subtrahends);
	}

	/**
	 * The sections of a node are derived from its type, the way the collector derives them for a
	 * counter row ({@see UserCounters::resolveRecentSections()}): the node carries no row of its own.
	 */
	private function isCoveredByRule(ResolvedChatNode $node, UserMuteState $muteState): bool
	{
		if ($muteState->isEmpty())
		{
			return false;
		}

		$type = $this->typeRegistry->getByLiteralAndEntity($node->chatType, $node->entityType);

		return $muteState->covers(
			$node->chatId,
			UserCounters::resolveRecentSections(
				$type,
				$node->parentChatId,
				$this->recentConfigManager,
				$this->typeRegistry,
			),
			$node->parentChatId,
		);
	}

	/**
	 * @param int[] $requestedChatIds
	 */
	private function buildMissingRootCollection(
		int $userId,
		array $requestedChatIds,
		UserCounters $counters,
		UserMuteState $muteState,
	): ChatCounterCollection
	{
		$missingRootIds = [];
		foreach ($requestedChatIds as $chatId)
		{
			if ($counters->getByChatId($chatId) === null)
			{
				$missingRootIds[] = $chatId;
			}
		}

		if ($missingRootIds === [])
		{
			return ChatCounterCollection::empty();
		}

		$resolvedNodes = $this->chatTreeResolver->resolveForUser($userId, $missingRootIds, $counters->getChatIds());
		if ($resolvedNodes === [])
		{
			return ChatCounterCollection::empty();
		}

		$missingRootMap = array_fill_keys($missingRootIds, true);
		$items = [];
		$parentByChatId = [];

		foreach ($resolvedNodes as $node)
		{
			$parentByChatId[$node->chatId] = $node->parentChatId;
			if (!isset($missingRootMap[$node->chatId]))
			{
				continue;
			}

			// These rows are built past the collector, so the rules are applied to them here as well.
			$items[$node->chatId] = new ChatCounterItem(
				chatId: $node->chatId,
				parentChatId: $node->parentChatId,
				ownCounter: 0,
				isMuted: $node->isMuted || $this->isCoveredByRule($node, $muteState),
			);
		}

		if ($items === [])
		{
			return ChatCounterCollection::empty();
		}

		return ChatCounterCollection::fromItems($items, $parentByChatId);
	}
}
