<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository;

use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Crm\Activity\CustomType;
use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Order\Order;
use Bitrix\Crm\QuoteTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use CCrmOwnerType;

/**
 * Batch reads of CRM elements for the task list. Existence and displayable data are two separate
 * operations on purpose: existence must never touch a protected field, so a caller can classify an
 * element before it is allowed to know its name.
 */
class CrmItemDataReader
{
	private const BATCH_SIZE = 500;

	/**
	 * Confirms which of the given elements still exist. Every family but the order is read through
	 * the public CRM Item provider with an `id` projection (companies also take `isMyCompany`,
	 * because "my company" has its own read rule), the order -- through the CRM order table with an
	 * `ID` projection. No title, caption, number or address is selected, and no permission is applied.
	 *
	 * @param array<int, list<int>> $entityIdsByType
	 * @return array<string, bool> reference key => isMyCompany flag; a missing key means the element
	 *                             does not exist or its family is not supported here
	 */
	public function readExistence(array $entityIdsByType): array
	{
		if (empty($entityIdsByType) || !Loader::includeModule('crm'))
		{
			return [];
		}

		$existing = [];
		foreach ($entityIdsByType as $typeId => $entityIds)
		{
			$typeId = (int)$typeId;
			$entityIds = $this->normalizeEntityIds($entityIds);
			if (empty($entityIds))
			{
				continue;
			}

			$existing += $typeId === CCrmOwnerType::Order
				? $this->readOrderExistence($entityIds)
				: $this->readItemExistence($typeId, $entityIds)
			;
		}

		return $existing;
	}

	/**
	 * Loads the title, the type caption and the show path of elements the caller has already resolved as
	 * readable. Rights are not re-checked here: passing an element the current user may not read leaks
	 * its name.
	 *
	 * @param array<int, list<int>> $entityIdsByType
	 * @return array<string, array{title: string, typeName: string|null, url: string}>
	 */
	public function readDetails(array $entityIdsByType): array
	{
		if (empty($entityIdsByType) || !Loader::includeModule('crm'))
		{
			return [];
		}

		$details = [];
		foreach ($entityIdsByType as $typeId => $entityIds)
		{
			$typeId = (int)$typeId;
			$entityIds = $this->normalizeEntityIds($entityIds);
			if (empty($entityIds) || $this->isSuspendedType($typeId))
			{
				continue;
			}

			$typeName = Container::getInstance()->getFactory($typeId)?->getEntityDescription();
			foreach (array_chunk($entityIds, self::BATCH_SIZE) as $chunk)
			{
				$entityInfos = $this->getEntityInfos($typeId, $chunk);
				$legacyTitles = $this->getLegacyTitles($typeId, $entityInfos);

				foreach ($entityInfos as $entityId => $entityInfo)
				{
					$title = $legacyTitles[$entityId] ?? '';
					if ($title === '')
					{
						continue;
					}

					$details[CrmItemReference::makeKey($typeId, (int)$entityId)] = [
						'title' => $title,
						'typeName' => $typeName ?? ($entityInfo['ENTITY_TYPE_CAPTION'] ?? null),
						'url' => (string)CCrmOwnerType::GetEntityShowPath($typeId, (int)$entityId, false),
					];
				}
			}
		}

		return $details;
	}

	/**
	 * @param list<int> $entityIds
	 * @return array<string, bool>
	 */
	private function readItemExistence(int $typeId, array $entityIds): array
	{
		if (!EntityType::isValid($typeId))
		{
			return [];
		}

		$entityType = EntityType::fromId($typeId);
		// A smart process whose type row is gone has no readable storage at all - CRM denies it the same way.
		if (
			$entityType->isSmartProcessBasedApproach()
			&& Container::getInstance()->getTypeByEntityTypeId($typeId) === null
		)
		{
			return [];
		}

		$select = $typeId === CCrmOwnerType::Company ? ['id', 'isMyCompany'] : ['id'];
		$provider = ItemProvider::forEntityType($entityType);

		$existing = [];
		foreach (array_chunk($entityIds, self::BATCH_SIZE) as $chunk)
		{
			foreach ($provider->getByIds($chunk, $select)->getAll() as $item)
			{
				$key = CrmItemReference::makeKey($typeId, (int)$item->getId());
				$existing[$key] = $item instanceof Company && $item->getIsMyCompany() === true;
			}
		}

		return $existing;
	}

	/**
	 * @param list<int> $entityIds
	 * @return array<string, bool>
	 */
	private function readOrderExistence(array $entityIds): array
	{
		if (!Loader::includeModule('sale'))
		{
			return [];
		}

		$existing = [];
		foreach (array_chunk($entityIds, self::BATCH_SIZE) as $chunk)
		{
			$rows = Order::getList([
				'select' => ['ID'],
				'filter' => ['@ID' => $chunk],
			])->fetchAll();

			foreach ($rows as $row)
			{
				$existing[CrmItemReference::makeKey(CCrmOwnerType::Order, (int)$row['ID'])] = false;
			}
		}

		return $existing;
	}

