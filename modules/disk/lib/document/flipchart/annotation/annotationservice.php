<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Annotation;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Flipchart\Comment\BoardLock;
use Bitrix\Disk\File;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class AnnotationService
{
	private const ACTIVE_ANNOTATION_LIMIT = 5;
	private const LOCK_TIMEOUT = 15;
	private const LOCK_PREFIX = "disk:flipchart:annotations\0";
	private const CURSOR_VERSION = 1;
	private const DELETE_REASON_MANUAL = 'manual';
	private const DELETE_REASON_BOARD_DELETED = 'board_deleted';
	private const DELETE_REASON_PARENT_CASCADE = 'parent_cascade';

	private const ERROR_ANNOTATION_NOT_FOUND = 'annotation_not_found';
	private const ERROR_LIMIT_EXCEEDED = 'annotation_limit_exceeded';
	private const ERROR_PAYLOAD_CONFLICT = 'annotation_payload_conflict';
	private const ERROR_RESTORE_REQUIRED = 'annotation_restore_required';
	private const ERROR_RESTORE_CONFLICT = 'annotation_restore_conflict';
	private const ERROR_INVALID_ARGUMENT = 'invalid_argument';
	private const ERROR_FORBIDDEN = 'forbidden';
	private const ERROR_INTERNAL = 'internal_error';

	private const GUEST_NAME_MESSAGE = 'DISK_FLIPCHART_ANNOTATION_GUEST_AUTHOR_NAME';
	private const UNKNOWN_NAME_MESSAGE = 'DISK_FLIPCHART_ANNOTATION_UNKNOWN_AUTHOR_NAME';
	private const GUEST_NAME_FALLBACK = 'Guest';
	private const UNKNOWN_NAME_FALLBACK = 'Unknown user';

	private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
	private const REGISTERED_USER_ID_PATTERN = '/^[1-9][0-9]{0,18}$/D';

	private const ROW_FIELDS = [
		'ID',
		'DOCUMENT_ID',
		'OBJECT_ID',
		'VERSION_ID',
		'ANNOTATION_ID',
		'PARENT_ID',
		'EDGE',
		'POSITION_T',
		'CONTENT',
		'AUTHOR_ID',
		'CREATED_AT',
		'UPDATED_AT',
		'DELETED_AT',
		'DELETE_REASON',
	];

	public static function deleteBoard(int $objectId): void
	{
		if (!BoardLock::acquire($objectId))
		{
			throw new SystemException('Could not lock the board to delete its annotations.');
		}

		try
		{
			$connection = Application::getConnection();
			$deletedAt = $connection->getSqlHelper()->convertToDbDateTime(new DateTime());
			$connection->queryExecute(new SqlExpression(
				"UPDATE b_disk_flipchart_annotation SET DELETED_AT = {$deletedAt}, DELETE_REASON = ?s"
				. ' WHERE OBJECT_ID = ?i AND DELETED_AT IS NULL',
				self::DELETE_REASON_BOARD_DELETED,
				$objectId,
			));
		}
		finally
		{
			BoardLock::release($objectId);
		}
	}

	/**
	 * Returns one page of active annotations in stable creation order.
	 *
	 * @param array{documentId: string, limit: int, cursor: ?string, parentIds: list<string>} $input
	 */
	public function list(AccessContext $context, array $input): Result
	{
		try
		{
			$query = AnnotationTable::query()
				->setSelect(self::ROW_FIELDS)
				->where('DOCUMENT_ID', $context->getDocumentId())
				->whereNull('DELETED_AT')
				->setOrder([
					'CREATED_AT' => 'ASC',
					'ANNOTATION_ID' => 'ASC',
				])
				->setLimit($input[RequestValidator::FIELD_LIMIT] + 1)
			;

			$parentIds = $input[RequestValidator::FIELD_PARENT_IDS];
			if ($parentIds !== [])
			{
				$query->whereIn('PARENT_ID', $parentIds);
			}

			$cursorValue = $input[RequestValidator::FIELD_CURSOR];
			if ($cursorValue !== null)
			{
				$cursor = self::decodeCursor($cursorValue);
				if ($cursor === null)
				{
					return self::fail('Cursor is not valid.', self::ERROR_INVALID_ARGUMENT, 400);
				}

				$afterCursor = Query::filter()
					->logic('or')
					->where('CREATED_AT', '>', $cursor['createdAt'])
					->where(
						Query::filter()
							->where('CREATED_AT', $cursor['createdAt'])
							->where('ANNOTATION_ID', '>', $cursor['annotationId']),
					)
				;
				$query->where($afterCursor);
			}

			$rows = $query->fetchAll();
			$hasNextPage = count($rows) > $input[RequestValidator::FIELD_LIMIT];
			if ($hasNextPage)
			{
				array_pop($rows);
			}

			$nextCursor = null;
			if ($hasNextPage && $rows !== [])
			{
				$nextCursor = self::encodeCursor($rows[array_key_last($rows)]);
			}

			return self::succeed([
				'items' => $this->formatRows($rows, $context),
				'nextCursor' => $nextCursor,
			]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Counts active annotations attached to one board element.
	 *
	 * @param array{documentId: string, parentId: string} $input
	 */
	public function count(AccessContext $context, array $input): Result
	{
		try
		{
			$count = (int)AnnotationTable::query()
				->where('DOCUMENT_ID', $context->getDocumentId())
				->where('PARENT_ID', $input[RequestValidator::FIELD_PARENT_ID])
				->whereNull('DELETED_AT')
				->queryCountTotal()
			;

			return self::succeed(['count' => $count]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Returns an addressed annotation, including a softly deleted one.
	 *
	 * @param array{annotationId: string} $input
	 */
	public function get(AccessContext $context, array $input): Result
	{
		try
		{
			$row = $this->findAnnotation(
				$context->getDocumentId(),
				$input[RequestValidator::FIELD_ANNOTATION_ID],
			);
			if ($row === null)
			{
				return self::annotationNotFound();
			}

			return self::succeed($this->formatRows([$row], $context)[0]);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * Creates an annotation or returns an unchanged idempotent match.
	 *
	 * @param array{
	 *     annotationId: string,
	 *     documentId: string,
	 *     parentId: string,
	 *     position: array{edge: string, t: float},
	 *     content: string
	 * } $input
	 */
	public function put(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden();
		}

		$documentId = $context->getDocumentId();

		$result = $this->mutateBoard(
			$context,
			function () use ($context, $input, $documentId): Result {
				$annotationId = $input[RequestValidator::FIELD_ANNOTATION_ID];
				$row = $this->findAnnotation($documentId, $annotationId);
				if ($row !== null)
				{
					return $this->resolveExistingPut($row, $input);
				}

				$activeCount = AnnotationTable::query()
					->where('DOCUMENT_ID', $documentId)
					->where('PARENT_ID', $input[RequestValidator::FIELD_PARENT_ID])
					->whereNull('DELETED_AT')
					->queryCountTotal()
				;
				if ($activeCount >= self::ACTIVE_ANNOTATION_LIMIT)
				{
					return self::fail(
						'Annotation limit per element exceeded.',
						self::ERROR_LIMIT_EXCEEDED,
						409,
					);
				}

				$position = $input[RequestValidator::FIELD_POSITION];
				$addResult = AnnotationTable::add([
					'DOCUMENT_ID' => $documentId,
					'OBJECT_ID' => $context->getObjectId(),
					'VERSION_ID' => $context->getVersionId(),
					'ANNOTATION_ID' => $annotationId,
					'PARENT_ID' => $input[RequestValidator::FIELD_PARENT_ID],
					'EDGE' => $position[RequestValidator::FIELD_EDGE],
					'POSITION_T' => self::positionSql($position[RequestValidator::FIELD_T]),
					'CONTENT' => $input[RequestValidator::FIELD_CONTENT],
					'AUTHOR_ID' => $context->getUserId(),
					'CREATED_AT' => new DateTime(),
				]);
				if (!$addResult->isSuccess())
				{
					$row = $this->findAnnotation($documentId, $annotationId);
					if ($row !== null)
					{
						return $this->resolveExistingPut($row, $input);
					}

					throw new SystemException('Could not create an annotation.');
				}

				$row = $this->findAnnotation($documentId, $annotationId);
				if ($row === null)
				{
					throw new SystemException('Created annotation could not be read.');
				}

				return self::mutationSucceeded($row, 201);
			},
		);

		return $this->formatMutationResult($result, $context);
	}

	/**
	 * Applies a last-write-wins update to an active annotation.
	 *
	 * @param array{
	 *     annotationId: string,
	 *     content?: string,
	 *     position?: array{edge: string, t: float}
	 * } $input
	 */
	public function patch(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden();
		}

		$documentId = $context->getDocumentId();

		$result = $this->mutateBoard(
			$context,
			function () use ($context, $input, $documentId): Result {
				$row = $this->findAnnotation(
					$documentId,
					$input[RequestValidator::FIELD_ANNOTATION_ID],
				);
				if ($row === null || $row['DELETED_AT'] !== null)
				{
					return self::annotationNotFound();
				}

				$hasContent = array_key_exists(RequestValidator::FIELD_CONTENT, $input);
				if ($hasContent && $row['AUTHOR_ID'] !== $context->getUserId())
				{
					return self::fail(
						'Only the author can edit annotation content.',
						self::ERROR_FORBIDDEN,
						403,
					);
				}

				$fields = ['UPDATED_AT' => new DateTime()];
				if ($hasContent)
				{
					$fields['CONTENT'] = $input[RequestValidator::FIELD_CONTENT];
				}
				if (array_key_exists(RequestValidator::FIELD_POSITION, $input))
				{
					$position = $input[RequestValidator::FIELD_POSITION];
					$fields['EDGE'] = $position[RequestValidator::FIELD_EDGE];
					$fields['POSITION_T'] = self::positionSql($position[RequestValidator::FIELD_T]);
				}

				$updateResult = AnnotationTable::update((int)$row['ID'], $fields);
				if (!$updateResult->isSuccess())
				{
					throw new SystemException('Could not update an annotation.');
				}

				$row = $this->findAnnotation(
					$documentId,
					$input[RequestValidator::FIELD_ANNOTATION_ID],
				);
				if ($row === null)
				{
					throw new SystemException('Updated annotation could not be read.');
				}

				return self::mutationSucceeded($row);
			},
			true,
		);

		return $this->formatMutationResult($result, $context);
	}

	/**
	 * @param array{documentId: string, annotationIds?: list<string>, parentIds?: list<string>} $input
	 */
	public function delete(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden();
		}

		$documentId = $context->getDocumentId();

		return $this->mutateBoard(
			$context,
			function () use ($context, $input, $documentId): Result {
				$byAnnotationIds = isset($input[RequestValidator::FIELD_ANNOTATION_IDS]);
				$rows = $this->findBatchRows($documentId, $input, false);

				if ($byAnnotationIds)
				{
					foreach ($rows as $row)
					{
						if ((string)$row['AUTHOR_ID'] !== $context->getUserId())
						{
							return self::fail(
								'Only the author can delete an annotation directly.',
								self::ERROR_FORBIDDEN,
								403,
							);
						}
					}
				}

				$deletedAt = new DateTime();
				$reason = $byAnnotationIds
					? self::DELETE_REASON_MANUAL
					: self::DELETE_REASON_PARENT_CASCADE;
				if ($rows !== [])
				{
					$updateResult = AnnotationTable::updateMulti(array_column($rows, 'ID'), [
						'DELETED_AT' => $deletedAt,
						'DELETE_REASON' => $reason,
					]);
					if (!$updateResult->isSuccess())
					{
						throw new SystemException('Could not delete annotations.');
					}
				}

				return self::succeed([
					'deletedIds' => array_values(array_unique(array_column($rows, 'ANNOTATION_ID'))),
				]);
			},
		);
	}

	/**
	 * @param array{documentId: string, annotationIds?: list<string>, parentIds?: list<string>} $input
	 */
	public function restore(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::forbidden();
		}

		$documentId = $context->getDocumentId();

		return $this->mutateBoard(
			$context,
			function () use ($context, $input, $documentId): Result {
				$byAnnotationIds = isset($input[RequestValidator::FIELD_ANNOTATION_IDS]);
				$rows = $this->findBatchRows($documentId, $input, true);

				if ($byAnnotationIds)
				{
					foreach ($rows as $row)
					{
						if (
							$row['DELETE_REASON'] === self::DELETE_REASON_MANUAL
							&& (string)$row['AUTHOR_ID'] !== $context->getUserId()
						)
						{
							return self::fail(
								'Only the author can restore a manually deleted annotation.',
								self::ERROR_FORBIDDEN,
								403,
							);
						}
					}

					foreach ($rows as $row)
					{
						if ($row['DELETE_REASON'] === self::DELETE_REASON_PARENT_CASCADE)
						{
							return self::fail(
								'Cascade-deleted annotations must be restored by parentIds.',
								self::ERROR_RESTORE_CONFLICT,
								409,
							);
						}
					}
				}

				$candidates = array_values(array_filter(
					$rows,
					static fn(array $row): bool => $row['DELETE_REASON'] === (
						$byAnnotationIds ? self::DELETE_REASON_MANUAL : self::DELETE_REASON_PARENT_CASCADE
					),
				));
				if ($candidates === [])
				{
					return self::succeed(['restoredIds' => []]);
				}

				$candidateCount = [];
				foreach ($candidates as $row)
				{
					$parentId = (string)$row['PARENT_ID'];
					$candidateCount[$parentId] = ($candidateCount[$parentId] ?? 0) + 1;
				}

				$activeCount = array_fill_keys(array_keys($candidateCount), 0);
				$activeRows = AnnotationTable::query()
					->setSelect(['PARENT_ID'])
					->where('DOCUMENT_ID', $documentId)
					->whereIn('PARENT_ID', array_keys($candidateCount))
					->whereNull('DELETED_AT')
					->fetchAll()
				;
				foreach ($activeRows as $row)
				{
					$parentId = (string)$row['PARENT_ID'];
					$activeCount[$parentId]++;
				}

				$parentOrder = $input[RequestValidator::FIELD_PARENT_IDS] ?? array_keys($candidateCount);
				if ($byAnnotationIds)
				{
					sort($parentOrder, SORT_STRING);
				}
				$conflictingParentIds = [];
				foreach ($parentOrder as $parentId)
				{
					if (
						isset($candidateCount[$parentId])
						&& $activeCount[$parentId] + $candidateCount[$parentId] > self::ACTIVE_ANNOTATION_LIMIT
					)
					{
						$conflictingParentIds[] = $parentId;
					}
				}
				if ($conflictingParentIds !== [])
				{
					return self::fail(
						'Annotation limit per element exceeded.',
						self::ERROR_LIMIT_EXCEEDED,
						409,
						$conflictingParentIds,
					);
				}

				$updateResult = AnnotationTable::updateMulti(array_column($candidates, 'ID'), [
					'DELETED_AT' => null,
					'DELETE_REASON' => null,
				]);
				if (!$updateResult->isSuccess())
				{
					throw new SystemException('Could not restore annotations.');
				}

				return self::succeed([
					'restoredIds' => array_values(array_unique(array_column($candidates, 'ANNOTATION_ID'))),
				]);
			},
		);
	}

	/**
	 * @param array{annotationIds?: list<string>, parentIds?: list<string>} $input
	 * @return list<array<string, mixed>>
	 */
	private function findBatchRows(string $documentId, array $input, bool $deleted): array
	{
		$query = AnnotationTable::query()
			->setSelect(['ID', 'ANNOTATION_ID', 'PARENT_ID', 'AUTHOR_ID', 'DELETE_REASON'])
			->where('DOCUMENT_ID', $documentId)
		;
		if ($deleted)
		{
			$query->whereNotNull('DELETED_AT');
		}
		else
		{
			$query->whereNull('DELETED_AT');
		}

		if (isset($input[RequestValidator::FIELD_ANNOTATION_IDS]))
		{
			$query->whereIn('ANNOTATION_ID', $input[RequestValidator::FIELD_ANNOTATION_IDS]);
		}
		else
		{
			$query->whereIn('PARENT_ID', $input[RequestValidator::FIELD_PARENT_IDS]);
			if ($deleted)
			{
				// Restoring by parentId only ever brings back a cascade-deleted row; a manually
				// deleted sibling must stay out of the read, not just out of the result.
				$query->where('DELETE_REASON', self::DELETE_REASON_PARENT_CASCADE);
			}
		}

		return $query->fetchAll();
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $input
	 */
	private function resolveExistingPut(array $row, array $input): Result
	{
		if ($row['DELETED_AT'] !== null)
		{
			return self::fail(
				'Annotation has been deleted and must be restored explicitly.',
				self::ERROR_RESTORE_REQUIRED,
				409,
			);
		}

		$position = $input[RequestValidator::FIELD_POSITION];
		$isSamePayload
			= $row['DOCUMENT_ID'] === $input[RequestValidator::FIELD_DOCUMENT_ID]
			&& $row['PARENT_ID'] === $input[RequestValidator::FIELD_PARENT_ID]
			&& $row['EDGE'] === $position[RequestValidator::FIELD_EDGE]
			&& (float)$row['POSITION_T'] === $position[RequestValidator::FIELD_T]
			&& $row['CONTENT'] === $input[RequestValidator::FIELD_CONTENT];
		if (!$isSamePayload)
		{
			return self::fail(
				'Existing annotation has a different payload.',
				self::ERROR_PAYLOAD_CONFLICT,
				409,
			);
		}

		return self::mutationSucceeded($row);
	}

	private function formatMutationResult(Result $result, AccessContext $context): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		try
		{
			$data = $result->getData();

			return self::succeed(
				$this->formatRows([$data['row']], $context)[0],
				$data['status'],
			);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
	}

	/**
	 * @param callable(): Result $mutation
	 */
	private function mutateBoard(AccessContext $context, callable $mutation, bool $isAddressed = false): Result
	{
		$objectId = $context->getObjectId();
		$locked = false;
		try
		{
			if ($objectId !== null)
			{
				$locked = BoardLock::acquire($objectId);
				if (!$locked)
				{
					return self::fail('Annotation storage is temporarily unavailable.', self::ERROR_INTERNAL, 500);
				}
			}

			$file = $objectId === null ? null : File::loadById($objectId);
			if ($file === null || $file->isDeleted())
			{
				return $isAddressed
					? self::annotationNotFound()
					: self::fail('Document was not found.', 'document_not_found', 404);
			}

			return $this->mutateDocument($context->getDocumentId(), $mutation);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}
		finally
		{
			if ($locked)
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
		}
	}

	/**
	 * @param callable(): Result $mutation
	 */
	private function mutateDocument(string $documentId, callable $mutation): Result
	{
		$lockName = hash('sha256', self::LOCK_PREFIX . $documentId);

		try
		{
			$connection = Application::getConnection();
			$locked = $connection->lock($lockName, self::LOCK_TIMEOUT);
		}
		catch (\Throwable $exception)
		{
			return $this->unexpected($exception);
		}

		if (!$locked)
		{
			return self::fail('Annotation storage is temporarily unavailable.', self::ERROR_INTERNAL, 500);
		}

		try
		{
			$connection->startTransaction();
			$result = $mutation();
			if (!$result->isSuccess())
			{
				if (!$this->rollback($connection))
				{
					return self::fail('Could not roll back annotation changes.', self::ERROR_INTERNAL, 500);
				}

				return $result;
			}

			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);

			return $this->unexpected($exception);
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
	}

	private function rollback(Connection $connection): bool
	{
		try
		{
			$connection->rollbackTransaction();

			return true;
		}
		catch (TransactionException)
		{
			return true;
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);

			return false;
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findAnnotation(string $documentId, string $annotationId): ?array
	{
		$row = AnnotationTable::query()
			->setSelect(self::ROW_FIELDS)
			->where('DOCUMENT_ID', $documentId)
			->where('ANNOTATION_ID', $annotationId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function formatRows(array $rows, AccessContext $context): array
	{
		$authors = $this->loadAuthors($rows);
		$annotations = [];

		foreach ($rows as $row)
		{
			$authorId = (string)$row['AUTHOR_ID'];
			$isActive = $row['DELETED_AT'] === null;
			$isAuthor = $authorId === $context->getUserId();
			$canWrite = $context->canWrite();
			$author = $authors[$authorId] ?? null;
			$isRegisteredAuthor = self::isRegisteredUserId($authorId) && !($author['isAnonymous'] ?? false);

			$annotations[] = [
				'id' => (string)$row['ANNOTATION_ID'],
				'documentId' => (string)$row['DOCUMENT_ID'],
				'parentId' => (string)$row['PARENT_ID'],
				'position' => [
					'edge' => (string)$row['EDGE'],
					't' => (float)$row['POSITION_T'],
				],
				'content' => (string)$row['CONTENT'],
				'author' => [
					'id' => $authorId,
					'name' => $author['name'] ?? self::fallbackAuthorName($isRegisteredAuthor),
					'avatar' => $author['avatar'] ?? null,
					'isAnonymous' => !$isRegisteredAuthor,
				],
				'permissions' => [
					'canEditContent' => $isActive && $canWrite && $isAuthor,
					'canMove' => $isActive && $canWrite,
					'canDelete' => $isActive && $canWrite && $isAuthor,
					'canRestore' => !$isActive
						&& $canWrite
						&& $isAuthor
						&& $row['DELETE_REASON'] === self::DELETE_REASON_MANUAL
					,
				],
				'createdAt' => self::formatDate($row['CREATED_AT']),
				'updatedAt' => self::formatNullableDate($row['UPDATED_AT']),
				'deletedAt' => self::formatNullableDate($row['DELETED_AT']),
			];
		}

		return $annotations;
	}

	/**
	 * The temporary user a public link signs an anonymous editor in as is shown as a guest: its row
	 * in the user table is not a person of the portal.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, array{name: string, avatar: ?string, isAnonymous: bool}>
	 */
	private function loadAuthors(array $rows): array
	{
		$userIds = [];
		foreach ($rows as $row)
		{
			$authorId = (string)$row['AUTHOR_ID'];
			if (self::isRegisteredUserId($authorId))
			{
				$userIds[(int)$authorId] = true;
			}
		}

		if ($userIds === [])
		{
			return [];
		}

		$userRows = UserTable::query()
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
			->whereIn('ID', array_keys($userIds))
			->fetchAll()
		;

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

		$files = FileTable::query()
			->setSelect(['*'])
			->whereIn('ID', $photoIds)
			->fetchAll()
		;

		$avatars = [];
		foreach ($files as $file)
		{
			$avatar = self::sanitizeAvatar((string)\CFile::getFileSRC($file));
			if ($avatar !== null)
			{
				$avatars[(int)$file['ID']] = $avatar;
			}
		}

		return $avatars;
	}

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
	 * 17 significant digits restore any binary64 exactly; unlike json_encode this does not depend on
	 * the `serialize_precision` setting of the portal, and `%H` does not depend on the locale either.
	 */
	private static function positionSql(float $position): SqlExpression
	{
		return new SqlExpression(sprintf('%.17H', $position));
	}

	private static function fallbackAuthorName(bool $isRegisteredUser): string
	{
		$messageCode = $isRegisteredUser ? self::UNKNOWN_NAME_MESSAGE : self::GUEST_NAME_MESSAGE;
		$fallback = $isRegisteredUser ? self::UNKNOWN_NAME_FALLBACK : self::GUEST_NAME_FALLBACK;
		$name = Loc::getMessage($messageCode);

		return is_string($name) && $name !== '' ? $name : $fallback;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function encodeCursor(array $row): string
	{
		$createdAt = $row['CREATED_AT'];
		if (!$createdAt instanceof DateTime)
		{
			throw new SystemException('Annotation creation time has an invalid type.');
		}

		$json = Json::encode([
			'version' => self::CURSOR_VERSION,
			'createdAt' => $createdAt->getTimestamp(),
			'annotationId' => (string)$row['ANNOTATION_ID'],
		]);

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * @return array{createdAt: DateTime, annotationId: string}|null
	 */
	private static function decodeCursor(string $cursor): ?array
	{
		if (strlen($cursor) % 4 === 1)
		{
			return null;
		}

		$padding = (4 - strlen($cursor) % 4) % 4;
		$json = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
		if ($json === false)
		{
			return null;
		}

		try
		{
			$data = Json::decode($json);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (
			!is_array($data)
			|| count($data) !== 3
			|| ($data['version'] ?? null) !== self::CURSOR_VERSION
			|| !is_int($data['createdAt'] ?? null)
			|| $data['createdAt'] <= 0
			|| !is_string($data['annotationId'] ?? null)
			|| preg_match(self::UUID_V4_PATTERN, $data['annotationId']) !== 1
		)
		{
			return null;
		}

		return [
			'createdAt' => DateTime::createFromTimestamp($data['createdAt']),
			'annotationId' => $data['annotationId'],
		];
	}

	private static function formatDate(mixed $date): string
	{
		if (!$date instanceof DateTime)
		{
			throw new SystemException('Annotation date has an invalid type.');
		}

		return gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
	}

	private static function formatNullableDate(mixed $date): ?string
	{
		return $date === null ? null : self::formatDate($date);
	}

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
	 * @param array<string, mixed> $row
	 */
	private static function mutationSucceeded(array $row, int $status = 200): Result
	{
		$result = new Result();
		$result->setData([
			'row' => $row,
			'status' => $status,
		]);

		return $result;
	}

	private static function annotationNotFound(): Result
	{
		return self::fail('Annotation was not found.', self::ERROR_ANNOTATION_NOT_FOUND, 404);
	}

	private static function forbidden(): Result
	{
		return self::fail('Annotations access is not sufficient.', self::ERROR_FORBIDDEN, 403);
	}

	/** @param list<string>|null $conflictingParentIds */
	private static function fail(
		string $message,
		string $code,
		int $status,
		?array $conflictingParentIds = null,
	): Result
	{
		$result = new Result();
		$customData = ['status' => $status];
		if ($conflictingParentIds !== null)
		{
			$customData['details'] = ['conflictingParentIds' => $conflictingParentIds];
		}
		$result->addError(new Error($message, $code, $customData));

		return $result;
	}

	private function unexpected(\Throwable $exception): Result
	{
		$this->logException($exception);

		return self::fail('Internal annotation storage error.', self::ERROR_INTERNAL, 500);
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
