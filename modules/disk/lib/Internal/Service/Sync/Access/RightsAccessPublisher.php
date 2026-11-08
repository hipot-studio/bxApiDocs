<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\Internal\Repository\Sync\SyncRightsSnapshotRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\AudienceResolver;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An edit of the rights of an object, told to the journal.
 *
 * The moment is what this class exists for. The rights of a subtree are materialized by a background
 * recalculation, so a record published when the rows of b_disk_right were written would send the client
 * to compare a subtree the portal has not finished recalculating, and the client would settle on the
 * picture it already held. The record therefore goes out after the recalculation has finished.
 *
 * The codes it is addressed to cannot come from that finished state, though. A user whose access was
 * revoked holds no code of the state after the edit, and a record published to the current codes alone
 * would be idle on exactly the revocation the signal exists for. So the codes of the root are read once
 * before the edit and held for the session that recalculates them; the writer unites them with the ones
 * the object carries now.
 *
 * The captured half is kept in a table rather than in the closure of the background job, because the
 * recalculation is allowed to fail. A session that expires is restarted by an agent of the module hours
 * later and through a path of its own, which publishes nothing; what it leaves behind is a finished
 * session whose record is still due, and the reconciliation of P7 writes it from the snapshot this class
 * stored.
 *
 * Nothing here is allowed to break the edit it reports. A record that was not written costs the client
 * one reconciliation; an exception raised here would cost the user the change of rights itself.
 */
final class RightsAccessPublisher
{
	private const LOGGER_CHANNEL = 'sync';

	private SyncRightsSnapshotRepository $snapshots;
	private AudienceResolver $audience;
	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?SyncRightsSnapshotRepository $snapshots = null,
		?AudienceResolver $audience = null,
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->snapshots = $snapshots ?? new SyncRightsSnapshotRepository();
		$this->audience = $audience ?? new AudienceResolver();
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'rights access publisher');
	}

	/**
	 * The codes of the root as the rights hold them now.
	 *
	 * Read where the writer reads them, and read before the rows of the object are torn down: the codes
	 * of an object are those of the nearest ancestor carrying a readable right of its own, and deleting
	 * the rights of the object moves that ancestor.
	 *
	 * @return string[]
	 */
	public function captureCodes(int $objectId): array
	{
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return [];
		}

		try
		{
			return $this->audience->rightsCodesOf($objectId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the codes of the root were not read before the edit of rights: {message}',
				['message' => $exception->getMessage(), 'objectId' => $objectId, 'exception' => $exception],
			);

			return [];
		}
	}

	/**
	 * Hands the captured codes over to the recalculation that has just been registered, so that they
	 * outlive the process that captured them.
	 *
	 * @param string[] $codes Codes of the root as {@see captureCodes} read them before the edit.
	 */
	public function rememberCapture(int $setupSessionId, int $objectId, array $codes): void
	{
		if ($setupSessionId <= 0 || $objectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			$result = $this->snapshots->createIfAbsent($setupSessionId, $objectId, $codes);
			if (!$result->isSuccess())
			{
				$this->logger->error(
					'the codes before the edit of rights were not stored: {errors}',
					[
						'errors' => implode('; ', $result->getErrorMessages()),
						'setupSessionId' => $setupSessionId,
						'objectId' => $objectId,
					],
				);
			}
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the codes before the edit of rights were not stored: {message}',
				[
					'message' => $exception->getMessage(),
					'setupSessionId' => $setupSessionId,
					'objectId' => $objectId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * The recalculation of the subtree is over and the boundary it moved is published.
	 *
	 * The scope is the subtree and not the object: an edit of the rights of a node is inherited by
	 * everything under it, which is what the recalculation walks. It is the meaning of the operation
	 * that says so and never the count of the objects it touched - a folder whose subtree is empty
	 * today opens the same subtree tomorrow, and a record that named only the node would leave the
	 * client comparing one row of it.
	 *
	 * The record carries no card. It reports where to look again rather than what an object now holds,
	 * and a card of a root the addressee can no longer see is one nobody could assemble.
	 *
	 * The snapshot is spent by a record that was written and by no other: it is the only source of the
	 * half of the audience that lost access, and a snapshot taken away by a publication that failed
	 * would leave the reconciliation of P7 addressing the current codes alone. One nobody ever spends
	 * is taken by the cleaning of the journal by age.
	 *
	 * A recalculation that moved no boundary publishes nothing at all. Every move of an object within a
	 * storage runs one, and the codes of an object that carries no rights of its own are those of the
	 * place it lies in - so a move between two places open to the same people would otherwise hand every
	 * one of them a whole subtree to walk again, on top of the record of the move that already reached
	 * them. What decides it is the two sets of codes and never the operation: the same edit of rights
	 * that changes nothing is as silent, and for the same reason.
	 *
	 * The set the object carries now is read here once and handed on to the writer, which unites the
	 * two halves and would otherwise read it a second time. Every move of an object within a storage
	 * comes through here, and a reading of the codes is a walk up the closure of the tree.
	 *
	 * The snapshot of such a recalculation is deliberately left where it is. The reconciliation of P7
	 * reaches the session once, finds no record over its root, and comes back through this method: with
	 * the snapshot still there it reads the same two sets and keeps the same silence, while a snapshot
	 * taken away would leave it publishing to the current codes - the very record refused here, hours
	 * later. The cleaning by age is what takes it in the end.
	 *
	 * @return bool whether a record was written
	 */
	public function publishRecalculated(int $setupSessionId, int $objectId): bool
	{
		if ($setupSessionId <= 0 || $objectId <= 0 || !$this->feature->isEnabled())
		{
			return false;
		}

		try
		{
			$snapshot = $this->snapshots->findBySetupSession($setupSessionId);
			$currentCodes = $this->audience->rightsCodesOf($objectId);

			if ($snapshot === null)
			{
				// The record still goes out: it reaches everyone who kept access, which is less than
				// the whole audience and more than nothing at all.
				$this->logger->warning(
					'the codes before the edit of rights were not found, the record goes to the current ones',
					['setupSessionId' => $setupSessionId, 'objectId' => $objectId],
				);
			}
			elseif (self::sameCodes($snapshot['CODES'], $currentCodes))
			{
				$this->logger->debug(
					'the recalculation of rights moved no boundary of access, nothing is published',
					['setupSessionId' => $setupSessionId, 'objectId' => $objectId],
				);

				return false;
			}

			$published = $this->journal->publishAccessChanged(
				ChangeRecord::SCOPE_SUBTREE,
				$objectId,
				true,
				$snapshot['CODES'] ?? [],
				currentCodes: $currentCodes,
			);

			if ($snapshot === null)
			{
				return $published;
			}

			if ($published)
			{
				$this->snapshots->deleteBySetupSession($setupSessionId);
			}
			else
			{
				$this->logger->warning(
					'the journal refused the record of an edit of rights, the codes before it are kept',
					['setupSessionId' => $setupSessionId, 'objectId' => $objectId],
				);
			}

			return $published;
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by an edit of rights was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'setupSessionId' => $setupSessionId,
					'objectId' => $objectId,
					'exception' => $exception,
				],
			);

			return false;
		}
	}

	/**
	 * @param string[] $before
	 * @param string[] $after
	 */
	private static function sameCodes(array $before, array $after): bool
	{
		$before = array_unique(array_map('strval', $before));
		$after = array_unique(array_map('strval', $after));
		sort($before, SORT_STRING);
		sort($after, SORT_STRING);

		return $before === $after;
	}
}
