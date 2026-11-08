<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Flipchart\Comment\Request\SetThreadFlagRequest;
use Bitrix\Disk\File;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

/**
 * Storage of the comments API: the operations of the contract that create, read and change threads
 * and the comments inside them.
 *
 * Every operation works inside the board of the token: a thread of another board is "no such
 * thread", and a request that names a different document or a different author than the token
 * carries is refused. The board itself, the rights on it and the guest cap are already settled by
 * {@see ContextResolver}, so the context received here is trusted.
 *
 * A thread or a comment is written under the lock of its board ({@see BoardLock}), where the board is
 * read again: the context was resolved while the board existed, and its final deletion may have
 * completed since. A comment then gets its number under a named lock of its thread: the number is
 * the next after the greatest one the thread has ever had, so deleted comments keep their numbers
 * taken. Once it is committed, the users it mentions are told by {@see MentionNotifier}; that never
 * changes the outcome.
 *
 * Deletion is a state, not a removal: a deleted thread or comment keeps its row and is left out of
 * every ordinary read, while the transitions that delete and restore address the row in either state
 * and bring it to the one asked for, so a repeated call finds nothing left to do and answers as the
 * first one did.
 */
final class CommentService
{
	public const STATUS_NO_CONTENT = 204;

	private const ERROR_THREAD_NOT_FOUND = 'thread_not_found';
	private const ERROR_COMMENT_NOT_FOUND = 'comment_not_found';
	private const ERROR_INVALID_ARGUMENT = 'invalid_argument';
	private const ERROR_FORBIDDEN = 'forbidden';
	private const ERROR_INTERNAL = 'internal_error';

	private const LOCK_PREFIX = 'disk.flipchart.comment.';
	private const LOCK_TIMEOUT = 15;
	private const COMMENT_ID_MAX = 2147483647;
	private const QUERY_CHUNK_SIZE = 500;

	private const GUEST_NAME_MESSAGE = 'DISK_FLIPCHART_COMMENT_GUEST_AUTHOR_NAME';
	private const UNKNOWN_NAME_MESSAGE = 'DISK_FLIPCHART_COMMENT_UNKNOWN_AUTHOR_NAME';
	private const GUEST_NAME_FALLBACK = 'Guest';
	private const UNKNOWN_NAME_FALLBACK = 'Unknown user';

	private const REGISTERED_USER_ID_PATTERN = '/^[1-9][0-9]{0,18}$/D';

	private const THREAD_FIELDS = [
		'ID',
		'DOCUMENT_ID',
		'THREAD_ID',
		'AUTHOR_ID',
		'COORD_X',
		'COORD_Y',
		'PARENT_ID',
		'WIDTH',
		'HEIGHT',
		'CREATED_AT',
		'RESOLVED_AT',
		'RESOLVED_BY',
		'DELETED_AT',
	];

	private const COMMENT_FIELDS = [
		'ID',
		'THREAD_ID',
		'COMMENT_ID',
		'AUTHOR_ID',
		'CONTENT',
		'ATTACHED',
		'CREATED_AT',
		'EDITED_AT',
		'DELETED_AT',
	];

	private ?GuestReadStore $guestStore = null;

	/**
	 * Creates a thread of the board of the token and returns its metadata.
	 *
	 * @param array{
	 *     documentId: string,
	 *     authorId: string,
	 *     coordinates: array{x: float, y: float, parentId: ?string, width: ?float, height: ?float}
	 * } $input
	 */
	public function addThread(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to create a thread.');
		}

