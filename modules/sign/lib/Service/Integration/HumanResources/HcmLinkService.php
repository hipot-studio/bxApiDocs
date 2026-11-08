<?php

namespace Bitrix\Sign\Service\Integration\HumanResources;

use Bitrix\HumanResources\Item\Collection\HcmLink\EmployeeCollection;
use Bitrix\HumanResources\Item\HcmLink\Employee;
use Bitrix\HumanResources\Item\HcmLink\Company;
use Bitrix\HumanResources\Item\HcmLink\Person;
use Bitrix\HumanResources\Result\Service\HcmLink\FilterNotMappedUserIdsResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\HcmLink\JobStatus;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Result\Service\Integration\HumanResources\HcmLinkJobsCheckResult;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\HumanResources;
use Bitrix\Main;

class HcmLinkService
{
	public function isAvailable(): bool
	{
		if (!Main\Loader::includeModule('humanresources'))
		{
			return false;
		}

		if (!class_exists('\Bitrix\HumanResources\Config\Feature'))
		{
			return false;
		}

		return HumanResources\Config\Feature::instance()->isHcmLinkAvailable();
	}

	public function fillOneLinkedMembersWithEmployeeId(
		Document $document,
		MemberCollection $members,
		int $representativeId,
	): void
	{
		if (!$this->isAvailable() || !$document->hcmLinkCompanyId)
		{
			return;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$userIds = [];
		foreach ($members as $member)
		{
			if ($member->entityType === EntityType::USER)
			{
				$userIds[$member->entityId] = $member->entityId;
			}
		}
		$userIds[$representativeId] = $representativeId;
		$userIds = array_values($userIds);

		$employeesByUserIds = Container::getHcmLinkMapperService()
			->listMappedUserIdWithOneEmployeePosition($document->hcmLinkCompanyId, ...$userIds)
		;

		foreach ($members as $member)
		{
			$userId = $this->resolveMemberUserId($member, $representativeId);
			if ($userId > 0)
			{
				$member->employeeId = $employeesByUserIds[$userId] ?? null;
			}
		}
	}

	/**
	 * ALG-01, the rule of who blocks the send. A member blocks it while he has no employee position,
	 * unless he is excluded in the company and holds no person link: the exclusion applies only while
	 * the user stays unmapped, because the data of 1C wins over it.
	 *
	 * The mapping is read by person links and never by $member->employeeId: that property means "one
	 * certain employee position is chosen" and is filled only for a user with exactly one position, so
	 * a mapped employee with two vacancies never gets it.
	 *
	 * The method writes nothing at all. The state "excluded and mapped" is resolved silently here,
	 * while dropping such an exclusion belongs to the branches of humanresources that assign a user to
	 * a person - this one is called by actions polled in a loop.
	 *
	 * @return MemberCollection the members that block the send
	 */
	public function resolveBlockingMembers(Document $document, MemberCollection $members): MemberCollection
	{
		$companyId = (int)$document->hcmLinkCompanyId;
		if ($companyId < 1 || !$this->isAvailable())
		{
			return new MemberCollection();
		}

		// A member blocks only while he is left without an employee position, so with every position
		// filled the answer is empty whatever the two sets say. A send by template runs this per
		// document of the batch, and both reads would be spent on a known answer.
		$candidates = $this->filterMembersWithoutEmployee($members);
		if ($candidates->isEmpty())
		{
			return $candidates;
		}

		$representativeId = (int)$document->representativeId;
		$userIds = $this->listMemberUserIds($members, $representativeId);
		if ($userIds === [])
		{
			return $candidates;
		}

		$excludedUserIds = $this->filterExcludedUserIds($companyId, $userIds);
		$notMappedUserIds = $this->listNotMappedUserIds($companyId, $userIds);
		if ($notMappedUserIds === null)
		{
			// The person links stayed unread, so "the mapping wins" cannot be decided: no exclusion is
			// applied and the members block exactly as they do today.
			$excludedUserIds = [];
			$notMappedUserIds = $userIds;
		}

		$excluded = array_fill_keys($excludedUserIds, true);
		$mapped = array_fill_keys(array_diff($userIds, $notMappedUserIds), true);

		return $members->filter(
			function (Member $member) use ($excluded, $mapped, $representativeId): bool {
				$userId = $this->resolveMemberUserId($member, $representativeId);
				if (isset($excluded[$userId]) && !isset($mapped[$userId]))
				{
					return false;
				}

				return !$member->employeeId;
			},
		);
	}

	/**
	 * The read contract of humanresources is the only surface the flag is taken from, and this is the
	 * only place of sign that touches it. An installed version knowing nothing about exclusions is not
	 * an error: nobody is excluded and the send behaves as it does today.
	 *
	 * @param array<int> $userIds
	 *
	 * @return list<int> those of the passed users who are excluded in the company
	 */
	public function filterExcludedUserIds(int $companyId, array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($companyId < 1 || $userIds === [])
		{
			return [];
		}

		try
		{
			return $this->normalizeUserIds($this->readExcludedUserIds($companyId, $userIds) ?? []);
		}
		catch (\Throwable)
		{
			// The code of humanresources can arrive before its migration, and the table of the
			// exclusions would not exist yet: reading it must not break every send of the portal.
			return [];
		}
	}

	/**
	 * Seam for tests: the service of the other module is reached through its static container, and an
	 * installed version knowing nothing about exclusions has no service to reach.
	 *
	 * @param list<int> $userIds
	 *
	 * @return list<int>|null null when the installed humanresources knows no exclusions
	 */
	protected function readExcludedUserIds(int $companyId, array $userIds): ?array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		if (!method_exists(Container::class, 'getHcmLinkExclusionService'))
		{
			return null;
		}

		return Container::getHcmLinkExclusionService()->filterExcludedUserIds($companyId, $userIds);
	}

