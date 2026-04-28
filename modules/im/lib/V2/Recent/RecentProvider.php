<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\Recent\Internal\RecentItemCache;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;

class RecentProvider
{
	public function __construct(
		private readonly RecentItemCache $cache,
		private readonly TypeRegistry $typeRegistry,
	) {}

	public function getList(RecentParams $params): Recent
	{
		$typeCondition = $this->buildTypeCondition();
		$filter = $params->filter?->with(typeCondition: $typeCondition) ?? new RecentFilter(typeCondition: $typeCondition);

		$enrichedParams = new RecentParams(
			filter: $filter,
			limit: $params->limit,
			order: $params->order,
		);

		$recentEntities = Recent::getRecentEntities($enrichedParams);

		return Recent::initByArray($recentEntities);
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
		$excludeTypes = [
			$this->typeRegistry->getByExtendedType(ExtendedType::Comment->value),
			$this->typeRegistry->getByExtendedType(ExtendedType::System->value),
		];

		if (!CopilotChat::isActive())
		{
			$excludeTypes[] = $this->typeRegistry->getByExtendedType(ExtendedType::Copilot->value);
		}

		if (!Collab::isAvailable())
		{
			$excludeTypes[] = $this->typeRegistry->getByExtendedType(ExtendedType::Collab->value);
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
}
