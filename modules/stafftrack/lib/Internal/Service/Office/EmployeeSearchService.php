<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\User\Access\Model\TargetUserModel;
use Bitrix\Intranet\User\Access\UserAccessController;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\StaffTrack\Dictionary\OfficePresence;
use Bitrix\StaffTrack\Internal\Repository\EmployeeSearchRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Item\Collection\UserCollection;
use Bitrix\StaffTrack\Item\EmployeeSearchMatch;
use Bitrix\StaffTrack\Item\EmployeeSearchResult;
use Bitrix\StaffTrack\Provider\UserProvider;

class EmployeeSearchService
{
	public const MIN_QUERY_LENGTH = 3;
	public const MAX_QUERY_LENGTH = 100;
	public const CANDIDATE_LIMIT = 20;

	public function search(string $query, int $viewerId): EmployeeSearchResult
	{
		$query = EmployeeSearchRepository::normalizeQuery($query);
		$queryLength = mb_strlen($query);
		if ($queryLength < self::MIN_QUERY_LENGTH || $queryLength > self::MAX_QUERY_LENGTH)
		{
			return EmployeeSearchResult::empty();
		}

		$candidates = $this->findCandidates($query);
		if ($candidates === [])
		{
			return EmployeeSearchResult::empty();
		}

		$this->preloadViewTargets(array_keys($candidates));

		$activeUserIds = [];
		$hasDismissed = false;
		foreach ($candidates as $userId => $isActive)
		{
			if (!$isActive && $hasDismissed)
			{
				continue;
			}

			if (!$this->canView($viewerId, $userId))
			{
				continue;
			}

			if ($isActive)
			{
				$activeUserIds[] = $userId;
			}
			else
			{
				$hasDismissed = true;
			}
		}

		return new EmployeeSearchResult($this->buildMatches($activeUserIds, $viewerId), $hasDismissed);
	}

	/**
	 * @return array<int, bool>
	 */
	protected function findCandidates(string $query): array
	{
		return (new EmployeeSearchRepository())->findCandidates($query, self::CANDIDATE_LIMIT);
	}

	/**
	 * @param int[] $userIds
	 */
	protected function preloadViewTargets(array $userIds): void
	{
		$users = ServiceContainer::getInstance()->userRepository()->findUsersByIds($userIds);
		foreach ($users as $user)
		{
			TargetUserModel::createFromUserEntity($user);
		}
	}

	protected function canView(int $viewerId, int $userId): bool
	{
		if ($viewerId <= 0 || $userId <= 0)
		{
			return false;
		}

		return UserAccessController::getInstance($viewerId)
			->check(UserActionDictionary::VIEW, TargetUserModel::createFromId($userId))
		;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	protected function getOfficeIds(array $userIds): array
	{
		return (new OfficeEmployeeRepository())->getOfficeIdsByUserIds($userIds);
	}

	/**
	 * @param int[] $officeIds
	 * @return array<int, string>
	 */
	protected function getOfficeTitles(array $officeIds): array
	{
		return (new OfficeRepository())->getTitlesByIds($officeIds);
	}

	/**
	 * @param int[] $userIds
	 */
	protected function loadUsers(array $userIds): UserCollection
	{
		return UserProvider::getInstance()->getUsers($userIds);
	}

	/**
	 * @param int[] $officeIds
	 * @return int[]
	 */
	protected function getVisibleOfficeIds(array $officeIds, int $viewerId): array
	{
		return (new EmployeeListVisibility())->filterVisible($officeIds, $viewerId);
	}

	/**
	 * @param array<int, int> $officeIdByUserId
	 * @return array<int, OfficePresence>
	 */
	protected function getPresences(array $officeIdByUserId): array
	{
		return (new PresenceService())->getStatusesInOffices($officeIdByUserId);
	}

	/**
	 * @param int[] $userIds
	 * @return EmployeeSearchMatch[]
	 */
	private function buildMatches(array $userIds, int $viewerId): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$usersById = [];
		foreach ($this->loadUsers($userIds) as $user)
		{
			$usersById[$user->id] = $user;
		}

		$officeIds = $this->getOfficeIds(array_keys($usersById));
		$officeTitles = $officeIds === [] ? [] : $this->getOfficeTitles(array_values(array_unique($officeIds)));
		$presences = $this->readPresences($userIds, $officeIds, $viewerId);

		$matches = [];
		foreach ($userIds as $userId)
		{
			$user = $usersById[$userId] ?? null;
			if ($user === null)
			{
				continue;
			}

			$officeId = $officeIds[$userId] ?? null;

			$matches[] = new EmployeeSearchMatch(
				id: $user->id,
				name: $user->name,
				avatar: $user->avatar,
				workPosition: $user->workPosition,
				presence: $presences[$userId] ?? null,
				officeId: $officeId,
				officeTitle: $officeId === null ? null : ($officeTitles[$officeId] ?? null),
			);
		}

		return $matches;
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, int> $officeIds
	 * @return array<int, OfficePresence>
	 */
	private function readPresences(array $userIds, array $officeIds, int $viewerId): array
	{
		$officeIdByUserId = [];
		foreach ($userIds as $userId)
		{
			$officeId = $officeIds[$userId] ?? null;
			if ($officeId !== null && $userId !== $viewerId)
			{
				$officeIdByUserId[$userId] = $officeId;
			}
		}

		if ($officeIdByUserId === [])
		{
			return [];
		}

		$visibleOfficeIds = $this->getVisibleOfficeIds(array_values(array_unique($officeIdByUserId)), $viewerId);
		$officeIdByUserId = array_filter(
			$officeIdByUserId,
			static fn (int $officeId): bool => in_array($officeId, $visibleOfficeIds, true),
		);
		if ($officeIdByUserId === [])
		{
			return [];
		}

		$statuses = $this->getPresences($officeIdByUserId);

		$presences = [];
		foreach (array_keys($officeIdByUserId) as $userId)
		{
			$presences[$userId] = $statuses[$userId] ?? OfficePresence::UNKNOWN;
		}

		return $presences;
	}
}
