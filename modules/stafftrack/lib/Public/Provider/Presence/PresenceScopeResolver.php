<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayBatchService;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;

/**
 * Whom the presence list is built about, decided before any source of the day is read. Every refusal hides
 * the widget and is not worth a retry.
 *
 * The rows are the current team of the viewer (or whom the selector of the map names) without the viewer,
 * without users outside the check-in rollout and without those whose work time the viewer may not read.
 * The rights of work time tracking are those of the user of the request, so the list is built for him only.
 */
class PresenceScopeResolver
{
	/** Work time tracking is unavailable to the viewer or withholds the whole team from him. */
	public const ERROR_UNAVAILABLE = 'PRESENCE_UNAVAILABLE';

	/** The viewer has no team to show. */
	public const ERROR_NOT_APPLICABLE = 'PRESENCE_NOT_APPLICABLE';

	/** The team, or whom the selector of the map names, is larger than the list is built about. */
	public const ERROR_TEAM_TOO_LARGE = 'PRESENCE_TEAM_TOO_LARGE';

	/**
	 * The largest team the list is built about, measured before the work time rights are asked: the rows the rights
	 * leave are not measured again, the list carries them in portions. The rights of a team are one question, yet the
	 * ceiling still bounds two costs: a timeman older than that question is asked about every employee, each check a
	 * pass over the users the viewer may read (WorkDayBatchService), and the selector of the map is read into people
	 * up to it (readScope()). The ceiling is to be revisited once that per-employee path is removed.
	 */
	private const MAX_SCOPE_USER_IDS_BEFORE_RIGHTS = 1500;

	private CheckInSettingsProvider $settingsProvider;
	private WorkDayBatchService $workDayService;

	/**
	 * The team the last list without a selector was built of, with the scope resolve() gave for it and its viewer.
	 *
	 * @var array{scope: Result, viewerId: int, userIds: int[]}|null
	 */
	private ?array $teamOfTheList = null;

	/** @var int[]|null the team the map is drawn over while it is read, {@see resolveMapUserIds()} */
	private ?array $teamOfTheMap = null;

	public function __construct(
		?CheckInSettingsProvider $settingsProvider = null,
		?WorkDayBatchService $workDayService = null,
	)
	{
		$this->settingsProvider = $settingsProvider ?? new CheckInSettingsProvider();
		$this->workDayService = $workDayService ?? new WorkDayBatchService();
	}

	/**
	 * Whether the list may be built for this viewer at all, decided before anything of his team is read or taken from
	 * the cache of the index: the rights of work time tracking are those of the user of the request, so the list is
	 * built for him alone.
	 *
	 * @return Result no data when the viewer may be served; one of the codes above otherwise
	 */
	public function resolveViewer(int $viewerId): Result
	{
		$result = new Result();

		if ($viewerId <= 0)
		{
			return $result->addError(self::notApplicable());
		}

		if ($viewerId !== $this->getCurrentUserId())
		{
			return $result->addError(new Error(
				'The presence list is built for the user of the request only',
				self::ERROR_UNAVAILABLE,
			));
		}

		return $result;
	}

	/**
	 * @param array $entities selector of the map, empty for the team of the viewer
	 * @return Result data `['userIds' => int[]]`: the rows of the list; one of the codes above otherwise
	 */
	public function resolve(int $viewerId, array $entities): Result
	{
		$result = $this->resolveViewer($viewerId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->isWorkTimeTrackingUsable())
		{
			return $result->addError(new Error(
				'Work time tracking is unavailable to the viewer',
				self::ERROR_UNAVAILABLE,
			));
		}

		$scopeUserIds = $this->readScope($viewerId, $entities);
		if ($scopeUserIds === null)
		{
			return $result->addError(self::teamTooLarge());
		}

		$scopeUserIds = self::normalizeUserIds($scopeUserIds);
		if ($scopeUserIds === [])
		{
			return $result->addError(self::notApplicable());
		}

		// the selector names the viewer when he picks himself or his own department
		$teamUserIds = array_values(array_diff($scopeUserIds, [$viewerId]));
		if ($teamUserIds === [])
		{
			return $result->addError(self::notApplicable());
		}

		$userIds = $this->dropUsersOutsideRollout($teamUserIds);
		if ($userIds === [])
		{
			return $result->addError(self::notApplicable());
		}

		if (count($userIds) > self::MAX_SCOPE_USER_IDS_BEFORE_RIGHTS)
		{
			return $result->addError(self::teamTooLarge());
		}

		$userIds = $this->workDayService->filterReadableUserIds($userIds);
		if ($userIds === [])
		{
			return $result->addError(new Error(
				'Work time tracking withholds the day of every employee of the team from the viewer',
				self::ERROR_UNAVAILABLE,
			));
		}

		if ($entities === [])
		{
			$this->teamOfTheList = ['scope' => $result, 'viewerId' => $viewerId, 'userIds' => $scopeUserIds];
		}

		return $result->setData(['userIds' => $userIds]);
	}

