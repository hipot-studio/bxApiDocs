<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\Data\Cache;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\SelectorEntityNormalizer;
use Bitrix\StaffTrack\Internal\Integration\Timeman\DayStateDto;

/**
 * The index of the presence list of a viewer, kept for a minute after its build: the next portion, another tab and a
 * search on the same date and selector are cut from it, and the team, its rights, its work day and its check-ins are
 * not read again. The card beside the map is cut from it only for a client that keeps the card for what is left of the
 * minute of the index ({@see PresenceListQuery::$withIndexAge}), so the moment of the build comes back with the index.
 *
 * The index is the team after the rollout, the scope and the rights of work time tracking: the rows of the index, and
 * the work day and the zone of the profile of every one of them, and whom the map beside the list draws when the build
 * read that for the card. Nothing else of a build is kept - neither the statistics of the date nor the points of the
 * map - and nothing of a refusal or of a build that was not whole: they are asked again on the next request.
 *
 * An index is found by the viewer, the date as the aggregate resolves it and the selector as the scope reads it, and it
 * is given back only on all three and within the minute of its build by the clock of the request. Nothing but the time
 * resets it, so a new pause, a day started or ended, a change of the rollout or of the rights reach the list within
 * that minute. A viewer keeps the index of his last date and selector only: the card and the sheet ask about the same
 * ones, while an index of every date looked at would pile up in the file cache, which leaves an expired entry on the
 * disk.
 */
class PresenceIndexCache
{
	/** Seconds an index answers for after its build: the window the card of the map is refreshed in (Q-006). */
	private const TTL = 60;

	private const DIR = '/stafftrack/presence_index';
	private const ENTRY_ID = 'index';

	/**
	 * A new shape of the entry takes a new version: an entry of the old shape is then never read back. The rows and
	 * their work days are kept as objects of their classes, so a property of theirs added, dropped or retyped is a new
	 * shape too: {@see KEPT_CLASS_PROPERTIES} is their shape in this version, and a test holds the classes to it.
	 */
	private const VERSION = 2;

	/**
	 * The classes the entry keeps objects of, with their properties and types as {@see VERSION} knows them. An entry
	 * kept before a property was added comes back without it, and reading it throws: a change here takes a new version.
	 */
	private const KEPT_CLASS_PROPERTIES = [
		PresenceIndexRow::class => [
			'userId' => 'int',
			'group' => PresenceGroup::class,
			'hasCheckIns' => 'bool',
			'lastName' => 'string',
			'name' => 'string',
			'displayName' => 'string',
			'position' => 'string',
		],
		DayStateDto::class => [
			'trusted' => 'bool',
			'started' => 'bool',
			'closed' => 'bool',
			'paused' => 'bool',
			'startTs' => '?int',
			'endTs' => '?int',
			'truncated' => 'bool',
		],
	];

	/**
	 * @param 'past'|'today'|'future' $dateKind
	 * @param array $entities selector of the request, empty for the team of the viewer
	 * @param int $now the moment of the request
	 * @return array{rows: PresenceIndexRow[], dayStates: array<int, DayStateDto>, zones: array<int, string>,
	 *     mapUserIds: int[]|null, builtAt: int}|null the index of the viewer built on that date and selector less
	 *     than a minute before, with whom the map draws - null when the build did not read it - and the moment of the
	 *     build; null otherwise
	 */
	public function read(int $viewerId, string $localDate, string $dateKind, array $entities, int $now): ?array
	{
		if ($viewerId <= 0)
		{
			return null;
		}

		try
		{
			$entry = $this->load(self::dirOf($viewerId));
		}
		catch (\Throwable)
		{
			return null;
		}

		$isOfTheRequest = is_array($entry)
			&& ($entry['key'] ?? null) === self::keyOf($viewerId, $localDate, $dateKind, $entities)
			&& is_int($entry['builtAt'] ?? null)
			&& self::isFresh($entry['builtAt'], $now)
		;

		return $isOfTheRequest ? self::indexOf($entry) : null;
	}

