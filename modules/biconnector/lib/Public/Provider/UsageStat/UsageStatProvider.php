<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider\UsageStat;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Entity\UsageStatEntryCollection;
use Bitrix\BIConnector\Internal\Repository\UsageStatRepository;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\BIConnector\Services\GoogleDataStudio;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;

class UsageStatProvider
{
	private static ?array $tableLabels = null;

	private readonly UsageStatRepository $repository;

	/** @var array<string, array<string, string>> */
	private static array $usedTablesCache = [];

	public function __construct()
	{
		$this->repository = new UsageStatRepository();
	}

	public function getList(GridParams $gridParams): UsageStatEntryCollection
	{
		return $this->repository->getList(
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
			filter: $gridParams->filter,
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
		);
	}

	public function getCount(?FilterInterface $filter = null): int
	{
		return $this->repository->getCount($filter);
	}

	/**
	 * @param string|null $searchQuery
	 * @param string[]|null $ids
	 * @param int $limit
	 *
	 * @return array<int|string, string>
	 */
	public function searchUsedDashboards(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		return $this->repository->searchUsedDashboards($searchQuery, $ids, $limit);
	}

	/**
	 * @param string|null $searchQuery
	 * @param array|null $ids
	 * @param int $limit
	 *
	 * @return array<string, array{name: string, type: 'chart'|'filter'}>
	 */
	public function searchUsedCharts(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		return $this->repository->searchUsedCharts($searchQuery, $ids, $limit);
	}

	/**
	 * @param string|null $searchQuery
	 * @param array|null $ids
	 * @param int $limit
	 *
	 * @return array<int|string, string>
	 */
	public function searchUsedDatasets(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		return $this->repository->searchUsedDatasets($searchQuery, $ids, $limit);
	}

	/**
	 * Returns map of source ids actually present in the usage log to their localized labels.
	 *
	 * @return array<string, string>
	 */
	public function getUsedTables(?string $languageId = null): array
	{
		$languageId ??= LANGUAGE_ID;
		if (isset(self::$usedTablesCache[$languageId]))
		{
			return self::$usedTablesCache[$languageId];
		}

		$tableCodes = $this->repository->getUsedTables();
		if ($tableCodes === [])
		{
			self::$usedTablesCache[$languageId] = [];

			return [];
		}

		$labels = $this->getTableLabels($languageId);

		$items = [];
		foreach ($tableCodes as $tableCode)
		{
			$items[$tableCode] = $labels[$tableCode] ?? $tableCode;
		}

		asort($items, SORT_NATURAL | SORT_FLAG_CASE);

		self::$usedTablesCache[$languageId] = $items;

		return $items;
	}

	/**
	 * @return array<string, string> Map table code -> table name.
	 */
	private function getTableLabels(?string $languageId): array
	{
		if (self::$tableLabels !== null)
		{
			return self::$tableLabels;
		}

		$serviceCode = Feature::isBuilderEnabled()
			? ApacheSuperset::getServiceId()
			: GoogleDataStudio::getServiceId()
		;

		$service = Manager::getInstance()->createService($serviceCode);
		if (!$service)
		{
			return [];
		}

		if ($languageId !== null)
		{
			$service->setLanguage($languageId);
		}

		$labels = [];
		foreach ($service->getTableList() as $row)
		{
			$labels[(string)$row[0]] = (string)$row[1];
		}

		self::$tableLabels = $labels;

		return $labels;
	}
}
