<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Integration\Search\Enrichment\PortionEnricher;
use Bitrix\Im\V2\Integration\Search\Query\MessageSearchQuery;
use Bitrix\Im\V2\Integration\Search\Result\MessageSearchItem;
use Bitrix\Im\V2\Integration\Search\Result\MessageSearchItemCollection;
use Bitrix\Im\V2\Integration\Search\Result\Portion;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Config\Option;

/**
 * Asks the search path with a reserve, filters the raw output by the actual access of the user and
 * answers a portion plus the offset the next portion continues from without gaps (ALG-03).
 */
class MessageSearchService
{
	use ContextCustomer;

	/** index.max_result_window of the engine */
	private const WINDOW_CAP = 10000;

	private const
		DEFAULT_OVERFETCH_RATIO = 2,
		DEFAULT_SCAN_BUDGET = 500
	;

	public function __construct(
		private readonly SearchAdapter $adapter,
		private readonly PortionEnricher $enricher,
		private readonly HygieneRegistry $hygieneRegistry,
	)
	{}

	public function search(MessageSearchQuery $query): Portion
	{
		// fail closed: without a default active site the entry is not written either, and a query
		// against a foreign index would answer for the wrong portal
		$siteId = SiteHelper::getSiteId();
		if ($siteId === null)
		{
			return Portion::error(new SearchError(SearchError::SERVICE_UNAVAILABLE));
		}

		$userId = $this->getContext()->getUserId();
		$overfetch = $this->getOverfetchRatio();
		$budget = $this->getScanBudget();

		$offset = $query->offset;
		$scanned = 0;
		/** @var MessageSearchItem[] $rows */
		$rows = [];
		$exhausted = false;

		$reserve = max($query->limit, $this->getMinFetchSize()) * $overfetch;

		while (count($rows) < $query->limit && $scanned < $budget)
		{
			// the budget caps the size of the very next request, so one call never scans more than it.
			// The size of the portion is a floor and not the whole measure: a caller asking for one row
			// would otherwise spend the whole budget in requests of two records, and every one of them is
			// a round trip to the engine and a cycle of enrichment of its own
			$size = min(
				$this->measureWindow($reserve, $query->limit - count($rows), count($rows), $scanned),
				$budget - $scanned,
				self::WINDOW_CAP - $offset - $scanned,
			);
			if ($size <= 0)
			{
				break;
			}

			$window = $this->adapter->searchMessages($query, $siteId, $offset + $scanned, $size);
			if ($window === null)
			{
				return Portion::error(new SearchError(SearchError::SERVICE_UNAVAILABLE));
			}

			$records = [];
			foreach ($window->records as $position => $record)
			{
				$records[$offset + $scanned + $position] = $record;
			}
			// the positions of the engine and not the records handed over: a hit the core skipped costs a
			// position all the same, and counting the records instead would send the next offset back onto
			// rows already delivered
			$scanned += $window->positions;

			$items = $this->enricher->enrichAndFilter($records, $userId);
			try
			{
				$nonIndexable = array_fill_keys($this->hygieneRegistry->nonIndexableOf(array_map(
					static fn (MessageSearchItem $item): int => $item->messageId,
					$items,
				)), true);
			}
			catch (\Throwable $exception)
			{
				(new Logger('message-search-facade'))->logArray([
					'type' => 'hygieneFailure',
					'exception' => $exception::class,
					'code' => $exception->getCode(),
				]);

				return Portion::error(new SearchError(SearchError::SERVICE_UNAVAILABLE));
			}

			foreach ($items as $item)
			{
				if (!isset($nonIndexable[$item->messageId]))
				{
					$rows[] = $item;
				}
			}

			if ($window->positions < $size)
			{
				$exhausted = true;

				break;
			}
		}

		return $this->buildPortion($query, $rows, $offset, $scanned, $exhausted);
	}

	/**
	 * Size of the next request to the search path, measured on what the access filtering has left of the
	 * output so far.
	 *
	 * The reserve alone is a guess made before anything is known, and a window that keeps it while the
	 * filtering throws nearly everything away spends the budget in a dozen round trips of the same size:
	 * the engine is asked, the portion is enriched and the checks of the delegated access are made anew
	 * every time. What the rows already cost says how many are needed for the missing ones, so the next
	 * window asks for them at once. The budget still caps it, and a window never shrinks below the
	 * reserve.
	 *
	 * @param int $missing Rows the portion still has to deliver.
	 * @param int $accepted Rows the filtering has let through so far.
	 * @param int $scanned Raw records the requests have cost so far.
	 */
	private function measureWindow(int $reserve, int $missing, int $accepted, int $scanned): int
	{
		if ($scanned === 0)
		{
			return $reserve;
		}

		if ($accepted === 0)
		{
			// nothing survived the filtering, so there is no rate to measure: what is left of the budget
			// goes in one request instead of a dozen windows of the reserve
			return PHP_INT_MAX;
		}

		return max($reserve, (int)ceil($missing * $scanned / $accepted));
	}

	/**
	 * @param MessageSearchItem[] $rows
	 */
	private function buildPortion(
		MessageSearchQuery $query,
		array $rows,
		int $offset,
		int $scanned,
		bool $exhausted,
	): Portion
	{
		$delivered = array_slice($rows, 0, $query->limit);
		$collection = new MessageSearchItemCollection(...$delivered);
		$lastDelivered = $collection->getLast();

		if ($delivered === [])
		{
			// A portion the access filtering emptied answers exactly what a query matching nothing
			// answers. The distance the raw output travelled would otherwise say that a text the user may
			// not read exists, and an offset of his own choosing turns that into a probe. The output loses
			// nothing by it: a portion of no rows never carries a next page either way.
			return new Portion($collection);
		}

		if (count($rows) > $query->limit && $lastDelivered !== null)
		{
			// the rows above the portion are enriched but not delivered: the next portion starts at them
			$nextOffset = $lastDelivered->rawPosition + 1;

			return new Portion($collection, $nextOffset, $nextOffset < self::WINDOW_CAP);
		}

		// A portion short of the limit is the budget of the scan running out before the output did, and
		// the flag answers whether there is any point in asking for more - not whether the portion came
		// out full. A single reachable message among the first five hundred matches would otherwise end
		// the paging of both clients on the row it delivered.
		$nextOffset = $offset + $scanned;
		$hasNextPage = !$exhausted && $nextOffset < self::WINDOW_CAP;

		return new Portion($collection, $nextOffset, $hasNextPage);
	}

	private function getOverfetchRatio(): int
	{
		return max(1, (int)Option::get('im', 'message_search_overfetch_ratio', self::DEFAULT_OVERFETCH_RATIO));
	}

	private function getScanBudget(): int
	{
		return max(1, (int)Option::get('im', 'message_search_scan_budget', self::DEFAULT_SCAN_BUDGET));
	}

	/**
	 * Floor of a single request to the search path. A caller is free to ask for a portion of one, and
	 * the budget would then be spent in requests of two records - a round trip to the engine and a
	 * cycle of enrichment each - while the work of one request is the same either way.
	 */
	private function getMinFetchSize(): int
	{
		return max(1, (int)Option::get('im', 'message_search_min_fetch_size', MessageSearchQuery::DEFAULT_LIMIT));
	}
}
