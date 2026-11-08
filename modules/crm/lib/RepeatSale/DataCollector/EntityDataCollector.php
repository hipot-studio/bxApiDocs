<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\Item;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityDataCollector;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyFactory;
use Bitrix\Crm\RepeatSale\DataCollector\Mapper\SystemFieldsMapper;
use Bitrix\Crm\RepeatSale\DataCollector\Mapper\UserFieldsMapper;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

class EntityDataCollector extends BaseDataCollector
{
	protected const DEFAULT_LIMIT = 5;

	protected const SUPPORTED_ENTITY_TYPES = [
		CCrmOwnerType::Deal,
	];

	/** @var array<int, Item|null> the same deal is asked for by id twice: for its markers and for the aggregate */
	private array $fetchedItems = [];

	/**
	 * `baseEntityId` names the base deal of the client: it is counted in the purchases aggregate but
	 * stays out of the returned history. Access to it is the caller's business - the collector reads
	 * the deal by id and checks no rights of its own.
	 */
	public function getMarkers(array $parameters = []): array
	{
		$emptyResult = [[], []];

		$filter = $this->prepareCopilotMarkersFilter(
			entityId: (int)($parameters['entityId'] ?? 0),
			clientIdentifiers: $parameters['clientIdentifiers'] ?? [],
			excludeId: (int)($parameters['excludeId'] ?? 0),
		);

		if (empty($filter))
		{
			return $emptyResult;
		}

		$items = $this->getData([
			'filter' => $filter,
			'order' => [
				Item::FIELD_NAME_CREATED_TIME => 'DESC',
			],
			'limit' => $this->getLimit(),
		]);

		$purchases = $this->withBaseItem($items, (int)($parameters['baseEntityId'] ?? 0));
		if (empty($purchases))
		{
			return $emptyResult;
		}

		$itemsData = $this->getExternalData($items);
		// the same deals in the same order unless the base deal joined them - no reason to map twice
		$purchasesData = $purchases === $items ? $itemsData : $this->getExternalData($purchases);

		return [
			$this->getEntityList($itemsData),
			$this->getOrdersSummary($purchasesData),
		];
	}

	public function getMarkersForEntity(int $entityId): array
	{
		if ($entityId <= 0)
		{
			return [];
		}

		$item = $this->fetchItem($entityId);
		if ($item === null)
		{
			return [];
		}

		return $this->getEntityList($this->getExternalData([$item]))[$entityId] ?? [];
	}

	/**
	 * The base deal is a purchase of the client, but it never gets into the selection as one: the tip
	 * excludes it by filter to pass it apart, and for the screening it falls out as soon as the client
	 * has a whole selection of newer deals. The aggregate has to count it either way, and exactly once.
	 *
	 * @param Item[] $items
	 *
	 * @return Item[]
	 */
	private function withBaseItem(array $items, int $baseEntityId): array
	{
		if ($baseEntityId <= 0)
		{
			return $items;
		}

		foreach ($items as $item)
		{
			if ($item->getId() === $baseEntityId)
			{
				return $items;
			}
		}

		$baseItem = $this->fetchItem($baseEntityId);
		if ($baseItem === null)
		{
			return $items;
		}

		$items[] = $baseItem;

		// the latest purchase is taken by the order of the selection, and the base deal joins it anywhere;
		// the id breaks a tie on the date, otherwise the appended deal would always lose an equal one
		usort(
			$items,
			static fn (Item $first, Item $second): int => [
				self::getCreationTimestamp($second),
				$second->getId(),
			] <=> [
				self::getCreationTimestamp($first),
				$first->getId(),
			],
		);

		return $items;
	}

	private static function getCreationTimestamp(Item $item): int
	{
		$createdTime = $item->get(Item::FIELD_NAME_CREATED_TIME);

		return $createdTime instanceof DateTime ? $createdTime->getTimestamp() : 0;
	}

	private function fetchItem(int $entityId): ?Item
	{
		if (!array_key_exists($entityId, $this->fetchedItems))
		{
			$items = $this->getData([
				'filter' => [
					Item::FIELD_NAME_ID => $entityId,
					'=' . Item::FIELD_NAME_IS_RECURRING => 'N',
				],
				'limit' => 1,
			]);

			$this->fetchedItems[$entityId] = empty($items) ? null : reset($items);
		}

		return $this->fetchedItems[$entityId];
	}

