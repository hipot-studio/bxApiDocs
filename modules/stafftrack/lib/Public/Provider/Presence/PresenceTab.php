<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * The tabs of the presence list, made up of the groups of the work day. The kind of the date decides which tabs
 * it has, in what order, and which one the list opens on.
 */
enum PresenceTab: string
{
	case Working = 'working';
	case NoCheckIns = 'no_checkins';
	case WithCheckIns = 'with_checkins';
	case Finished = 'finished';
	case NotStarted = 'not_started';
	case All = 'all';

	private const TODAY = 'today';
	private const PAST = 'past';
	private const FUTURE = 'future';

	private const WORKING_GROUPS = [PresenceGroup::WorkingNoCheckIns, PresenceGroup::WorkingWithCheckIns];

	/**
	 * @param 'past'|'today'|'future' $dateKind
	 * @return self[] the tabs of the date in the order they are shown
	 */
	public static function ofDate(string $dateKind): array
	{
		return match ($dateKind)
		{
			self::TODAY => [
				self::Working,
				self::NoCheckIns,
				self::WithCheckIns,
				self::Finished,
				self::NotStarted,
				self::All,
			],
			self::PAST, self::FUTURE => [
				self::Finished,
				self::NotStarted,
				self::WithCheckIns,
				self::NoCheckIns,
				self::All,
			],
		};
	}

	/**
	 * @param 'past'|'today'|'future' $dateKind
	 */
	public static function defaultOf(string $dateKind): self
	{
		return match ($dateKind)
		{
			self::TODAY => self::Working,
			self::PAST => self::Finished,
			self::FUTURE => self::NotStarted,
		};
	}

	/**
	 * The tab asked for, when the date has it, and the tab the date opens on otherwise.
	 *
	 * @param string|null $code the code of the tab from the request
	 * @param 'past'|'today'|'future' $dateKind
	 */
	public static function resolve(?string $code, string $dateKind): self
	{
		$tab = self::tryFrom((string)$code);

		return $tab !== null && in_array($tab, self::ofDate($dateKind), true) ? $tab : self::defaultOf($dateKind);
	}

	/**
	 * Whether an employee of the group is in this tab on a date of the kind. A tab the date does not have holds
	 * nobody.
	 *
	 * @param bool $hasCheckIns the employee has check-ins on the date, with coordinates or without
	 * @param 'past'|'today'|'future' $dateKind
	 */
	public function holds(PresenceGroup $group, bool $hasCheckIns, string $dateKind): bool
	{
		return match ($dateKind)
		{
			self::TODAY => $this->holdsToday($group),
			self::PAST => $this->holdsOnPastDate($group, $hasCheckIns),
			self::FUTURE => $this === self::NotStarted || $this === self::All,
		};
	}

	private function holdsToday(PresenceGroup $group): bool
	{
		return match ($this)
		{
			self::Working => in_array($group, self::WORKING_GROUPS, true),
			self::NoCheckIns => $group === PresenceGroup::WorkingNoCheckIns,
			self::WithCheckIns => $group === PresenceGroup::WorkingWithCheckIns,
			self::Finished => $group === PresenceGroup::Finished,
			self::NotStarted => $group === PresenceGroup::NotStarted,
			self::All => true,
		};
	}

	/**
	 * Nobody works on a date that is over, so the tabs of the check-ins divide those who finished the day.
	 */
	private function holdsOnPastDate(PresenceGroup $group, bool $hasCheckIns): bool
	{
		return match ($this)
		{
			self::Working => false,
			self::NoCheckIns => $group === PresenceGroup::Finished && !$hasCheckIns,
			self::WithCheckIns => $group === PresenceGroup::Finished && $hasCheckIns,
			self::Finished => $group === PresenceGroup::Finished,
			self::NotStarted => $group === PresenceGroup::NotStarted,
			self::All => true,
		};
	}
}
