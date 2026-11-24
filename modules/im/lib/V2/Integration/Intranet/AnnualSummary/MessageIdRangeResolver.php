<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Date;

/**
 * Resolves the period bounds of the annual summary into message identifiers.
 *
 * A miss scans for each bound and costs enough to matter against the budget of a single step, and
 * the stepper builds the providers anew on every step, so the bounds live in the cache between
 * steps and in the memo within one step.
 * Only the bounds are cached; the metric totals belong to the intranet storage.
 */
class MessageIdRangeResolver
{
	public const CACHE_DIR = '/im/annual_summary/bounds';

	/**
	 * Every bound is an entry of its own, because they are worth keeping for different times: a
	 * filled bound stands still and lives out the season, an empty one only an hour. Kept as a pair,
	 * both would take the shorter of the two lives, and the lower bound — the one that costs a scan
	 * of the whole archive — would be looked up anew every hour of the eleven months the upper bound
	 * stays open.
	 */
	private const LOWER_BOUND_SUFFIX = '_from';
	private const UPPER_BOUND_SUFFIX = '_to';

	/**
	 * How long an entry whose bound came back empty is trusted: an empty bound is a fact about the
	 * moment of the lookup, not about the period.
	 */
	private const EMPTY_BOUND_TTL = 3600;

	/** @var array<string, MessageIdRange> */
	private array $memo = [];

	public function resolve(Date $from, Date $to): MessageIdRange
	{
		// Both dates are in the key: a change of the period must not read the bounds of the previous one.
		$key = $from->format('Ymd') . '_' . $to->format('Ymd');

		if (isset($this->memo[$key]))
		{
			return $this->memo[$key];
		}

		$seasonTtl = SummarySeason::getRemainingSeconds($from);
		$lowerBoundId = $key . self::LOWER_BOUND_SUFFIX;
		$upperBoundId = $key . self::UPPER_BOUND_SUFFIX;

		$idFrom = $this->resolveBound($from, $lowerBoundId, $seasonTtl);
		$idTo = $this->resolveBound($to, $upperBoundId, $seasonTtl);

		// Messages from idTo are a subset of those from idFrom, so a filled upper bound without a
		// lower one at or below it is a pair no lookup could have returned. The entries are written
		// apart, and which of them is the damaged one is unknowable, so both are looked up again.
		if ($idTo !== null && ($idFrom === null || $idFrom > $idTo))
		{
			$idFrom = $this->lookUpBound($from, $lowerBoundId, $seasonTtl);
			$idTo = $this->lookUpBound($to, $upperBoundId, $seasonTtl);
		}

		$range = new MessageIdRange($idFrom, $idTo);
		$this->memo[$key] = $range;

		return $range;
	}

	private function resolveBound(Date $date, string $cacheId, int $seasonTtl): ?int
	{
		$cache = Cache::createInstance();

		// Read with the longest life a bound can have: how long this entry is really worth is told by
		// the moment of the lookup it carries, and that is inside the entry.
		$restored = $cache->initCache($seasonTtl, $cacheId, self::CACHE_DIR)
			? $this->restoreBound($cache->getVars())
			: null
		;

		return $restored === null ? $this->lookUpBound($date, $cacheId, $seasonTtl) : $restored[0];
	}

	private function lookUpBound(Date $date, string $cacheId, int $seasonTtl): ?int
	{
		$bound = $this->findFirstMessageId($date);

		$cache = Cache::createInstance();

		// An entry that failed the check is still on disk and would be read again next step.
		$cache->forceRewriting(true);
		$cache->startDataCache(
			$bound === null ? self::EMPTY_BOUND_TTL : $seasonTtl,
			$cacheId,
			self::CACHE_DIR,
		);
		$cache->endDataCache([$bound, time()]);

		return $bound;
	}

	/**
	 * The cache entry is untrusted input: one left by another version of the code or damaged on
	 * disk is a miss to recompute, never a period without messages.
	 *
	 * An empty bound is not a fact about the period but about the moment it was looked up: while the
	 * season runs, it only says that no message is dated at or after that date yet. Kept for the
	 * season, such an entry would let the metrics count the messages of the next period as soon as
	 * they appear, so it is trusted for an hour and looked up again after that. A filled bound
	 * stands still and lives out the season.
	 *
	 * @return array{0: ?int}|null The bound wrapped in an array, null when the entry is not to be
	 * trusted: an entry that holds an empty bound is an answer all the same.
	 */
	private function restoreBound(mixed $vars): ?array
	{
		if (!is_array($vars) || count($vars) !== 2)
		{
			return null;
		}

		[$bound, $writtenAt] = array_values($vars);

		if (!is_int($writtenAt) || $writtenAt <= 0)
		{
			return null;
		}

		// Identifiers start at 1, so zero or below is damage: an empty bound is written as null.
		if ($bound !== null && (!is_int($bound) || $bound <= 0))
		{
			return null;
		}

		if ($bound === null && (time() - $writtenAt) > self::EMPTY_BOUND_TTL)
		{
			return null;
		}

		return [$bound];
	}

	/**
	 * Null when the table has no message dated at or after the given date.
	 */
	private function findFirstMessageId(Date $date): ?int
	{
		$row = MessageTable::query()
			->setSelect(['MIN_ID'])
			->registerRuntimeField('MIN_ID', new ExpressionField('MIN_ID', 'MIN(ID)'))
			->where('DATE_CREATE', '>=', $date)
			->where('NOTIFY_TYPE', 0)
			->fetch()
		;

		return isset($row['MIN_ID']) ? (int)$row['MIN_ID'] : null;
	}
}
