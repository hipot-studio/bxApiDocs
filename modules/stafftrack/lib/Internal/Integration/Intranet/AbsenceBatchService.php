<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Intranet;

use Bitrix\Intranet\Internal\Entity\AbsenceType;
use Bitrix\Intranet\Internal\Entity\User\Absence;
use Bitrix\Intranet\Public\Dto\User\Absence\BlockingAbsenceBatchResult;
use Bitrix\Intranet\Public\Provider\User\AbsenceProvider;
use Bitrix\Intranet\UserAbsence;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Absences of employees on one whole date, asked of the intranet by its public methods. The date is the calendar
 * day of the portal: an absence is kept in dates of the portal and has no zone of its own.
 *
 * A portal without an absence schedule answers that nobody is absent; only a schedule that failed to answer
 * leaves employees untrusted. The absences switched off among the tools of the portal are no schedule either.
 */
class AbsenceBatchService
{
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * The types of absence that have a badge, and the badge of each, as the statuses of the work days show them.
	 */
	private const KIND_BY_TYPE = [
		'VACATION' => 'vacation',
		'LEAVEMATERINITY' => 'vacation',
		'LEAVESICK' => 'sick_leave',
		'LEAVEUNPAYED' => 'time_off',
	];

	private const NO_BADGE = ['trusted' => true, 'absence' => null];
	private const UNANSWERED = ['trusted' => false, 'absence' => null];

	private ToolAvailabilityService $tools;

	public function __construct(?ToolAvailabilityService $tools = null)
	{
		$this->tools = $tools ?? ServiceLocator::getInstance()->get(ToolAvailabilityService::class);
	}

	/**
	 * The badge the absence of every row of a portion of the list gives. One call of the batch finds who is
	 * absent; the day is read only of those it found - by one more call, or by a call for each of them where the
	 * intranet is older than the reading of the day of a team - and the first absence of the day whose type has a
	 * badge and counts as an absence on the portal gives it. A found employee whose day holds no such absence gets no
	 * badge and is answered for all the same; a reading of the day that failed leaves unanswered whom it was made for.
	 *
	 * @param int[] $userIds the rows of one portion, fewer than the batch method takes at once
	 * @param string $localDate 'Y-m-d'
	 * @return array<int, array{
	 *     trusted: bool,
	 *     absence: ?array{kind: 'vacation'|'sick_leave'|'time_off', dateTo: string}
	 * }> dateTo is the last day of the absence
	 */
	public function getAbsenceKinds(array $userIds, string $localDate): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		if (!$this->hasAbsenceSchedule())
		{
			return array_fill_keys($userIds, self::NO_BADGE);
		}

		try
		{
			[$dateFrom, $dateTo] = self::getBoundsOfTheDay($localDate);
			$day = new Date($localDate, self::DATE_FORMAT);
			$blockingAbsences = self::withoutTimeZones(
				fn (): BlockingAbsenceBatchResult => $this->readBlockingAbsences($userIds, $dateFrom, $dateTo),
			);
		}
		catch (\Throwable)
		{
			return array_fill_keys($userIds, self::UNANSWERED);
		}

		$kinds = [];
		$absentUserIds = [];
		foreach ($userIds as $userId)
		{
			$trusted = $blockingAbsences->isTrusted($userId);
			$kinds[$userId] = $trusted ? self::NO_BADGE : self::UNANSWERED;

			if ($trusted && $blockingAbsences->getForUser($userId) === true)
			{
				$absentUserIds[] = $userId;
			}
		}

		if ($absentUserIds === [])
		{
			return $kinds;
		}

		$kindByType = $this->readKindByActiveType();
		$kindsOfTheDay = $kindByType === null
			? array_fill_keys($absentUserIds, self::UNANSWERED)
			: $this->readKindsOfTheDay($absentUserIds, $day, $kindByType)
		;