		if (!$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]))
		{
			return self::forbidden('Field documentId does not match the token.');
		}

		if (!$context->matchesUser($input[RequestValidator::FIELD_AUTHOR_ID]))
		{
			return self::forbidden('Field authorId does not match the token.');
		}

		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$threadId = UuidGenerator::generateV4();
			$coordinates = $input[RequestValidator::FIELD_COORDINATES];

			$addResult = CommentThreadTable::add([
				'OBJECT_ID' => $objectId,
				'DOCUMENT_ID' => $context->getDocumentId(),
				'THREAD_ID' => $threadId,
				'AUTHOR_ID' => $context->getUserId(),
				'COORD_X' => self::coordinateSql($coordinates[RequestValidator::FIELD_X]),
				'COORD_Y' => self::coordinateSql($coordinates[RequestValidator::FIELD_Y]),
				'PARENT_ID' => $coordinates[RequestValidator::FIELD_PARENT_ID],
				'WIDTH' => self::nullableCoordinateSql($coordinates[RequestValidator::FIELD_WIDTH]),
				'HEIGHT' => self::nullableCoordinateSql($coordinates[RequestValidator::FIELD_HEIGHT]),
				'CREATED_AT' => new DateTime(),
			]);
			if (!$addResult->isSuccess())
			{
				throw new SystemException('Could not create a comment thread.');
			}

			$row = $this->findThread($objectId, $threadId);
			if ($row === null)
			{
				throw new SystemException('Created comment thread could not be read.');
			}

			return self::succeed($this->formatThread($row), 201);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}
	}

	/**
	 * A board is written to only while its file is there and not in the trash; this is read under the
	 * lock of the board, so it holds for as long as the lock does.
	 */
	private static function isBoardAvailable(int $objectId): bool
	{
		$file = File::loadById($objectId);

		return $file instanceof File && !$file->isDeleted();
	}

	private function releaseBoard(int $objectId): void
	{
		try
		{
			BoardLock::release($objectId);
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * Returns the metadata of a thread of the board of the token.
	 *
	 * @param array{threadId: string} $input
	 */
	public function getThreadMeta(AccessContext $context, array $input): Result
	{
		try
		{
			$row = $this->findThread(
				$context->getObjectId(),
				$input[RequestValidator::FIELD_THREAD_ID],
			);
			if ($row === null)
			{
				return self::threadNotFound();
			}

			return self::succeed($this->formatThread($row));
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Replaces the coordinates of a live thread of the board of the token as a whole and returns its
	 * metadata: an optional coordinate the body left out is stored as `null`, not kept.
	 *
	 * @param array{
	 *     threadId: string,
	 *     coordinates: array{x: float, y: float, parentId: ?string, width: ?float, height: ?float}
	 * } $input
	 */
	public function updateThreadCoordinates(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to move a thread.');
		}

		$coordinates = $input[RequestValidator::FIELD_COORDINATES];

		return $this->transitThread(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			false,
			fn(array $thread) => $this->updateThreadRow($thread, [
				'COORD_X' => self::coordinateSql($coordinates[RequestValidator::FIELD_X]),
				'COORD_Y' => self::coordinateSql($coordinates[RequestValidator::FIELD_Y]),
				'PARENT_ID' => $coordinates[RequestValidator::FIELD_PARENT_ID],
				'WIDTH' => self::nullableCoordinateSql($coordinates[RequestValidator::FIELD_WIDTH]),
				'HEIGHT' => self::nullableCoordinateSql($coordinates[RequestValidator::FIELD_HEIGHT]),
			]),
		);
	}

	/**
	 * Marks a thread of the board of the token deleted and returns its metadata. A thread already
	 * deleted keeps the time it was deleted at.
	 *
	 * @param array{threadId: string, userId: string} $input
	 */
	public function deleteThread(AccessContext $context, array $input): Result
	{
		$refusal = self::refuseUnlessActor($context, $input[RequestValidator::FIELD_USER_ID], 'delete a thread');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitThread(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			true,
			function (array $thread): void
			{
				if ($thread['DELETED_AT'] === null)
				{
					$this->updateThreadRow($thread, ['DELETED_AT' => new DateTime()]);
				}
			},
		);
	}

	/**
	 * Takes the deletion mark off a thread of the board of the token and returns its metadata.
	 *
	 * @param array{threadId: string, userId: string} $input
	 */
	public function restoreThread(AccessContext $context, array $input): Result
	{
		$refusal = self::refuseUnlessActor($context, $input[RequestValidator::FIELD_USER_ID], 'restore a thread');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitThread(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			true,
			function (array $thread): void
			{
				if ($thread['DELETED_AT'] !== null)
				{
					$this->updateThreadRow($thread, ['DELETED_AT' => null]);
				}
			},
		);
	}

	/**
	 * Resolves a live thread of the board of the token by the initiator and returns its metadata. A
	 * thread already resolved keeps who resolved it and when.
	 *
	 * @param array{threadId: string, userId: string} $input
	 */
	public function resolveThread(AccessContext $context, array $input): Result
	{
		$refusal = self::refuseUnlessActor($context, $input[RequestValidator::FIELD_USER_ID], 'resolve a thread');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitThread(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			false,
			function (array $thread) use ($context): void
			{
				if ($thread['RESOLVED_AT'] === null)
				{
					$this->updateThreadRow($thread, [
						'RESOLVED_AT' => new DateTime(),
						'RESOLVED_BY' => $context->getUserId(),
					]);
				}
			},
		);
	}

	/**
	 * Takes the resolution off a live thread of the board of the token and returns its metadata.
	 *
	 * @param array{threadId: string, userId: string} $input
	 */
	public function unresolveThread(AccessContext $context, array $input): Result
	{
		$refusal = self::refuseUnlessActor($context, $input[RequestValidator::FIELD_USER_ID], 'unresolve a thread');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitThread(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			false,
			function (array $thread): void
			{
				if ($thread['RESOLVED_AT'] !== null || $thread['RESOLVED_BY'] !== null)
				{
					$this->updateThreadRow($thread, [
						'RESOLVED_AT' => null,
						'RESOLVED_BY' => null,
					]);
				}
			},
		);
	}

	/**
	 * The initiator of a state transition has to be the bearer of the token and able to write.
	 */
	private static function refuseUnlessActor(AccessContext $context, string $userId, string $operation): ?Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden("Board access is not sufficient to {$operation}.");
		}

		if (!$context->matchesUser($userId))
		{
			return self::forbidden('Field userId does not match the token.');
		}

		return null;
	}

	/**
	 * A transition of one thread under the lock of its board, answered with the metadata read back.
	 * Deletion and restoration address the thread in whichever state it is in; the other transitions
	 * see only a live one.
	 *
	 * @param callable(array<string, mixed>): void $transition Receives the row of the thread as found.
	 */
	private function transitThread(
		AccessContext $context,
		string $threadId,
		bool $includeDeleted,
		callable $transition,
	): Result
	{
		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$thread = $this->findThread($objectId, $threadId, $includeDeleted);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			$transition($thread);

			$row = $this->findThread($objectId, $threadId, true);
			if ($row === null)
			{
				throw new SystemException('Changed comment thread could not be read.');
			}

			return self::succeed($this->formatThread($row));
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}
	}

	/**
	 * @param array<string, mixed> $thread
	 * @param array<string, mixed> $fields
	 */
	private function updateThreadRow(array $thread, array $fields): void
	{
		$result = CommentThreadTable::update((int)$thread['ID'], $fields);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not update a comment thread.');
		}
	}

	/**
	 * Returns the full threads of the board of the token that have at least one live comment.
	 *
	 * The board is read in three passes: its threads, their live comments, the mentions of those
	 * comments; the authors of everything are then read once. Pinned and important threads are
	 * states the storage does not keep, so a filter for them alone is an empty list, and `unread`
	 * degrades to the order of the last activity.
	 *
	 * @param array{
	 *     documentId: string,
	 *     userId: string,
	 *     threadId: ?string,
	 *     showResolved: bool,
	 *     showOnlyOwned: bool,
	 *     showWhereUserMentioned: bool,
	 *     showOnlyImportant: bool,
	 *     showOnlyPinned: bool,
	 *     sorting: string
	 * } $input
	 */
	public function getThreadsByFilter(AccessContext $context, array $input): Result
	{
		if (!$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]))
		{
			return self::forbidden('Field documentId does not match the token.');
		}

		if (!$context->matchesUser($input[RequestValidator::FIELD_USER_ID]))
		{
			return self::forbidden('Field userId does not match the token.');
		}

		try
		{
			$userId = $context->getUserId();
			$sorting = $input[RequestValidator::FIELD_SORTING];
			$stateFilter = null;
			if (
				$input[RequestValidator::FIELD_THREAD_ID] === null
				&& ($input[RequestValidator::FIELD_SHOW_ONLY_IMPORTANT] || $input[RequestValidator::FIELD_SHOW_ONLY_PINNED])
			)
			{
				if ($context->isGuest())
				{
					return self::succeed([]);
				}

				// Convert the state UUID, keeping the indexed binary thread column unchanged.
				$binaryThreadId = Application::getConnection() instanceof PgsqlConnection
					? "convert_to(%s::text, 'UTF8')"
					: 'CAST(%s AS BINARY)'
				;
				$stateFilter = ThreadStateTable::query()
					->setSelect([new ExpressionField('BINARY_THREAD_ID', $binaryThreadId, ['THREAD_ID'])])
					->where('USER_ID', (int)$userId)
				;
				if ($input[RequestValidator::FIELD_SHOW_ONLY_IMPORTANT])
				{
					$stateFilter->where('IMPORTANT', true);
				}
				if ($input[RequestValidator::FIELD_SHOW_ONLY_PINNED])
				{
					$stateFilter->where('PINNED', true);
				}
			}

			$mentionFilter = null;
			if ($input[RequestValidator::FIELD_SHOW_WHERE_USER_MENTIONED])
			{
				// Only a live comment's mention counts, so the thread filter reads the same rows loadLiveComments() would.
				// COMMENT_ROW_ID is scoped to the board's live comments first, so the USER_ID predicate runs over
				// the indexed IX_DISK_FLIPCHART_COMMENT_MENTION_COMMENT range instead of the whole mention table.
				$mentionFilter = CommentTable::query()
					->setSelect(['THREAD_ID'])
					->whereNull('DELETED_AT')
					->whereIn('ID', CommentMentionTable::query()
						->setSelect(['COMMENT_ROW_ID'])
						->where('USER_ID', $userId)
						->whereIn('COMMENT_ROW_ID', CommentTable::query()
							->setSelect(['ID'])
							->whereNull('DELETED_AT')
							->whereIn('THREAD_ID', CommentThreadTable::query()
								->setSelect(['THREAD_ID'])
								->where('OBJECT_ID', $context->getObjectId())
								->whereNull('DELETED_AT')
							)
						)
					)
				;
			}

			$threadRows = $this->loadThreads(
				$context->getObjectId(),
				$input[RequestValidator::FIELD_THREAD_ID],
				$input[RequestValidator::FIELD_SHOW_ONLY_OWNED] ? $userId : null,
				$sorting === RequestValidator::SORTING_DATE_ASC,
				$input[RequestValidator::FIELD_SHOW_RESOLVED],
				$stateFilter,
				$mentionFilter,
			);
			if ($threadRows === [])
			{
				return self::succeed([]);
			}

			$states = $this->loadThreadStates($context, array_keys($threadRows));

			$commentsByThreadId = $this->loadLiveComments(array_keys($threadRows));
			$threadRows = array_intersect_key($threadRows, $commentsByThreadId);
			if ($threadRows === [])
			{
				return self::succeed([]);
			}

			$rowIds = [];
			foreach ($commentsByThreadId as $commentRows)
			{
				foreach ($commentRows as $commentRow)
				{
					$rowIds[] = (int)$commentRow['ID'];
				}
			}
			$mentionsByRowId = $this->loadMentions($rowIds);

			$threads = $this->assembleThreads($threadRows, $commentsByThreadId, $mentionsByRowId);
			$this->applyReadState($context, $threads);
			if ($sorting === RequestValidator::SORTING_UNREAD)
			{
				self::sortByUnread($threads);
			}

			foreach ($threads as &$entry)
			{
				$state = $states[$entry['thread']['id']] ?? [];
				$entry['thread']['isImportant'] = $state['IMPORTANT'] ?? false;
				$entry['thread']['isPinned'] = $state['PINNED'] ?? false;
				$entry['thread']['pinnedAt'] = self::formatNullableDate($state['PINNED_AT'] ?? null);
			}
			unset($entry);
			usort($threads, static fn(array $a, array $b): int =>
				(int)$b['thread']['isPinned'] <=> (int)$a['thread']['isPinned']
			);

			return self::succeed(array_column($threads, 'thread'));
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/** @return array<string, array<string, mixed>> */
	private function loadThreadStates(AccessContext $context, array $threadIds): array
	{
		$states = [];
		if ($context->isGuest())
		{
			return $states;
		}

		foreach (array_chunk($threadIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$rows = ThreadStateTable::query()
				->setSelect(['THREAD_ID', 'IMPORTANT', 'PINNED', 'PINNED_AT'])
				->where('USER_ID', (int)$context->getUserId())
				->whereIn('THREAD_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$row['IMPORTANT'] = ThreadStateTable::getEntity()->getField('IMPORTANT')->cast($row['IMPORTANT']);
				$row['PINNED'] = ThreadStateTable::getEntity()->getField('PINNED')->cast($row['PINNED']);
				$states[$row['THREAD_ID']] = $row;
			}
		}

		return $states;
	}

	public function setThreadImportant(AccessContext $context, SetThreadFlagRequest $request): Result
	{
		return $this->setThreadFlag($context, $request, 'IMPORTANT');
	}

	public function setThreadPinned(AccessContext $context, SetThreadFlagRequest $request): Result
	{
		return $this->setThreadFlag($context, $request, 'PINNED');
	}

	private function setThreadFlag(AccessContext $context, SetThreadFlagRequest $request, string $field): Result
	{
		if (!$context->canWrite() || $context->isGuest() || !$context->matchesUser($request->userId))
		{
			return self::forbidden('Only the registered initiator may change thread flags.');
		}

		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}
			$thread = $this->findThread($objectId, $request->threadId);
			if ($thread === null || !$context->matchesDocument((string)$thread['DOCUMENT_ID']))
			{
				return self::threadNotFound();
			}

			$primary = ['THREAD_ID' => $request->threadId, 'USER_ID' => (int)$context->getUserId()];
			$now = new DateTime();
			$fields = [$field => $request->value, 'UPDATED_AT' => $now];
			if ($field === 'PINNED')
			{
				$fields['PINNED_AT'] = $request->value ? $now : null;
			}
			$result = ThreadStateTable::getByPrimary($primary)->fetch()
				? ThreadStateTable::update($primary, $fields)
				: ThreadStateTable::add($primary + $fields + ['CREATED_AT' => $now])
			;
			if (!$result->isSuccess())
			{
				throw new SystemException('Could not save thread flags.');
			}

			return self::succeedWithoutContent();
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}
	}

	/**
	 * Live threads in creation order; the primary key breaks ties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function loadThreads(
		int $objectId,
		?string $threadId,
		?string $ownerId,
		bool $ascending,
		bool $showResolved,
		?Query $stateFilter = null,
		?Query $mentionFilter = null,
	): array
	{
		$query = CommentThreadTable::query()
			->setSelect(self::THREAD_FIELDS)
			->where('OBJECT_ID', $objectId)
			->whereNull('DELETED_AT')
		;
		if ($threadId !== null)
		{
			// An addressed thread is answered regardless of its resolved state: the caller already
			// knows which thread it wants, and the filter exists to shape a list, not to hide one row.
			$query->where('THREAD_ID', $threadId);
		}
		elseif (!$showResolved)
		{
			$query->whereNull('RESOLVED_AT');
		}
		if ($ownerId !== null)
		{
			$query->where('AUTHOR_ID', $ownerId);
		}
		if ($stateFilter !== null)
		{
			$query->whereIn('THREAD_ID', $stateFilter);
		}
		if ($mentionFilter !== null)
		{
			$query->whereIn('THREAD_ID', $mentionFilter);
		}
		$direction = $ascending ? 'ASC' : 'DESC';
		$query->setOrder(['CREATED_AT' => $direction, 'ID' => $direction]);

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$rows[(string)$row['THREAD_ID']] = $row;
		}

		return $rows;
	}

	/**
	 * Live comments of the threads in the order of their numbers, keyed by thread; a thread without
	 * a live comment has no key.
	 *
	 * @param list<string> $threadIds
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function loadLiveComments(array $threadIds): array
	{
		$comments = [];
		foreach (array_chunk($threadIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentTable::query()
				->setSelect(self::COMMENT_FIELDS)
				->whereIn('THREAD_ID', $chunk)
				->whereNull('DELETED_AT')
				->setOrder(['THREAD_ID' => 'ASC', 'COMMENT_ID' => 'ASC'])
				->exec()
			;
			while ($row = $result->fetch())
			{
				$comments[(string)$row['THREAD_ID']][] = $row;
			}
		}

		return $comments;
	}

	/**
	 * Live comments of the given threads that mention the user, keyed by comment row. The USER_ID
	 * predicate reaches the SQL of the lookup itself, through the same {@see CommentMentionTable}
	 * pattern the `showWhereUserMentioned` filter of {@see getThreadsByFilter()} already uses, so a
	 * mention of another user is never read.
	 *
	 * @param list<string> $threadIds
	 * @return array<int, array<string, mixed>>
	 */
	private function loadCommentsMentioning(array $threadIds, string $userId): array
	{
		$mentioned = [];
		foreach (array_chunk($threadIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentTable::query()
				->setSelect(self::COMMENT_FIELDS)
				->whereIn('THREAD_ID', $chunk)
				->whereNull('DELETED_AT')
				->whereIn('ID', CommentMentionTable::query()
					->setSelect(['COMMENT_ROW_ID'])
					->where('USER_ID', $userId)
				)
				->setOrder(['THREAD_ID' => 'ASC', 'COMMENT_ID' => 'ASC'])
				->exec()
			;
			while ($row = $result->fetch())
			{
				$mentioned[(int)$row['ID']] = $row;
			}
		}

		return $mentioned;
	}

	/**
	 * Participants are the distinct authors of the live comments in the order they first wrote;
	 * mentioned are the distinct users those comments mention, in the same order, less the
	 * participants. The author of the thread is a participant only through a comment of their own.
	 *
	 * @param array<string, array<string, mixed>> $threadRows
	 * @param array<string, list<array<string, mixed>>> $commentsByThreadId
	 * @param array<int, list<string>> $mentionsByRowId
	 * @return list<array{thread: array<string, mixed>, lastCommentTimestamp: int, rowId: int}>
	 */
	private function assembleThreads(array $threadRows, array $commentsByThreadId, array $mentionsByRowId): array
	{
		// only the first comment of a thread is rendered here, so only its reactions are read
		$firstRowIds = [];
		foreach ($threadRows as $threadId => $threadRow)
		{
			$firstRowIds[] = (int)$commentsByThreadId[$threadId][0]['ID'];
		}
		$reactionsByRowId = $this->loadReactions($firstRowIds);

		$authorIds = self::reactionUserIds($reactionsByRowId);
		foreach ($threadRows as $threadId => $threadRow)
		{
			$authorIds[] = (string)$threadRow['AUTHOR_ID'];
			if ($threadRow['RESOLVED_BY'] !== null)
			{
				$authorIds[] = (string)$threadRow['RESOLVED_BY'];
			}
			foreach ($commentsByThreadId[$threadId] as $commentRow)
			{
				$authorIds[] = (string)$commentRow['AUTHOR_ID'];
				foreach ($mentionsByRowId[(int)$commentRow['ID']] ?? [] as $mentionedId)
				{
					$authorIds[] = $mentionedId;
				}
			}
		}
		$authors = $this->loadAuthors(array_values(array_unique($authorIds)));

		$threads = [];
		foreach ($threadRows as $threadId => $threadRow)
		{
			$commentRows = $commentsByThreadId[$threadId];
			$firstRow = $commentRows[0];
			$lastRow = $commentRows[count($commentRows) - 1];

			$participantIds = [];
			$mentionedIds = [];
			foreach ($commentRows as $commentRow)
			{
				$participantIds[(string)$commentRow['AUTHOR_ID']] = true;
				foreach ($mentionsByRowId[(int)$commentRow['ID']] ?? [] as $mentionedId)
				{
					$mentionedIds[$mentionedId] = true;
				}
			}
			$mentionedIds = array_diff_key($mentionedIds, $participantIds);

			$thread = $this->formatThreadRow($threadRow, $authors);
			$thread['lastCommentAt'] = self::formatDate($lastRow['CREATED_AT']);
			$thread['firstComment'] = $this->formatCommentRow(
				$firstRow,
				$mentionsByRowId,
				$authors,
				$reactionsByRowId,
			);
			$thread['participants'] = self::formatAuthors(array_keys($participantIds), $authors);
			$thread['mentioned'] = self::formatAuthors(array_keys($mentionedIds), $authors);
			$thread['commentsCount'] = count($commentRows);
			$thread['maxCommentId'] = (int)$lastRow['COMMENT_ID'];

			$threads[] = [
				'thread' => $thread,
				'lastCommentTimestamp' => self::timestampOf($lastRow['CREATED_AT']),
				'rowId' => (int)$threadRow['ID'],
			];
		}

		return $threads;
	}

	/**
	 * Adds a comment to a thread of the board of the token and returns it with its number.
	 *
	 * @param array{
	 *     threadId: string,
	 *     authorId: string,
	 *     content: string,
	 *     mentions: list<string>,
	 *     attached: list<string>
	 * } $input
	 */
	public function addComment(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to add a comment.');
		}

		if (!$context->matchesUser($input[RequestValidator::FIELD_AUTHOR_ID]))
		{
			return self::forbidden('Field authorId does not match the token.');
		}

		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$thread = $this->findThread($objectId, $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			$stored = $this->storeComment(
				$context,
				(string)$thread['THREAD_ID'],
				$input[RequestValidator::FIELD_CONTENT],
				$input[RequestValidator::FIELD_MENTIONS],
				$input[RequestValidator::FIELD_ATTACHED],
			);
			if (!$stored->isSuccess())
			{
				return $stored;
			}

			$row = CommentTable::query()
				->setSelect(self::COMMENT_FIELDS)
				->where('ID', $stored->getData()['rowId'])
				->fetch()
			;
			if (!is_array($row))
			{
				throw new SystemException('Created comment could not be read.');
			}

			$comment = $this->formatComment($row);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}

		$this->notifyMentioned($objectId, $comment, $input[RequestValidator::FIELD_MENTIONS]);

		return self::succeed($comment, 201);
	}

	/**
	 * The comment is already committed and answered with, so the delivery is deferred to a single
	 * background job of the whole operation, not one per recipient: nothing here should slow the
	 * response the comment already has. The closure carries only scalars and the plain list of
	 * mentions, no request, controller, service or ORM object of this operation; the job builds its
	 * own {@see MentionNotifier} the way {@see \Bitrix\Disk\Document\OnlyOffice\Handlers\AvailableDocumentSessionCountNotifier}
	 * builds its own event, and that notifier reloads the board on its own, self-guards and logs its
	 * own failure.
	 *
	 * @param array<string, mixed> $comment
	 * @param list<string> $mentions
	 */
	private function notifyMentioned(int $objectId, array $comment, array $mentions): void
	{
		$threadId = (string)$comment['threadId'];
		$authorId = (string)$comment['author']['id'];
		$authorName = (string)$comment['author']['name'];

		Application::getInstance()->addBackgroundJob(
			static function () use ($objectId, $threadId, $authorId, $authorName, $mentions): void {
				(new MentionNotifier())->notify($objectId, $threadId, $authorId, $authorName, $mentions);
			},
		);
	}

	/**
	 * Returns the live comments of a thread in the order of their numbers.
	 *
	 * @param array{threadId: string} $input
	 */
	public function getThreadComments(AccessContext $context, array $input): Result
	{
		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			$rows = CommentTable::query()
				->setSelect(self::COMMENT_FIELDS)
				->where('THREAD_ID', (string)$thread['THREAD_ID'])
				->whereNull('DELETED_AT')
				->setOrder(['COMMENT_ID' => 'ASC'])
				->fetchAll()
			;

			return self::succeed($this->formatComments($rows));
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Returns one live comment of a thread. The operation knows a single refusal, so a missing
	 * thread reads as a missing comment.
	 *
	 * @param array{threadId: string, commentId: int} $input
	 */
	public function getComment(AccessContext $context, array $input): Result
	{
		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::commentNotFound();
			}

			$row = $this->findCommentRow(
				(string)$thread['THREAD_ID'],
				$input[RequestValidator::FIELD_COMMENT_ID],
				false,
			);
			if ($row === null)
			{
				return self::commentNotFound();
			}

			return self::succeed($this->formatComment($row));
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Changes a live comment of a live thread of the board of the token and returns it.
	 *
	 * The body is sparse: a field that is `null` here was not sent and stays as stored, a field that
	 * was sent replaces the stored one, an empty list included. The text and the attachments that
	 * result may not both be empty. What actually differs from the stored comment is written in one
	 * transaction, the mentions replaced with the rows of the new list as it came, repeats and order
	 * kept, and only then is `editedAt` set; a body that changes nothing leaves the comment as it is.
	 * Once the change is committed, the users the new list mentions and the old one did not are told.
	 *
	 * @param array{
	 *     threadId: string,
	 *     commentId: int,
	 *     content: ?string,
	 *     mentions: ?list<string>,
	 *     attached: ?list<string>
	 * } $input
	 */
	public function updateComment(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to edit a comment.');
		}

		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$thread = $this->findThread($objectId, $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::commentNotFound();
			}

			$threadId = (string)$thread['THREAD_ID'];
			$commentId = $input[RequestValidator::FIELD_COMMENT_ID];
			$row = $this->findCommentRow($threadId, $commentId, false);
			if ($row === null)
			{
				return self::commentNotFound();
			}

			$rowId = (int)$row['ID'];
			$storedContent = (string)$row['CONTENT'];
			$storedAttached = self::decodeAttached($row['ATTACHED']);
			$storedMentions = $this->loadMentions([$rowId])[$rowId] ?? [];

			$content = $input[RequestValidator::FIELD_CONTENT] ?? $storedContent;
			$attached = $input[RequestValidator::FIELD_ATTACHED] ?? $storedAttached;
			$mentions = $input[RequestValidator::FIELD_MENTIONS] ?? $storedMentions;
			if ($content === '' && $attached === [])
			{
				return self::fail(
					'Field content must not be empty without attached.',
					self::ERROR_INVALID_ARGUMENT,
					400,
				);
			}

			$changes = [];
			if ($content !== $storedContent)
			{
				$changes['CONTENT'] = self::requiredStringSql($content);
			}
			if ($attached !== $storedAttached)
			{
				$changes['ATTACHED'] = $attached === [] ? null : Json::encode($attached);
			}
			$replaceMentions = $mentions !== $storedMentions;
			$requestedAttached = $input[RequestValidator::FIELD_ATTACHED];
			if ($changes !== [] || $replaceMentions || $requestedAttached !== null)
			{
				if ($changes !== [] || $replaceMentions)
				{
					$changes['EDITED_AT'] = new DateTime();
				}

				$rejection = $this->applyCommentChanges(
					$context,
					$rowId,
					$changes,
					$replaceMentions ? $mentions : null,
					$requestedAttached,
				);
				if ($rejection !== null)
				{
					return (new Result())->addError($rejection);
				}
			}

			$updatedRow = $this->findCommentRow($threadId, $commentId, false);
			if ($updatedRow === null)
			{
				throw new SystemException('Changed comment could not be read.');
			}

			$comment = $this->formatComment($updatedRow);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}

		if ($input[RequestValidator::FIELD_MENTIONS] !== null)
		{
			$newlyMentioned = array_values(array_diff(array_unique($mentions), $storedMentions));
			if ($newlyMentioned !== [])
			{
				$this->notifyMentioned($objectId, $comment, $newlyMentioned);
			}
		}

		return self::succeed($comment);
	}

	/**
	 * Puts one reaction of the user of the token on a live comment of the board of the token.
	 *
	 * The unique key over comment, user and emoji is what makes a repeat harmless: a second call
	 * finds the row already there, keeps the time the first one wrote and answers as that one did.
	 *
	 * @param array{threadId: string, commentId: int, userId: string, emoji: string} $input
	 */
	public function addReaction(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReactionInitiator($context, $input, 'add a reaction');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitComment(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			$input[RequestValidator::FIELD_COMMENT_ID],
			function (array $row) use ($context, $input): void
			{
				$this->storeReaction(
					(int)$row['ID'],
					$context->getUserId(),
					$input[RequestValidator::FIELD_EMOJI],
				);
			},
			false,
		);
	}

	/**
	 * Takes the reaction of the user of the token off a live comment. A reaction that is not there is
	 * the state the call asks for, so it answers as the one that removed it did.
	 *
	 * @param array{threadId: string, commentId: int, userId: string, emoji: string} $input
	 */
	public function removeReaction(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReactionInitiator($context, $input, 'remove a reaction');
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->transitComment(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			$input[RequestValidator::FIELD_COMMENT_ID],
			function (array $row) use ($context, $input): void
			{
				CommentReactionTable::deleteByFilter([
					'=COMMENT_ROW_ID' => (int)$row['ID'],
					'=USER_ID' => $context->getUserId(),
					'=EMOJI' => FourByteText::encode($input[RequestValidator::FIELD_EMOJI]),
				]);
			},
			false,
		);
	}

	/**
	 * A reaction belongs to the user of the token and needs the right to write, as any other change
	 * of a comment does.
	 *
	 * @param array{userId: string} $input
	 * @return Result|null The refusal, `null` when the initiator may act.
	 */
	private static function checkReactionInitiator(AccessContext $context, array $input, string $action): ?Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden("Board access is not sufficient to {$action}.");
		}

		if (!$context->matchesUser($input[RequestValidator::FIELD_USER_ID]))
		{
			return self::forbidden("Field '" . RequestValidator::FIELD_USER_ID . "' does not match the token.");
		}

		return null;
	}

	/**
	 * The row is written straight against the unique key: a repeat breaks it, and that is the answer
	 * "already there" rather than a failure. Two calls racing for the same key end the same way.
	 */
	private function storeReaction(int $commentRowId, string $userId, string $emoji): void
	{
		$fields = [
			'COMMENT_ROW_ID' => $commentRowId,
			'USER_ID' => $userId,
			'EMOJI' => FourByteText::encode($emoji),
			'CREATED_AT' => new DateTime(),
		];

		try
		{
			$added = CommentReactionTable::add($fields);
		}
		catch (DuplicateEntryException)
		{
			return;
		}

		if (!$added->isSuccess() && !self::reactionExists($fields))
		{
			throw new SystemException('Could not store a reaction.');
		}
	}

	/**
	 * @param array{COMMENT_ROW_ID: int, USER_ID: string, EMOJI: string} $fields
	 */
	private static function reactionExists(array $fields): bool
	{
		return CommentReactionTable::query()
			->setSelect(['ID'])
			->where('COMMENT_ROW_ID', $fields['COMMENT_ROW_ID'])
			->where('USER_ID', $fields['USER_ID'])
			->where('EMOJI', $fields['EMOJI'])
			->setLimit(1)
			->fetch() !== false
		;
	}

	/**
	 * Moves the boundary of what the reader has read in one thread.
	 *
	 * `readFullThread` puts it at the last live comment there is, whatever the request says about a
	 * position; an empty thread has the boundary `0` and no state is written for it, so the thread
	 * stays out of the answered statuses until a comment gives it a boundary of its own. Without the
	 * flag the boundary is the comment the request names, and a comment that is not there or is
	 * deleted is the single `404` of the operation.
	 *
	 * @param array{
	 *     threadId: string,
	 *     userId: string,
	 *     isAnonymous: ?bool,
	 *     readFullThread: bool,
	 *     lastReadCommentId: ?int
	 * } $input
	 */
	public function markCommentsAsRead(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReader($context, $input);
		if ($refusal !== null)
		{
			return $refusal;
		}

		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::commentNotFound();
			}

			$threadId = (string)$thread['THREAD_ID'];
			if ($input[RequestValidator::FIELD_READ_FULL_THREAD])
			{
				$boundary = $this->maxCommentNumber($threadId, true);
				if ($boundary > 0)
				{
					$this->writeBoundary($context, $threadId, $boundary, true);
				}

				return self::succeedWithoutContent();
			}

			$commentId = $input[RequestValidator::FIELD_LAST_READ_COMMENT_ID];
			if ($this->findCommentRow($threadId, $commentId, false) === null)
			{
				return self::commentNotFound();
			}

			$this->writeBoundary($context, $threadId, $commentId, false);

			return self::succeedWithoutContent();
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Takes the boundary of one thread away. The board stops counting as read through, while the
	 * boundaries of its other threads stay where they are.
	 *
	 * @param array{threadId: string, userId: string, isAnonymous: ?bool} $input
	 */
	public function markThreadAsUnread(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReader($context, $input);
		if ($refusal !== null)
		{
			return $refusal;
		}

		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			$this->dropBoundary($context, (string)$thread['THREAD_ID']);

			return self::succeedWithoutContent();
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Reads the whole board through: every thread that has a live comment gets its boundary, and the
	 * board gets the mark of the last comment row it held at that moment.
	 *
	 * Both the boundaries and the mark are written under the lock of the board and in one transaction,
	 * so a comment added at the same time either counts as read or makes the mark stale, depending on
	 * which of the two took the lock first, and never lands in between.
	 *
	 * @param array{documentId: string, userId: string, isAnonymous: ?bool} $input
	 */
	public function markAllThreadsAsRead(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReader($context, $input);
		if ($refusal !== null)
		{
			return $refusal;
		}

		if (!$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]))
		{
			return self::forbidden('Field documentId does not match the token.');
		}

		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$this->writeAllRead(
				$context,
				$this->maxLiveCommentNumbers($objectId),
				$this->boardMaxCommentRowId($objectId),
			);

			return self::succeedWithoutContent();
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}
	}

	/**
	 * Whether the board still counts as read through: the mark holds the last comment row of the
	 * board at the moment it was made, so any comment written since makes it stale by comparison,
	 * without walking over the readers who hold one.
	 *
	 * @param array{documentId: string, userId: string, isAnonymous: ?bool} $input
	 */
	public function isFlipReadAll(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReader($context, $input);
		if ($refusal !== null)
		{
			return $refusal;
		}

		if (!$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]))
		{
			return self::forbidden('Field documentId does not match the token.');
		}

		try
		{
			return self::succeed(['readAll' => $this->isReadAllActive($context)]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * The stored boundaries of the threads that have one. The operation declares no refusal of its
	 * own: the asked identifiers are matched against the threads of the board of the token first, so
	 * one of another board and one that never existed are alike absent from the answer, and a thread
	 * with no boundary is simply not listed.
	 *
	 * @param array{userId: string, isAnonymous: ?bool, threadIds: list<string>} $input
	 */
	public function getReadStatuses(AccessContext $context, array $input): Result
	{
		$refusal = self::checkReader($context, $input);
		if ($refusal !== null)
		{
			return $refusal;
		}

		try
		{
			$threadIds = $this->threadsOfBoard(
				$context->getObjectId(),
				$input[RequestValidator::FIELD_THREAD_IDS],
			);
			$boundaries = $this->readBoundaries($context, $threadIds);

			$statuses = [];
			foreach ($threadIds as $threadId)
			{
				if (isset($boundaries[$threadId]))
				{
					$statuses[] = [
						'threadId' => $threadId,
						'lastReadCommentId' => $boundaries[$threadId],
					];
				}
			}

			return self::succeed($statuses);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * The comments of the board of the token that mention the reader, each comment answered once.
	 *
	 * The feed starts at the board rather than at the mentions: the live threads of the board carry
	 * the live comments, and only those comments are looked up among the stored mentions, so a
	 * deleted board, thread or comment never reaches the answer. A mention repeated inside one
	 * comment is normalised here, in the answer; what is stored stays as it was written.
	 *
	 * `onlyUnread` keeps the comments past the read boundary the reader already has: the boundary of
	 * the thread when there is one, and the "everything is read" mark of the board behind it. The
	 * contract promises neither an order nor a page, and none is added.
	 *
	 * @param array{documentId: string, userId: string, onlyUnread: bool} $input
	 */
	public function getMentions(AccessContext $context, array $input): Result
	{
		if (!$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]))
		{
			return self::forbidden('Field documentId does not match the token.');
		}

		if (!$context->matchesUser($input[RequestValidator::FIELD_USER_ID]))
		{
			return self::forbidden('Field userId does not match the token.');
		}

		try
		{
			// A resolved thread still owes its mentions: the feed is about being mentioned, not about
			// the thread's current state, so every live thread counts here regardless of resolution.
			$threadRows = $this->loadThreads($context->getObjectId(), null, null, true, true);
			if ($threadRows === [])
			{
				return self::succeed([]);
			}

			$mentioned = $this->loadCommentsMentioning(array_keys($threadRows), $context->getUserId());
			if ($mentioned === [])
			{
				return self::succeed([]);
			}

			if ($input[RequestValidator::FIELD_ONLY_UNREAD])
			{
				$mentioned = $this->keepUnread($context, $mentioned);
			}
			if ($mentioned === [])
			{
				return self::succeed([]);
			}

			$documentId = $context->getDocumentId();
			$mentions = [];
			foreach ($this->formatComments(array_values($mentioned)) as $comment)
			{
				$mentions[] = [
					'documentId' => $documentId,
					'comment' => $comment,
				];
			}

			return self::succeed($mentions);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * The comments the reader has not read yet, by the same boundaries the thread list is answered
	 * with: the stored boundary of the thread decides, and where a thread has none the board-wide
	 * "everything is read" mark stands in for it.
	 *
	 * @param array<int, array<string, mixed>> $commentRows Keyed by the row of the comment.
	 * @return array<int, array<string, mixed>>
	 */
	private function keepUnread(AccessContext $context, array $commentRows): array
	{
		$threadIds = array_values(array_unique(array_map(
			static fn(array $row): string => (string)$row['THREAD_ID'],
			$commentRows,
		)));
		$boundaries = $this->readBoundaries($context, $threadIds);
		$readAllActive = $this->isReadAllActive($context);

		return array_filter(
			$commentRows,
			static function (array $row) use ($boundaries, $readAllActive): bool {
				$boundary = $boundaries[(string)$row['THREAD_ID']] ?? null;

				return $boundary === null
					? !$readAllActive
					: (int)$row['COMMENT_ID'] > $boundary
				;
			},
		);
	}

	/**
	 * The reader of an operation of read state is the user of the token, and their claim of being a
	 * guest has to agree with what the token proved: the flag of the body decides nothing on its own.
	 *
	 * @param array{userId: string, isAnonymous: ?bool} $input
	 * @return Result|null The refusal, `null` when the reader may act.
	 */
	private static function checkReader(AccessContext $context, array $input): ?Result
	{
		if (!$context->matchesUser($input[RequestValidator::FIELD_USER_ID]))
		{
			return self::forbidden(
				"Field '" . RequestValidator::FIELD_USER_ID . "' does not match the token.",
			);
		}

		$claimed = $input[RequestValidator::FIELD_IS_ANONYMOUS] ?? null;
		if ($claimed !== null && $claimed !== $context->isGuest())
		{
			return self::forbidden(
				"Field '" . RequestValidator::FIELD_IS_ANONYMOUS . "' does not match the token.",
			);
		}

		return null;
	}

	/**
	 * Marks a comment of the board of the token deleted. A comment already deleted keeps the time it
	 * was deleted at.
	 *
	 * @param array{threadId: string, commentId: int} $input
	 */
	public function deleteComment(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to delete a comment.');
		}

		return $this->transitComment(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			$input[RequestValidator::FIELD_COMMENT_ID],
			function (array $row): void
			{
				if ($row['DELETED_AT'] === null)
				{
					$this->updateCommentRow((int)$row['ID'], ['DELETED_AT' => new DateTime()]);
				}
			},
		);
	}

	/**
	 * Takes the deletion mark off a comment of the board of the token; the comment keeps its number.
	 *
	 * @param array{threadId: string, commentId: int} $input
	 */
	public function recoverComment(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden('Board access is not sufficient to recover a comment.');
		}

		return $this->transitComment(
			$context,
			$input[RequestValidator::FIELD_THREAD_ID],
			$input[RequestValidator::FIELD_COMMENT_ID],
			function (array $row): void
			{
				if ($row['DELETED_AT'] !== null)
				{
					$this->updateCommentRow((int)$row['ID'], ['DELETED_AT' => null]);
				}
			},
		);
	}

	/**
	 * A transition of one comment under the lock of its board, answered with `204` and no body. The
	 * comment and its thread are addressed in whichever state they are in: what is brought to a state
	 * has to be found in the other one first, and a thread deleted after its last comment does not
	 * hide the comment from being recovered.
	 *
	 * @param callable(array<string, mixed>): void $transition Receives the row of the comment as found.
	 */
	private function transitComment(
		AccessContext $context,
		string $threadId,
		int $commentId,
		callable $transition,
		bool $includeDeleted = true,
	): Result
	{
		$objectId = $context->getObjectId();
		if (!BoardLock::acquire($objectId))
		{
			return self::storageUnavailable();
		}

		try
		{
			if (!self::isBoardAvailable($objectId))
			{
				return self::boardNotAvailable();
			}

			$thread = $this->findThread($objectId, $threadId, $includeDeleted);
			if ($thread === null)
			{
				return self::commentNotFound();
			}

			$row = $this->findCommentRow((string)$thread['THREAD_ID'], $commentId, $includeDeleted);
			if ($row === null)
			{
				return self::commentNotFound();
			}

			$transition($row);

			return self::succeedWithoutContent();
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			$this->releaseBoard($objectId);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findCommentRow(string $threadId, int $commentId, bool $includeDeleted): ?array
	{
		$query = CommentTable::query()
			->setSelect(self::COMMENT_FIELDS)
			->where('THREAD_ID', $threadId)
			->where('COMMENT_ID', $commentId)
			->setLimit(1)
		;
		if (!$includeDeleted)
		{
			$query->whereNull('DELETED_AT');
		}

		$row = $query->fetch();

		return is_array($row) ? $row : null;
	}

	/**
	 * The fields of the comment, its attached paths and, when a list is given, its mention rows are
	 * written together: a path that cannot be attached leaves the comment exactly as it was.
	 *
	 * @param array<string, mixed> $fields
	 * @param list<string>|null $mentions
	 * @param list<string>|null $attached The list the request carries, `null` when it carries none.
	 * @return Error|null The refusal of a path, `null` once everything is written.
	 */
	private function applyCommentChanges(
		AccessContext $context,
		int $rowId,
		array $fields,
		?array $mentions,
		?array $attached,
	): ?Error
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if ($attached !== null)
			{
				$bindable = $this->resolveAttachments($context, $attached, $rowId);
				if ($bindable instanceof Error)
				{
					$this->rollback($connection);

					return $bindable;
				}

				self::bindAttachments($bindable, $rowId);
			}

			if ($fields !== [])
			{
				$this->updateCommentRow($rowId, $fields);
			}
			if ($mentions !== null)
			{
				CommentMentionTable::deleteByFilter(['=COMMENT_ROW_ID' => $rowId]);
				$this->insertMentions($rowId, $mentions);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);

			throw $exception;
		}

		return null;
	}

	/**
	 * Proves in one query that every path of the request may be attached to the comment at hand.
	 *
	 * A path counts as usable when its upload belongs to this board, is not yet taken by a comment
	 * and was made by the very user the token names. Changing a comment adds one allowance: a path
	 * already attached to that comment stays, whoever uploaded it, so a co-worker with the right to
	 * write edits the text without losing the images. Anything else is refused alike: a path that
	 * does not exist, one of another board, one somebody else uploaded and one another comment
	 * already took, so the answer never tells whether a file is there.
	 *
	 * @param list<string> $paths
	 * @param int|null $commentRowId The comment being changed, `null` while it is being created.
	 * @return list<int>|Error The rows to bind, or the refusal of the whole request.
	 */
	private function resolveAttachments(AccessContext $context, array $paths, ?int $commentRowId): array|Error
	{
		$distinctPaths = array_values(array_unique($paths));
		if ($distinctPaths === [])
		{
			return [];
		}

		$rows = CommentAttachmentTable::query()
			->setSelect(['ID', 'OBJECT_ID', 'OWNER_ID', 'COMMENT_ROW_ID'])
			->whereIn('PATH', $distinctPaths)
			->fetchAll()
		;
		if (count($rows) !== count($distinctPaths))
		{
			return self::attachmentRejected();
		}

		$bindable = [];
		foreach ($rows as $row)
		{
			if ((int)$row['OBJECT_ID'] !== $context->getObjectId())
			{
				return self::attachmentRejected();
			}

			$boundTo = $row['COMMENT_ROW_ID'] === null ? null : (int)$row['COMMENT_ROW_ID'];
			if ($commentRowId !== null && $boundTo === $commentRowId)
			{
				continue;
			}
			if ($boundTo !== null || !$context->matchesUser((string)$row['OWNER_ID']))
			{
				return self::attachmentRejected();
			}

			$bindable[] = (int)$row['ID'];
		}

		return $bindable;
	}

	/**
	 * The first binding of an upload, and the only one: a path dropped from `attached` later keeps
	 * the comment it was bound to and never returns to the temporary set.
	 *
	 * @param list<int> $rowIds
	 */
	private static function bindAttachments(array $rowIds, int $commentRowId): void
	{
		if ($rowIds === [])
		{
			return;
		}

		$result = CommentAttachmentTable::updateMulti($rowIds, ['COMMENT_ROW_ID' => $commentRowId], true);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not attach an upload to a comment.');
		}
	}

	private static function attachmentRejected(): Error
	{
		return new Error(
			"Field '" . RequestValidator::FIELD_ATTACHED . "' holds a path that cannot be attached.",
			self::ERROR_INVALID_ARGUMENT,
			['status' => 400],
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function updateCommentRow(int $rowId, array $fields): void
	{
		$result = CommentTable::update($rowId, $fields);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not update a comment.');
		}
	}

	/**
	 * @param array{threadId: string} $input
	 */
	public function countComments(AccessContext $context, array $input): Result
	{
		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			$count = CommentTable::query()
				->setSelect(['ID'])
				->where('THREAD_ID', (string)$thread['THREAD_ID'])
				->whereNull('DELETED_AT')
				->queryCountTotal()
			;

			return self::succeed(['count' => (int)$count]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * The greatest number among the live comments, unlike the one a new comment is counted from.
	 *
	 * @param array{threadId: string} $input
	 */
	public function getMaxCommentId(AccessContext $context, array $input): Result
	{
		try
		{
			$thread = $this->findThread($context->getObjectId(), $input[RequestValidator::FIELD_THREAD_ID]);
			if ($thread === null)
			{
				return self::threadNotFound();
			}

			return self::succeed([
				'maxCommentId' => $this->maxCommentNumber((string)$thread['THREAD_ID'], true),
			]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * A thread of another board is invisible here, so it reads as "no such thread"; so does a deleted
	 * one, unless the caller addresses the thread in either state.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findThread(int $objectId, string $threadId, bool $includeDeleted = false): ?array
	{
		$query = CommentThreadTable::query()
			->setSelect(self::THREAD_FIELDS)
			->where('OBJECT_ID', $objectId)
			->where('THREAD_ID', $threadId)
			->setLimit(1)
		;
		if (!$includeDeleted)
		{
			$query->whereNull('DELETED_AT');
		}

		$row = $query->fetch();

		return is_array($row) ? $row : null;
	}

	/**
	 * Numbering and writing under the named lock of the thread: the lock is taken before the
	 * transaction starts, so the greatest number read inside it already counts every comment a
	 * concurrent writer has committed. The lock is released whatever happens.
	 *
	 * The attached paths are proved and bound inside the same transaction: the comment row has to
	 * exist before anything can point at it, and a path that cannot be attached undoes the comment
	 * along with itself.
	 *
	 * @param list<string> $mentions
	 * @param list<string> $attached
	 * @return Result On success `getData()['rowId']` is the primary key of the comment row.
	 */
	private function storeComment(
		AccessContext $context,
		string $threadId,
		string $content,
		array $mentions,
		array $attached,
	): Result
	{
		$lockName = hash('sha256', self::LOCK_PREFIX . $threadId);
		$connection = Application::getConnection();
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return self::storageUnavailable();
		}

		try
		{
			$connection->startTransaction();
			try
			{
				$bindable = $this->resolveAttachments($context, $attached, null);
				if ($bindable instanceof Error)
				{
					$this->rollback($connection);

					return (new Result())->addError($bindable);
				}

				$rowId = $this->insertComment($threadId, $context->getUserId(), $content, $mentions, $attached);
				self::bindAttachments($bindable, $rowId);
				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				$this->rollback($connection);

				throw $exception;
			}
		}
		finally
		{
			try
			{
				$connection->unlock($lockName);
			}
			catch (\Throwable $exception)
			{
				$this->logException($exception);
			}
		}

		return (new Result())->setData(['rowId' => $rowId]);
	}

	/**
	 * @param list<string> $mentions
	 * @param list<string> $attached
	 */
	private function insertComment(
		string $threadId,
		string $authorId,
		string $content,
		array $mentions,
		array $attached,
	): int
	{
		$maxNumber = $this->maxCommentNumber($threadId, false);
		if ($maxNumber >= self::COMMENT_ID_MAX)
		{
			throw new SystemException('Comment numbers of the thread are exhausted.');
		}

		$addResult = CommentTable::add([
			'THREAD_ID' => $threadId,
			'COMMENT_ID' => $maxNumber + 1,
			'AUTHOR_ID' => $authorId,
			'CONTENT' => self::requiredStringSql($content),
			'ATTACHED' => $attached === [] ? null : Json::encode($attached),
			'CREATED_AT' => new DateTime(),
		]);
		if (!$addResult->isSuccess())
		{
			throw new SystemException('Could not create a comment.');
		}

		$rowId = (int)$addResult->getId();
		$this->insertMentions($rowId, $mentions);

		return $rowId;
	}

	/**
	 * One row per mention in the order of the list, repeats included. The rows go in by chunks of
	 * one multi-row INSERT each, in sequence, so the ids follow the order of the list.
	 *
	 * @param list<string> $mentions
	 */
	private function insertMentions(int $rowId, array $mentions): void
	{
		foreach (array_chunk($mentions, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$rows = [];
			foreach ($chunk as $userId)
			{
				$rows[] = [
					'COMMENT_ROW_ID' => $rowId,
					'USER_ID' => self::requiredStringSql($userId),
				];
			}
			$mentionResult = CommentMentionTable::addMulti($rows, true);
			if (!$mentionResult->isSuccess())
			{
				throw new SystemException('Could not save comment mentions.');
			}
		}
	}

	/**
	 * An empty text is a legal comment when something is attached and an empty mention is a legal
	 * string of the API, but the ORM reads an empty string of a required field as a missing one;
	 * the literal keeps the column filled.
	 */
	private static function requiredStringSql(string $value): string|SqlExpression
	{
		return $value === '' ? new SqlExpression('?s', '') : $value;
	}

	/**
	 * Inside an outer transaction the connection rolls back to its savepoint and reports the nested
	 * rollback as an exception; the comment is still undone, so that is not a failure here.
	 */
	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * The greatest number of the thread, `0` without comments. Numbering counts every comment ever
	 * written, the API counts only the live ones.
	 */
	private function maxCommentNumber(string $threadId, bool $liveOnly): int
	{
		$query = CommentTable::query()
			->registerRuntimeField(
				new ExpressionField('MAX_COMMENT_ID', 'MAX(%s)', ['COMMENT_ID'], ['data_type' => 'integer']),
			)
			->setSelect(['MAX_COMMENT_ID'])
			->where('THREAD_ID', $threadId)
		;
		if ($liveOnly)
		{
			$query->whereNull('DELETED_AT');
		}

		$row = $query->fetch();

		return is_array($row) ? (int)$row['MAX_COMMENT_ID'] : 0;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function formatComment(array $row): array
	{
		return $this->formatComments([$row])[0];
	}

	/**
	 * `mentions` and `attached` are present only when there is something to list, `reactions` always.
	 * Mentions, reactions and the users behind the whole list are read once each.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function formatComments(array $rows): array
	{
		if ($rows === [])
		{
			return [];
		}

		$rowIds = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		$mentionsByRowId = $this->loadMentions($rowIds);
		$reactionsByRowId = $this->loadReactions($rowIds);

		$authorIds = array_map(static fn(array $row): string => (string)$row['AUTHOR_ID'], $rows);
		$authors = $this->loadAuthors(
			array_values(array_unique([...$authorIds, ...self::reactionUserIds($reactionsByRowId)])),
		);

		$comments = [];
		foreach ($rows as $row)
		{
			$comments[] = $this->formatCommentRow($row, $mentionsByRowId, $authors, $reactionsByRowId);
		}

		return $comments;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, list<string>> $mentionsByRowId
	 * @param array<string, array{name: string, avatar: ?string, isAnonymous: bool}> $authors
	 * @param array<int, list<array<string, mixed>>> $reactionsByRowId
	 * @return array<string, mixed>
	 */
	private function formatCommentRow(
		array $row,
		array $mentionsByRowId,
		array $authors,
		array $reactionsByRowId,
	): array
	{
		$comment = [
			'threadId' => (string)$row['THREAD_ID'],
			'commentId' => (int)$row['COMMENT_ID'],
			'author' => self::formatAuthor((string)$row['AUTHOR_ID'], $authors),
			'content' => (string)$row['CONTENT'],
			'createdAt' => self::formatDate($row['CREATED_AT']),
			'editedAt' => self::formatNullableDate($row['EDITED_AT']),
		];

		$mentions = $mentionsByRowId[(int)$row['ID']] ?? [];
		if ($mentions !== [])
		{
			$comment['mentions'] = $mentions;
		}

		$attached = self::decodeAttached($row['ATTACHED']);
		if ($attached !== [])
		{
			$comment['attached'] = self::formatAttached($attached);
		}

		$comment['reactions'] = self::formatReactions($reactionsByRowId[(int)$row['ID']] ?? [], $authors);

		return $comment;
	}

	/**
	 * The reactions of a comment in the order they were first put, each with the user behind it as
	 * the portal knows them right now: a name that cannot be told and a missing avatar are left out
	 * rather than answered as empty.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, array{name: string, avatar: ?string, isAnonymous: bool}> $authors
	 * @return list<array<string, mixed>>
	 */
	private static function formatReactions(array $rows, array $authors): array
	{
		$reactions = [];
		foreach ($rows as $row)
		{
			$userId = (string)$row['USER_ID'];
			$author = $authors[$userId] ?? null;

			$reaction = [
				'userId' => $userId,
				'emoji' => FourByteText::decode((string)$row['EMOJI']),
				'createdAt' => self::formatDate($row['CREATED_AT']),
			];
			if ($author !== null && $author['name'] !== '')
			{
				$reaction['userName'] = $author['name'];
			}
			if (isset($author['avatar']) && $author['avatar'] !== '')
			{
				$reaction['userAvatar'] = $author['avatar'];
			}

			$reactions[] = $reaction;
		}

		return $reactions;
	}

	/**
	 * @param array<int, list<array<string, mixed>>> $reactionsByRowId
	 * @return list<string>
	 */
	private static function reactionUserIds(array $reactionsByRowId): array
	{
		$userIds = [];
		foreach ($reactionsByRowId as $rows)
		{
			foreach ($rows as $row)
			{
				$userIds[] = (string)$row['USER_ID'];
			}
		}

		return $userIds;
	}

	/**
	 * ALG-05: the two fields of read state of every thread of the answer, from three reads at most
	 * whatever the number of threads is: the boundaries, the mark of the board, and the last comment
	 * row of the board the mark is weighed against. A guest answers the first two from one document.
	 *
	 * An individual boundary is the truth; the mark of the board stands in only for a thread that has
	 * none, and only while it is not stale.
	 *
	 * @param list<array{thread: array<string, mixed>, lastCommentTimestamp: int, rowId: int}> $threads
	 */
	private function applyReadState(AccessContext $context, array &$threads): void
	{
		$threadIds = array_map(static fn(array $entry): string => (string)$entry['thread']['id'], $threads);
		$boundaries = $this->readBoundaries($context, $threadIds);
		$readAllActive = $this->isReadAllActive($context);

		foreach ($threads as &$entry)
		{
			$maxCommentId = (int)$entry['thread']['maxCommentId'];
			$saved = $boundaries[(string)$entry['thread']['id']] ?? null;
			$boundary = $saved ?? ($readAllActive ? $maxCommentId : 0);

			$entry['thread']['showNotification'] = $maxCommentId > $boundary;
			$entry['thread']['lastReadMessageId'] = $boundary;
		}
		unset($entry);
	}

	/**
	 * Threads with something unread first; inside each group the order of the last activity stands.
	 *
	 * @param list<array{thread: array<string, mixed>, lastCommentTimestamp: int, rowId: int}> $threads
	 */
	private static function sortByUnread(array &$threads): void
	{
		usort(
			$threads,
			static fn(array $a, array $b): int => [
				(int)$b['thread']['showNotification'],
				$b['lastCommentTimestamp'],
				$b['rowId'],
			] <=> [
				(int)$a['thread']['showNotification'],
				$a['lastCommentTimestamp'],
				$a['rowId'],
			],
		);
	}

	private function isReadAllActive(AccessContext $context): bool
	{
		$stamp = $this->readAllStamp($context);

		return $stamp !== null && $stamp >= $this->boardMaxCommentRowId($context->getObjectId());
	}

	/**
	 * @param list<string> $threadIds
	 * @return array<string, int> The stored boundary of every thread that has one.
	 */
	private function readBoundaries(AccessContext $context, array $threadIds): array
	{
		if ($threadIds === [])
		{
			return [];
		}

		if ($context->isGuest())
		{
			return $this->guestStore()->getBoundaries($context->getObjectId(), $context->getUserId(), $threadIds);
		}

		$boundaries = [];
		foreach (array_chunk($threadIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentReadTable::query()
				->setSelect(['THREAD_ID', 'LAST_READ_COMMENT_ID'])
				->whereIn('THREAD_ID', $chunk)
				->where('USER_ID', $context->getUserId())
				->exec()
			;
			while ($row = $result->fetch())
			{
				$boundaries[(string)$row['THREAD_ID']] = (int)$row['LAST_READ_COMMENT_ID'];
			}
		}

		return $boundaries;
	}

	private function readAllStamp(AccessContext $context): ?int
	{
		if ($context->isGuest())
		{
			return $this->guestStore()->getReadAllStamp($context->getObjectId(), $context->getUserId());
		}

		$row = CommentReadAllTable::query()
			->setSelect(['MAX_COMMENT_ROW_ID'])
			->where('OBJECT_ID', $context->getObjectId())
			->where('USER_ID', $context->getUserId())
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['MAX_COMMENT_ROW_ID'] : null;
	}

	/**
	 * @param bool $advanceOnly Whether a boundary already past the new one is left where it is: a
	 *     thread read through never goes back, an addressed position is written as it is asked.
	 */
	private function writeBoundary(AccessContext $context, string $threadId, int $boundary, bool $advanceOnly): void
	{
		if ($context->isGuest())
		{
			$this->guestStore()->setBoundary(
				$context->getObjectId(),
				$context->getUserId(),
				$threadId,
				$boundary,
				$advanceOnly,
			);

			return;
		}

		$userId = $context->getUserId();
		$row = $this->findBoundaryRow($threadId, $userId);
		if ($row === null)
		{
			try
			{
				$added = CommentReadTable::add([
					'THREAD_ID' => $threadId,
					'USER_ID' => $userId,
					'LAST_READ_COMMENT_ID' => $boundary,
					'UPDATED_AT' => new DateTime(),
				]);
				if ($added->isSuccess())
				{
					return;
				}
			}
			catch (DuplicateEntryException)
			{
			}

			$row = $this->findBoundaryRow($threadId, $userId);
			if ($row === null)
			{
				throw new SystemException('Could not store the read boundary of a thread.');
			}
		}

		if ($advanceOnly && (int)$row['LAST_READ_COMMENT_ID'] >= $boundary)
		{
			return;
		}

		$updated = CommentReadTable::update((int)$row['ID'], [
			'LAST_READ_COMMENT_ID' => $boundary,
			'UPDATED_AT' => new DateTime(),
		]);
		if (!$updated->isSuccess())
		{
			throw new SystemException('Could not move the read boundary of a thread.');
		}
	}

	private function dropBoundary(AccessContext $context, string $threadId): void
	{
		if ($context->isGuest())
		{
			$this->guestStore()->removeBoundary($context->getObjectId(), $context->getUserId(), $threadId);

			return;
		}

		CommentReadTable::deleteByFilter([
			'=THREAD_ID' => $threadId,
			'=USER_ID' => $context->getUserId(),
		]);
		CommentReadAllTable::deleteByFilter([
			'=OBJECT_ID' => $context->getObjectId(),
			'=USER_ID' => $context->getUserId(),
		]);
	}

	/**
	 * @param array<string, int> $boundaries The last live comment number of every thread that has one.
	 * @param int $stamp The last comment row of the board at the moment of the mark.
	 */
	private function writeAllRead(AccessContext $context, array $boundaries, int $stamp): void
	{
		if ($context->isGuest())
		{
			$this->guestStore()->markAllRead(
				$context->getObjectId(),
				$context->getUserId(),
				$boundaries,
				$stamp,
			);

			return;
		}

		$userId = $context->getUserId();
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$stored = $this->loadBoundaryRows(array_keys($boundaries), $userId);
			$now = new DateTime();

			$newRows = [];
			$advancingRows = [];
			foreach ($boundaries as $threadId => $boundary)
			{
				$row = $stored[$threadId] ?? null;
				if ($row === null)
				{
					$newRows[] = [
						'THREAD_ID' => $threadId,
						'USER_ID' => $userId,
						'LAST_READ_COMMENT_ID' => $boundary,
						'UPDATED_AT' => $now,
					];

					continue;
				}

				if ((int)$row['LAST_READ_COMMENT_ID'] < $boundary)
				{
					$advancingRows[] = [
						'THREAD_ID' => $threadId,
						'USER_ID' => $userId,
						'LAST_READ_COMMENT_ID' => $boundary,
						'UPDATED_AT' => $now,
					];
				}
			}
			$this->mergeAdvancingBoundaries($advancingRows);
			foreach (array_chunk($newRows, self::QUERY_CHUNK_SIZE) as $chunk)
			{
				CommentReadTable::addMulti($chunk, true);
			}

			$this->writeReadAllStamp($context->getObjectId(), $userId, $stamp, $now);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);

			throw $exception;
		}
	}

	/**
	 * Moves already-stored boundaries of the current reader in one portable batch statement per
	 * chunk instead of one `UPDATE` per row. `GREATEST()` keeps the advance-only invariant inside
	 * the merge itself, so a boundary moved further by another request in between is never pulled
	 * back down.
	 *
	 * @param list<array{THREAD_ID: string, USER_ID: string, LAST_READ_COMMENT_ID: int, UPDATED_AT: DateTime}> $rows
	 */
	private function mergeAdvancingBoundaries(array $rows): void
	{
		if ($rows === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		foreach (array_chunk($rows, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$sql = $sqlHelper->prepareMergeValues(
				CommentReadTable::getTableName(),
				['THREAD_ID', 'USER_ID'],
				$chunk,
				[
					'LAST_READ_COMMENT_ID' => new SqlExpression(
						'GREATEST(?#, ?v)',
						'LAST_READ_COMMENT_ID',
						'LAST_READ_COMMENT_ID',
					),
					'UPDATED_AT' => new SqlExpression('?v', 'UPDATED_AT'),
				],
			);
			$connection->queryExecute($sql);
		}
	}

	private function writeReadAllStamp(int $objectId, string $userId, int $stamp, DateTime $now): void
	{
		$row = CommentReadAllTable::query()
			->setSelect(['ID'])
			->where('OBJECT_ID', $objectId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;
		if (is_array($row))
		{
			CommentReadAllTable::update((int)$row['ID'], [
				'MAX_COMMENT_ROW_ID' => $stamp,
				'UPDATED_AT' => $now,
			]);

			return;
		}

		try
		{
			CommentReadAllTable::add([
				'OBJECT_ID' => $objectId,
				'USER_ID' => $userId,
				'MAX_COMMENT_ROW_ID' => $stamp,
				'UPDATED_AT' => $now,
			]);
		}
		catch (DuplicateEntryException)
		{
			$this->writeReadAllStamp($objectId, $userId, $stamp, $now);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findBoundaryRow(string $threadId, string $userId): ?array
	{
		$row = CommentReadTable::query()
			->setSelect(['ID', 'LAST_READ_COMMENT_ID'])
			->where('THREAD_ID', $threadId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	/**
	 * @param list<string> $threadIds
	 * @return array<string, array<string, mixed>>
	 */
	private function loadBoundaryRows(array $threadIds, string $userId): array
	{
		$rows = [];
		foreach (array_chunk($threadIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentReadTable::query()
				->setSelect(['ID', 'THREAD_ID', 'LAST_READ_COMMENT_ID'])
				->whereIn('THREAD_ID', $chunk)
				->where('USER_ID', $userId)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$rows[(string)$row['THREAD_ID']] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The last comment row of the board, soft-deleted ones included: a mark of the board is only as
	 * fresh as this number, and a comment that was written and then deleted keeps it moved on.
	 */
	private function boardMaxCommentRowId(int $objectId): int
	{
		$row = CommentTable::query()
			->registerRuntimeField('MAX_ROW_ID', new ExpressionField('MAX_ROW_ID', 'MAX(%s)', 'ID'))
			->setSelect(['MAX_ROW_ID'])
			->whereIn('THREAD_ID', self::boardThreadsQuery($objectId))
			->fetch()
		;

		return is_array($row) ? (int)$row['MAX_ROW_ID'] : 0;
	}

	/**
	 * The last live comment number of every thread of the board that has one, in one pass.
	 *
	 * @return array<string, int>
	 */
	private function maxLiveCommentNumbers(int $objectId): array
	{
		$result = CommentTable::query()
			->registerRuntimeField('MAX_NUMBER', new ExpressionField('MAX_NUMBER', 'MAX(%s)', 'COMMENT_ID'))
			->setSelect(['THREAD_ID', 'MAX_NUMBER'])
			->whereIn('THREAD_ID', self::boardThreadsQuery($objectId))
			->whereNull('DELETED_AT')
			->setGroup(['THREAD_ID'])
			->exec()
		;

		$numbers = [];
		while ($row = $result->fetch())
		{
			$numbers[(string)$row['THREAD_ID']] = (int)$row['MAX_NUMBER'];
		}

		return $numbers;
	}

	/**
	 * The asked identifiers that name a thread of this board, in the order they were asked. Anything
	 * else is dropped here, before any state is looked at.
	 *
	 * @param list<string> $threadIds
	 * @return list<string>
	 */
	private function threadsOfBoard(int $objectId, array $threadIds): array
	{
		$asked = array_values(array_unique($threadIds));
		if ($asked === [])
		{
			return [];
		}

		$known = [];
		foreach (array_chunk($asked, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentThreadTable::query()
				->setSelect(['THREAD_ID'])
				->where('OBJECT_ID', $objectId)
				->whereIn('THREAD_ID', $chunk)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$known[(string)$row['THREAD_ID']] = true;
			}
		}

		return array_values(array_filter($asked, static fn(string $threadId): bool => isset($known[$threadId])));
	}

	private static function boardThreadsQuery(int $objectId): Query
	{
		return CommentThreadTable::query()
			->setSelect(['THREAD_ID'])
			->where('OBJECT_ID', $objectId)
		;
	}

	private function guestStore(): GuestReadStore
	{
		return $this->guestStore ??= new GuestReadStore();
	}

	/**
	 * Reactions of the whole answer in one pass, grouped by the comment they belong to and ordered
	 * by the row they were written in, which is the order they were first put in.
	 *
	 * @param list<int> $rowIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	private function loadReactions(array $rowIds): array
	{
		$reactions = [];
		foreach (array_chunk($rowIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentReactionTable::query()
				->setSelect(['COMMENT_ROW_ID', 'USER_ID', 'EMOJI', 'CREATED_AT'])
				->whereIn('COMMENT_ROW_ID', $chunk)
				->setOrder(['ID' => 'ASC'])
				->exec()
			;
			while ($row = $result->fetch())
			{
				$reactions[(int)$row['COMMENT_ROW_ID']][] = $row;
			}
		}

		return $reactions;
	}

	/**
	 * Mentions in the order they were saved, which is the order they were received in.
	 *
	 * @param list<int> $rowIds
	 * @return array<int, list<string>>
	 */
	private function loadMentions(array $rowIds): array
	{
		$mentions = [];
		foreach (array_chunk($rowIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = CommentMentionTable::query()
				->setSelect(['COMMENT_ROW_ID', 'USER_ID'])
				->whereIn('COMMENT_ROW_ID', $chunk)
				->setOrder(['ID' => 'ASC'])
				->exec()
			;
			while ($row = $result->fetch())
			{
				$mentions[(int)$row['COMMENT_ROW_ID']][] = (string)$row['USER_ID'];
			}
		}

		return $mentions;
	}

	/**
	 * @return list<string>
	 */
	private static function decodeAttached(mixed $stored): array
	{
		if (!is_string($stored) || $stored === '')
		{
			return [];
		}

		$attached = Json::decode($stored);

		return is_array($attached) ? array_values($attached) : [];
	}

	/**
	 * The stored value is the path alone ({@see AttachmentService::pathFromPublicUrl()}), so a reader
	 * has to rebuild the address itself; this is the one place a comment is handed back to a caller.
	 *
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private static function formatAttached(array $paths): array
	{
		return array_map(AttachmentService::publicUrl(...), $paths);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function formatThread(array $row): array
	{
		$authorIds = [(string)$row['AUTHOR_ID']];
		if ($row['RESOLVED_BY'] !== null)
		{
			$authorIds[] = (string)$row['RESOLVED_BY'];
		}

		return $this->formatThreadRow($row, $this->loadAuthors(array_values(array_unique($authorIds))));
	}

	/**
	 * `ThreadMeta` as stored: the state fields carry their values, and the user who resolved the
	 * thread is enriched the way every author is.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, array{name: string, avatar: ?string, isAnonymous: bool}> $authors
	 * @return array<string, mixed>
	 */
	private function formatThreadRow(array $row, array $authors): array
	{
		return [
			'id' => (string)$row['THREAD_ID'],
			'documentId' => (string)$row['DOCUMENT_ID'],
			'author' => self::formatAuthor((string)$row['AUTHOR_ID'], $authors),
			'coordinates' => [
				'x' => (float)$row['COORD_X'],
				'y' => (float)$row['COORD_Y'],
				'parentId' => $row['PARENT_ID'] === null ? null : (string)$row['PARENT_ID'],
				'width' => $row['WIDTH'] === null ? null : (float)$row['WIDTH'],
				'height' => $row['HEIGHT'] === null ? null : (float)$row['HEIGHT'],
			],
			'createdAt' => self::formatDate($row['CREATED_AT']),
			'resolvedAt' => self::formatNullableDate($row['RESOLVED_AT']),
			'resolvedBy' => $row['RESOLVED_BY'] === null
				? null
				: self::formatAuthor((string)$row['RESOLVED_BY'], $authors),
			'deletedAt' => self::formatNullableDate($row['DELETED_AT']),
		];
	}

	/**
	 * The same `Author` for a thread and for a comment: an author the portal does not know keeps the
	 * identifier and gets a fallback name, and is anonymous unless the portal says otherwise. The
	 * schema allows no `null` in `avatar`, so an author without one has no such key at all.
	 *
	 * @param array<string, array{name: string, avatar: ?string, isAnonymous: bool}> $authors
	 * @return array{id: string, name: string, avatar?: string, isAnonymous: bool}
	 */
	private static function formatAuthor(string $authorId, array $authors): array
	{
		$author = $authors[$authorId] ?? null;
		$formatted = [
			'id' => $authorId,
			'name' => $author['name'] ?? self::fallbackAuthorName(self::isRegisteredUserId($authorId)),
		];
		if (isset($author['avatar']) && $author['avatar'] !== '')
		{
			$formatted['avatar'] = $author['avatar'];
		}
		$formatted['isAnonymous'] = $author === null || $author['isAnonymous'];

		return $formatted;
	}

	/**
	 * @param list<string> $authorIds
	 * @param array<string, array{name: string, avatar: ?string, isAnonymous: bool}> $authors
	 * @return list<array{id: string, name: string, avatar?: string, isAnonymous: bool}>
	 */
	private static function formatAuthors(array $authorIds, array $authors): array
	{
		$formatted = [];
		foreach ($authorIds as $authorId)
		{
			$formatted[] = self::formatAuthor((string)$authorId, $authors);
		}

		return $formatted;
	}

	/**
	 * Names and avatars of the authors in one pass: a guest identifier is not a user of the portal
	 * and is left out, and the temporary user a public link signs an anonymous editor in as is shown
	 * as a guest too.
	 *
	 * @param list<string> $authorIds
	 * @return array<string, array{name: string, avatar: ?string, isAnonymous: bool}>
	 */
	private function loadAuthors(array $authorIds): array
	{
		$userIds = [];
		foreach ($authorIds as $authorId)
		{
			if (self::isRegisteredUserId($authorId))
			{
				$userIds[(int)$authorId] = true;
			}
		}

		if ($userIds === [])
		{
			return [];
		}

		$userRows = [];
		foreach (array_chunk(array_keys($userIds), self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = UserTable::query()
				->setSelect([
					'ID',
					'LOGIN',
					'EMAIL',
					'NAME',
					'SECOND_NAME',
					'LAST_NAME',
					'PERSONAL_PHOTO',
					'EXTERNAL_AUTH_ID',
				])
				->whereIn('ID', $chunk)
				->exec()
			;
			while ($userRow = $result->fetch())
			{
				$userRows[] = $userRow;
			}
		}

		$photoIds = [];
		foreach ($userRows as $userRow)
		{
			$photoId = (int)$userRow['PERSONAL_PHOTO'];
			if ($photoId > 0 && !self::isDocumentEditorUser($userRow))
			{
				$photoIds[$photoId] = true;
			}
		}

		$avatarByPhotoId = $this->loadAvatars(array_keys($photoIds));
		$authors = [];
		foreach ($userRows as $userRow)
		{
			$authorId = (string)$userRow['ID'];
			if (self::isDocumentEditorUser($userRow))
			{
				$authors[$authorId] = [
					'name' => self::fallbackAuthorName(false),
					'avatar' => null,
					'isAnonymous' => true,
				];

				continue;
			}

			$name = (string)\CUser::formatName(\CSite::getNameFormat(), $userRow, true, false);
			$authors[$authorId] = [
				'name' => $name !== '' ? $name : self::fallbackAuthorName(true),
				'avatar' => $avatarByPhotoId[(int)$userRow['PERSONAL_PHOTO']] ?? null,
				'isAnonymous' => false,
			];
		}

		return $authors;
	}

	/**
	 * @param array<string, mixed> $userRow
	 */
	private static function isDocumentEditorUser(array $userRow): bool
	{
		return ($userRow['EXTERNAL_AUTH_ID'] ?? null) === DocumentEditorUser::EXTERNAL_AUTH_ID;
	}

	/**
	 * @param list<int> $photoIds
	 * @return array<int, string>
	 */
	private function loadAvatars(array $photoIds): array
	{
		if ($photoIds === [])
		{
			return [];
		}

		$avatars = [];
		foreach (array_chunk($photoIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = FileTable::query()
				->setSelect(['*'])
				->whereIn('ID', $chunk)
				->exec()
			;
			while ($file = $result->fetch())
			{
				$avatar = self::sanitizeAvatar((string)\CFile::getFileSRC($file));
				if ($avatar !== null)
				{
					$avatars[(int)$file['ID']] = $avatar;
				}
			}
		}

		return $avatars;
	}

	/**
	 * The board front works from another origin, so only an absolute address is of any use to it.
	 */
	private static function sanitizeAvatar(string $avatar): ?string
	{
		if ($avatar === '')
		{
			return null;
		}

		if (preg_match(
			'#^data:image/(?:png|jpeg|gif|webp|avif);base64,([A-Za-z0-9+/]+={0,2})$#D',
			$avatar,
			$match,
		) === 1)
		{
			return base64_decode($match[1], true) !== false ? $avatar : null;
		}

		try
		{
			$absoluteAvatar = (string)(new Uri($avatar))->toAbsolute();
			$uri = new Uri($absoluteAvatar);
		}
		catch (\Throwable)
		{
			return null;
		}

		return (
			in_array($uri->getScheme(), ['http', 'https'], true)
			&& $uri->getHost() !== ''
			&& preg_match('#^https?://#D', $absoluteAvatar) === 1
		)
			? $absoluteAvatar
			: null;
	}

	private static function isRegisteredUserId(string $userId): bool
	{
		return preg_match(self::REGISTERED_USER_ID_PATTERN, $userId) === 1
			&& (string)(int)$userId === $userId;
	}

	/**
	 * 17 significant digits restore any binary64 exactly, and `%H` does not depend on the locale of
	 * the process; the default string conversion of a float would round the coordinate away.
	 */
	private static function coordinateSql(float $value): SqlExpression
	{
		return new SqlExpression(sprintf('%.17H', $value));
	}

	private static function nullableCoordinateSql(?float $value): ?SqlExpression
	{
		return $value === null ? null : self::coordinateSql($value);
	}

	private static function fallbackAuthorName(bool $isRegisteredUser): string
	{
		$messageCode = $isRegisteredUser ? self::UNKNOWN_NAME_MESSAGE : self::GUEST_NAME_MESSAGE;
		$fallback = $isRegisteredUser ? self::UNKNOWN_NAME_FALLBACK : self::GUEST_NAME_FALLBACK;
		$name = Loc::getMessage($messageCode);

		return is_string($name) && $name !== '' ? $name : $fallback;
	}

	private static function formatDate(mixed $date): string
	{
		return gmdate('Y-m-d\TH:i:s\Z', self::timestampOf($date));
	}

	private static function formatNullableDate(mixed $date): ?string
	{
		return $date === null ? null : self::formatDate($date);
	}

	private static function timestampOf(mixed $date): int
	{
		if (!$date instanceof DateTime)
		{
			throw new SystemException('Stored comment date has an invalid type.');
		}

		return $date->getTimestamp();
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private static function succeed(array $response, int $status = 200): Result
	{
		$result = new Result();
		$result->setData([
			'response' => $response,
			'status' => $status,
		]);

		return $result;
	}

	/**
	 * A success the contract answers with `204`: the controller sends the status and nothing else.
	 */
	private static function succeedWithoutContent(): Result
	{
		$result = new Result();
		$result->setData([
			'response' => null,
			'status' => self::STATUS_NO_CONTENT,
		]);

		return $result;
	}

	private static function threadNotFound(): Result
	{
		return self::fail('Thread was not found.', self::ERROR_THREAD_NOT_FOUND, 404);
	}

	private static function commentNotFound(): Result
	{
		return self::fail('Comment was not found.', self::ERROR_COMMENT_NOT_FOUND, 404);
	}

	private static function forbidden(string $message): Result
	{
		return self::fail($message, self::ERROR_FORBIDDEN, 403);
	}

	private static function boardNotAvailable(): Result
	{
		return self::forbidden('Board is not available.');
	}

	private static function storageUnavailable(): Result
	{
		return self::fail('Comment storage is temporarily unavailable.', self::ERROR_INTERNAL, 500);
	}

	private static function fail(string $message, string $code, int $status): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code, ['status' => $status]));

		return $result;
	}

	private function unexpected(\Throwable $exception): Result
	{
		$this->logException($exception);

		return self::fail('Internal comment storage error.', self::ERROR_INTERNAL, 500);
	}

	private function logException(\Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (\Throwable)
		{
		}
	}
}
