<?php

namespace Bitrix\HumanResources\Service\HcmLink;

use Bitrix\HumanResources\Contract;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Repository\HcmLink\ExclusionRepository;
use Bitrix\HumanResources\Service\HcmLink\Counter\CompanyCounterService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use Bitrix\Sign;

/**
 * The single point where the exclusion flag is written and read inside the module.
 */
class ExclusionService
{
	public const ERROR_LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

	/**
	 * The head case of the feature is a whole department added at once, "300+ people": the limit keeps
	 * room for it and still bounds the damage of an erroneous mass call.
	 */
	public const USER_IDS_LIMIT = 500;

	/**
	 * The code of the feature in the list of released ones the sign module carries along with its code.
	 */
	public const SIGN_FEATURE_CODE = 'hcmLinkExclusions';

	public function __construct(
		private readonly ExclusionRepository $exclusionRepository,
		private readonly Contract\Repository\HcmLink\PersonRepository $personRepository,
		private readonly CompanyCounterService $companyCounterService,
	) {}

	/**
	 * Excluding a mapped user drops his person links first: the two steps are one logical operation,
	 * a user left without both would lose his 1C match and get nothing in return.
	 *
	 * @param array<int> $userIds
	 *
	 * @return Result with data{addedCount: int, excludedUserIds: array<int>}
	 * @throws \Throwable when the storage refuses either step
	 */
	public function exclude(int $companyId, array $userIds, int $actorId): Result
	{
		$result = new Result();

		if (count($userIds) > self::USER_IDS_LIMIT)
		{
			return $result->addError($this->createLimitExceededError());
		}

		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return $result->setData(['addedCount' => 0, 'excludedUserIds' => []]);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$droppedLinkCount = $this->personRepository->dropLinksByUserIds($companyId, $userIds);
			$addedCount = $this->exclusionRepository->addUserIds($companyId, $userIds, $actorId);
		}
		catch (\Throwable $exception)
		{
			$this->rollbackQuietly($connection);

			throw $exception;
		}

		$connection->commitTransaction();

		// A dropped link is the only thing the operation can change the number of companies with
		// unmapped persons by, and the recount groups the whole person table of the portal: it runs
		// once per operation, never once per user, and not at all when no link was dropped.
		if ($droppedLinkCount > 0)
		{
			$this->companyCounterService->update();
		}

		return $result->setData([
			'addedCount' => $addedCount,
			'excludedUserIds' => $userIds,
		]);
	}

	/**
	 * Person links are not restored - there is nothing to restore them from.
	 *
	 * @param array<int> $userIds
	 * @param int|null $actorId null when the caller is not a portal user
	 *
	 * @return Result with data{deletedCount: int}
	 */
	public function removeExclusions(int $companyId, array $userIds, ?int $actorId = null): Result
	{
		$result = new Result();

		if (count($userIds) > self::USER_IDS_LIMIT)
		{
			return $result->addError($this->createLimitExceededError());
		}

		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return $result->setData(['deletedCount' => 0]);
		}

		$deletedCount = $this->exclusionRepository->deleteByUserIds($companyId, $userIds);

		return $result->setData(['deletedCount' => $deletedCount]);
	}

	/**
	 * API-02 read contract: the only surface through which sign learns about exclusions.
	 *
	 * @param array<int> $userIds
	 *
	 * @return array<int> those of the passed users who are excluded in the company
	 */
	public function filterExcludedUserIds(int $companyId, array $userIds): array
	{
		return $this->exclusionRepository->filterExcludedUserIds($companyId, $userIds);
	}

	/**
	 * @return array<Item\HcmLink\Exclusion>
	 */
	public function getListByCompanyId(
		int $companyId,
		?ConditionTree $filter = null,
		?string $searchName = null,
		int $limit = 20,
		int $offset = 0,
	): array
	{
		return $this->exclusionRepository->getListByCompanyId($companyId, $filter, $searchName, $limit, $offset);
	}

	public function countByCompanyId(
		int $companyId,
		?ConditionTree $filter = null,
		?string $searchName = null,
	): int
	{
		return $this->exclusionRepository->countByCompanyId($companyId, $filter, $searchName);
	}

	/**
	 * The gate closes the reverse mismatch of the two updates: an exclusion drops the person link, and
	 * a sign that knows nothing about exclusions would start blocking a member that used to be sent
	 * fine. The list of released features travels with the code of sign, so a module older than the
	 * feature simply does not know the code. No sign module means no support - and humanresources does
	 * not declare sign among its dependencies at all, hence the check of the class next to the check of
	 * the module.
	 */
	public function isWriteSupportedBySign(): bool
	{
		if (!Loader::includeModule('sign'))
		{
			return false;
		}

		if (!class_exists(Sign\FeatureResolver::class))
		{
			return false;
		}

		return Sign\FeatureResolver::instance()->released(self::SIGN_FEATURE_CODE);
	}

	private function rollbackQuietly(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable $rollbackFailure)
		{
			// Inside somebody else's transaction the rollback reaches the savepoint and only then
			// throws, so the data is already safe. An unguarded call would replace the real cause
			// with a complaint about the rollback.
		}
	}

	private function createLimitExceededError(): Error
	{
		return new Error(
			'Too many users for a single operation, the limit is ' . self::USER_IDS_LIMIT,
			self::ERROR_LIMIT_EXCEEDED,
		);
	}

	/**
	 * @param array<int> $userIds
	 *
	 * @return array<int> unique positive identifiers
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
}