		return array_replace($kinds, $kindsOfTheDay);
	}

	protected function isIntranetAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * The absences are not switched off among the tools of the portal, asked the way the work days of the mobile
	 * work time ask it.
	 */
	protected function isAbsenceToolAvailable(): bool
	{
		return $this->tools->isToolAvailable('absence');
	}

	protected function getAbsenceIblockId(): int
	{
		return UserAbsence::getIblockId();
	}

	/**
	 * @param int[] $userIds
	 */
	protected function readBlockingAbsences(
		array $userIds,
		DateTime $dateFrom,
		DateTime $dateTo
	): BlockingAbsenceBatchResult
	{
		return (new AbsenceProvider())->getUsersBlockingAbsences($userIds, $dateFrom, $dateTo);
	}

	/**
	 * @return EntityCollection|null the absences of the day, those taking up the most of it first
	 */
	protected function readAbsencesOfTheDay(int $userId, Date $day): ?EntityCollection
	{
		return (new AbsenceProvider())->getUserAbsencesByDay($userId, $day);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, EntityCollection>|null the absences of the day of every employee, those taking up the most
	 *     of it first
	 */
	protected function readTeamAbsencesOfTheDay(array $userIds, Date $day): ?array
	{
		return (new AbsenceProvider())->getUsersAbsencesByDay($userIds, $day);
	}

	/**
	 * Whether the installed intranet reads the day of a team at once; the one before it reads an employee alone.
	 */
	protected function isTeamDayReadAvailable(): bool
	{
		return method_exists(AbsenceProvider::class, 'getUsersAbsencesByDay');
	}

	protected function readAbsenceTypes(): ?EntityCollection
	{
		return (new AbsenceProvider())->getTypes();
	}

	/**
	 * The portal keeps a schedule of absences to ask: the intranet is there, its absences are switched on and have
	 * their information block.
	 */
	private function hasAbsenceSchedule(): bool
	{
		return $this->isIntranetAvailable() && $this->isAbsenceToolAvailable() && $this->getAbsenceIblockId() > 0;
	}

	/**
	 * @return array<string, string>|null the badge of every type the portal counts as an absence, the types
	 *     without one left out; null when the types could not be read
	 */
	private function readKindByActiveType(): ?array
	{
		try
		{
			$types = $this->readAbsenceTypes();
		}
		catch (\Throwable)
		{
			return null;
		}

		if ($types === null)
		{
			return null;
		}

		$kindByType = [];
		foreach ($types as $type)
		{
			if ($type instanceof AbsenceType && $type->isActive && isset(self::KIND_BY_TYPE[$type->xmlId]))
			{
				$kindByType[$type->xmlId] = self::KIND_BY_TYPE[$type->xmlId];
			}
		}

		return $kindByType;
	}

	/**
	 * The badges of the employees the batch found absent, their days read at once where the intranet reads the day
	 * of a team, and one by one where it is older.
	 *
	 * @param int[] $userIds
	 * @param array<string, string> $kindByType
	 * @return array<int, array{trusted: bool, absence: ?array{kind: string, dateTo: string}}>
	 */
	private function readKindsOfTheDay(array $userIds, Date $day, array $kindByType): array
	{
		if (!$this->isTeamDayReadAvailable())
		{
			$kinds = [];
			foreach ($userIds as $userId)
			{
				$kinds[$userId] = $this->readKindOfTheDay($userId, $day, $kindByType);
			}

			return $kinds;
		}

		try
		{
			$absencesByUserId = self::withoutTimeZones(
				fn (): ?array => $this->readTeamAbsencesOfTheDay($userIds, $day),
			);
		}
		catch (\Throwable)
		{
			return array_fill_keys($userIds, self::UNANSWERED);
		}

		$kinds = [];
		foreach ($userIds as $userId)
		{
			$absences = $absencesByUserId[$userId] ?? null;
			$kinds[$userId] = $absences instanceof EntityCollection
				? self::getKindOf($absences, $kindByType)
				: self::UNANSWERED
			;
		}

		return $kinds;
	}

	/**
	 * @param array<string, string> $kindByType
	 * @return array{trusted: bool, absence: ?array{kind: string, dateTo: string}}
	 */
	private function readKindOfTheDay(int $userId, Date $day, array $kindByType): array
	{
		try
		{
			$absences = self::withoutTimeZones(fn (): ?EntityCollection => $this->readAbsencesOfTheDay($userId, $day));
		}
		catch (\Throwable)
		{
			return self::UNANSWERED;
		}

		return $absences === null ? self::UNANSWERED : self::getKindOf($absences, $kindByType);
	}

	/**
	 * @param EntityCollection $absences the absences of the day, those taking up the most of it first
	 * @param array<string, string> $kindByType
	 * @return array{trusted: true, absence: ?array{kind: string, dateTo: string}}
	 */
	private static function getKindOf(EntityCollection $absences, array $kindByType): array
	{
		foreach ($absences as $absence)
		{
			$kind = $absence instanceof Absence ? ($kindByType[$absence->getTypeXmlId()] ?? null) : null;
			if ($kind !== null)
			{
				return [
					'trusted' => true,
					'absence' => [
						'kind' => $kind,
						'dateTo' => $absence->getDateTo()->format(self::DATE_FORMAT),
					],
				];
			}
		}

		return self::NO_BADGE;
	}

	/**
	 * Runs a reading of the schedule with the time zones switched off, and puts them back as it found them. With the
	 * zones on, the information block hands the moment of a record that has a time of day over shifted into the zone
	 * of the viewer, and the intranet takes it for a moment of the portal: a vacation until 18:00 would end on the
	 * next day for a viewer seven hours east of the portal, and one that begins in the evening would begin on the
	 * next day as well.
	 */
	private static function withoutTimeZones(callable $read): mixed
	{
		$zonesWereOn = \CTimeZone::Enabled();
		if ($zonesWereOn)
		{
			\CTimeZone::Disable();
		}

		try
		{
			return $read();
		}
		finally
		{
			if ($zonesWereOn)
			{
				\CTimeZone::Enable();
			}
		}
	}

	/**
	 * @return array{0: DateTime, 1: DateTime} the first and the last moment of the calendar day of the portal
	 */
	private static function getBoundsOfTheDay(string $localDate): array
	{
		return [
			new DateTime($localDate . ' 00:00:00', 'Y-m-d H:i:s'),
			new DateTime($localDate . ' 23:59:59', 'Y-m-d H:i:s'),
		];
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
