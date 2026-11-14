<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsBatchService;
use Bitrix\StaffTrack\Internal\Integration\Intranet\AbsenceBatchService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\DayStateDto;
use Bitrix\StaffTrack\Internal\Integration\Timeman\TimeZoneService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayBatchService;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInVisitCounter;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Public\Services\DailyStatsReader;
use Bitrix\StaffTrack\Public\Services\LatestCheckInReader;
use Bitrix\StaffTrack\Public\Services\PointAcceptanceService;

/**
 * The work day of a whole team on one date, answered a portion of one tab at a time ({@see getPresencePortion()}),
 * every portion whole or not at all.
 *
 * The team, its work day and its check-ins are read of everybody, since the tabs and their numbers stand on them, and
 * the absences, the consent - the stored marks and the markers of the devices - and the visits of the rows of the
 * portion alone. Each of them must answer about everybody it is read of: one gap refuses the portion with
 * ERROR_INCOMPLETE, because a partial list looks whole on the screen.
 * The auto check-in setting only decides a mark, so its failure drops the mark and keeps the portion. The date is named
 * by the clock of the viewer, a record belongs to it by the clock of its employee, and a row is printed by that clock
 * too.
 *
 * The mark is put on a list of today alone, like the pause: neither the consent nor the setting is read for another
 * date, and a source that is not read is no gap in the portion.
 *
 * What is read of everybody makes the index of the team, and a whole one is kept for a minute
 * ({@see PresenceIndexCache}): the portions of the same date and selector within it read the sources of their rows
 * alone. The card beside the map is cut from it only for a client that asks for the age of the index and keeps the
 * card for what is left of its minute; the card of any other client builds the team and keeps its index.
 */
class TeamPresenceProvider
{
	/** A source did not answer about someone; a refusal worth a retry. */
	public const ERROR_INCOMPLETE = 'PRESENCE_INCOMPLETE';

	/** The cursor of the request was given by no portion: a request refused, worth a retry from the top. */
	public const ERROR_BAD_CURSOR = 'PRESENCE_BAD_CURSOR';

	private const ANALYTICS_EVENT = 'presence_list_build';
	private const ANALYTICS_TOOL = 'stafftrack';
	private const ANALYTICS_CATEGORY = 'checkin';
	private const ANALYTICS_SECTION = 'presence_list';

	private const OUTCOME_SUCCESS = 'success';

	/** Analytics names of the kind of a portion: the first one of its tab or a next one. */
	private const PORTION_FIRST = 'portion_first';
	private const PORTION_NEXT = 'portion_next';

	/** Analytics mark of a portion cut from the index kept of a build before it. */
	private const INDEX_CACHED = 'index_cached';

	/** Refusals of the scope: the widget is not shown, nothing failed. */
	private const OUTCOMES_CANCELED = [
		PresenceScopeResolver::ERROR_UNAVAILABLE,
		PresenceScopeResolver::ERROR_NOT_APPLICABLE,
		PresenceScopeResolver::ERROR_TEAM_TOO_LARGE,
	];

	/** Analytics names of the source a build failed on, never sent to the client. */
	protected const SOURCE_COMPOSITION = 'composition';

	protected const SOURCE_WORKTIME = 'worktime';

	/** The work time records hit the page limit: the same refusal for the client, a different fix. */
	protected const SOURCE_WORKTIME_TRUNCATED = 'worktime_truncated';

	protected const SOURCE_ABSENCE = 'absence';
	protected const SOURCE_CONSENT = 'consent';
	protected const SOURCE_CHECKINS = 'checkins';

	private const DATE_FORMAT = 'Y-m-d';

	private PresenceScopeResolver $scopeResolver;
	private WorkDayBatchService $workDayService;
	private AbsenceBatchService $absenceService;
	private CheckInDayFactsProvider $factsProvider;
	private NodeSettingsBatchService $nodeSettingsService;
	private PresenceBadgeResolver $badgeResolver;
	private LatestCheckInReader $latestCheckInReader;
	private CheckInDayZoneReader $dayZoneReader;
	private DailyStatsReader $statsReader;
	private CheckInVisitCounter $visitCounter;
	private PresenceIndexCache $indexCache;
	private TimeZoneService $timeZoneService;

