<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Type\Date;

abstract class BaseCollector
{
	use Singleton;

	protected int $limit = 50;
	protected bool $isOnlyCalc = false;
	protected ?Date $date = null;
	protected const MAX_DAYS_FOR_LAST_CLOSED_ENTITY_FILTER = 365;

	public function setLimit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	public function setIsOnlyCalc(bool $isOnlyCalc): self
	{
		$this->isOnlyCalc = $isOnlyCalc;

		return $this;
	}

	public function setDate(?Date $date): self
	{
		$this->date = $date;

		return $this;
	}

	public function getSegmentData(
		int $entityTypeId,
		?int $lastItemId = null,
		int $minimumDaysAfterLastClosedEntity = 0,
	): SegmentDataInterface
	{
		$filter = [];
		if ($lastItemId > 0)
		{
			$filter['>ID'] = $lastItemId;
		}

		return $this->createSegmentData($entityTypeId, $filter, $minimumDaysAfterLastClosedEntity);
	}

	abstract protected function createSegmentData(
		int $entityTypeId,
		array $filter,
		int $minimumDaysAfterLastClosedEntity,
	): SegmentDataInterface;

	final protected function getItemsByIds(array $ids, int $entityTypeId): array
	{
		if (empty($ids))
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory?->getItems([
			'select' => $this->getItemFields(),
			'filter' => [
				'@ID' => $ids,
			],
		]) ?? [];
	}

	protected function getItemFields(): array
	{
		return ['ID'];
	}

	protected function filterItemsWithRecentlyClosedEntities(
		array $ids,
		int $entityTypeId,
		int $minimumDaysAfterLastClosedEntity,
	): array
	{
		if ($minimumDaysAfterLastClosedEntity <= 0 || empty($ids))
		{
			return $ids;
		}

		$minimumDaysAfterLastClosedEntity = min(
			$minimumDaysAfterLastClosedEntity,
			self::MAX_DAYS_FOR_LAST_CLOSED_ENTITY_FILTER,
		);
		$entityClosedDate = (new \Bitrix\Main\Type\DateTime())->add('- ' . $minimumDaysAfterLastClosedEntity . ' days');

		/*
		 * I put this logic in if-statements on purpose so it will be easier in future to pull it into separate classes
		 */
		if ($entityTypeId === \CCrmOwnerType::Company)
		{
			$entityFieldName = 'COMPANY_ID';

			$itemsWithRecentlyClosedEntities =
				DealTable::query()
					->addSelect($entityFieldName)
					->setDistinct()
					->whereIn($entityFieldName, $ids)
					->where('CLOSED', '=', 'Y')
					->where('CLOSEDATE', '>', $entityClosedDate)
					->exec()
					->fetchAll()
			;
		}
		elseif ($entityTypeId === \CCrmOwnerType::Contact)
		{
			$entityFieldName = 'CONTACT_ID';

			$recentlyClosedEntities =
				DealTable::query()
					->addSelect('ID')
					->whereIn($entityFieldName, $ids)
					->where('CLOSED', '=', 'Y')
					->where('CLOSEDATE', '>', $entityClosedDate)
					->exec()
					->fetchAll()
			;

			if (empty($recentlyClosedEntities))
			{
				return $ids;
			}

			$recentlyClosedEntitiesIds = array_column($recentlyClosedEntities, 'ID');

			$itemsWithRecentlyClosedEntities =
				DealContactTable::query()
					->addSelect($entityFieldName)
					->setDistinct()
					->whereIn('DEAL_ID', $recentlyClosedEntitiesIds)
					->exec()
					->fetchAll()
			;
		}
		else
		{
			return $ids;
		}

		$itemsWithRecentlyClosedEntities = array_column(
			$itemsWithRecentlyClosedEntities,
			$entityFieldName,
		);

		return array_diff($ids, $itemsWithRecentlyClosedEntities);
	}
}
