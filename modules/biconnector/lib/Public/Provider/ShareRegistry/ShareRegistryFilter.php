<?php

namespace Bitrix\BIConnector\Public\Provider\ShareRegistry;

use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Filter\ShareRegistryFilterProvider;
use Bitrix\BIConnector\Internal\Services\Share\ShareStatusResolver;

final class ShareRegistryFilter
{
	private const MAX_SEARCH_WORDS = 5;

	/** @var int[] */
	public readonly array $authorIds;

	/** @var int[] */
	public readonly array $dashboardIds;

	/** @var int[] Who switched the link off; only switched off links match. */
	public readonly array $disabledByIds;

	/** @var string[] ShareStatusResolver::STATUS_* */
	public readonly array $statuses;

	/** @var string[] Words of the search string, each must match the report title or the author name. */
	public readonly array $searchWords;

	/**
	 * @param int[] $authorIds
	 * @param int[] $dashboardIds
	 * @param string[] $statuses ShareStatusResolver::STATUS_*; unknown values are dropped.
	 * @param int|null $notOpenedDays Links not opened for more than this number of days; a link never
	 *   opened counts from its creation.
	 * @param string $search Search string of the filter bar.
	 * @param int[] $disabledByIds
	 */
	public function __construct(
		array $authorIds = [],
		array $dashboardIds = [],
		array $statuses = [],
		public readonly ?int $notOpenedDays = null,
		public readonly bool $onlyBanned = false,
		string $search = '',
		array $disabledByIds = [],
	)
	{
		$this->authorIds = self::normalizeIds($authorIds);
		$this->dashboardIds = self::normalizeIds($dashboardIds);
		$this->disabledByIds = self::normalizeIds($disabledByIds);
		$this->statuses = array_values(array_intersect(ShareStatusResolver::getStatuses(), $statuses));
		$this->searchWords = self::splitSearch($search);
	}

	/**
	 * @param array $filterValue Value prepared by {@see ShareRegistryFilterProvider::prepareFilterValue()}.
	 */
	public static function fromFilterValue(array $filterValue): self
	{
		$notOpenedDays = $filterValue[ShareRegistryFilterProvider::FIELD_NOT_OPENED_DAYS] ?? null;

		return new self(
			authorIds: (array)($filterValue[ShareRegistryFilterProvider::FIELD_AUTHOR] ?? []),
			dashboardIds: (array)($filterValue[ShareRegistryFilterProvider::FIELD_DASHBOARD] ?? []),
			statuses: (array)($filterValue[ShareRegistryFilterProvider::FIELD_STATUS] ?? []),
			notOpenedDays: $notOpenedDays === null ? null : (int)$notOpenedDays,
			onlyBanned: ($filterValue[ShareRegistryFilterProvider::FIELD_ONLY_BANNED] ?? null) === 'Y',
			search: (string)($filterValue[ShareRegistryFilterProvider::FIELD_SEARCH] ?? ''),
			disabledByIds: (array)($filterValue[ShareRegistryFilterProvider::FIELD_DISABLED_BY] ?? []),
		);
	}

	/**
	 * @return string[]
	 */
	private static function splitSearch(string $search): array
	{
		$words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

		return array_slice(array_values(array_unique($words)), 0, self::MAX_SEARCH_WORDS);
	}

	/**
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
	}
}