	public function __construct(
		?PresenceScopeResolver $scopeResolver = null,
		?WorkDayBatchService $workDayService = null,
		?AbsenceBatchService $absenceService = null,
		?CheckInDayFactsProvider $factsProvider = null,
		?NodeSettingsBatchService $nodeSettingsService = null,
		?PresenceBadgeResolver $badgeResolver = null,
		?LatestCheckInReader $latestCheckInReader = null,
		?CheckInDayZoneReader $dayZoneReader = null,
		?DailyStatsReader $statsReader = null,
		?CheckInVisitCounter $visitCounter = null,
		?PresenceIndexCache $indexCache = null,
		?TimeZoneService $timeZoneService = null,
	)
	{
		$this->scopeResolver = $scopeResolver ?? new PresenceScopeResolver();
		$this->workDayService = $workDayService ?? new WorkDayBatchService();
		$this->absenceService = $absenceService ?? new AbsenceBatchService();
		$this->factsProvider = $factsProvider ?? new CheckInDayFactsProvider();
		$this->nodeSettingsService = $nodeSettingsService ?? new NodeSettingsBatchService();
		$this->badgeResolver = $badgeResolver ?? new PresenceBadgeResolver();
		$this->latestCheckInReader = $latestCheckInReader ?? new LatestCheckInReader();
		$this->dayZoneReader = $dayZoneReader ?? new CheckInDayZoneReader();
		$this->statsReader = $statsReader ?? new DailyStatsReader();
		$this->visitCounter = $visitCounter ?? new CheckInVisitCounter();
		$this->indexCache = $indexCache ?? new PresenceIndexCache();
		$this->timeZoneService = $timeZoneService ?? ServiceLocator::getInstance()->get(TimeZoneService::class);
	}

