<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Access;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplatePermissionCleanupRepositoryInterface;
use Throwable;

class TemplatePermissionCleanupService
{
	public function __construct(
		private readonly TemplatePermissionCleanupRepositoryInterface $repository,
	)
	{
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function deleteByAccessCodes(array $accessCodes): void
	{
		$subjectCodes = $this->filterSubjectCodes($accessCodes);
		if (empty($subjectCodes))
		{
			return;
		}

		$this->deleteInNestedTransaction(fn() => $this->repository->deleteByAccessCodes($subjectCodes));
	}

	public function deleteGroupPermissions(int $groupId): void
	{
		if ($groupId <= 0)
		{
			return;
		}

		$this->deleteInNestedTransaction(fn() => $this->repository->deleteByGroupId($groupId));
	}

	/**
	 * The cleanup runs inside the transaction of whoever removed the subject, so its failure must not
	 * cost the caller its own deletion: on PostgreSQL an SQL error aborts the whole transaction, and
	 * catching the exception does not lift that state. A nested transaction is emitted as a savepoint,
	 * and rolling back to it drops the failed cleanup alone, leaving the caller free to commit.
	 */
	private function deleteInNestedTransaction(callable $delete): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$delete();
			$connection->commitTransaction();
		}
		catch (Throwable $throwable)
		{
			$this->rollbackSafely($connection);

			throw $throwable;
		}
	}

	private function rollbackSafely(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// MySQL rolls back to the savepoint and reports the nested rollback as an exception
		}
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function filterSubjectCodes(array $accessCodes): array
	{
		$subjectCodes = [];

		foreach ($accessCodes as $accessCode)
		{
			if ((new AccessCode($accessCode))->getEntityId() > 0)
			{
				$subjectCodes[] = $accessCode;
			}
		}

		return $subjectCodes;
	}
}