	/**
	 * Keeps the index of a whole build in place of whatever the viewer kept before. A cache that could not be written
	 * leaves the next request to build the index again.
	 *
	 * @param 'past'|'today'|'future' $dateKind
	 * @param array $entities selector of the request, empty for the team of the viewer
	 * @param int $now the moment of the request the index was built for
	 * @param array{rows: PresenceIndexRow[], dayStates: array<int, DayStateDto>, zones: array<int, string>,
	 *     mapUserIds?: int[]|null} $index with whom the map draws when the build read that
	 */
	public function write(
		int $viewerId,
		string $localDate,
		string $dateKind,
		array $entities,
		int $now,
		array $index,
	): void
	{
		if ($viewerId <= 0)
		{
			return;
		}

		try
		{
			$this->store(self::dirOf($viewerId), [
				'key' => self::keyOf($viewerId, $localDate, $dateKind, $entities),
				'builtAt' => $now,
				'rows' => $index['rows'],
				'dayStates' => $index['dayStates'],
				'zones' => $index['zones'],
				'mapUserIds' => $index['mapUserIds'] ?? null,
			]);
		}
		catch (\Throwable)
		{
			// the portion is answered all the same
		}
	}

	/**
	 * @return mixed the entry the viewer keeps, whatever its key; null when there is none or it is older than the time
	 *     an index answers for
	 */
	protected function load(string $dir): mixed
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::TTL, self::ENTRY_ID, $dir) ? $cache->getVars() : null;
	}

	/**
	 * Writes the entry over the one the viewer keeps, of whatever key and age.
	 */
	protected function store(string $dir, array $entry): void
	{
		$cache = Cache::createInstance();
		// the entry of another date or selector is replaced before its minute is over as well
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TTL, self::ENTRY_ID, $dir))
		{
			$cache->endDataCache($entry);
		}
	}

	private static function dirOf(int $viewerId): string
	{
		return self::DIR . '/' . $viewerId;
	}

	/**
	 * The date as the aggregate resolves it and the selector as the scope reads it: its people, flat departments and
	 * departments with the nested ones, each sorted and once, and whether it has more codes than the parse reads, which
	 * the scope refuses whatever the parse keeps of them. The team of the viewer, asked about without a selector, is a
	 * key of its own.
	 */
	private static function keyOf(int $viewerId, string $localDate, string $dateKind, array $entities): string
	{
		$selector = 'team';
		if ($entities !== [])
		{
			[$userIds, $flatDepartmentIds, $recursiveDepartmentIds] = SelectorEntityNormalizer::normalize($entities);
			sort($userIds);
			sort($flatDepartmentIds);
			sort($recursiveDepartmentIds);
			$isOverTheParse = count($entities) > SelectorEntityNormalizer::MAX_SELECTOR_ENTITIES;

			$selector = md5(json_encode([$isOverTheParse, $userIds, $flatDepartmentIds, $recursiveDepartmentIds]));
		}

		return implode('|', [self::VERSION, $viewerId, $localDate, $dateKind, $selector]);
	}

	/**
	 * By the clock of the request rather than of the storage: an entry of a moment after the request is no answer
	 * either.
	 */
	private static function isFresh(int $builtAt, int $now): bool
	{
		$age = $now - $builtAt;

		return $age >= 0 && $age < self::TTL;
	}

	/**
	 * @param array{builtAt: int} $entry
	 * @return array{rows: PresenceIndexRow[], dayStates: array<int, DayStateDto>, zones: array<int, string>,
	 *     mapUserIds: int[]|null, builtAt: int}|null null when the entry is not of the shape an index is written in
	 */
	private static function indexOf(array $entry): ?array
	{
		$rows = $entry['rows'] ?? null;
		$dayStates = $entry['dayStates'] ?? null;
		$zones = $entry['zones'] ?? null;
		if (!is_array($rows) || !is_array($dayStates) || !is_array($zones))
		{
			return null;
		}

		if (!array_key_exists('mapUserIds', $entry) || !self::isMapScope($entry['mapUserIds']))
		{
			return null;
		}

		foreach ($rows as $row)
		{
			if (!$row instanceof PresenceIndexRow || !(($dayStates[$row->userId] ?? null) instanceof DayStateDto))
			{
				return null;
			}
		}

		foreach ($zones as $zone)
		{
			if (!is_string($zone))
			{
				return null;
			}
		}

		return [
			'rows' => array_values($rows),
			'dayStates' => $dayStates,
			'zones' => $zones,
			'mapUserIds' => $entry['mapUserIds'],
			'builtAt' => $entry['builtAt'],
		];
	}

	/**
	 * Whom the map draws as a build read it, people alone, or null for a build that did not read it.
	 */
	private static function isMapScope(mixed $mapUserIds): bool
	{
		if ($mapUserIds === null)
		{
			return true;
		}

		if (!is_array($mapUserIds) || !array_is_list($mapUserIds))
		{
			return false;
		}

		foreach ($mapUserIds as $userId)
		{
			if (!is_int($userId) || $userId <= 0)
			{
				return false;
			}
		}

		return true;
	}
}