	/**
	 * Seam for tests: the mapper of the other module is reached through its static container.
	 *
	 * @param list<int> $userIds
	 *
	 * @return list<int>|null null when the person links could not be read at all
	 */
	protected function listNotMappedUserIds(int $companyId, array $userIds): ?array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		$result = Container::getHcmLinkMapperService()->filterNotMappedUserIds($companyId, ...$userIds);
		if (!$result instanceof FilterNotMappedUserIdsResult)
		{
			return null;
		}

		return $this->normalizeUserIds($result->userIds);
	}

	private function filterMembersWithoutEmployee(MemberCollection $members): MemberCollection
	{
		return $members->filter(static fn(Member $member): bool => !$member->employeeId);
	}

	/**
	 * @return list<int> unique identifiers of the users the members stand for
	 */
	private function listMemberUserIds(MemberCollection $members, int $representativeId): array
	{
		$userIds = [];
		foreach ($members as $member)
		{
			$userId = $this->resolveMemberUserId($member, $representativeId);
			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}
		}

		return array_values($userIds);
	}

	/**
	 * A member of the company entity type stands for the representative of the document, so the
	 * representative can be excluded as well.
	 */
	private function resolveMemberUserId(Member $member, int $representativeId): int
	{
		return match ($member->entityType)
		{
			EntityType::USER => (int)$member->entityId,
			EntityType::COMPANY => $representativeId,
			default => 0,
		};
	}

	/**
	 * @param array<mixed> $userIds
	 *
	 * @return list<int> unique positive identifiers
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	public function isAllJobsDone(array $jobIds): Result|HcmLinkJobsCheckResult
	{
		if (!$this->isAvailable() || !Loader::includeModule('humanresources'))
		{
			return (new Result())->addError(new Error('Integration not available'));
		}

		foreach ($jobIds as $jobId)
		{
			$job = HumanResources\Service\Container::getHcmLinkJobRepository()->getById($jobId);
			if (!$job)
			{
				return (new Result())->addError(new Error('No job found','NO_JOB_FOUND'));
			}

			if ($job->status === JobStatus::CANCELED)
			{
				return (new Result())->addError(new Error('Job canceled','JOB_CANCELED'));
			}

			if ($job->status === JobStatus::EXPIRED)
			{
				return (new Result())->addError(new Error('Job expired','JOB_EXPIRED'));
			}

			if ($job->status !== JobStatus::DONE)
			{
				return new HcmLinkJobsCheckResult(false);
			}
		}

		return new HcmLinkJobsCheckResult(true);
	}

	public function isCompanyExistWithId(int $companyId): bool
	{
		return Container::getHcmLinkCompanyRepository()->getById($companyId) !== null;
	}

	public function getCompanyByUniqueId(string $companyUuid): ?Company
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getHcmLinkCompanyRepository()->getByUnique($companyUuid);
	}

	public function getCompanyById(int $id): ?Company
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getHcmLinkCompanyRepository()->getById($id);
	}

	public function getCompanyByMyCompanyId(int $myCompanyId): ?Company
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getHcmLinkCompanyRepository()->getByCompanyId($myCompanyId)->getFirst();
	}

	public function getEmployeesByIds(array $ids): EmployeeCollection
	{
		if (!Loader::includeModule('humanresources'))
		{
			return new EmployeeCollection();
		}

		return Container::getHcmLinkEmployeeRepository()->getByIds($ids);
	}

	public function getEmployeesByUnique(int $companyId, string $code): ?Employee
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getHcmLinkEmployeeRepository()->getByUnique($companyId, $code);
	}

	public function getPersonById(int $personId): ?Person
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getHcmLinkPersonRepository()->getById($personId);
	}

	public function getById(int $companyId): ?HumanResources\Item\HcmLink\Company
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return Container::getHcmLinkCompanyRepository()->getById($companyId);
	}
}
