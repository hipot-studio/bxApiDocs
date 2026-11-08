<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Dictionary\OfficePresence;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Item;
use Bitrix\StaffTrack\Provider\UserProvider;

/**
 * The staff of an office as a client reads it: the card of an office, the directory of offices and the
 * page of the staff all take it from here.
 *
 * The identifiers of a page come from the storage and the people behind them from the provider of users,
 * in one call for the whole page. A person the provider did not answer with leaves the page silently -
 * the same as a photo whose file is no longer in the storage - but never reaches the cursor or the sign
 * of continuation: both are counted by the storage over the rows it read, and a page shorter than the
 * rows behind it is exactly the case a sign taken from the length of the answer would get wrong.
 *
 * The count and the list are taken by two queries, so a person who disappears between them stays in the
 * number while leaving the list. The window is one call wide and is accepted as it is (ADR, "Reading").
 *
 * Where the staff of a page stands with the office is asked once for the whole page, before the records
 * are assembled: asking inside the loop would cost a query per person. The working employees are already
 * picked out by the read above, so presence repeats no predicate of that kind.
 *
 * What a unit test replaces is reached through the members below: the storage through the property, the
 * records of the people through the method.
 */
class EmployeeService
{
	/** No more than twenty employees per page (AC-010 BRIEF). */
	public const PAGE_SIZE = 20;

	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected PresenceService $presenceService;

	public function __construct()
	{
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->presenceService = new PresenceService();
	}

	/**
	 * A page of the staff of an office, continued by the cursor of the previous one; zero starts it.
	 *
	 * @return array{
	 *     employees: Item\Collection\OfficeEmployeeCollection,
	 *     nextAfterUserId: int,
	 *     hasMore: bool,
	 * }
	 */
	public function getPage(int $officeId, int $afterUserId = 0): array
	{
		$page = $this->officeEmployeeRepository->getUserIdsPage($officeId, $afterUserId, self::PAGE_SIZE);
		$presence = $this->presenceService->getStatuses($officeId, $page['userIds']);

		return [
			'employees' => $this->buildEmployees($page['userIds'], $presence),
			'nextAfterUserId' => $page['nextAfterUserId'],
			'hasMore' => $page['hasMore'],
		];
	}

	public function countByOfficeId(int $officeId): int
	{
		return $this->officeEmployeeRepository->countByOfficeId($officeId);
	}

	/**
	 * @param int[] $officeIds
	 * @return array<int, int> office id => how many employees it has
	 */
	public function countByOfficeIds(array $officeIds): array
	{
		return $this->officeEmployeeRepository->countByOfficeIds($officeIds);
	}

	/**
	 * @param int[] $userIds
	 */
	protected function loadUsers(array $userIds): Item\Collection\UserCollection
	{
		return UserProvider::getInstance()->getUsers($userIds);
	}

	/**
	 * The order of the page is the order the storage gave the identifiers in: the provider of users
	 * answers with the people it found and promises nothing about the order of them (AC-012).
	 *
	 * @param int[] $userIds
	 * @param array<int, OfficePresence> $presence where the staff of the page stands with this office
	 */
	private function buildEmployees(array $userIds, array $presence): Item\Collection\OfficeEmployeeCollection
	{
		if ($userIds === [])
		{
			return new Item\Collection\OfficeEmployeeCollection([]);
		}

		$usersById = [];
		foreach ($this->loadUsers($userIds) as $user)
		{
			$usersById[$user->id] = $user;
		}

		$employees = [];
		foreach ($userIds as $userId)
		{
			$user = $usersById[$userId] ?? null;
			if ($user === null)
			{
				continue;
			}

			$employees[] = new Item\OfficeEmployee(
				id: $user->id,
				name: $user->name,
				avatar: $user->avatar,
				workPosition: $user->workPosition,
				// A person the answer above left out is the same silence as a person with no row at all
				presence: $presence[$userId] ?? OfficePresence::UNKNOWN,
			);
		}

		return new Item\Collection\OfficeEmployeeCollection($employees);
	}
}
