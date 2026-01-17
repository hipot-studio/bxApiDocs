<?php

namespace Bitrix\Im\V2\Entity\View;

use Bitrix\Im\Model\EO_MessageViewed_Collection;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\V2\Entity\EntityCollection;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\ORM\Query\Query;

class ViewCollection extends EntityCollection
{
	public static function find(
		array $filter,
		array $order = ['ID' => 'DESC'],
		?int $limit = null,
		?Context $context = null
	): self
	{
		$viewOrder = ['ID' => 'DESC'];

		if (isset($order['ID']))
		{
			$viewOrder['ID'] = $order['ID'];
		}

		$query = MessageViewedTable::query()
			->setSelect(['ID', 'USER_ID', 'MESSAGE_ID', 'DATE_CREATE'])
			->setOrder($viewOrder)
		;

		if (isset($limit))
		{
			$query->setLimit($limit);
		}

		static::processFilters($query, $filter, $viewOrder);

		return (new static())->initByEntityCollection($query->fetchCollection());
	}

	public static function getRestEntityName(): string
	{
		return 'views';
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		$basePopupData = new PopupData([new UserPopupItem()], $excludedList);
		$entitiesPopupData = parent::getPopupData($excludedList);

		return $basePopupData->merge($entitiesPopupData);
	}

	private function initByEntityCollection(EO_MessageViewed_Collection $collection): self
	{
		foreach ($collection as $item)
		{
			$this[] = new ViewItem($item->getId(), $item->getMessageId(), $item->getUserId(), $item->getDateCreate());
		}

		return $this;
	}

	protected static function processFilters(Query $query, array $filter, array $order)
	{
		if (isset($filter['LAST_ID']))
		{
			$operator = $order['ID'] === 'DESC' ? '<' : '>';
			$query->where('ID', $operator, $filter['LAST_ID']);
		}
		if (isset($filter['MESSAGE_ID']))
		{
			$query->where('MESSAGE_ID', (int)$filter['MESSAGE_ID']);
		}
	}
}