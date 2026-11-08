<?php

declare(strict_types=1);

namespace Bitrix\Mail\Agent;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Integration\Draft\ScheduledMessageDraftGateway;
use Bitrix\Mail\Internals\DraftAttachmentTable;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Mail\Internals\ScheduledMessageAttachmentTable;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\Repository\ScheduledMessageRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ScheduledMessageCleanupAgent
{
	private const DEFAULT_RETENTION_DAYS = 30;
	private const UNDO_RETENTION_DAYS = 1;
	private const BATCH_SIZE = 10;
	private const MAX_ITERATIONS = 100;
	private const MAX_RUNTIME_SECONDS = 10.0;
	// The share of the run the return to the drafts may take. The deletion of the retention keeps the rest, so a
	// window of letters no draft accepts cannot leave the run with nothing removed.
	private const DRAFT_RETURN_RUNTIME_SECONDS = 5.0;
	private const DRAFT_RETURN_PAUSE_SECONDS = 3600;

	protected const DRAFT_RETURN_PAUSE_KEY = 'mail.scheduled_message_cleanup.draft_return_paused';

	// Plain properties with a default, not promoted ones: a promoted property is set by the constructor
	// alone, so a subclass declaring its own constructor without calling this one would leave it unset.
	private ?ScheduledMessageRepository $repository = null;
	private ?ScheduledMessageDraftGateway $draftGateway = null;
	private ?StorageInterface $storage = null;

	public function __construct(
		?ScheduledMessageRepository $repository = null,
		?ScheduledMessageDraftGateway $draftGateway = null,
		?StorageInterface $storage = null,
	)
	{
		$this->repository = $repository;
		$this->draftGateway = $draftGateway;
		$this->storage = $storage;
	}

	public static function execute(): string
	{
		if (Loader::includeModule('mail') && Feature::isScheduledMessageAvailable())
		{
			(new self())->cleanup();
		}

		return self::class . '::execute();';
	}

	public function cleanup(?DateTime $now = null): int
	{
		$startedAt = microtime(true);
		$deadline = $startedAt + self::MAX_RUNTIME_SECONDS;
		$now ??= new DateTime();
		$scheduledExpiredBefore = DateTime::createFromTimestamp(
			$now->getTimestamp() - self::DEFAULT_RETENTION_DAYS * 86400,
		);
		$undoExpiredBefore = DateTime::createFromTimestamp(
			$now->getTimestamp() - self::UNDO_RETENTION_DAYS * 86400,
		);
		$this->returnUnsentToDrafts($scheduledExpiredBefore, $startedAt + self::DRAFT_RETURN_RUNTIME_SECONDS);
		$deleted = 0;
		for ($iteration = 0; $iteration < self::MAX_ITERATIONS && $this->hasTimeRemaining($deadline); $iteration++)
		{
			$rows = $this->getExpiredRows($scheduledExpiredBefore, $undoExpiredBefore);
			if ($rows === [])
			{
				break;
			}

			$ids = [];
			foreach ($rows as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				if ($id > 0)
				{
					$ids[] = $id;
				}
			}
			if ($ids === [])
			{
				break;
			}

			if (!$this->hasTimeRemaining($deadline))
			{
				return $deleted;
			}

			$fileIds = $this->deleteDatabaseRows($ids);
			if ($fileIds === null)
			{
				return $deleted;
			}

			$deleted += count($ids);
			$this->deleteFiles($fileIds);
		}

		return $deleted;
	}

	/**
	 * A letter that never left goes back to the drafts with its body and its attachments, and the row the
	 * conversion leaves in `cancelled` is taken by the deletion that follows. Deleting such a row without a draft
	 * would lose the letter, so a conversion that failed — drafts turned off, draft not saved — leaves the
	 * row where it is.
	 *
	 * Each status has its own walk ordered by the same `(SCHEDULED_AT, ID)` pair as its index. A letter no draft
	 * accepts keeps its place in the table, but the cursor still moves past it and reaches the tail in this run.
	 *
	 * The step is given a share of the run and the deletion keeps the rest, because a conversion waiting for a
	 * disk that is not there is slow: a window full of such letters would otherwise leave the run with nothing
	 * removed.
	 */
	private function returnUnsentToDrafts(DateTime $expiredBefore, float $deadline): void
	{
		if ($this->isDraftReturnPaused())
		{
			return;
		}

		$attempted = 0;
		$returned = 0;
		$stoppedByTheClock = false;
		$iteration = 0;
		foreach ([ScheduledMessageTable::STATUS_SCHEDULED, ScheduledMessageTable::STATUS_ERROR] as $status)
		{
			$cursor = null;
			while ($iteration < self::MAX_ITERATIONS)
			{
				$rows = $this->getUnsentExpiredRows($expiredBefore, $status, $cursor);
				if ($rows === [])
				{
					break;
				}
				$iteration++;
				$messages = $this->getRepository()->getByIds(array_map(
					static fn (array $row): int => (int)$row['ID'],
					$rows,
				));

				foreach ($rows as $row)
				{
					if (!$this->hasTimeRemaining($deadline))
					{
						$stoppedByTheClock = true;

						break 3;
					}

					$id = (int)$row['ID'];
					$cursor = [
						'scheduledAt' => $row['SCHEDULED_AT'],
						'id' => $id,
					];
					$message = $messages[$id] ?? null;
					if ($message === null || !$this->isDueBefore($message, $expiredBefore))
					{
						continue;
					}

					$attempted++;
					try
					{
						if ($this->convertToDraft($message)->isSuccess())
						{
							$returned++;
						}
					}
					catch (\Throwable $exception)
					{
						$this->logFailure($exception, 'draft_convert', $id);
					}
				}

				if (count($rows) < self::BATCH_SIZE)
				{
					break;
				}
			}
		}

		// The clock says nothing about the letters the run has not reached, and a healthy tail behind ten slow
		// failures must not be silenced for an hour. Every other way out of the walk — the window came to its end,
		// or the run offered the drafts every letter one run may offer — is an answer about the drafts themselves.
		if (!$stoppedByTheClock && $attempted > 0 && $returned === 0)
		{
			$this->pauseDraftReturn($attempted);
		}
	}

	/**
	 * The age of a letter is counted from the later of the two moments, the very rule the dispatcher applies
	 * before it gives the letter up as too late (`ScheduledMessageDispatcher::isTooLate()`): a manual retry moves
	 * `NEXT_ATTEMPT_AT` to now and leaves the moment of the plan where it was. Counting from `SCHEDULED_AT` alone
	 * would take a letter the author has just sent again by hand into the drafts while the dispatcher still holds
	 * it for sending.
	 *
	 * The window asks the database about `SCHEDULED_AT` alone so that it keeps to
	 * `IX_MAIL_SCHEDULED_MESSAGE_EXPIRED`;
	 * the later of the moments is read here, from the row the conversion is about to take.
	 *
	 * @param array<string, mixed> $message
	 */
	private function isDueBefore(array $message, DateTime $moment): bool
	{
		$dueAt = 0;
		foreach ([$message['SCHEDULED_AT'] ?? null, $message['NEXT_ATTEMPT_AT'] ?? null] as $candidate)
		{
			if ($candidate instanceof DateTime)
			{
				$dueAt = max($dueAt, $candidate->getTimestamp());
			}
		}

		return $dueAt > 0 && $dueAt <= $moment->getTimestamp();
	}

	/**
	 * A lost attachment and a disk that is not there do not heal in five minutes, so a run that offered the drafts
	 * every letter it was allowed to offer and got not one back leaves the step alone for a while: every attempt
	 * costs a read of the body of the letter and a transaction.
	 */
	protected function isDraftReturnPaused(): bool
	{
		try
		{
			return $this->getStorage()->has(self::DRAFT_RETURN_PAUSE_KEY);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function pauseDraftReturn(int $attempted): void
	{
		$this->log(
			'Scheduled message cleanup returned none of {attempted} letters to the drafts and pauses the step.',
			['attempted' => $attempted],
		);

		try
		{
			$this->getStorage()->set(self::DRAFT_RETURN_PAUSE_KEY, true, self::DRAFT_RETURN_PAUSE_SECONDS);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception, 'draft_return_pause', 0);
		}
	}

	/** @param array<string, mixed> $message */
	protected function convertToDraft(array $message): Result
	{
		return $this->getDraftGateway()->convert($message, (int)$message['REVISION']);
	}

	private function getRepository(): ScheduledMessageRepository
	{
		return $this->repository ??= new ScheduledMessageRepository();
	}

	private function getDraftGateway(): ScheduledMessageDraftGateway
	{
		return $this->draftGateway ??= new ScheduledMessageDraftGateway($this->getRepository());
	}

	private function getStorage(): StorageInterface
	{
		return $this->storage ??= ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * @param int[] $ids
	 * @return int[]|null
	 */
	protected function deleteDatabaseRows(array $ids): ?array
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$attachments = ScheduledMessageAttachmentTable::query()
				->setSelect(['FILE_ID'])
				->whereIn('SCHEDULED_MESSAGE_ID', $ids)
				->fetchAll()
			;
			$this->deleteAttachmentRows($ids);
			$this->deleteMessageRows($ids);

			$connection->commitTransaction();

			return array_map(static fn (array $attachment): int => (int)$attachment['FILE_ID'], $attachments);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->logFailure($exception, 'database_delete', 0);

			return null;
		}
	}

	/** @param int[] $messageIds */
	protected function deleteAttachmentRows(array $messageIds): void
	{
		ScheduledMessageAttachmentTable::deleteByFilter(['@SCHEDULED_MESSAGE_ID' => $messageIds]);
	}

	/** @param int[] $ids */
	protected function deleteMessageRows(array $ids): void
	{
		ScheduledMessageTable::deleteByFilter(['@ID' => $ids]);
	}

	/**
	 * The files of the batch leave together so that one question to the drafts covers all of them: asking
	 * about every file apart would multiply the queries of the run by the size of the batch.
	 *
	 * @param int[] $fileIds
	 */
	private function deleteFiles(array $fileIds): void
	{
		if ($fileIds === [])
		{
			return;
		}

		try
		{
			$heldByDrafts = $this->getFileIdsHeldByLiveDrafts($fileIds);
		}
		catch (\Throwable $exception)
		{
			// Nothing goes while it is unknown what the drafts hold: the two mistakes do not cost the same.
			$this->logFailure($exception, 'draft_file_lookup', 0);

			return;
		}

		foreach ($fileIds as $fileId)
		{
			if (isset($heldByDrafts[$fileId]))
			{
				continue;
			}

			try
			{
				$this->deleteFile($fileId);
			}
			catch (\Throwable $exception)
			{
				// A residual copy is safe: it is no longer referenced and cannot be sent.
				$this->logFailure($exception, 'file_delete', $fileId);
			}
		}
	}

	/**
	 * Files of the batch a live draft holds as well. A letter returned to the drafts hands the draft the very
	 * file of its own attachment, so deleting that file would empty the attachment of a letter the user is
	 * still writing. Today the cancellation removes the attachment rows of the letter and no such file reaches
	 * the batch: the check stands for the day the cancellation starts keeping them.
	 *
	 * @param int[] $fileIds
	 * @return array<int, true> identifiers of the files to keep, as keys
	 */
	protected function getFileIdsHeldByLiveDrafts(array $fileIds): array
	{
		$rows = DraftAttachmentTable::query()
			->setSelect(['FILE_ID'])
			->registerRuntimeField(
				new Reference(
					'DRAFT',
					DraftTable::class,
					Join::on('this.DRAFT_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->whereIn('FILE_ID', $fileIds)
			->where('DRAFT.STATUS', DraftTable::STATUS_ACTIVE)
			->where('DRAFT.DATE_EXPIRE', '>', new DateTime())
			->fetchAll()
		;

		return array_fill_keys(
			array_map(static fn (array $row): int => (int)$row['FILE_ID'], $rows),
			true,
		);
	}

	protected function deleteFile(int $id): void
	{
		\CFile::Delete($id);
	}

	private function logFailure(\Throwable $exception, string $stage, int $id): void
	{
		$this->log(
			'Scheduled message cleanup failed at {stage} for #{id}: {exceptionClass}',
			[
				'stage' => $stage,
				'id' => $id,
				'exceptionClass' => $exception::class,
			],
		);
	}

	/** @param array<string, mixed> $context */
	private function log(string $message, array $context): void
	{
		try
		{
			(new \Bitrix\Main\Diag\LoggerFactory())->createById(
				'mail.scheduled_message',
				[],
				false,
			)?->error($message, $context);
		}
		catch (\Throwable)
		{
		}
	}

	protected function hasTimeRemaining(float $deadline): bool
	{
		return microtime(true) < $deadline;
	}

	/**
	 * The retention of a terminal row runs from the moment the terminal status was written: a cancelled letter
	 * with a far future planned moment must not wait for that future, and a letter cancelled today must not be
	 * removed because its plan is old.
	 *
	 * `IX_MAIL_SCHEDULED_MESSAGE_MODE_UPDATED` follows the exact mode and terminal statuses before the date
	 * range, so each retention policy reads its own short range instead of walking rows of the other mode.
	 *
	 * @return array<int, array{ID: int|string}>
	 */
	protected function getExpiredRows(DateTime $scheduledBefore, DateTime $undoBefore): array
	{
		$rows = [];
		foreach ([
			[ScheduledMessageTable::MODE_SCHEDULED, $scheduledBefore],
			[ScheduledMessageTable::MODE_UNDO, $undoBefore],
		] as [$mode, $updatedBefore])
		{
			$rows = array_merge(
				$rows,
				ScheduledMessageTable::query()
					->setSelect(['ID', 'UPDATED_AT'])
					->whereIn('STATUS', [
						ScheduledMessageTable::STATUS_SENT,
						ScheduledMessageTable::STATUS_CANCELLED,
					])
					->where('UPDATED_AT', '<=', $updatedBefore)
					->where('MODE', $mode)
					->setOrder(['UPDATED_AT' => 'ASC', 'ID' => 'ASC'])
					->setLimit(self::BATCH_SIZE)
					->fetchAll(),
			);
		}

		usort($rows, static fn (array $left, array $right): int => [
			$left['UPDATED_AT']->getTimestamp(),
			(int)$left['ID'],
		] <=> [
			$right['UPDATED_AT']->getTimestamp(),
			(int)$right['ID'],
		]);

		return array_slice($rows, 0, self::BATCH_SIZE);
	}

	/**
	 * Letters the retention window found unsent, taken past `$cursor`. A row being queued right now is not
	 * among them: the dispatcher owns it, and the recovery of an interrupted attempt brings it back into one
	 * of these two statuses on its own.
	 *
	 * `IX_MAIL_SCHEDULED_MESSAGE_EXPIRED` starts with the exact status and continues with the same date and
	 * identifier used by the cursor and the order, so every next batch starts where the previous one stopped.
	 *
	 * @param null|array{scheduledAt: DateTime, id: int} $cursor
	 * @return array<int, array{ID: int|string, SCHEDULED_AT: DateTime}>
	 */
	protected function getUnsentExpiredRows(DateTime $expiredBefore, string $status, ?array $cursor): array
	{
		$query = ScheduledMessageTable::query()
			->setSelect(['ID', 'SCHEDULED_AT'])
			->where('STATUS', $status)
			->where('SCHEDULED_AT', '<=', $expiredBefore)
			->setOrder(['SCHEDULED_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(self::BATCH_SIZE)
		;
		if ($cursor !== null)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->where('SCHEDULED_AT', '>', $cursor['scheduledAt'])
					->where(
						Query::filter()
							->where('SCHEDULED_AT', $cursor['scheduledAt'])
							->where('ID', '>', $cursor['id']),
					),
			);
		}

		return $query->fetchAll();
	}
}
