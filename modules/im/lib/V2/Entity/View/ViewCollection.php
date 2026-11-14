<?php

namespace Bitrix\Im\V2\Entity\View;

use Bitrix\Im\Model\EO_MessageViewed_Collection;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\V2\Common\MySqlIndexHint;
use Bitrix\Im\V2\Common\TailPageSizeTrait;
use Bitrix\Im\V2\Entity\EntityCollection;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\ORM\Query\Query;

class ViewCollection extends EntityCollection
{
	use TailPageSizeTrait;

	private const MYSQL_TAIL_INDEX = 'IX_B_IM_MESSAGE_VIEWED_MESSAGE_ID_ID';

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

		$query = MessageViewedTable::query()->setOrder($viewOrder);

		if (isset($limit))
		{
			$query->setLimit($limit);
		}

		static::processFilters($query, $filter, $viewOrder);
		if (
			$query->getEntity()->getConnection()->getType() === 'mysql'
			&& isset($filter['MESSAGE_ID'])
			&& $limit !== null
		)
		{
			$ids = MySqlIndexHint::fetchIds($query, self::MYSQL_TAIL_INDEX);
			if (empty($ids))
			{
				return new static();
			}

			$query = MessageViewedTable::query()
				->setSelect(['ID', 'USER_ID', 'MESSAGE_ID', 'DATE_CREATE'])
				->whereIn('ID', $ids)
				->setOrder($viewOrder)
			;

			return (new static())
				->initByEntityCollection($query->fetchCollection())
				->setTailPageSize(count($ids))
			;
		}

		$query->setSelect(['ID', 'USER_ID', 'MESSAGE_ID', 'DATE_CREATE']);

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
