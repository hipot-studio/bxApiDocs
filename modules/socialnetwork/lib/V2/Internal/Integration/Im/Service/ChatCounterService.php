<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterAggregationOptions;
use Bitrix\Im\V2\Public\Dto\Counter\ChatCounterCollection;
use Bitrix\Im\V2\Public\Provider\Counter\ChatCountersProvider;
use Bitrix\Im\V2\Public\Provider\Params\Counter\ChatCountersParams;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity\Counter;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterCollection;

class ChatCounterService
{
	private ?ChatCountersProvider $chatCounterProvider = null;

	public function __construct(
		private readonly ProjectChatResolver $projectChatResolver,
	)
	{
	}

	public function getTotalCountForGroupIds(int $userId, array $groupIds): int
	{
		if (!$this->isAvailable())
		{
			return 0;
		}

		$projectChatIdMap = $this->projectChatResolver->getByProjectIds($groupIds);

		$collection = null;
		$chatIds = array_values($projectChatIdMap);
		if ($projectChatIdMap)
		{
			$collection = $this->getChatCounterCollection($userId, $chatIds);
		}

		return $collection
			? $collection->getTotalCount($this->buildAggregationOptions())
			: 0;
	}

	public function getCounterCollectionByGroupIds(int $userId, array $groupIds): CounterCollection
	{
		if (!$this->isAvailable())
		{
			return new CounterCollection();
		}

		$projectChatIdMap = $this->projectChatResolver->getByProjectIds($groupIds);

		return $this->getCounterCollection($userId, $projectChatIdMap);
	}

	private function getCounterCollection(int $userId, array $projectChatIdMap): CounterCollection
	{
		$chatCollection = $this->getChatCounterCollection($userId, array_values($projectChatIdMap));

		$collection = new CounterCollection();
		$aggregationOptions = $this->buildAggregationOptions();

		foreach ($projectChatIdMap as $projectId => $chatId)
		{
			$chatId = (int)$chatId;
			$value = $chatId
				? (int)$chatCollection->getTotalCountForChat($chatId, $aggregationOptions)
				: 0;

			$collection->add(
				new Counter(
					groupId: (int)$projectId,
					value: $value,
					color: null,
				),
			);
		}

		return $collection;
	}

	private function getChatCounterCollection(int $userId, array $chatIds): ChatCounterCollection
	{
		return $this->getChatCounterProvider()->get(
			ChatCountersParams::forChats($userId, $chatIds),
		);
	}

	protected function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function getChatCounterProvider(): ChatCountersProvider
	{
		return $this->chatCounterProvider ??= ServiceLocator::getInstance()->get(ChatCountersProvider::class);
	}

	private function buildAggregationOptions(): ChatCounterAggregationOptions
	{
		return ChatCounterAggregationOptions::withoutMuted();
	}
}
