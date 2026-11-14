<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Quota;

use Bitrix\Disk\Internal\Repository\Interface\UserQuotaAssignmentRepositoryInterface;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Quota\StorageUsageCounter;
use Throwable;

readonly class SetUserQuotaCommandHandler
{
	private const BYTES_IN_GIGABYTE = 1024 ** 3;

	/** The journal a failure of the count is written into, the one the command writes into as well. */
	protected const LOG_FEATURE = 'disk personal quota assignment';

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
	 * Stores the limit and counts what the employee has taken up, in that order.
	 *
	 * The count comes second because it is kept only for those a quota is assigned to: an employee
	 * with no row yet is nobody the counter writes about, and counting first would leave the fresh
	 * limit with no size beside it.
	 *
	 * The count is synchronous rather than queued on purpose: a quota is assigned to the very
	 * employee whose disk is already full, and until the recalculation run came around such a limit
	 * would hold nothing back.
	 *
	 * A count that broke is not a limit that was not stored, so it stays inside: the row keeps the
	 * limit it was just given and waits with no size of its own, which the interface shows as a
	 * recalculation under way. The recalculation queue finishes it - a count that broke after it
	 * claimed the row is reclaimed there like any run that died - or the first write of that
	 * employee does. The reason goes to the journal of the module.
	 *
	 * The row is written past the counter, so what it keeps about the employee is dropped in between:
	 * a request that has already asked about them holds the answer of the moment they had no limit,
	 * and a count made against that answer writes nothing at all.
	 *
	 * @param SetUserQuotaCommand $command
	 * @return void
	 */
	public function __invoke(SetUserQuotaCommand $command): void
	{
		$this->quotaAssignmentRepository->assign(
			userId: $command->userId,
			quotaSize: $command->quotaGb * self::BYTES_IN_GIGABYTE,
		);

		$this->usageCounter->forget($command->userId);

		try
		{
			$this->usageCounter->computeNow($command->userId);
		}
		catch (Throwable $exception)
		{
			$this->logFailure($command, $exception);
		}
	}

	/**
	 * The reason of a count that broke belongs to the journal of the portal and not to the answer:
	 * the limit is stored, the client has nothing to act on, and a failed statement names the table
	 * and the columns it was read from.
	 */
	protected function logFailure(SetUserQuotaCommand $command, Throwable $exception): void
	{
		LoggerFactory::create(feature: self::LOG_FEATURE)->error(
			'Used size of user {userId} was not counted after the personal quota was stored: {exception}',
			[
				'userId' => $command->userId,
				'quotaGb' => $command->quotaGb,
				'exception' => $exception,
			],
		);
	}
}
