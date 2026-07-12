<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\Recent\Internal\RecentItemCache;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Main\Type\DateTime;

class RecentProvider
{
	public function __construct(
		private readonly RecentItemCache $cache,
		private readonly TypeRegistry $typeRegistry,
		private readonly SectionMetaProvider $sectionMetaProvider,
	) {}

	public function getSection(RecentParams $params): RecentSection
	{
		$meta = $this->sectionMetaProvider->getMeta(
			$params->filter->parentChatId,
			$params->filter->recentSection,
			$params->filter->userId,
		);

		$recent = $this->getList($params);

		$hasNextPage = $recent->count() >= $params->limit;

		$this->mergeFixedItems($recent, $params->filter->userId, $meta);

		return new RecentSection($recent, $meta, $hasNextPage);
	}

	public function getList(RecentParams $params): Recent
	{
		$typeCondition = $this->buildTypeCondition();
		$filter = $params->filter->with(typeCondition: $typeCondition);

		$enrichedParams = new RecentParams(
			filter: $filter,
			limit: $params->limit,
			order: $params->order,
		);

		$recentEntities = Recent::getRecentEntities($enrichedParams);

		return Recent::initByArray($recentEntities);
	}

	public function getItemsByChatIds(int $userId, array $chatIds): Recent
	{
		$filter = new RecentFilter(userId: $userId, chatIds: $chatIds);
		$params = new RecentParams(filter: $filter);

		return Recent::initByArray(Recent::getRecentEntities($params));
	}

	public function countPinned(int $userId): int
	{
		return RecentTable::getCount([
			'=USER_ID' => $userId,
			'=PINNED' => 'Y',
		]);
	}

	public function getItem(int $userId, int $chatId): ?RecentItem
	{
		if ($this->cache->has($userId, $chatId))
		{
			return $this->cache->get($userId, $chatId);
		}

		$rawItem = $this->fetchItem($userId, $chatId);
		if (!$rawItem)
		{
			$this->cache->setMissing($userId, $chatId);

			return null;
		}

		$item = $this->buildItem($rawItem);
		$this->cache->set($userId, $chatId, $item);

		return $item;
	}

	private function buildTypeCondition(): TypeCondition
	{
		return new TypeCondition(exclude: $this->getExcludedTypes());
	}

	/**
	 * @return Type[]
	 */
	private function getExcludedTypes(): array
	{
		$excludeTypes = array_merge(
			$this->typeRegistry->getAllByExtendedType(ExtendedType::Comment->value),
			$this->typeRegistry->getAllByExtendedType(ExtendedType::System->value),
		);

		if (!CopilotChat::isHistoryAvailable())
		{
			array_push($excludeTypes, ...$this->typeRegistry->getAllByExtendedType(ExtendedType::Copilot->value));
		}

		if (!Collab::isAvailable())
		{
			array_push($excludeTypes, ...$this->typeRegistry->getAllByExtendedType(ExtendedType::Collab->value));
		}

		return $excludeTypes;
	}

	private function fetchItem(int $userId, int $chatId): ?array
	{
		return RecentTable::query()
			->setSelect([
				'ITEM_CID',
				'ITEM_MID',
				'UNREAD',
				'PINNED',
				'DATE_LAST_ACTIVITY',
				'DATE_UPDATE',
				'RELATION.LAST_ID',
			])
			->where('USER_ID', $userId)
			->where('ITEM_CID', $chatId)
			->setLimit(1)
			->fetch() ?: null
		;
	}

	private function buildItem(array $rawItem): RecentItem
	{
		return RecentItem::initByArray($rawItem);
	}

	private function mergeFixedItems(Recent $recent, int $userId, SectionMeta $meta): void
	{
		$fixedChatIds = $meta->getFixedChatIds();
		if (empty($fixedChatIds))
		{
			return;
		}

		$existingChatIds = [];
		foreach ($recent as $item)
		{
			$existingChatIds[$item->getChatId()] = true;
		}

		$missingChatIds = array_filter(
			$fixedChatIds,
			static fn(int $chatId) => !isset($existingChatIds[$chatId]),
		);

		if (empty($missingChatIds))
		{
			return;
		}

		$fixedItems = $this->getItemsByChatIds($userId, array_values($missingChatIds));

		$loadedChatIds = [];
		foreach ($fixedItems as $item)
		{
			$loadedChatIds[$item->getChatId()] = true;
		}

		foreach ($missingChatIds as $chatId)
		{
			if (isset($loadedChatIds[$chatId]))
			{
				continue;
			}

			$synthetic = $this->synthesizeFixedItem($userId, $chatId);
			if ($synthetic !== null)
			{
				$fixedItems[] = $synthetic;
			}
		}

		$recent->mergeRegistry($fixedItems);
	}

	private function synthesizeFixedItem(int $userId, int $chatId): ?RecentItem
	{
		$chat = Chat::getInstance($chatId);
		if ($chat instanceof Chat\NullChat)
		{
			return null;
		}

		if (!$chat->checkAccess($userId)->isSuccess())
		{
			return null;
		}

		$lastMessage = $chat->getLastMessage();
		$lastActivity = $lastMessage?->getDateCreate()
			?? $chat->getDateCreate()
			?? new DateTime();

		return (new RecentItem())
			->setChatId($chatId)
			->setDialogId('chat' . $chatId)
			->setMessageId($lastMessage?->getId() ?? 0)
			->setLastReadMessageId(0)
			->setUnread(false)
			->setPinned(false)
			->setDateLastActivity($lastActivity)
			->setDateUpdate($lastActivity)
		;
	}
}
