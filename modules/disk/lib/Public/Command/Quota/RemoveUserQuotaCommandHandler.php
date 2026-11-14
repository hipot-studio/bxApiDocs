<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Quota;

use Bitrix\Disk\Internal\Repository\Interface\UserQuotaAssignmentRepositoryInterface;
use Bitrix\Disk\Internal\Service\Quota\StorageUsageCounter;

readonly class RemoveUserQuotaCommandHandler
{
	/**
	 * @param UserQuotaAssignmentRepositoryInterface $quotaAssignmentRepository
	 * @param StorageUsageCounter $usageCounter
	 */
	public function __construct(
		protected UserQuotaAssignmentRepositoryInterface $quotaAssignmentRepository,
		protected StorageUsageCounter $usageCounter,
	)
	{
	}

	/**
	 * Takes the limit away and drops what the counter keeps about the employee.
	 *
	 * The row is removed past the counter, so a request that has already asked about them would go
	 * on measuring writes against a limit that is no longer there.
	 *
	 * @param RemoveUserQuotaCommand $command
	 * @return bool Whether there was a quota to take away.
	 */
	public function __invoke(RemoveUserQuotaCommand $command): bool
	{
		$removed = $this->quotaAssignmentRepository->withdraw($command->userId);

		$this->usageCounter->forget($command->userId);

		return $removed;
	}
}