	/**
	 * One portion of one tab of the team on a date: the rows of the tab the search finds, in the alphabet, strictly
	 * after the cursor, and the number of every tab of the date after the search.
	 *
	 * The team, its work day and its check-ins are read of everybody - the tabs and their numbers stand on them -
	 * and the absences, the consent and the visits of the rows of the portion alone. A gap in any of them refuses the
	 * portion with ERROR_INCOMPLETE. The points of the map, counted when the query asks for them, are none of these
	 * sources: when they cannot be read the portion is whole and `mapPointsCount` is null. A cursor no portion gave
	 * refuses the request with ERROR_BAD_CURSOR, which is no build and is not counted as one. The index of a whole
	 * build is kept for a minute, and a portion of the same date and selector within it is cut from that index:
	 * nothing of the team is read for it, the sources of its rows are. The query that asks for the age of the index
	 * gets it as `indexAgeSec`: the seconds from the build of the index to the request, zero for a portion of a build.
	 * A query for the points of the map - the card - is cut from the index only when it asks for that age: the client
	 * then keeps the card for what is left of the minute of the index rather than for a minute of its own, while the
	 * card of a client that does not ask builds the team and keeps the index of its build.
	 *
	 * @return Result data of `presence.list.v2` without `users` on success, one code and no data otherwise
	 */
	public function getPresencePortion(int $viewerId, PresenceListQuery $query): Result
	{
		$portionKind = $query->cursor === null ? self::PORTION_FIRST : self::PORTION_NEXT;

		try
		{
			$viewer = $this->scopeResolver->resolveViewer($viewerId);
		}
		catch (\Throwable)
		{
			return $this->incomplete($viewerId, self::SOURCE_COMPOSITION, $portionKind);
		}

		if (!$viewer->isSuccess())
		{
			$this->reportBuild($viewerId, self::outcomeOf($viewer), null, $portionKind);

			return $viewer;
		}

		$now = $this->getServerNow();
		$viewerOffset = $this->getViewerTimezoneOffset();
		$localDate = date(self::DATE_FORMAT, ($query->timestamp > 0 ? $query->timestamp : $now) + $viewerOffset);
		$dateKind = self::dateKindOf($localDate, date(self::DATE_FORMAT, $now + $viewerOffset));

		// a client that does not count the age keeps the card a minute of its own: cut from an index, the card would
		// show a team up to two minutes old
		$team = !$query->withMapPoints || $query->withIndexAge
			? $this->indexCache->read($viewerId, $localDate, $dateKind, $query->entities, $now)
			: null
		;
		$isIndexCached = $team !== null;
		// the statistics of the date a build reads on the way count the points of the map as well
		$statsUserIds = [];
		$stats = [];
		$mapUserIds = null;
		if ($team === null)
		{
			$build = $this->buildTeam($viewerId, $query->entities, $localDate, $dateKind, $now, $portionKind);
			if (!$build->isSuccess())
			{
				return $build;
			}

			[
				'team' => $team,
				'statsUserIds' => $statsUserIds,
				'stats' => $stats,
				'scope' => $scope,
			] = $build->getData();
			if ($query->withMapPoints)
			{
				// kept with the index, so that a card cut from it does not read the structure again
				$mapUserIds = $this->readMapUserIds($viewerId, $query->entities, $scope);
			}
			$this->indexCache->write(
				$viewerId,
				$localDate,
				$dateKind,
				$query->entities,
				$now,
				[...$team, 'mapUserIds' => $mapUserIds],
			);
		}

		$dayStates = $team['dayStates'];
		$index = (new PresenceIndex($team['rows'], $dateKind, PresenceIndex::collatorFor()))->matching($query->query);
		$tab = PresenceTab::resolve($query->tab, $dateKind);

		try
		{
			$portion = $index->portion($tab, $query->cursor, $query->limit);
		}
		catch (ArgumentException)
		{
			return (new Result())->addError(new Error(
				'The cursor of the presence list was given by no portion',
				self::ERROR_BAD_CURSOR,
			));
		}

		$rows = $portion['rows'];
		$absences = $this->readAbsenceKinds(self::userIdsOf($rows), $localDate);
		$badges = $rows === [] ? [] : $this->readBadges(self::userIdsOf($rows), $dateKind);
		$visits = $this->readVisits(
			self::userIdsOf(array_filter($rows, static fn (PresenceIndexRow $row): bool => $row->hasCheckIns)),
			$localDate,
		);

		$failedSource = self::findFailedRowSource($rows, $absences, $badges, $visits);
		if ($failedSource !== null)
		{
			return $this->incomplete($viewerId, $failedSource, $portionKind, $isIndexCached);
		}

		$zoneOffsets = $this->readZoneOffsets(
			self::userIdsOf(array_filter(
				$rows,
				static fn (PresenceIndexRow $row): bool => $row->group !== PresenceGroup::NotStarted,
			)),
			$localDate,
			$dayStates,
			$team['zones'],
		);

		$this->reportBuild($viewerId, self::OUTCOME_SUCCESS, null, $portionKind, $isIndexCached);

		$data = [
			'date' => $localDate,
			'dateKind' => $dateKind,
			'serverNow' => $now,
			'tab' => $tab->value,
			'tabs' => $index->tabCounts(),
			'items' => self::portionItemsOf($rows, $dayStates, $absences, $badges, $visits, $zoneOffsets),
			'nextCursor' => $portion['nextCursor'],
		];

		if ($query->withMapPoints)
		{
			if ($isIndexCached)
			{
				// an index the sheet built, or a card that could not read the map, does not know whom the map draws
				$mapUserIds = $team['mapUserIds'] ?? $this->readMapUserIds($viewerId, $query->entities);
			}

			$data['mapPointsCount'] = $mapUserIds === null
				? null
				: $this->countMapPoints($mapUserIds, $localDate, $now, $statsUserIds, $stats)
			;
		}

		if ($query->withIndexAge)
		{
			$data['indexAgeSec'] = $isIndexCached ? $now - $team['builtAt'] : 0;
		}

		return (new Result())->setData($data);
	}

	protected function getServerNow(): int
	{
		return time();
	}

	/**
	 * The offset of the viewer from the server zone, the one the map names its dates by.
	 */
	protected function getViewerTimezoneOffset(): int
	{
		return (int)\CTimeZone::GetOffset();
	}

