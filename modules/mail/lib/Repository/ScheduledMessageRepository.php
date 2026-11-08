<?php

declare(strict_types=1);

namespace Bitrix\Mail\Repository;

use Bitrix\Mail\Internals\ScheduledMessageAttachmentTable;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ScheduledMessageRepository
{
	public const ERROR_SAVE_FAILED = 'save_failed';
	public const ERROR_REVISION_CONFLICT = 'revision_conflict';
	public const ERROR_NOT_FOUND = 'not_found';
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_INVALID_MODE = 'invalid_mode';
	public const ERROR_CANCEL_WINDOW_EXPIRED = 'cancel_window_expired';
	public const ERROR_ALREADY_SENDING = 'already_sending';
	public const ERROR_CANCEL_NOT_AVAILABLE = 'cancel_not_available';

	/**
	 * The states the section is made of. A letter that has left or was called back belongs to neither the rows
	 * nor the number over them, so both paths name the same set from here.
	 */
	public const SECTION_STATUSES = [
		ScheduledMessageTable::STATUS_SCHEDULED,
		ScheduledMessageTable::STATUS_SENDING,
		ScheduledMessageTable::STATUS_ERROR,
	];

	/**
	 * Everything a row of the list is rendered from. `BODY` is a `LONGTEXT` and no list reads it.
	 *
	 * Both moments are here because the row is worded by the later of them, the very pair `getDue()` claims a
	 * letter by (`ScheduledMessageTimePresenter::dueAt()`).
	 *
	 * `UPDATED_AT` is here for the wording as well: a row in the sending state is a letter on its way only
	 * while the send behind it is running, and a send interrupted after the claim keeps that state until
	 * `recoverStuckSending` takes the row back. The moment of the last change is what tells the two apart.
	 */
	private const LIST_COLUMNS = [
		'ID',
		'MAILBOX_ID',
		'AUTHOR_ID',
		'STATUS',
		'REVISION',
		'SENDER',
		'RECIPIENTS',
		'SUBJECT',
		'SCHEDULED_AT',
		'NEXT_ATTEMPT_AT',
		'UPDATED_AT',
		'MODE',
		'AUTHOR_TIMEZONE_ID',
		'LAST_ERROR',
	];

	/** The dispatcher reads the letter itself in `claim()`; the batch only decides what to claim. */
	private const DUE_COLUMNS = ['ID', 'REVISION'];
	private const OPERATION_COLUMNS = ['ID', 'REVISION', 'STATUS', 'CANCEL_UNTIL', 'MAILBOX_ID', 'SUBJECT'];
	private const UNDO_PROJECTION_COLUMNS = [
		'ID',
		'MAILBOX_ID',
		'STATUS',
		'RECIPIENTS',
		'SUBJECT',
		'NEXT_ATTEMPT_AT',
		'DISPATCH_RECEIPT_AT',
		'CREATED_AT',
	];

	/** Recovery closes or requeues a stuck row without queueing the letter again, so the letter is never needed. */
	private const STUCK_COLUMNS = [
		'ID',
		'REVISION',
		'MAILBOX_ID',
		'AUTHOR_ID',
		'SUBJECT',
		'ATTEMPTS',
		'DISPATCH_RECEIPT_AT',
	];

	/**
	 * @param array<string, mixed> $fields
	 * @param array<int, array<string, mixed>> $attachments
	 */
	public function create(array $fields, array $attachments = [], bool $operationProjection = false): Result
	{
		$fields['LAST_ERROR'] ??= null;
		$connection = Application::getConnection();
		$transactionStarted = false;
		$connection->startTransaction();
		$transactionStarted = true;
		$result = new Result();

		try
		{
			$addResult = ScheduledMessageTable::add($fields);
			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;
				$existing = $this->getByFieldsIdempotency($fields, $operationProjection);
				if ($existing !== null)
				{
					return $result->setData([
						'scheduledMessage' => $existing,
						'created' => false,
					]);
				}

				return $result->addErrors($addResult->getErrors());
			}

			$id = (int)$addResult->getId();
			foreach ($attachments as $attachment)
			{
				$attachment['SCHEDULED_MESSAGE_ID'] = $id;
				$attachmentResult = ScheduledMessageAttachmentTable::add($attachment);
				if (!$attachmentResult->isSuccess())
				{
					$connection->rollbackTransaction();
					$transactionStarted = false;

					return $result->addErrors($attachmentResult->getErrors());
				}
			}

			$scheduledMessage = $operationProjection
				? $this->operationRow($id, $fields)
				: $this->get($id)
			;
			if ($scheduledMessage === null)
			{
				throw new \RuntimeException('The saved scheduled message could not be read.');
			}

			$connection->commitTransaction();
			$transactionStarted = false;
			$result->setData([
				'scheduledMessage' => $scheduledMessage,
				'created' => true,
			]);

			return $result;
		}
		catch (\Throwable)
		{
			if ($transactionStarted)
			{
				$connection->rollbackTransaction();
			}
			$existing = $this->getByFieldsIdempotency($fields, $operationProjection);
			if ($existing !== null)
			{
				return $result->setData([
					'scheduledMessage' => $existing,
					'created' => false,
				]);
			}

			return $result->addError(new Error('Could not save scheduled message.', self::ERROR_SAVE_FAILED));
		}
	}

	/** @return array<string, mixed>|null */
	public function getByIdempotencyKey(int $authorId, string $idempotencyKey): ?array
	{
		$row = ScheduledMessageTable::query()
			->setSelect(self::OPERATION_COLUMNS)
			->where('AUTHOR_ID', $authorId)
			->where('IDEMPOTENCY_KEY', $idempotencyKey)
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	/** @return array<string, mixed>|null */
	public function get(int $id): ?array
	{
		$row = ScheduledMessageTable::query()->setSelect(['*'])->where('ID', $id)->setLimit(1)->fetch();
		if (!$row)
		{
			return null;
		}

		$row['ATTACHMENTS'] = ScheduledMessageAttachmentTable::query()
			->setSelect(['ID', 'FILE_ID', 'SOURCE_OBJECT_ID', 'FILE_NAME', 'CONTENT_ID', 'IS_INLINE'])
			->where('SCHEDULED_MESSAGE_ID', $id)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return $row;
	}

	/**
	 * The snapshot is the whole letter, body and attachments included, and a batch of fifty carries fifty of
	 * them through the request. An operation that only moves the letter — and answers with the row of the
	 * section — asks for the row of the section instead.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>> messages indexed by identifier
	 */
	public function getByIds(array $ids, bool $withSnapshot = true): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if ($ids === [])
		{
			return [];
		}

		$rows = ScheduledMessageTable::query()
			->setSelect($withSnapshot ? ['*'] : self::LIST_COLUMNS)
			->whereIn('ID', $ids)
			->fetchAll()
		;
		$attachmentsByMessage = $withSnapshot ? $this->getAttachmentsByMessageIds($ids) : [];
		$messages = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if ($withSnapshot)
			{
				$row['ATTACHMENTS'] = $attachmentsByMessage[$id] ?? [];
			}
			$messages[$id] = $row;
		}

		return $messages;
	}

	/**
	 * @param int[] $mailboxIds
	 * @param string[] $statuses
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function getList(
		array $mailboxIds,
		array $statuses = [],
		int $offset = 0,
		int $limit = 50,
		bool $withAttachments = false,
	): array
	{
		if ($mailboxIds === [])
		{
			return ['items' => [], 'total' => 0];
		}

		$filter = $this->listFilter($mailboxIds, $statuses);

		$offset = max(0, $offset);
		$limit = max(1, min(100, $limit));
		$rows = ScheduledMessageTable::getList([
			'select' => self::LIST_COLUMNS,
			'filter' => $filter,
			'order' => ['SCHEDULED_AT' => 'ASC', 'ID' => 'ASC'],
			'offset' => $offset,
			'limit' => $limit,
		])->fetchAll();

		// A first page that did not fill up is the whole list, and counting it again would cost a second scan.
		$total = $offset === 0 && count($rows) < $limit
			? count($rows)
			: ScheduledMessageTable::getCount($filter);

		if ($withAttachments)
		{
			$attachmentsByMessage = $this->getAttachmentsByMessageIds(array_map(
				static fn (array $row): int => (int)$row['ID'],
				$rows,
			));
			foreach ($rows as &$row)
			{
				$row['ATTACHMENTS'] = $attachmentsByMessage[(int)$row['ID']] ?? [];
			}
			unset($row);
		}

		return ['items' => $rows, 'total' => $total];
	}

	/** @return array<int, array<string, mixed>> */
	public function getUndoProjections(int $userId, int $mailboxId, int $limit = 25): array
	{
		if ($userId <= 0 || $mailboxId <= 0)
		{
			return [];
		}

		$rows = ScheduledMessageTable::query()
			->setSelect(self::UNDO_PROJECTION_COLUMNS)
			->where('AUTHOR_ID', $userId)
			->where('MAILBOX_ID', $mailboxId)
			->where('MODE', ScheduledMessageTable::MODE_UNDO)
			->whereIn('STATUS', [
				ScheduledMessageTable::STATUS_SCHEDULED,
				ScheduledMessageTable::STATUS_SENDING,
			])
			->setOrder(['CREATED_AT' => 'DESC', 'ID' => 'DESC'])
			->setLimit(max(1, min(100, $limit)))
			->fetchAll()
		;

		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => !(
				(string)$row['STATUS'] === ScheduledMessageTable::STATUS_SENDING
				&& ($row['DISPATCH_RECEIPT_AT'] ?? null) instanceof DateTime
			),
		));
	}

	/**
	 * The number the section shows over its own rows, counted by the filter the page itself is collected by so
	 * that the two cannot disagree. The author of a letter takes no part in it: a shared mailbox lists the
	 * letters of everyone who writes from it.
	 */
	public function countSection(int $mailboxId): int
	{
		return ScheduledMessageTable::getCount($this->listFilter([$mailboxId], self::SECTION_STATUSES));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param string[] $allowedStatuses
	 * @param array<int, array<string, mixed>>|null $attachments
	 */
	public function updateByRevision(
		int $id,
		int $revision,
		array $fields,
		array $allowedStatuses,
		?array $attachments = null,
		bool $hydrate = true,
		bool $manageTransaction = true,
	): Result
	{
		$connection = Application::getConnection();
		if ($manageTransaction)
		{
			$connection->startTransaction();
		}
		$result = new Result();

		try
		{
			$fields['UPDATED_AT'] = new DateTime();
			$entity = ScheduledMessageTable::getEntity();
			$where = Query::buildFilterSql($entity, [
				'=ID' => $id,
				'=REVISION' => $revision,
				'@STATUS' => $allowedStatuses,
			]);
			$lockedRow = $connection->query(sprintf(
				'SELECT %s FROM %s WHERE %s FOR UPDATE',
				$connection->getSqlHelper()->quote('ID'),
				$connection->getSqlHelper()->quote($entity->getDBTableName()),
				$where,
			))->fetch();

			if (!$lockedRow)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Revision conflict.', self::ERROR_REVISION_CONFLICT));
			}

			$fields['REVISION'] = $revision + 1;
			$updateResult = ScheduledMessageTable::update($id, $fields);
			if (!$updateResult->isSuccess())
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addErrors($updateResult->getErrors());
			}

			if ($attachments !== null)
			{
				$attachmentEntity = ScheduledMessageAttachmentTable::getEntity();
				$connection->queryExecute(sprintf(
					'DELETE FROM %s WHERE %s',
					$connection->getSqlHelper()->quote($attachmentEntity->getDBTableName()),
					Query::buildFilterSql($attachmentEntity, ['=SCHEDULED_MESSAGE_ID' => $id]),
				));
				foreach ($attachments as $attachment)
				{
					$attachment['SCHEDULED_MESSAGE_ID'] = $id;
					$attachmentResult = ScheduledMessageAttachmentTable::add($attachment);
					if (!$attachmentResult->isSuccess())
					{
						$this->rollbackIfOwned($connection, $manageTransaction);

						return $result->addErrors($attachmentResult->getErrors());
					}
				}
			}

			if ($hydrate)
			{
				$scheduledMessage = $this->get($id);
				if ($scheduledMessage === null)
				{
					throw new \RuntimeException('The saved scheduled message could not be read.');
				}

				$result->setData(['scheduledMessage' => $scheduledMessage]);
			}
			$this->commitIfOwned($connection, $manageTransaction);

			return $result;
		}
		catch (\Throwable)
		{
			$this->rollbackIfOwned($connection, $manageTransaction);

			return $result->addError(new Error('Could not save scheduled message.', self::ERROR_SAVE_FAILED));
		}
	}

	/** @param string[] $allowedStatuses */
	public function claim(int $id, int $revision, array $allowedStatuses): Result
	{
		return $this->updateByRevision(
			$id,
			$revision,
			['STATUS' => ScheduledMessageTable::STATUS_SENDING],
			$allowedStatuses,
		);
	}

	public function cancelUndo(
		int $userId,
		int $id,
		int $revision,
		DateTime $now,
		bool $manageTransaction = true,
	): Result
	{
		$connection = Application::getConnection();
		if ($manageTransaction)
		{
			$connection->startTransaction();
		}
		$result = new Result();

		try
		{
			$entity = ScheduledMessageTable::getEntity();
			$where = Query::buildFilterSql($entity, ['=ID' => $id]);
			$lockedRow = $connection->query(sprintf(
				'SELECT %s FROM %s WHERE %s FOR UPDATE',
				$connection->getSqlHelper()->quote('ID'),
				$connection->getSqlHelper()->quote($entity->getDBTableName()),
				$where,
			))->fetch();
			if (!$lockedRow)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Scheduled message is not found.', self::ERROR_NOT_FOUND));
			}

			$row = ScheduledMessageTable::query()
				->setSelect([
					...self::OPERATION_COLUMNS,
					'AUTHOR_ID',
					'MODE',
				])
				->where('ID', $id)
				->setLimit(1)
				->fetch()
			;
			if ((int)$row['AUTHOR_ID'] !== $userId)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Access denied.', self::ERROR_ACCESS_DENIED));
			}

			if ((string)$row['MODE'] !== ScheduledMessageTable::MODE_UNDO)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Operation is not cancellable.', self::ERROR_INVALID_MODE));
			}

			if ((string)$row['STATUS'] === ScheduledMessageTable::STATUS_CANCELLED)
			{
				$this->commitIfOwned($connection, $manageTransaction);
				$result->setData(['scheduledMessage' => $this->operationRowFromStored($row)]);

				return $result;
			}

			if ((int)$row['REVISION'] !== $revision)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Revision conflict.', self::ERROR_REVISION_CONFLICT));
			}

			if ((string)$row['STATUS'] === ScheduledMessageTable::STATUS_SENDING)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Message is already sending.', self::ERROR_ALREADY_SENDING));
			}

			$cancelUntil = $row['CANCEL_UNTIL'] ?? null;
			if (
				(string)$row['STATUS'] !== ScheduledMessageTable::STATUS_SCHEDULED
				|| !$cancelUntil instanceof DateTime
			)
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Cancellation is not available.', self::ERROR_CANCEL_NOT_AVAILABLE));
			}

			if ($cancelUntil->getTimestamp() < $now->getTimestamp())
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addError(new Error('Cancellation window has expired.', self::ERROR_CANCEL_WINDOW_EXPIRED));
			}

			$updateResult = ScheduledMessageTable::update($id, [
				'STATUS' => ScheduledMessageTable::STATUS_CANCELLED,
				'NEXT_ATTEMPT_AT' => null,
				'REVISION' => $revision + 1,
				'UPDATED_AT' => $now,
			]);
			if (!$updateResult->isSuccess())
			{
				$this->rollbackIfOwned($connection, $manageTransaction);

				return $result->addErrors($updateResult->getErrors());
			}

			$this->commitIfOwned($connection, $manageTransaction);
			$row['STATUS'] = ScheduledMessageTable::STATUS_CANCELLED;
			$row['REVISION'] = $revision + 1;
			$result->setData(['scheduledMessage' => $this->operationRowFromStored($row)]);

			return $result;
		}
		catch (\Throwable)
		{
			$this->rollbackIfOwned($connection, $manageTransaction);

			return $result->addError(new Error('Could not cancel scheduled message.', self::ERROR_SAVE_FAILED));
		}
	}

	/**
	 * The letters the queue is ready to send, oldest attempt first. The whole portion comes back at once
	 * because the caller reads the state of all of it before it claims the first row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getDue(DateTime $now, int $limit): array
	{
		return ScheduledMessageTable::query()
			->setSelect(self::DUE_COLUMNS)
			->where('STATUS', ScheduledMessageTable::STATUS_SCHEDULED)
			->where('NEXT_ATTEMPT_AT', '<=', $now)
			->where('SCHEDULED_AT', '<=', $now)
			->setOrder(['NEXT_ATTEMPT_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(max(1, $limit))
			->fetchAll()
		;
	}

	/** @return iterable<array<string, mixed>> */
	public function getStuckSending(DateTime $updatedBefore, int $limit): iterable
	{
		$rows = ScheduledMessageTable::query()
			->setSelect(self::STUCK_COLUMNS)
			->where('STATUS', ScheduledMessageTable::STATUS_SENDING)
			->where('UPDATED_AT', '<=', $updatedBefore)
			->setOrder(['UPDATED_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(max(1, $limit))
			->exec()
		;

		while ($row = $rows->fetch())
		{
			yield $row;
		}
	}

	/**
	 * The receipt of the row as the table holds it now. A pass reads the receipt off the row it claimed, and
	 * that row carries the receipt of the runs before it; this answers where the receipt of the running attempt
	 * itself is what is being asked about — right after that attempt broke off.
	 */
	public function readDispatchReceipt(int $id): ?DateTime
	{
		$row = ScheduledMessageTable::query()
			->setSelect(['DISPATCH_RECEIPT_AT'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;

		return $row ? ($row['DISPATCH_RECEIPT_AT'] ?? null) : null;
	}

	public function recordDispatchReceipt(int $id): Result
	{
		return ScheduledMessageTable::update($id, [
			'DISPATCH_RECEIPT_AT' => new DateTime(),
		]);
	}

	public function markSent(int $id, int $revision): Result
	{
		return $this->updateByRevision(
			$id,
			$revision,
			[
				'STATUS' => ScheduledMessageTable::STATUS_SENT,
				'NEXT_ATTEMPT_AT' => null,
				'LAST_ERROR' => null,
			],
			[ScheduledMessageTable::STATUS_SENDING],
			hydrate: false,
		);
	}

	public function returnToQueue(int $id, int $revision, DateTime $nextAttemptAt): Result
	{
		return $this->updateByRevision(
			$id,
			$revision,
			[
				'STATUS' => ScheduledMessageTable::STATUS_SCHEDULED,
				'NEXT_ATTEMPT_AT' => $nextAttemptAt,
				'LAST_ERROR' => null,
				'DISPATCH_RECEIPT_AT' => null,
			],
			[ScheduledMessageTable::STATUS_SENDING],
			hydrate: false,
		);
	}

	/** @param array{code: string, message: string, retryable: bool} $error */
	public function markForRetry(
		int $id,
		int $revision,
		int $attempts,
		DateTime $nextAttemptAt,
		array $error,
	): Result
	{
		return $this->updateByRevision(
			$id,
			$revision,
			[
				'STATUS' => ScheduledMessageTable::STATUS_SCHEDULED,
				'ATTEMPTS' => $attempts,
				'NEXT_ATTEMPT_AT' => $nextAttemptAt,
				'LAST_ERROR' => $error,
			],
			[ScheduledMessageTable::STATUS_SENDING],
			hydrate: false,
		);
	}

	/** @param array{code: string, message: string, retryable: bool} $error */
	public function markError(
		int $id,
		int $revision,
		int $attempts,
		array $error,
		bool $manageTransaction = true,
	): Result
	{
		return $this->updateByRevision(
			$id,
			$revision,
			[
				'STATUS' => ScheduledMessageTable::STATUS_ERROR,
				'ATTEMPTS' => $attempts,
				'NEXT_ATTEMPT_AT' => null,
				'LAST_ERROR' => $error,
			],
			[ScheduledMessageTable::STATUS_SENDING],
			hydrate: false,
			manageTransaction: $manageTransaction,
		);
	}

	/**
	 * @param int[] $mailboxIds
	 * @param string[] $statuses
	 * @return array<string, mixed>
	 */
	private function listFilter(array $mailboxIds, array $statuses): array
	{
		$filter = [
			'@MAILBOX_ID' => $mailboxIds,
			'=MODE' => ScheduledMessageTable::MODE_SCHEDULED,
		];
		if ($statuses !== [])
		{
			$filter['@STATUS'] = $statuses;
		}

		return $filter;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function getAttachmentsByMessageIds(array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$grouped = [];
		$rows = ScheduledMessageAttachmentTable::query()
			->setSelect([
				'ID',
				'SCHEDULED_MESSAGE_ID',
				'FILE_ID',
				'SOURCE_OBJECT_ID',
				'FILE_NAME',
				'CONTENT_ID',
				'IS_INLINE',
			])
			->whereIn('SCHEDULED_MESSAGE_ID', $messageIds)
			->setOrder(['SCHEDULED_MESSAGE_ID' => 'ASC', 'ID' => 'ASC'])
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$grouped[(int)$row['SCHEDULED_MESSAGE_ID']][] = $row;
		}

		return $grouped;
	}

	private function commitIfOwned(\Bitrix\Main\DB\Connection $connection, bool $manageTransaction): void
	{
		if ($manageTransaction)
		{
			$connection->commitTransaction();
		}
	}

	private function rollbackIfOwned(\Bitrix\Main\DB\Connection $connection, bool $manageTransaction): void
	{
		if ($manageTransaction)
		{
			$connection->rollbackTransaction();
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>|null
	 */
	private function getByFieldsIdempotency(array $fields, bool $operationProjection): ?array
	{
		$authorId = (int)($fields['AUTHOR_ID'] ?? 0);
		$idempotencyKey = (string)($fields['IDEMPOTENCY_KEY'] ?? '');
		if ($authorId <= 0 || $idempotencyKey === '')
		{
			return null;
		}

		$row = $this->getByIdempotencyKey($authorId, $idempotencyKey);
		if ($row === null || $operationProjection)
		{
			return $row;
		}

		return $this->get((int)$row['ID']);
	}

	/** @param array<string, mixed> $fields */
	private function operationRow(int $id, array $fields): array
	{
		return [
			'ID' => $id,
			'REVISION' => (int)$fields['REVISION'],
			'STATUS' => (string)$fields['STATUS'],
			'CANCEL_UNTIL' => $fields['CANCEL_UNTIL'] ?? null,
			'MAILBOX_ID' => (int)($fields['MAILBOX_ID'] ?? 0),
			'SUBJECT' => (string)($fields['SUBJECT'] ?? ''),
		];
	}

	/** @param array<string, mixed> $row */
	private function operationRowFromStored(array $row): array
	{
		return array_intersect_key($row, array_fill_keys(self::OPERATION_COLUMNS, true));
	}
}