	/**
	 * @param Item[] $items
	 */
	private function getExternalData(array $items): array
	{
		return array_map(
			static fn (Item $item) => array_filter($item->getCompatibleData()),
			$items,
		);
	}

	private function getEntityList(array $items): array
	{
		if (empty($items))
		{
			return [];
		}

		$systemFieldsMapper = new SystemFieldsMapper($this->entityTypeId);
		$userFieldsMapper = new UserFieldsMapper($this->entityTypeId);

		// by default create StrategyFactory with all supported strategies,
		// but you can create your own StrategyFactory with only needed strategies
		$activityCollector = new ActivityDataCollector($this->entityTypeId, new StrategyFactory());

		$result = [];
		foreach ($items as $item)
		{
			$result[$item['ID']] = [
				'deal_fields' => [
					'system_fields' => $systemFieldsMapper->map($item),
					'user_fields' => $userFieldsMapper->map($item),
				],
				'communication_data' => $this->isSupportedEntityType()
					? $activityCollector->getMarkers(['entityId' => $item['ID']])
					: []
				,
			];
		}

		return $result;
	}

	private function getOrdersSummary(array $items): array
	{
		if (!$this->isSupportedEntityType())
		{
			return [];
		}

		$latestItemFound = false;
		$latestItem = null;
		$successDealSum = 0;
		$successDealCnt = 0;
		$failedDealCnt = 0;

		foreach ($items as $item)
		{
			// the empty values of the deal are filtered out before the aggregate, so a deal closed with
			// no amount at all reaches it without the OPPORTUNITY key
			$semanticId = $item[Item::FIELD_NAME_STAGE_SEMANTIC_ID] ?? '';

			if ($latestItemFound === false && $semanticId !== PhaseSemantics::FAILURE)
			{
				$latestItem = $item;
				$latestItemFound = true;
			}

			if ($semanticId === PhaseSemantics::SUCCESS)
			{
				$successDealSum += (float)($item[Item::FIELD_NAME_OPPORTUNITY] ?? 0);
				$successDealCnt++;
			}

			if ($semanticId === PhaseSemantics::FAILURE)
			{
				$failedDealCnt++;
			}
		}

		if ($latestItem === null)
		{
			$latestItem = reset($items);
		}

		return [
			'latest_purchase_deal_id' => $latestItem[Item::FIELD_NAME_ID],
			'latest_purchase_date' => $latestItem['DATE_CREATE'],
			'successful_deals_sum' => $successDealSum,
			'successful_deals_count' => $successDealCnt,
			'failed_deals_count' => $failedDealCnt,
		];
	}

	private function prepareCopilotMarkersFilter(
		int $entityId,
		array $clientIdentifiers,
		?int $excludeId = null,
	): array
	{
		$filter = [];

		foreach ($clientIdentifiers as $clientIdentifier)
		{
			$clientEntityTypeId = $clientIdentifier['entityTypeId'] ?? 0;
			$clientEntityId = $clientIdentifier['entityId'] ?? 0;

			if ($clientEntityId <= 0)
			{
				continue;
			}

			if ($clientEntityTypeId === CCrmOwnerType::Contact)
			{
				$filter['@' . Item::FIELD_NAME_CONTACT_BINDINGS . '.CONTACT_ID'][] = $clientEntityId;
			}
			elseif ($clientEntityTypeId === CCrmOwnerType::Company)
			{
				$filter['@' . Item::FIELD_NAME_COMPANY_ID][] = $clientEntityId;
			}
		}

		if (empty($filter))
		{
			return [];
		}

		$entityFilter = [
			'=' . Item::FIELD_NAME_IS_RECURRING => 'N',
		];

		if ($entityId > 0 && $excludeId > 0)
		{
			$entityFilter['!@' . Item::FIELD_NAME_ID] = [$entityId, $excludeId];
		}
		elseif ($entityId > 0)
		{
			$entityFilter['!=' . Item::FIELD_NAME_ID] = $entityId;
		}
		elseif ($excludeId > 0)
		{
			$entityFilter['!=' . Item::FIELD_NAME_ID] = $excludeId;
		}

		return array_merge($filter, $entityFilter);
	}
}
