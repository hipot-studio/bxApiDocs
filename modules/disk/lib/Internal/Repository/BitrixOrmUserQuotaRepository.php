<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Entity\UserQuota;
use Bitrix\Disk\Internal\Enum\UsageStatus;
use Bitrix\Disk\Internal\Model\EO_UserQuota;
use Bitrix\Disk\Internal\Model\UserQuotaTable;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaAssignmentRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRecalculationRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRepositoryInterface;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use LogicException;

class BitrixOrmUserQuotaRepository implements
	UserQuotaRepositoryInterface,
	UserQuotaRecalculationRepositoryInterface,
	UserQuotaAssignmentRepositoryInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function findByUserId(int $userId): ?UserQuota
	{
		/** @var EO_UserQuota|null $ormModel */
		$ormModel =
			UserQuotaTable::query()
				->setSelect(['*'])
				->where('USER_ID', $userId)
				->setLimit(1)
				->fetchObject()
		;

		if ($ormModel === null)
		{
			return null;
		}

		return $this->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 */
	public function applyDelta(int $userId, int $deltaBytes): void
	{
		if ($deltaBytes === 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(UserQuotaTable::getTableName());
		$usedSize = $helper->quote('USED_SIZE');
		$status = $helper->quote('USED_SIZE_STATUS');
		$recalcRequested = $helper->quote('RECALC_REQUESTED');
		$userIdColumn = $helper->quote('USER_ID');

		// GREATEST() over null is not portable - MySQL answers null, PostgreSQL skips the null and
		// answers zero - so a size that was never calculated is filtered out before it gets there.
		$sql = (new SqlExpression(
			"UPDATE {$table} SET "
			. "{$usedSize} = CASE WHEN {$status} = ?s OR {$usedSize} IS NULL "
				. "THEN {$usedSize} ELSE GREATEST({$usedSize} + (?i), 0) END, "
			. "{$recalcRequested} = CASE WHEN {$status} = ?s THEN 1 ELSE {$recalcRequested} END "
			. "WHERE {$userIdColumn} = ?i",
			UsageStatus::Calculating->value,
			$deltaBytes,
			UsageStatus::Calculating->value,
			$userId,
		))->compile();

		$connection->queryExecute($sql);
	}

	/**
	 * {@inheritDoc}
	 */
	public function markForRecalculation(int $userId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(UserQuotaTable::getTableName());
		$status = $helper->quote('USED_SIZE_STATUS');
		$statusTime = $helper->quote('STATUS_TIME');
		$recalcRequested = $helper->quote('RECALC_REQUESTED');
		$userIdColumn = $helper->quote('USER_ID');
		$now = $helper->getCurrentDateTimeFunction();

		// The clause that changes the status goes last on purpose: MySQL evaluates a SET list left to
		// right, so every clause above it has to be read against the status the row came in with.
		$sql = (new SqlExpression(
			"UPDATE {$table} SET "
			. "{$recalcRequested} = CASE WHEN {$status} = ?s THEN 1 ELSE {$recalcRequested} END, "
			. "{$statusTime} = CASE WHEN {$status} = ?s THEN {$now} ELSE {$statusTime} END, "
			. "{$status} = CASE WHEN {$status} = ?s THEN ?s ELSE {$status} END "
			. "WHERE {$userIdColumn} = ?i",
			UsageStatus::Calculating->value,
			UsageStatus::Actual->value,
			UsageStatus::Actual->value,
			UsageStatus::Stale->value,
			$userId,
		))->compile();

		$connection->queryExecute($sql);
	}

	/**
	 * {@inheritDoc}
	 */
	public function findCandidates(int $limit, int $reclaimTtl, int $verifyTtl): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$helper = Application::getConnection()->getSqlHelper();
		// The boundary is built by the database out of its own now(), the clock every status time in
		// the table was stamped by: an age answered by the clock of php would drift away from it.
		$reclaimBoundary = $helper->addSecondsToDateTime(-$reclaimTtl);
		$verifyBoundary = $helper->addSecondsToDateTime(-$verifyTtl);

		// Every branch pins the status and ranges over the time next to it, so the index over the
		// pair serves all of them.
		$pending =
			Query::filter()
				->logic('or')
				->where('USED_SIZE_STATUS', UsageStatus::Stale->value)
				->where(
					Query::filter()
						->where('USED_SIZE_STATUS', UsageStatus::Calculating->value)
						->whereExpr("%s < {$reclaimBoundary}", ['STATUS_TIME'])
				)
		;

		// The queue is read in two goes rather than in one. A portal hands out its quotas in a batch,
		// so the whole population comes of age for the verification at the same moment, and a single
		// read would give the portion away to it while the invalidations of that same moment waited
		// for the tail of it to pass. The order of the rows is decided here rather than by a sort:
		// a sort over the union of the branches would cost the index path they are read by.
		$candidates = $this->fetchCandidates($pending, $limit);

		$restOfTheLimit = $limit - count($candidates);
		if ($restOfTheLimit <= 0)
		{
			return $candidates;
		}

		$verification =
			Query::filter()
				->where('USED_SIZE_STATUS', UsageStatus::Actual->value)
				->whereExpr("%s < {$verifyBoundary}", ['STATUS_TIME'])
		;

		return array_merge($candidates, $this->fetchCandidates($verification, $restOfTheLimit));
	}

	/**
	 * @return UserQuota[]
	 */
	private function fetchCandidates(ConditionTree $filter, int $limit): array
	{
		$candidates = [];

		$ormModels =
			UserQuotaTable::query()
				->setSelect(['*'])
				->where($filter)
				->setLimit($limit)
				->fetchCollection()
		;

		foreach ($ormModels as $ormModel)
		{
			$candidates[] = $this->convertFromOrm($ormModel);
		}

		return $candidates;
	}

	/**
	 * {@inheritDoc}
	 */
	public function capture(UserQuota $candidate): ?UserQuota
	{
		$id = $candidate->getId();
		$status = $candidate->getUsedSizeStatus();
		$statusTime = $candidate->getStatusTime();

		if ($id === null || $status === null || $statusTime === null)
		{
			return null;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(UserQuotaTable::getTableName());
		$statusColumn = $helper->quote('USED_SIZE_STATUS');
		$statusTimeColumn = $helper->quote('STATUS_TIME');
		$idColumn = $helper->quote('ID');
		$now = $helper->getCurrentDateTimeFunction();
		$readWith = $this->buildStatusTimeGuard($helper, $statusTimeColumn, $statusTime);

		$sql = (new SqlExpression(
			"UPDATE {$table} SET "
			. "{$statusColumn} = ?s, {$statusTimeColumn} = {$now} "
			. "WHERE {$idColumn} = ?i AND {$statusColumn} = ?s AND {$readWith}",
			UsageStatus::Calculating->value,
			$id,
			$status->value,
		))->compile();

		$connection->queryExecute($sql);

		// A run that touched no row is looking at a row somebody else has already moved on.
		if ($connection->getAffectedRowsCount() !== 1)
		{
			return null;
		}

		// The stamp the claim is told by belongs to the clock of the database, so it is read back
		// from the row instead of being assembled here.
		return $this->findById($id);
	}

	/**
	 * {@inheritDoc}
	 */
	public function finishRecalculation(UserQuota $claim, int $usedSize): bool
	{
		$id = $claim->getId();
		$claimedAt = $claim->getStatusTime();

		if ($id === null || $claimedAt === null)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(UserQuotaTable::getTableName());
		$usedSizeColumn = $helper->quote('USED_SIZE');
		$usedSizeTimeColumn = $helper->quote('USED_SIZE_TIME');
		$statusColumn = $helper->quote('USED_SIZE_STATUS');
		$statusTimeColumn = $helper->quote('STATUS_TIME');
		$recalcRequested = $helper->quote('RECALC_REQUESTED');
		$idColumn = $helper->quote('ID');
		$now = $helper->getCurrentDateTimeFunction();
		$claimedWith = $this->buildStatusTimeGuard($helper, $statusTimeColumn, $claimedAt);

		// The clause that clears the mark goes last on purpose: MySQL evaluates a SET list left to
		// right, so the status above it has to be read against the mark the row came in with.
		$sql = (new SqlExpression(
			"UPDATE {$table} SET "
			. "{$usedSizeColumn} = ?i, {$usedSizeTimeColumn} = {$now}, {$statusTimeColumn} = {$now}, "
			. "{$statusColumn} = CASE WHEN {$recalcRequested} = 1 THEN ?s ELSE ?s END, "
			. "{$recalcRequested} = 0 "
			. "WHERE {$idColumn} = ?i AND {$statusColumn} = ?s AND {$claimedWith}",
			$usedSize,
			UsageStatus::Stale->value,
			UsageStatus::Actual->value,
			$id,
			UsageStatus::Calculating->value,
		))->compile();

		$connection->queryExecute($sql);

		// The stamp of another claim in place of this one is a row that was taken over meanwhile.
		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * A stamp comes back from the row through a format of whole seconds, so the fraction PostgreSQL
	 * keeps in the column is not part of what the caller holds, and an equality against it would
	 * never hold there. The guard matches the second the row was read with instead: on MySQL, whose
	 * column keeps no fraction at all, that is the very set of rows the equality answered with.
	 *
	 * Protected so that the predicate can be rendered against the helper of either database.
	 */
	protected function buildStatusTimeGuard(SqlHelper $helper, string $statusTimeColumn, DateTime $stamp): string
	{
		$secondItWasReadWith = $helper->convertToDbDateTime($stamp);
		$secondAfterThat = $helper->addSecondsToDateTime(1, $secondItWasReadWith);

		return "{$statusTimeColumn} >= {$secondItWasReadWith} AND {$statusTimeColumn} < {$secondAfterThat}";
	}

	private function findById(int $id): ?UserQuota
	{
		/** @var EO_UserQuota|null $ormModel */
		$ormModel =
			UserQuotaTable::query()
				->setSelect(['*'])
				->where('ID', $id)
				->setLimit(1)
				->fetchObject()
		;

		if ($ormModel === null)
		{
			return null;
		}

		return $this->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 */
	public function assign(int $userId, int $quotaSize): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		// The conflict is resolved over USER_ID and not over the primary key: the row of an employee
		// is the one their unique index points at, and which id it was born with is of no interest.
		$statements = $helper->prepareMerge(
			UserQuotaTable::getTableName(),
			['USER_ID'],
			[
				'USER_ID' => $userId,
				'QUOTA_SIZE' => $quotaSize,
				'USED_SIZE' => null,
				'USED_SIZE_STATUS' => UsageStatus::Stale->value,
				'RECALC_REQUESTED' => 0,
				'STATUS_TIME' => new SqlExpression($helper->getCurrentDateTimeFunction()),
			],
			[
				'QUOTA_SIZE' => $quotaSize,
			],
		);

		$connection->queryExecute(current($statements));
	}

	/**
	 * {@inheritDoc}
	 */
	public function withdraw(int $userId): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(UserQuotaTable::getTableName());
		$userIdColumn = $helper->quote('USER_ID');

		$sql = (new SqlExpression(
			"DELETE FROM {$table} WHERE {$userIdColumn} = ?i",
			$userId,
		))->compile();

		$connection->queryExecute($sql);

		return $connection->getAffectedRowsCount() === 1;
	}

	private function convertFromOrm(EO_UserQuota $ormModel): UserQuota
	{
		$storedStatus = (string)$ormModel->getUsedSizeStatus();
		$status = UsageStatus::tryFrom($storedStatus);

		if ($status === null)
		{
			throw new LogicException("Invalid value \"{$storedStatus}\" for used size status");
		}

		return
			(new UserQuota())
				->setId($ormModel->getId())
				->setUserId($ormModel->getUserId())
				->setQuotaSize($ormModel->getQuotaSize())
				->setUsedSize($ormModel->getUsedSize())
				->setUsedSizeStatus($status)
				->setUsedSizeTime($ormModel->getUsedSizeTime())
				->setRecalcRequested($ormModel->getRecalcRequested())
				->setStatusTime($ormModel->getStatusTime())
		;
	}
}
