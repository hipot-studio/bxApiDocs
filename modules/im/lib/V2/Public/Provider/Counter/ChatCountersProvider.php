<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Counter;

use Bitrix\Im\V2\Chat\Tree\ChatTreeResolver;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterCollection;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterItem;
use Bitrix\Im\V2\Public\Provider\Params\Counter\ChatCountersParams;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Im\V2\Reading\Counter\Provider\MessageParamUnreadCounterProvider;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;

final class ChatCountersProvider
{
	public function __construct(
		private readonly UserCountersCollector $userCountersCollector,
		private readonly ChatTreeResolver $chatTreeResolver,
		private readonly MessageParamUnreadCounterProvider $messageParamUnreadCounterProvider,
	)
	{}

	public function get(ChatCountersParams $params): ChatCounterCollection
	{
		if ($params->userId <= 0 || $params->isEmpty())
		{
			return ChatCounterCollection::empty();
		}

		$counters = $this->userCountersCollector->get($params->userId);
		$collection = ChatCounterCollection::fromUserCounters($counters, $params->chatIds)->merge(
			$this->buildMissingRootCollection($params->userId, $params->chatIds, $counters),
		);

		return $this->applyMessageParamExclusion($collection, $params);
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
	 * @param int[] $requestedChatIds
	 */
	private function buildMissingRootCollection(int $userId, array $requestedChatIds, UserCounters $counters): ChatCounterCollection
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

			$items[$node->chatId] = new ChatCounterItem(
				chatId: $node->chatId,
				parentChatId: $node->parentChatId,
				ownCounter: 0,
				isMuted: $node->isMuted,
			);
		}

		if ($items === [])
		{
			return ChatCounterCollection::empty();
		}

		return ChatCounterCollection::fromItems($items, $parentByChatId);
	}
}