	/**
	 * @param list<int> $entityIds
	 * @return list<int>
	 */
	private function normalizeEntityIds(array $entityIds): array
	{
		$normalized = [];
		foreach ($entityIds as $entityId)
		{
			$entityId = (int)$entityId;
			if ($entityId > 0)
			{
				$normalized[$entityId] = $entityId;
			}
		}

		return array_values($normalized);
	}

	private function isSuspendedType(int $typeId): bool
	{
		return str_starts_with(CCrmOwnerType::ResolveName($typeId), 'SUS_');
	}

	private function getEntityInfos(int $typeId, array $entityIds): array
	{
		if ($typeId === CCrmOwnerType::Quote)
		{
			return $this->getQuoteInfos($entityIds);
		}

		if ($typeId === CCrmOwnerType::DealCategory)
		{
			return $this->getDealCategoryInfos($entityIds);
		}

		if ($typeId === CCrmOwnerType::CustomActivityType)
		{
			return $this->getCustomActivityTypeInfos($entityIds);
		}

		$entityInfos = array_fill_keys($entityIds, []);
		CCrmOwnerType::PrepareEntityInfoBatch($typeId, $entityInfos, false);

		return $entityInfos;
	}

	private function getQuoteInfos(array $entityIds): array
	{
		$entityInfos = [];
		$quotes = QuoteTable::query()
			->setSelect(['ID', 'QUOTE_NUMBER', 'TITLE'])
			->whereIn('ID', $entityIds)
			->fetchAll()
		;
		foreach ($quotes as $quote)
		{
			$entityId = (int)$quote['ID'];
			$entityInfos[$entityId] = [
				'QUOTE_NUMBER' => (string)($quote['QUOTE_NUMBER'] ?? ''),
				'TITLE' => (string)($quote['TITLE'] ?? ''),
			];
		}

		return $entityInfos;
	}

	private function getDealCategoryInfos(array $entityIds): array
	{
		$entityIdMap = array_fill_keys($entityIds, true);
		$entityInfos = [];

		foreach (DealCategory::getAll(true) as $category)
		{
			$entityId = (int)($category['ID'] ?? 0);
			if (isset($entityIdMap[$entityId]))
			{
				$entityInfos[$entityId] = ['TITLE' => (string)($category['NAME'] ?? '')];
			}
		}

		return $entityInfos;
	}

	private function getCustomActivityTypeInfos(array $entityIds): array
	{
		$entityIdMap = array_fill_keys($entityIds, true);
		$entityInfos = [];

		foreach (CustomType::getAll() as $customType)
		{
			$entityId = (int)($customType['ID'] ?? 0);
			if (isset($entityIdMap[$entityId]))
			{
				$entityInfos[$entityId] = ['TITLE' => (string)($customType['NAME'] ?? '')];
			}
		}

		return $entityInfos;
	}

	private function getLegacyTitles(int $typeId, array $entityInfos): array
	{
		$titles = [];
		foreach ($entityInfos as $entityId => $entityInfo)
		{
			if ($typeId === CCrmOwnerType::Lead)
			{
				$title = (string)($entityInfo['TITLE'] ?? '');
				$titles[$entityId] = $title !== '' ? $title : (string)($entityInfo['LEGEND'] ?? '');

				continue;
			}

			if ($typeId === CCrmOwnerType::Quote)
			{
				$titles[$entityId] = $this->getNumberedTitle(
					(string)($entityInfo['QUOTE_NUMBER'] ?? ''),
					(string)($entityInfo['TITLE'] ?? ''),
				);

				continue;
			}

			if ($typeId === CCrmOwnerType::Order)
			{
				$titles[$entityId] = $this->getNumberedTitle(
					(string)($entityInfo['ACCOUNT_NUMBER'] ?? ''),
					(string)($entityInfo['ORDER_TOPIC'] ?? ''),
				);

				continue;
			}

			if ($typeId === CCrmOwnerType::StoreDocument && empty($entityInfo['TITLE']))
			{
				$documentTypes = Loader::includeModule('catalog') ? StoreDocumentTable::getTypeList(true) : [];
				$titles[$entityId] = (string)($documentTypes[$entityInfo['DOC_TYPE'] ?? ''] ?? '');

				continue;
			}

			$titles[$entityId] = (string)($entityInfo['TITLE'] ?? '');
		}

		return $titles;
	}

	private function getNumberedTitle(string $number, string $title): string
	{
		$number = empty($number) ? '' : $number;
		$title = empty($title) ? '' : $title;
		$caption = $number;
		if ($title !== '')
		{
			$caption = $caption === '' ? $title : $caption . ' - ' . $title;
		}

		return str_replace([';', ','], ' ', $caption);
	}
}
