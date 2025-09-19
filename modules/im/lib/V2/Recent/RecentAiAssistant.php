<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\EO_Recent_Collection;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class RecentAiAssistant extends Recent
{
	protected const AVAILABLE_GROUP_TYPES = [Chat::IM_TYPE_AI_ASSISTANT, Chat::IM_TYPE_COPILOT];

	public static function getAiAssistantChats(int $limit, ?DateTime $lastMessageDate = null): self
	{
		$recent = new static();
		$userId = $recent->getContext()->getUserId();

		$recentEntities = static::getOrmEntities($limit, $userId, $lastMessageDate);

		$chatIds = $recentEntities->getItemCidList();
		$counters = (new CounterService($userId))->getForEachChat($chatIds);

		foreach ($recentEntities as $entity)
		{
			$recentItem =
				RecentItem::initByEntity($entity)
					->setCounter($counters[$entity->getItemCid()] ?? 0)
					->setLastReadMessageId($entity->getRelation()?->getLastId() ?? 0)
					->setDateUpdate($entity->getDateUpdate())
					->setDateLastActivity($entity->getDateLastActivity())
			;

			$recent[] = $recentItem;
		}

		return $recent;
	}

	protected static function getOrmEntities(int $limit, int $userId, ?DateTime $lastMessageDate = null): EO_Recent_Collection
	{
		$query = RecentTable::query()
			->setSelect([
				'ITEM_CID',
				'ITEM_MID',
				'UNREAD',
				'PINNED',
				'DATE_LAST_ACTIVITY',
				'DATE_UPDATE',
				'RELATION.LAST_ID',
			])
			->setLimit($limit)
			->setOrder(self::getOrder($userId))
		;

		self::processFilters($query, $userId, $lastMessageDate);

		return $query->fetchCollection();
	}

	protected static function processFilters(Query $query, int $userId, ?DateTime $lastMessageDate = null): void
	{
		$aiAssistantId = ServiceLocator::getInstance()->get(AiAssistantService::class)->getBotId();

		$personalAiChatFilter =
			Query::filter()
				->logic('and')
				->where('ITEM_TYPE', Chat::IM_TYPE_PRIVATE)
				->where('ITEM_ID', $aiAssistantId)
		;

		$chatAllowedTypesFilter =
			Query::filter()
				->logic('or')
				->whereIn('ITEM_TYPE', self::AVAILABLE_GROUP_TYPES)
				->where($personalAiChatFilter)
		;

		$query
			->where('USER_ID', $userId)
			->where($chatAllowedTypesFilter)
		;

		if (isset($lastMessageDate))
		{
			$query->where('DATE_LAST_ACTIVITY', '<=', $lastMessageDate);
		}
	}
}