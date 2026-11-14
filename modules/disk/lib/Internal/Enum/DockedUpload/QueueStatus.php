<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\DockedUpload;

/**
 * State of a whole docked upload queue (one user upload session).
 *
 * The wire-value (::value) is persisted to b_disk_docked_upload_queue.STATUS
 * and travels in DTOs. The two stop states live only here (a per-item concern
 * cannot stop the whole queue by quota), and they are two because the way OUT of
 * them differs: StoppedByQuota is the cloud tariff limit, so it carries the
 * commercial path, while RequiresAction is every other space shortage - a storage
 * quota anywhere, or the portal-wide limit on a box - where there is no tariff to
 * upgrade ({@see \Bitrix\Disk\Internal\Service\DockedUpload\QuotaScenario} owns
 * the split).
 *
 * RequiresAction is ALSO a display token composed over pending/active queues. The
 * composed one is never persisted: only the quota stop writes this case down, and
 * only the stored one gates work and blocks a retry.
 */
enum QueueStatus: string
{
	case Pending = 'pending';
	case Active = 'active';
	case StoppedByQuota = 'stopped_by_quota';
	case RequiresAction = 'requires_action';
	case Completed = 'completed';
	case Dismissed = 'dismissed';

	/**
	 * Whether a queue in this status is transferring right now: Active alone, no
	 * other case.
	 *
	 * NOT a gate any production path reads - the only caller is the enum unit test
	 * {@see \Bitrix\Disk\Tests\Unit\Lib\Internal\Enum\DockedUpload\DockedUploadEnumTest}.
	 * The two questions the callers actually ask are separate predicates, and both
	 * admit pending on top of active, so neither is this set: whether bytes already
	 * in temp may still be fixed is {@see self::acceptsCommit()}, and whether a retry
	 * may re-queue anything is
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver::acceptsRetry()}.
	 *
	 * Kept while the errata ADR decides what "taking work" is to mean against the
	 * acceptsCommit() the call sites settled on. Until it does, gate nothing new here.
	 */
	public function acceptsWork(): bool
	{
		return $this === self::Active;
	}

	/**
	 * Whether bytes that ALREADY reached temp may still be fixed on the Disk - the
	 * gate of the commit trigger in updateItem / updateItems
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier}).
	 *
	 * Wider than {@see self::acceptsWork()} by exactly pending: the queue not having
	 * started is a CLIENT-side reason (the parameter set is not collected yet), never
	 * a reason to drop a temp file the user already paid the traffic for - a queue that
	 * missed its transition to active would otherwise lose the right to fix it.
	 * Narrower than "not terminal" by the two stop states: a queue stopped for space
	 * waits for an explicit restore (API-07), and a dismissed one is gone for good.
	 */
	public function acceptsCommit(): bool
	{
		return match ($this)
		{
			self::Pending, self::Active => true,
			default => false,
		};
	}

	/**
	 * Wire values of {@see self::acceptsCommit()} for a STATUS IN (...) guard - what the
	 * quota stop writes its state through
	 * ({@see \Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository::markStoppedByQuota()}).
	 *
	 * Derived from the predicate itself rather than listed again, so the SQL guard and the
	 * in-memory gate cannot drift apart.
	 *
	 * @return string[]
	 */
	public static function commitAcceptingValues(): array
	{
		return array_values(array_map(
			static fn(self $status): string => $status->value,
			array_filter(self::cases(), static fn(self $status): bool => $status->acceptsCommit()),
		));
	}

	/**
	 * Whether restore may bring a queue in this status back (API-02, AC-077).
	 *
	 * This is the status half of the restore predicate, and the ONLY place the
	 * status set is written down: the SQL filter reads it through
	 * {@see self::restorableValues()} and the in-memory verdict
	 * {@see \Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue::isRestorable()}
	 * reads it here, so the two cannot drift apart.
	 */
	public function isRestorable(): bool
	{
		return in_array($this, self::restorableCases(), true);
	}

	/**
	 * Wire values of {@see self::isRestorable()} for a STATUS IN (...) filter.
	 *
	 * @return string[]
	 */
	public static function restorableValues(): array
	{
		return array_map(static fn(self $status): string => $status->value, self::restorableCases());
	}

	/**
	 * The queues that are not over: everything but the two closed states. Restore
	 * offers exactly these back (AC-077), and they are the same queues a lifecycle
	 * write may still touch - completing a drained one, budgeting its items against
	 * the user ceiling.
	 *
	 * @return self[]
	 */
	public static function restorableCases(): array
	{
		return [self::Pending, self::Active, self::StoppedByQuota, self::RequiresAction];
	}
}