	/**
	 * Zones, names and positions in one query. The zone is resolved from the stored value: the per-user
	 * method of the helper reads b_user one user at a time. The name is the one the site formats, the login
	 * without one - the full name the client shows the row with.
	 *
	 * @param int[] $userIds
	 * @return array<int, array{zone: string, name: string, lastName: string, firstName: string, position: string}>
	 *     a value for every employee asked about
	 */
	protected function readProfiles(array $userIds): array
	{
		$rows = UserTable::query()
			->setSelect(['ID', 'LOGIN', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'TIME_ZONE', 'WORK_POSITION'])
			->whereIn('ID', $userIds)
			->exec()
			->fetchAll()
		;

		$nameFormat = (string)\CSite::GetNameFormat();

		$rowByUserId = [];
		foreach ($rows as $row)
		{
			$rowByUserId[(int)$row['ID']] = $row;
		}

		$profiles = [];
		foreach ($userIds as $userId)
		{
			$row = $rowByUserId[$userId] ?? [];

			$profiles[$userId] = [
				'zone' => $this->timeZoneService->resolveEmployeeZone(
					$userId,
					(string)($row['TIME_ZONE'] ?? ''),
				),
				'name' => $row === [] ? '' : \CUser::FormatName(
					$nameFormat,
					[
						'LOGIN' => $row['LOGIN'] ?? '',
						'NAME' => $row['NAME'] ?? '',
						'LAST_NAME' => $row['LAST_NAME'] ?? '',
						'SECOND_NAME' => $row['SECOND_NAME'] ?? '',
					],
					true,
					false,
				),
				'lastName' => (string)($row['LAST_NAME'] ?? ''),
				'firstName' => (string)($row['NAME'] ?? ''),
				'position' => (string)($row['WORK_POSITION'] ?? ''),
			];
		}

		return $profiles;
	}

	protected function sendAnalyticsEvent(AnalyticsEvent $event): void
	{
		$event->send();
	}

	protected function worktimeFailureSource(DayStateDto $state): string
	{
		return $state->truncated ? self::SOURCE_WORKTIME_TRUNCATED : self::SOURCE_WORKTIME;
	}