	/**
	 * Whom the map beside the list draws: the viewer included, nobody dropped for the rollout or the rights. It is
	 * read only when the points of the map are counted: with a selector it is a reading of the structure of its own.
	 * Without one, given the scope resolve() gave for the list of the same build, the map is drawn over the team that
	 * list was built of: read again, the team could be another one, and a reset of the structure in between would
	 * make the read a second build.
	 *
	 * @param array $entities selector of the map, empty for the team of the viewer
	 * @param Result|null $scope what resolve() gave for the list of the same build
	 * @return int[] nobody for a viewer the list is not built for
	 */
	public function resolveMapUserIds(int $viewerId, array $entities, ?Result $scope = null): array
	{
		if (!$this->resolveViewer($viewerId)->isSuccess())
		{
			return [];
		}

		$this->teamOfTheMap = $this->findTeamOfTheList($scope, $viewerId, $entities);
		try
		{
			return self::normalizeUserIds($this->readMapScope($viewerId, $entities));
		}
		finally
		{
			$this->teamOfTheMap = null;
		}
	}

	/**
	 * The team as the team cache keeps it, or whom the selector names as the company structure holds them now; for a
	 * past date too: the history of the structure is not kept. The map of a build is given the team of its list.
	 *
	 * @param array $entities
	 * @return int[]|null null when the selector names more codes or people than the list may read whole
	 */
	protected function readScope(int $viewerId, array $entities): ?array
	{
		if ($entities !== [])
		{
			return HumanResources\DepartmentProvider::resolveCompleteDepartmentSelectorEntitiesForUser(
				$entities,
				$viewerId,
				self::MAX_SCOPE_USER_IDS_BEFORE_RIGHTS,
			);
		}

		return $this->teamOfTheMap ?? $this->readTeam($viewerId)->userIds;
	}

	/**
	 * The team of the viewer without a filter, kept between processes until the structure is reset.
	 */
	protected function readTeam(int $viewerId): HumanResources\TeamScope
	{
		return HumanResources\DepartmentProvider::getTeamScope($viewerId);
	}

	/**
	 * Whom the map draws, as CheckInProvider::getLatestUsersCheckInByDepartment() counts them: without a selector the
	 * viewer and his team before the rollout filter.
	 *
	 * @return int[]
	 */
	protected function readMapScope(int $viewerId, array $entities): array
	{
		if ($entities !== [])
		{
			return HumanResources\DepartmentProvider::resolveDepartmentSelectorEntitiesForUser($entities, $viewerId);
		}

		return [$viewerId, ...($this->readScope($viewerId, []) ?? [])];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>
	 */
	protected function readRolloutFlags(array $userIds): array
	{
		return Feature::filterCheckInRolloutEnabled($userIds);
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function isWorkTimeTrackingUsable(): bool
	{
		return $this->settingsProvider->isTimemanAllowedByLicense()
			&& $this->settingsProvider->isWorkTimeToolAvailable()
			&& $this->settingsProvider->isTimemanAvailable()
		;
	}

	/**
	 * @return int[]|null the team of the list the scope was given for, when the map is drawn for the same viewer
	 *     without a selector; null otherwise
	 */
	private function findTeamOfTheList(?Result $scope, int $viewerId, array $entities): ?array
	{
		$team = $this->teamOfTheList;
		if ($scope === null || $team === null || $entities !== [])
		{
			return null;
		}

		return $team['scope'] === $scope && $team['viewerId'] === $viewerId ? $team['userIds'] : null;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function dropUsersOutsideRollout(array $userIds): array
	{
		$flags = $this->readRolloutFlags($userIds);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => (bool)($flags[$userId] ?? false),
		));
	}

	private static function notApplicable(): Error
	{
		return new Error('The viewer has no team to show', self::ERROR_NOT_APPLICABLE);
	}

	private static function teamTooLarge(): Error
	{
		return new Error('The team is larger than the presence list is built about', self::ERROR_TEAM_TOO_LARGE);
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
