<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * An employee as the index of the presence list holds him: the group his work day puts him in, and the names he is
 * found and ordered by.
 *
 * The index of the list is kept for a minute with him as he is: a property added, dropped or retyped is a new shape
 * of its entry and bumps {@see PresenceIndexCache::VERSION}.
 */
final readonly class PresenceIndexRow
{
	/**
	 * @param bool $hasCheckIns the employee has check-ins on the date, with coordinates or without
	 * @param string $displayName the name formatted by the name format of the site, the one the row shows
	 */
	public function __construct(
		public int $userId,
		public PresenceGroup $group,
		public bool $hasCheckIns,
		public string $lastName,
		public string $name,
		public string $displayName,
		public string $position,
	)
	{
	}

	/**
	 * The last name, the name and the id; without a last name the name comes first, and without both the name the
	 * row shows. Nothing of the day is in it, so an employee keeps his place whatever tab he moves to.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 */
	public function sortKey(): array
	{
		$lastName = trim($this->lastName);
		$name = trim($this->name);

		if ($lastName !== '')
		{
			return [$lastName, $name, $this->userId];
		}

		if ($name !== '')
		{
			return [$name, '', $this->userId];
		}

		return [trim($this->displayName), '', $this->userId];
	}
}
