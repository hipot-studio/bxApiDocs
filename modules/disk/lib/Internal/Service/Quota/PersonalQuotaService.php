<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Enum\RestrictionsAvailabilityState;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Disk\Storage;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers whether a size may still be written into the personal storage of an employee.
 *
 * The checks go from the cheapest to the most expensive one: the write context, the availability of
 * the restriction, the assigned quota, and last of all the used size - which is aggregated over the
 * source of truth only when it has never been calculated.
 *
 * The service judges the write and stores nothing: the counter is moved by the lifecycle of the
 * version rows, not by the question asked here.
 */
class PersonalQuotaService
{
	/**
	 * The refusal reaches the user as an error of the file being written, so the code belongs to
	 * the model of that file and is named here by it. One value, one owner: the transports read
	 * the very same code out of the file the write was refused for.
	 */
	public const ERROR_PERSONAL_QUOTA_EXCEEDED = File::ERROR_PERSONAL_QUOTA_EXCEEDED;

	/** Used size, bytes, the refusal was measured against. */
	public const REFUSAL_DATA_USED = 'used';

	/** Assigned quota, bytes, the refusal was measured against. */
	public const REFUSAL_DATA_LIMIT = 'limit';

	/**
	 * @param RestrictionsAvailabilityProvider $availabilityProvider
	 * @param StorageUsageCounter $usageCounter
	 */
	public function __construct(
		protected readonly RestrictionsAvailabilityProvider $availabilityProvider,
		protected readonly StorageUsageCounter $usageCounter,
	)
	{
	}

	/**
	 * Tells whether a file of this size may be written into the personal storage of this employee.
	 *
	 * @param int $userId Owner of the personal storage being written.
	 * @param int $fileSize Size being written, bytes.
	 * @param WriteOperationContext $context Context of the write operation.
	 * @return Result
	 */
	public function canWrite(int $userId, int $fileSize, WriteOperationContext $context): Result
	{
		$result = new Result();

		if ($context === WriteOperationContext::EditingSession)
		{
			return $result;
		}

		if ($this->availabilityProvider->getState(RestrictionPolicy::Quota) !== RestrictionsAvailabilityState::Applied)
		{
			return $result;
		}

		$quota = $this->usageCounter->getUsage($userId);
		$quotaSize = $quota?->getQuotaSize();
		if ($quotaSize === null)
		{
			return $result;
		}

		$usedSize = $quota->getUsedSize() ?? $this->usageCounter->computeNow($userId);
		if ($usedSize === null)
		{
			// The quota was taken away while the write was being judged: there is nothing left to
			// measure the write against.
			return $result;
		}

		if ($usedSize + $fileSize <= $quotaSize)
		{
			return $result;
		}

		return $result->addError($this->createRefusal($usedSize, $quotaSize));
	}

	/**
	 * The same verdict for a write addressed to a storage. The quota belongs to the owner of the
	 * storage being written rather than to the author of the write: an attachment to the task of
	 * another employee spends the personal disk of the one whose storage it lands in.
	 *
	 * @param Storage|null $storage Storage being written.
	 * @param int $fileSize Size being written, bytes.
	 * @param WriteOperationContext $context Context of the write operation.
	 * @return Result
	 */
	public function canWriteToStorage(?Storage $storage, int $fileSize, WriteOperationContext $context): Result
	{
		$ownerId = $this->findPersonalStorageOwnerId($storage);

		return $ownerId === null ? new Result() : $this->canWrite($ownerId, $fileSize, $context);
	}

	/**
	 * Owner of the personal storage, or null for everything the quota says nothing about: a group
	 * storage, the common one, the storage of an application.
	 */
	protected function findPersonalStorageOwnerId(?Storage $storage): ?int
	{
		if ($storage === null || !($storage->getProxyType() instanceof ProxyType\User))
		{
			return null;
		}

		$ownerId = (int)$storage->getEntityId();

		return $ownerId > 0 ? $ownerId : null;
	}

	/**
	 * The refusal carries the sizes it was measured by: the caller tells the user how much of the
	 * quota is spent, and the text of that is the business of the model the write was refused for.
	 */
	protected function createRefusal(int $usedSize, int $quotaSize): Error
	{
		return new Error(
			'The personal storage quota of the employee is exceeded.',
			self::ERROR_PERSONAL_QUOTA_EXCEEDED,
			[
				self::REFUSAL_DATA_USED => $usedSize,
				self::REFUSAL_DATA_LIMIT => $quotaSize,
			],
		);
	}
}