	/**
	 * The team of the viewer on the date, read whole: its scope, its profiles, its work day and its check-ins. A
	 * refusal of the scope or a source that did not answer about somebody ends the build, counted.
	 *
	 * @param 'past'|'today'|'future' $dateKind
	 * @return Result data `['team' => array, 'statsUserIds' => int[], 'stats' => array<int, array>,
	 *     'scope' => Result]`: the index of the team as {@see PresenceIndexCache} keeps it, without whom the map
	 *     draws, the statistics of the date read on the way and whom they were read of, and the scope the team was
	 *     built of; the refusal or the failure otherwise
	 */
	private function buildTeam(
		int $viewerId,
		array $entities,
		string $localDate,
		string $dateKind,
		int $now,
		string $portionKind,
	): Result
	{
		try
		{
			$scope = $this->scopeResolver->resolve($viewerId, $entities);
		}
		catch (\Throwable)
		{
			return $this->incomplete($viewerId, self::SOURCE_COMPOSITION, $portionKind);
		}

		if (!$scope->isSuccess())
		{
			$this->reportBuild($viewerId, self::outcomeOf($scope), null, $portionKind);

			return $scope;
		}

		$userIds = $scope->getData()['userIds'];

		$profiles = $this->readProfilesSafely($userIds);
		$timeZoneByUserId = array_map(static fn (array $profile): string => $profile['zone'], $profiles);

		$dayStates = $this->readDayStates($userIds, $localDate, $timeZoneByUserId, $dateKind === 'today');
		// one reading of the statistics of the date serves the facts of the team and the points of the map
		$stats = $this->readStats($userIds, $localDate, $now);
		$facts = $this->readFacts($userIds, $stats);

		$failedSource = $this->findFailedTeamSource($userIds, $dayStates, $facts);
		if ($failedSource !== null)
		{
			return $this->incomplete($viewerId, $failedSource, $portionKind);
		}

		// the index holds nobody the scope and the rights left out, whatever a source answered about him
		$teamUserIds = array_flip($userIds);

		return (new Result())->setData([
			'team' => [
				'rows' => self::indexRowsOf($userIds, $profiles, $dayStates, $facts, $dateKind),
				'dayStates' => array_intersect_key($dayStates, $teamUserIds),
				'zones' => array_intersect_key($timeZoneByUserId, $teamUserIds),
			],
			'statsUserIds' => $userIds,
			'stats' => $stats ?? [],
			'scope' => $scope,
		]);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array{zone: string, name: string, lastName?: string, firstName?: string, position?: string}>
	 */
	private function readProfilesSafely(array $userIds): array
	{
		try
		{
			return $this->readProfiles($userIds);
		}
		catch (\Throwable)
		{
			// an unknown zone leaves the work day untrusted, so the build ends as incomplete
			return array_fill_keys($userIds, ['zone' => '', 'name' => '']);
		}
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, string> $timeZoneByUserId
	 * @return array<int, DayStateDto>
	 */
	private function readDayStates(array $userIds, string $localDate, array $timeZoneByUserId, bool $isToday): array
	{
		try
		{
			return $this->workDayService->getDayStates($userIds, $localDate, $timeZoneByUserId, $isToday);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * The badges of the absences of the rows of a portion.
	 *
	 * @param int[] $userIds
	 * @return array<int, array{trusted: bool, absence: ?array{kind: string, dateTo: string}}>|null null when the
	 *     schedule could not be asked at all
	 */
	private function readAbsenceKinds(array $userIds, string $localDate): ?array
	{
		if ($userIds === [])
		{
			return [];
		}

		try
		{
			return $this->absenceService->getAbsenceKinds($userIds, $localDate);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * The visits of the date, the number the ribbon of the day and the badge on the map carry. They are asked of the
	 * rows with check-ins of the date alone: a row without has no visit, and no day is decoded for it.
	 *
	 * @param int[] $userIds
	 * @return array<int, int>|null null when the check-ins of the date could not be decoded
	 */
	private function readVisits(array $userIds, string $localDate): ?array
	{
		if ($userIds === [])
		{
			return [];
		}

		try
		{
			return $this->visitCounter->countByUsers($userIds, new Date($localDate, self::DATE_FORMAT));
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * The statistics of the date of the rows. The stamps of the zones of the rows are read off these rows,
	 * the ones this reading writes included ({@see CheckInDayZoneReader}).
	 *
	 * @param int[] $userIds
	 * @return array<int, array>|null userId => statistics row of the date; null when they could not be read
	 */
	private function readStats(array $userIds, string $localDate, int $now): ?array
	{
		try
		{
			return $this->readStatsOfTheDate($userIds, $localDate, $now);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * A check-in writes the row of its date in its own transaction, so a date still open to new points is read
	 * as written: a missing row there is no check-in, and the list, rebuilt on every refresh of the widget, does
	 * not look for one in the window. A date closed to them has the rows it lacks written the way the map writes
	 * them, by the rule the map caches such a date by.
	 *
	 * @param int[] $userIds
	 * @return array<int, array> userId => statistics row of the date
	 */
	private function readStatsOfTheDate(array $userIds, string $localDate, int $now): array
	{
		return PointAcceptanceService::isDateClosedToNewPoints($localDate, $now)
			? $this->statsReader->read($userIds, $localDate)
			: $this->statsReader->readWithoutBackFill($userIds, $localDate)
		;
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, array>|null $stats
	 * @return array<int, array{trusted: bool, total: int}>
	 */
	private function readFacts(array $userIds, ?array $stats): array
	{
		try
		{
			return $this->factsProvider->getFactsOfStats($userIds, $stats);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * The mark of a withheld consent. An unread auto check-in setting leaves everybody unmarked, an unread
	 * consent - the stored marks or the markers of the devices - refuses the portion.
	 *
	 * The mark is a state of today, as the pause is: a date that is over or still to come leaves everybody
	 * unmarked, and neither the consent nor the setting is read for it.
	 *
	 * @param int[] $userIds
	 * @param 'past'|'today'|'future' $dateKind
	 * @return array<int, array{noConsent: bool}>|null null when the consent could not be read
	 */
	private function readBadges(array $userIds, string $dateKind): ?array
	{
		if ($dateKind !== 'today')
		{
			return array_fill_keys($userIds, ['noConsent' => false]);
		}

		try
		{
			return $this->badgeResolver->resolveReadingSettings($userIds, $this->nodeSettingsService);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * The zone every started day is printed in, the one the panel of the route of the same employee prints it in:
	 * the stamp his check-ins of the date carry and, without one, the zone of his profile - the zone the panel
	 * reads a day without points in. Both are offsets from the server zone, and the row gets what the client of
	 * the panel makes of them: the offset east of UTC it reads off the marker of the start of the day. It is
	 * taken at that start, so a past date keeps the offset it was lived with.
	 *
	 * The zone is none of the four sources: stamps that could not be read leave the profiles to answer.
	 *
	 * @param int[] $userIds
	 * @param array<int, DayStateDto> $dayStates
	 * @param array<int, string> $timeZoneByUserId
	 * @return array<int, int|null> the employees whose day has a start
	 */
	private function readZoneOffsets(
		array $userIds,
		string $localDate,
		array $dayStates,
		array $timeZoneByUserId,
	): array
	{
		$startTsByUserId = [];
		foreach ($userIds as $userId)
		{
			$startTs = $dayStates[$userId]->startTs;
			if ($startTs !== null)
			{
				$startTsByUserId[$userId] = $startTs;
			}
		}

		if ($startTsByUserId === [])
		{
			return [];
		}

		try
		{
			$stamps = $this->dayZoneReader->read(array_keys($startTsByUserId), $localDate);
		}
		catch (\Throwable)
		{
			$stamps = [];
		}

		$zoneOffsets = [];
		foreach ($startTsByUserId as $userId => $startTs)
		{
			$offsetFromServer = $stamps[$userId]
				?? self::offsetFromServerOf($timeZoneByUserId[$userId] ?? '', $startTs)
			;

			$zoneOffsets[$userId] = $offsetFromServer === null
				? null
				: CheckInNormalizer::zoneOffsetFromDateCreate(
					$startTs,
					CheckInNormalizer::formatDateCreate($startTs, [], $offsetFromServer),
				)
			;
		}

		return $zoneOffsets;
	}

	/**
	 * The offset of a zone from the server zone at a moment, the way the panel counts it for a day without points.
	 */
	private static function offsetFromServerOf(string $timeZone, int $timestamp): ?int
	{
		try
		{
			$moment = new \DateTimeImmutable('@' . $timestamp);

			return (new \DateTimeZone($timeZone))->getOffset($moment) - (int)date('Z', $timestamp);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * The first source of the team without a trusted value for somebody: the work day or the check-ins, which the
	 * tabs stand on; a missing value counts as a failure.
	 *
	 * @param int[] $userIds
	 * @param array<int, DayStateDto> $dayStates
	 * @param array<int, array{trusted: bool, total: int}> $facts
	 */
	private function findFailedTeamSource(array $userIds, array $dayStates, array $facts): ?string
	{
		foreach ($userIds as $userId)
		{
			$dayState = $dayStates[$userId] ?? null;
			if (!$dayState?->trusted)
			{
				return $this->worktimeFailureSource($dayState ?? DayStateDto::untrusted());
			}

			if (!($facts[$userId]['trusted'] ?? false))
			{
				return self::SOURCE_CHECKINS;
			}
		}

		return null;
	}

	/**
	 * The first source of the rows of a portion without a trusted value for one of them: the absence, the consent,
	 * or the visits of a row with check-ins, which fail as the check-ins; a missing value counts as a failure.
	 *
	 * @param PresenceIndexRow[] $rows
	 * @param array<int, array{trusted: bool, absence: ?array}>|null $absences
	 * @param array<int, array{noConsent: bool}>|null $badges
	 * @param array<int, int>|null $visits
	 */
	private static function findFailedRowSource(array $rows, ?array $absences, ?array $badges, ?array $visits): ?string
	{
		if ($absences === null)
		{
			return self::SOURCE_ABSENCE;
		}

		if ($badges === null)
		{
			return self::SOURCE_CONSENT;
		}

		if ($visits === null)
		{
			return self::SOURCE_CHECKINS;
		}

		foreach ($rows as $row)
		{
			if (!($absences[$row->userId]['trusted'] ?? false))
			{
				return self::SOURCE_ABSENCE;
			}

			if (!isset($badges[$row->userId]))
			{
				return self::SOURCE_CONSENT;
			}

			if ($row->hasCheckIns && !isset($visits[$row->userId]))
			{
				return self::SOURCE_CHECKINS;
			}
		}

		return null;
	}

	/**
	 * Every employee of the team as the index holds him: the group of his day on the date, whether he checked in,
	 * and the names he is found and ordered by. A date to come carries no check-ins, only the badges of absences,
	 * though an employee east of the viewer may have checked in on it already.
	 *
	 * @param int[] $userIds
	 * @param array<int, array{name: string, lastName?: string, firstName?: string, position?: string}> $profiles
	 * @param array<int, DayStateDto> $dayStates
	 * @param array<int, array{trusted: bool, total: int}> $facts
	 * @param 'past'|'today'|'future' $dateKind
	 * @return PresenceIndexRow[]
	 */
	private static function indexRowsOf(
		array $userIds,
		array $profiles,
		array $dayStates,
		array $facts,
		string $dateKind,
	): array
	{
		$rows = [];
		foreach ($userIds as $userId)
		{
			$profile = $profiles[$userId] ?? [];
			$hasCheckIns = $dateKind !== 'future' && $facts[$userId]['total'] > 0;

			$rows[] = new PresenceIndexRow(
				userId: $userId,
				group: self::groupOfTheDate($dayStates[$userId], $hasCheckIns, $dateKind),
				hasCheckIns: $hasCheckIns,
				lastName: $profile['lastName'] ?? '',
				name: $profile['firstName'] ?? '',
				displayName: $profile['name'] ?? '',
				position: $profile['position'] ?? '',
			);
		}

		return $rows;
	}

	/**
	 * The group of a day on a date of the kind. Nobody has started a day of a date to come: its tabs hold everybody
	 * among those who did not start, and a row of it says the same, whatever work time tracking holds for it.
	 *
	 * @param 'past'|'today'|'future' $dateKind
	 */
	private static function groupOfTheDate(DayStateDto $state, bool $hasCheckIns, string $dateKind): PresenceGroup
	{
		if ($dateKind === 'future')
		{
			return PresenceGroup::NotStarted;
		}

		return PresenceGrouper::groupOf($state->startTs, $state->closed, $hasCheckIns, $dateKind === 'past');
	}

	/**
	 * The rows of `items`. A day that has not started has no time and no zone; a pause marks a day being worked, and
	 * only today has such days.
	 *
	 * @param PresenceIndexRow[] $rows
	 * @param array<int, DayStateDto> $dayStates
	 * @param array<int, array{trusted: bool, absence: ?array}> $absences
	 * @param array<int, array{noConsent: bool}> $badges
	 * @param array<int, int> $visits
	 * @param array<int, int|null> $zoneOffsets
	 * @return list<array>
	 */
	private static function portionItemsOf(
		array $rows,
		array $dayStates,
		array $absences,
		array $badges,
		array $visits,
		array $zoneOffsets,
	): array
	{
		$items = [];
		foreach ($rows as $row)
		{
			$state = $dayStates[$row->userId];
			$started = $row->group !== PresenceGroup::NotStarted;
			$working = $row->group === PresenceGroup::WorkingNoCheckIns
				|| $row->group === PresenceGroup::WorkingWithCheckIns
			;

			$items[] = (new PresenceItemDto(
				userId: $row->userId,
				startTs: $started ? $state->startTs : null,
				endTs: $started ? $state->endTs : null,
				isPaused: $working && $state->paused,
				noConsent: $badges[$row->userId]['noConsent'],
				zoneOffsetSec: $zoneOffsets[$row->userId] ?? null,
				state: $row->group,
				checkInCount: $row->hasCheckIns ? $visits[$row->userId] : 0,
				absence: $absences[$row->userId]['absence'],
			))->toArray();
		}

		return $items;
	}

	/**
	 * @param PresenceIndexRow[] $rows
	 * @return int[]
	 */
	private static function userIdsOf(array $rows): array
	{
		return array_values(array_map(static fn (PresenceIndexRow $row): int => $row->userId, $rows));
	}

	/**
	 * Whom the map beside the list draws, the viewer included. It is none of the sources of a portion: unread, it
	 * leaves the count of the points of the map unknown and the portion whole.
	 *
	 * @param array $entities selector of the map, empty for the team of the viewer
	 * @param Result|null $scope the scope of the build of this request: without a selector the map is drawn over its
	 *     team
	 * @return int[]|null null when it could not be read
	 */
	private function readMapUserIds(int $viewerId, array $entities, ?Result $scope = null): ?array
	{
		try
		{
			return $this->scopeResolver->resolveMapUserIds($viewerId, $entities, $scope);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * The points the map beside the list draws on that date: over whom it draws, and read the way the map reads them,
	 * a folded day included. The statistics the build of this request took the facts off serve here as well; whom they
	 * were not read of - the viewer, for one, and everybody when the portion is cut from an index - has his read here.
	 * The client opens the sheet at once when there are none, so zero is only the count of a map read whole; whom the
	 * map draws, their statistics or their points unread leave the count unknown.
	 *
	 * @param int[] $mapUserIds whom the map draws, {@see readMapUserIds()}
	 * @param int[] $readUserIds whom the statistics of the date were read of by the build of this request
	 * @param array<int, array> $readStats those statistics, userId => row
	 * @return int|null the number of the points; null when they could not be read
	 */
	private function countMapPoints(
		array $mapUserIds,
		string $localDate,
		int $now,
		array $readUserIds,
		array $readStats,
	): ?int
	{
		try
		{
			$unreadUserIds = array_values(array_diff($mapUserIds, $readUserIds));
			$stats = $unreadUserIds === []
				? $readStats
				: $readStats + $this->readStatsOfTheDate($unreadUserIds, $localDate, $now)
			;

			return count($this->latestCheckInReader->readOfStats($mapUserIds, $stats, $localDate));
		}
		catch (\Throwable)
		{
			// the list does not depend on this count, and zero would be taken for an empty map
			return null;
		}
	}

	private function incomplete(
		int $viewerId,
		string $source,
		string $portionKind,
		bool $isIndexCached = false,
	): Result
	{
		$this->reportBuild($viewerId, self::ERROR_INCOMPLETE, $source, $portionKind, $isIndexCached);

		return (new Result())->addError(new Error(
			'The presence list could not be built whole',
			self::ERROR_INCOMPLETE,
		));
	}

	/**
	 * One analytics event per portion: the outcome, the failed source, whether it is the first portion of its tab or
	 * a next one, and whether it was cut from the index kept of a build before it.
	 */
	private function reportBuild(
		int $viewerId,
		string $outcome,
		?string $source,
		string $portionKind,
		bool $isIndexCached = false,
	): void
	{
		try
		{
			$event = new AnalyticsEvent(self::ANALYTICS_EVENT, self::ANALYTICS_TOOL, self::ANALYTICS_CATEGORY);
			$event
				->setSection(self::ANALYTICS_SECTION)
				->setElement($outcome)
				->setUserId($viewerId)
				->setP1($portionKind)
			;

			if ($source !== null)
			{
				$event->setSubSection($source);
			}

			if ($isIndexCached)
			{
				$event->setP2(self::INDEX_CACHED);
			}

			if ($outcome === self::OUTCOME_SUCCESS)
			{
				$event->markAsSuccess();
			}
			elseif (in_array($outcome, self::OUTCOMES_CANCELED, true))
			{
				$event->markAsCanceled();
			}
			else
			{
				$event->markAsError();
			}

			$this->sendAnalyticsEvent($event);
		}
		catch (\Throwable)
		{
			// Measuring the build must not break it.
		}
	}

	private static function outcomeOf(Result $refusal): string
	{
		$error = $refusal->getErrors()[0] ?? null;

		return $error === null ? self::ERROR_INCOMPLETE : (string)$error->getCode();
	}

	/**
	 * @return 'past'|'today'|'future'
	 */
	private static function dateKindOf(string $localDate, string $todayOfViewer): string
	{
		if ($localDate === $todayOfViewer)
		{
			return 'today';
		}

		return $localDate < $todayOfViewer ? 'past' : 'future';
	}
}
