<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Mail\Internal\Entity\Draft\DraftAttachmentFingerprint;
use Bitrix\Mail\Internal\Entity\Draft\DraftPage;
use Bitrix\Mail\Internal\Entity\Draft\DraftSnapshot;
use Bitrix\Mail\Internal\Entity\Draft\DraftView;
use Bitrix\Mail\Internal\Service\DateTime\UtcDateTimeFormatter;
use Bitrix\Mail\Internals\DraftAttachmentTable;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class DraftRepository
{
	public const RETENTION_DAYS = 30;
	public const SAVE_FAILURE_CONFLICT = 'conflict';
	public const SAVE_FAILURE_TECHNICAL = 'technical';

	private bool $lastSaveWasIdempotent = false;
	private ?string $lastSaveFailure = null;

	public function findActiveById(int $userId, int $draftId, ?string $contextType = null): ?DraftView
	{
		$query = DraftTable::query()
			->setSelect(['*'])
			->where('ID', $draftId)
			->where('USER_ID', $userId)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
		;
		if ($contextType !== null)
		{
			$query->where('CONTEXT_TYPE', $contextType);
		}
		$row = $query->fetch();

		return $row !== false ? DraftView::fromRow($row, $this->loadAttachments($draftId)) : null;
	}

	public function findActiveByCrmContext(int $userId, int $entityTypeId, int $entityId): ?DraftView
	{
		$row = $this->findActiveCrmRow($userId, $entityTypeId, $entityId);

		return $row !== null ? DraftView::fromRow($row, $this->loadAttachments((int)$row['ID'])) : null;
	}

	/**
	 * Identifiers of the attachment copies the claimed draft owns, in the order of the draft, or an empty
	 * list when the claim does not hold. The whole claim is one statement: the same owner, an active and
	 * unexpired draft of the claimed context, the revision the send was built on and, when reported, the
	 * compose form and the business context of the draft.
	 *
	 * The claim and the bindings it releases are read by the same join, never one after the other. Read
	 * apart, a save landing in between passes the claim on the revision the send was built on and then
	 * hands out the bindings the save has just replaced, so the send would attach the files of a revision
	 * it never showed and disclose them to its own recipients.
	 *
	 * The send needs four identifiers and nothing else: the stored copy, the file it was copied from,
	 * which a restored body still references in bxacid links, its Disk object, by which a restored body
	 * references an inline image, and the source object the copy was made of, by which a consumer binds a
	 * copy to the converted set it belongs to. The read model of the form, its links and previews are not
	 * built.
	 *
	 * @return list<array{fileId: int, sourceFileId: int, sourceObjectId: int, objectId: int}>
	 */
	public function findClaimedAttachments(
		int $userId,
		int $draftId,
		string $contextType,
		int $expectedRevision,
		?string $expectedClientId = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
	): array
	{
		if ($userId <= 0 || $draftId <= 0 || $expectedRevision <= 0)
		{
			return [];
		}
		$storage = Storage::getStorage();
		if ($storage === false)
		{
			return [];
		}

		$query = DraftAttachmentTable::query()
			->setSelect(['FILE_ID', 'SOURCE_FILE_ID', 'SOURCE_OBJECT_ID', 'OBJECT_ID' => 'AVAILABLE_OBJECT.ID'])
			->registerRuntimeField(
				new Reference(
					'CLAIMED_DRAFT',
					DraftTable::class,
					Join::on('this.DRAFT_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->registerRuntimeField(
				new Reference(
					'AVAILABLE_OBJECT',
					ObjectTable::class,
					Join::on('this.FILE_ID', 'ref.FILE_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->where('DRAFT_ID', $draftId)
			->where('CLAIMED_DRAFT.USER_ID', $userId)
			->where('CLAIMED_DRAFT.CONTEXT_TYPE', $contextType)
			->where('CLAIMED_DRAFT.STATUS', DraftTable::STATUS_ACTIVE)
			->where('CLAIMED_DRAFT.DATE_EXPIRE', '>', new DateTime())
			->where('CLAIMED_DRAFT.REVISION', $expectedRevision)
			->where('AVAILABLE_OBJECT.STORAGE_ID', $storage->getId())
			->where('AVAILABLE_OBJECT.TYPE', ObjectTable::TYPE_FILE)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
		;
		if ($expectedClientId !== null && $expectedClientId !== '')
		{
			$query->where('CLAIMED_DRAFT.CLIENT_ID', $expectedClientId);
		}
		if ($crmEntityTypeId !== null)
		{
			$query
				->where('CLAIMED_DRAFT.CRM_ENTITY_TYPE_ID', $crmEntityTypeId)
				->where('CLAIMED_DRAFT.CRM_ENTITY_ID', $crmEntityId)
			;
		}

		$attachments = [];
		foreach ($query->fetchAll() as $row)
		{
			$fileId = (int)$row['FILE_ID'];
			if (isset($attachments[$fileId]))
			{
				continue;
			}

			$attachments[$fileId] = [
				'fileId' => $fileId,
				'sourceFileId' => (int)$row['SOURCE_FILE_ID'],
				'sourceObjectId' => (int)($row['SOURCE_OBJECT_ID'] ?? 0),
				'objectId' => (int)$row['OBJECT_ID'],
			];
		}

		return array_values($attachments);
	}

	public function save(
		int $userId,
		string $contextType,
		?int $draftId,
		?int $expectedRevision,
		DraftSnapshot $snapshot,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
		array $attachments = [],
		bool $manageTransaction = true,
	): ?DraftView
	{
		$this->lastSaveWasIdempotent = false;
		$this->lastSaveFailure = null;
		$isCreate = $draftId === null && $expectedRevision === null;
		$isUpdate = ($draftId ?? 0) > 0 && ($expectedRevision ?? 0) > 0;
		if (!$isCreate && !$isUpdate)
		{
			$this->lastSaveFailure = self::SAVE_FAILURE_TECHNICAL;

			return null;
		}

		$now = new DateTime();
		$expiresAt = (clone $now)->add(sprintf('%d days', self::RETENTION_DAYS));
		$fields = $this->snapshotToFields($snapshot) + [
			'USER_ID' => $userId,
			'CONTEXT_TYPE' => $contextType,
			'CRM_ENTITY_TYPE_ID' => $crmEntityTypeId,
			'CRM_ENTITY_ID' => $crmEntityId,
			'DATE_MODIFY' => $now,
			'DATE_EXPIRE' => $expiresAt,
		];

		$connection = Application::getConnection();
		if ($manageTransaction)
		{
			$connection->startTransaction();
		}
		try
		{
			if ($draftId === null)
			{
				$this->releaseExpiredSlots(
					$userId,
					$contextType,
					$snapshot->clientId,
					$crmEntityTypeId,
					$crmEntityId,
					$now,
				);
				$existing = $this->findByAttempt($userId, $contextType, $snapshot->clientId);
				if ($existing !== null)
				{
					if (
						$contextType === DraftTable::CONTEXT_CRM
						&& (
							(int)$existing['CRM_ENTITY_TYPE_ID'] !== (int)$crmEntityTypeId
							|| (int)$existing['CRM_ENTITY_ID'] !== (int)$crmEntityId
						)
					)
					{
						$this->lastSaveFailure = self::SAVE_FAILURE_CONFLICT;
						$this->rollbackIfOwned($connection, $manageTransaction);

						return null;
					}

					if (!$this->isSamePayload($existing, $snapshot, $attachments))
					{
						$draftId = (int)$existing['ID'];
						$expectedRevision = (int)$existing['REVISION'];
					}
					else
					{
						$this->lastSaveWasIdempotent = true;
						$view = $this->findActiveById($userId, (int)$existing['ID'], $contextType);
						if ($view === null)
						{
							$this->lastSaveFailure = self::SAVE_FAILURE_TECHNICAL;
							$this->rollbackIfOwned($connection, $manageTransaction);

							return null;
						}
						$this->commitIfOwned($connection, $manageTransaction);

						return $view;
					}
				}
				else
				{
					if (
						$contextType === DraftTable::CONTEXT_CRM
						&& $this->findActiveCrmRow($userId, (int)$crmEntityTypeId, (int)$crmEntityId) !== null
					)
					{
						$this->lastSaveFailure = self::SAVE_FAILURE_CONFLICT;
						$this->rollbackIfOwned($connection, $manageTransaction);

						return null;
					}

					$addResult = DraftTable::add($fields + ['REVISION' => 1]);
					if (!$addResult->isSuccess())
					{
						$this->lastSaveFailure
							= $contextType === DraftTable::CONTEXT_CRM
							&& $this->findActiveCrmRow(
								$userId,
								(int)$crmEntityTypeId,
								(int)$crmEntityId,
							) !== null
								? self::SAVE_FAILURE_CONFLICT
								: self::SAVE_FAILURE_TECHNICAL;
						$this->rollbackIfOwned($connection, $manageTransaction);

						return null;
					}
					$draftId = (int)$addResult->getId();
				}
			}

			if (
				$expectedRevision !== null
				&& !$this->compareAndSwap($userId, $contextType, $draftId, $expectedRevision, $fields)
			)
			{
				$this->lastSaveFailure = self::SAVE_FAILURE_CONFLICT;
				$this->rollbackIfOwned($connection, $manageTransaction);

				return null;
			}

			$this->replaceAttachments($draftId, $attachments);
			$view = $this->findActiveById($userId, $draftId);
			if ($view === null)
			{
				throw new \RuntimeException('Saved draft was not found.');
			}
			$this->commitIfOwned($connection, $manageTransaction);

			return $view;
		}
		catch (\Throwable)
		{
			$this->lastSaveFailure = self::SAVE_FAILURE_TECHNICAL;
			$this->rollbackIfOwned($connection, $manageTransaction);

			return null;
		}
	}

	public function wasLastSaveIdempotent(): bool
	{
		return $this->lastSaveWasIdempotent;
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

	public function getLastSaveFailure(): ?string
	{
		return $this->lastSaveFailure;
	}

	public function listMail(
		int $userId,
		int $page,
		int $pageSize,
		string $search = '',
		string $recipient = '',
		?bool $hasAttachments = null,
	): DraftPage
	{
		$page = max(1, $page);
		$pageSize = min(50, max(1, $pageSize));
		$search = trim($search);
		$recipient = mb_strtolower(trim($recipient));
		$filter = Query::filter()
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', DraftTable::CONTEXT_MAIL)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
		;
		if ($search !== '')
		{
			$searchPattern = '%' . self::escapeLikeLiteral($search) . '%';
			$filter->where(
				Query::filter()
					->logic('or')
					->whereLike('SUBJECT', $searchPattern)
					->whereLike('BODY', $searchPattern)
					->whereLike('RECIPIENTS_DATA', $searchPattern),
			);
		}
		if ($recipient !== '')
		{
			$filter->whereLike(
				'TO_RECIPIENTS_SEARCH',
				'%' . self::escapeLikeLiteral($recipient) . '%',
			);
		}
		if ($hasAttachments !== null)
		{
			$this->applyAvailableAttachmentFilter($filter, $hasAttachments);
		}

		$total = DraftTable::getCount($filter);
		$rows = DraftTable::query()
			->setSelect([
				'ID',
				'REVISION',
				'SENDER_DATA',
				'RECIPIENTS_DATA',
				'SUBJECT',
				'BODY_PREVIEW',
				'DATE_MODIFY',
			])
			->where($filter)
			->setOrder(['DATE_MODIFY' => 'DESC', 'ID' => 'DESC'])
			->setOffset(($page - 1) * $pageSize)
			->setLimit($pageSize)
			->fetchAll()
		;

		$items = [];
		$attachmentCountsByDraftId = $this->loadAvailableAttachmentCountsByDraftIds(array_column($rows, 'ID'));
		foreach ($rows as $row)
		{
			$items[] = $this->toListRow($row, $attachmentCountsByDraftId[(int)$row['ID']] ?? 0);
		}

		return new DraftPage($items, $total, $page, $pageSize);
	}

	/**
	 * Active mail drafts of the user, newest first, in the row model of {@see self::listMail()} and
	 * paired with the message a reply draft answers. A new or forwarded draft reports no parent, so
	 * a caller that groups by conversation keeps it as a row of its own.
	 *
	 * The bound is the keyset the grid orders by: drafts modified before the given moment, and of
	 * that very moment only those below the given identifier.
	 *
	 * @return list<array{view: array, parentMessageId: int, updatedAt: DateTime}>
	 */
	public function listActiveMailDrafts(
		int $userId,
		int $limit,
		?DateTime $modifiedBefore = null,
		int $idBefore = 0,
	): array
	{
		$query = $this->buildActiveMailDraftQuery($userId)
			->setOrder(['DATE_MODIFY' => 'DESC', 'ID' => 'DESC'])
			->setLimit(max(1, $limit))
		;
		if ($modifiedBefore !== null)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->where('DATE_MODIFY', '<', $modifiedBefore)
					->where(
						Query::filter()
							->where('DATE_MODIFY', $modifiedBefore)
							->where('ID', '<', $idBefore),
					),
			);
		}

		return $this->toActiveMailDraftRows($query->fetchAll());
	}

	/**
	 * The given active mail drafts of the user, in the row model of {@see self::listActiveMailDrafts()}
	 * and keyed by identifier. A draft of another owner, of another context or already gone is simply
	 * absent from the answer.
	 *
	 * @param int[] $draftIds
	 * @return array<int, array{view: array, parentMessageId: int, updatedAt: DateTime}>
	 */
	public function findActiveMailDraftsByIds(int $userId, array $draftIds): array
	{
		$draftIds = array_values(array_unique(array_map('intval', $draftIds)));
		if ($draftIds === [])
		{
			return [];
		}

		$drafts = [];
		$rows = $this->buildActiveMailDraftQuery($userId)->whereIn('ID', $draftIds)->fetchAll();
		foreach ($this->toActiveMailDraftRows($rows) as $draft)
		{
			$drafts[(int)$draft['view']['id']] = $draft;
		}

		return $drafts;
	}

	private function buildActiveMailDraftQuery(int $userId): Query
	{
		return DraftTable::query()
			->setSelect([
				'ID',
				'REVISION',
				'SENDER_DATA',
				'RECIPIENTS_DATA',
				'SUBJECT',
				'BODY_PREVIEW',
				'DATE_MODIFY',
				'COMPOSE_MODE',
				'PARENT_MESSAGE_ID',
			])
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', DraftTable::CONTEXT_MAIL)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
		;
	}

	/**
	 * @return list<array{view: array, parentMessageId: int, updatedAt: DateTime}>
	 */
	private function toActiveMailDraftRows(array $rows): array
	{
		$attachmentCountsByDraftId = $this->loadAvailableAttachmentCountsByDraftIds(array_column($rows, 'ID'));

		return array_map(
			fn(array $row): array => [
				'view' => $this->toListRow($row, $attachmentCountsByDraftId[(int)$row['ID']] ?? 0),
				'parentMessageId' => $row['COMPOSE_MODE'] === DraftTable::COMPOSE_MODE_REPLY
					? (int)$row['PARENT_MESSAGE_ID']
					: 0,
				'updatedAt' => $row['DATE_MODIFY'],
			],
			$rows,
		);
	}

	/** Row model of the drafts list. */
	private function toListRow(array $row, int $attachmentCount): array
	{
		$recipients = Json::decode((string)$row['RECIPIENTS_DATA']);

		return [
			'id' => (int)$row['ID'],
			'revision' => (int)$row['REVISION'],
			'sender' => $row['SENDER_DATA'] !== null ? Json::decode((string)$row['SENDER_DATA']) : null,
			'recipients' => array_merge(
				$recipients['to'] ?? [],
				$recipients['cc'] ?? [],
				$recipients['bcc'] ?? [],
			),
			'subject' => (string)($row['SUBJECT'] ?? ''),
			'bodyPreview' => (string)($row['BODY_PREVIEW'] ?? ''),
			'updatedAt' => UtcDateTimeFormatter::format($row['DATE_MODIFY']),
			'attachmentCount' => $attachmentCount,
		];
	}

	private function applyAvailableAttachmentFilter(ConditionTree $filter, bool $hasAttachments): void
	{
		$storage = Storage::getStorage();
		if ($storage === false)
		{
			if ($hasAttachments)
			{
				$filter->where('ID', 0);
			}

			return;
		}

		$attachmentQuery = DraftAttachmentTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				new Reference(
					'AVAILABLE_OBJECT',
					ObjectTable::class,
					Join::on('this.FILE_ID', 'ref.FILE_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->where('AVAILABLE_OBJECT.STORAGE_ID', $storage->getId())
			->where('AVAILABLE_OBJECT.TYPE', ObjectTable::TYPE_FILE)
			->whereExpr(
				'%s = ' . DraftTable::query()->getInitAlias() . '.ID',
				['DRAFT_ID'],
			)
		;

		if ($hasAttachments)
		{
			$filter->whereExists($attachmentQuery);
		}
		else
		{
			$filter->whereNotExists($attachmentQuery);
		}
	}

	/**
	 * A reported revision and compose form identity make this a compare-and-delete, the same statement
	 * that locks the row deciding it: a draft another form has written into since is newer work, and the
	 * caller that asked to drop the draft it was showing has not seen that work. Reported by nobody, the
	 * delete stays what it was: the owner drops a draft of his own by its identifier.
	 */
	public function delete(
		int $userId,
		int $draftId,
		?string $contextType = null,
		?int $expectedRevision = null,
		?string $expectedClientId = null,
	): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$locked = $this->lockOwnedActiveDraftIds(
				$userId,
				[$draftId],
				$contextType,
				$expectedRevision,
				$expectedClientId,
			);
			if ($locked === [])
			{
				$connection->rollbackTransaction();

				return false;
			}
			$attachments = $this->loadAttachmentRows($draftId);
			$attachmentFilter = Query::filter()->where('DRAFT_ID', $draftId);
			$connection->queryExecute(sprintf(
				'DELETE FROM %s WHERE %s',
				$connection->getSqlHelper()->quote(DraftAttachmentTable::getTableName()),
				Query::buildFilterSql(DraftAttachmentTable::getEntity(), $attachmentFilter),
			));
			if (!DraftTable::delete($draftId)->isSuccess())
			{
				throw new \RuntimeException('Draft aggregate was not deleted.');
			}
			$connection->commitTransaction();
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return false;
		}
		$this->unregisterAttachmentFiles($attachments);

		return true;
	}

	/**
	 * @param int[] $draftIds
	 *
	 * @return int[]
	 */
	public function deleteMany(int $userId, array $draftIds, ?string $contextType = null): array
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$lockedDraftIds = $this->lockOwnedActiveDraftIds($userId, $draftIds, $contextType);
			$lockedDraftIdMap = array_fill_keys($lockedDraftIds, true);
			$ownedDraftIds = array_values(array_filter(
				$draftIds,
				static fn(int $draftId): bool => isset($lockedDraftIdMap[$draftId]),
			));
			if ($ownedDraftIds === [])
			{
				$connection->rollbackTransaction();

				return [];
			}
			$attachments = DraftAttachmentTable::query()
				->setSelect(['FILE_ID'])
				->whereIn('DRAFT_ID', $ownedDraftIds)
				->fetchAll()
			;
			$draftFilter = Query::filter()->whereIn('ID', $ownedDraftIds);
			$attachmentFilter = Query::filter()->whereIn('DRAFT_ID', $ownedDraftIds);
			$connection->queryExecute(sprintf(
				'DELETE FROM %s WHERE %s',
				$connection->getSqlHelper()->quote(DraftAttachmentTable::getTableName()),
				Query::buildFilterSql(DraftAttachmentTable::getEntity(), $attachmentFilter),
			));
			$connection->queryExecute(sprintf(
				'DELETE FROM %s WHERE %s',
				$connection->getSqlHelper()->quote(DraftTable::getTableName()),
				Query::buildFilterSql(DraftTable::getEntity(), $draftFilter),
			));
			$connection->commitTransaction();
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return [];
		}

		$this->unregisterAttachmentFiles($attachments);

		return $ownedDraftIds;
	}

	/**
	 * @param int[] $draftIds
	 *
	 * @return int[]
	 */
	private function lockOwnedActiveDraftIds(
		int $userId,
		array $draftIds,
		?string $contextType,
		?int $expectedRevision = null,
		?string $expectedClientId = null,
	): array
	{
		$connection = Application::getConnection();
		$filter = Query::filter()
			->where('USER_ID', $userId)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
			->whereIn('ID', $draftIds)
		;
		if ($contextType !== null)
		{
			$filter->where('CONTEXT_TYPE', $contextType);
		}
		if ($expectedRevision !== null)
		{
			$filter->where('REVISION', $expectedRevision);
		}
		if ($expectedClientId !== null && $expectedClientId !== '')
		{
			$filter->where('CLIENT_ID', $expectedClientId);
		}
		$rows = $connection->query(sprintf(
			'SELECT ID FROM %s WHERE %s ORDER BY ID FOR UPDATE',
			$connection->getSqlHelper()->quote(DraftTable::getTableName()),
			Query::buildFilterSql(DraftTable::getEntity(), $filter),
		))->fetchAll();

		return array_map('intval', array_column($rows, 'ID'));
	}

	public function complete(
		int $userId,
		int $draftId,
		?string $contextType = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
		?int $expectedRevision = null,
		?string $expectedClientId = null,
	): bool
	{
		$connection = Application::getConnection();
		$entity = DraftTable::getEntity();
		$filter = Query::filter()
			->where('ID', $draftId)
			->where('USER_ID', $userId)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
		;
		if ($contextType !== null)
		{
			$filter->where('CONTEXT_TYPE', $contextType);
		}
		if ($expectedRevision !== null)
		{
			$filter->where('REVISION', $expectedRevision);
		}
		// A client that reports its compose form identity is completed only under it, in the same
		// statement that flips the status: a draft another attempt has taken over stays active.
		if ($expectedClientId !== null && $expectedClientId !== '')
		{
			$filter->where('CLIENT_ID', $expectedClientId);
		}
		if ($contextType === DraftTable::CONTEXT_CRM)
		{
			if (($crmEntityTypeId ?? 0) <= 0 || ($crmEntityId ?? 0) <= 0)
			{
				return false;
			}
			$filter
				->where('CRM_ENTITY_TYPE_ID', $crmEntityTypeId)
				->where('CRM_ENTITY_ID', $crmEntityId)
			;
		}
		// CLIENT_ID and CRM_ENTITY_* are released so a new compose attempt is not blocked
		// by the unique indexes while the completed row waits for physical cleanup.
		[$setSql] = $connection->getSqlHelper()->prepareUpdate(DraftTable::getTableName(), [
			'STATUS' => DraftTable::STATUS_COMPLETED,
			'DATE_COMPLETED' => new DateTime(),
			'CLIENT_ID' => null,
			'CRM_ENTITY_TYPE_ID' => null,
			'CRM_ENTITY_ID' => null,
		]);
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote(DraftTable::getTableName()),
			$setSql,
			Query::buildFilterSql($entity, $filter),
		));

		if ($connection->getAffectedRowsCount() > 0)
		{
			return true;
		}

		$existing = DraftTable::query()
			->setSelect(['ID', 'USER_ID', 'CONTEXT_TYPE', 'STATUS'])
			->where('ID', $draftId)
			->fetch()
		;

		return $existing === false
			|| (
				(int)$existing['USER_ID'] === $userId
				&& ($contextType === null || $existing['CONTEXT_TYPE'] === $contextType)
				&& $existing['STATUS'] === DraftTable::STATUS_COMPLETED
			);
	}

	public function deleteByUser(int $userId): void
	{
		$lastId = 0;
		do
		{
			$rows = DraftTable::query()
				->setSelect(['ID'])
				->where('USER_ID', $userId)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit(100)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$lastId = (int)$row['ID'];
				try
				{
					$this->deleteAggregate($lastId);
				}
				catch (\Throwable $exception)
				{
					// One stuck aggregate must not abort user deletion; the cleanup agent retries it.
					(new LoggerFactory())->createById('mail.Draft')?->error(
						'Draft aggregate was not deleted while removing user data.',
						[
							'userId' => $userId,
							'draftId' => $lastId,
							'error' => $exception->getMessage(),
						],
					);
				}
			}
		}
		while (count($rows) === 100);
	}

	public function deleteAggregate(int $draftId): void
	{
		$attachments = $this->loadAttachmentRows($draftId);
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			foreach ($attachments as $attachment)
			{
				$this->deleteBindingOrFail((int)$attachment['ID']);
			}
			if (!DraftTable::delete($draftId)->isSuccess())
			{
				throw new \RuntimeException('Draft aggregate was not deleted.');
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
		$this->unregisterAttachmentFiles($attachments);
	}

	private function compareAndSwap(
		int $userId,
		string $contextType,
		int $draftId,
		int $revision,
		array $fields,
	): bool
	{
		$connection = Application::getConnection();
		$entity = DraftTable::getEntity();
		$fields['REVISION'] = $revision + 1;
		[$setSql] = $connection->getSqlHelper()->prepareUpdate(DraftTable::getTableName(), $fields);
		$filter = Query::filter()
			->where('ID', $draftId)
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', $contextType)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('REVISION', $revision)
		;
		if ($contextType === DraftTable::CONTEXT_CRM)
		{
			$filter
				->where('CRM_ENTITY_TYPE_ID', $fields['CRM_ENTITY_TYPE_ID'])
				->where('CRM_ENTITY_ID', $fields['CRM_ENTITY_ID'])
			;
		}
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote(DraftTable::getTableName()),
			$setSql,
			Query::buildFilterSql($entity, $filter),
		));

		return $connection->getAffectedRowsCount() === 1;
	}

	private function findActiveCrmRow(int $userId, int $entityTypeId, int $entityId): ?array
	{
		$row = DraftTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', DraftTable::CONTEXT_CRM)
			->where('CRM_ENTITY_TYPE_ID', $entityTypeId)
			->where('CRM_ENTITY_ID', $entityId)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
			->fetch()
		;

		return $row !== false ? $row : null;
	}

	private function releaseExpiredSlots(
		int $userId,
		string $contextType,
		string $clientId,
		?int $crmEntityTypeId,
		?int $crmEntityId,
		DateTime $now,
	): void
	{
		$connection = Application::getConnection();
		$slotFilter = Query::filter()
			->logic('or')
			->where('CLIENT_ID', $clientId)
		;
		if (
			$contextType === DraftTable::CONTEXT_CRM
			&& ($crmEntityTypeId ?? 0) > 0
			&& ($crmEntityId ?? 0) > 0
		)
		{
			$slotFilter->where(
				Query::filter()
					->where('CRM_ENTITY_TYPE_ID', $crmEntityTypeId)
					->where('CRM_ENTITY_ID', $crmEntityId),
			);
		}
		$filter = Query::filter()
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', $contextType)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '<=', $now)
			->where($slotFilter)
		;
		[$setSql] = $connection->getSqlHelper()->prepareUpdate(DraftTable::getTableName(), [
			'STATUS' => DraftTable::STATUS_COMPLETED,
			'DATE_COMPLETED' => $now,
			'CLIENT_ID' => null,
			'CRM_ENTITY_TYPE_ID' => null,
			'CRM_ENTITY_ID' => null,
		]);
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote(DraftTable::getTableName()),
			$setSql,
			Query::buildFilterSql(DraftTable::getEntity(), $filter),
		));
	}

	private function findByAttempt(int $userId, string $contextType, string $clientId): ?array
	{
		$row = DraftTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', $contextType)
			->where('CLIENT_ID', $clientId)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->fetch()
		;

		return $row !== false ? $row : null;
	}

	private function isSamePayload(array $row, DraftSnapshot $snapshot, array $attachments): bool
	{
		foreach ($this->snapshotToFields($snapshot) as $field => $value)
		{
			if (($row[$field] ?? null) !== $value)
			{
				return false;
			}
		}

		$storedRows = $this->loadAttachmentRows((int)$row['ID']);
		if (count($storedRows) !== count($this->loadAttachments((int)$row['ID'])))
		{
			return false;
		}
		$storedAttachments = DraftAttachmentFingerprint::fromRows($storedRows);
		$incomingAttachments = DraftAttachmentFingerprint::fromRows($attachments);

		return $storedAttachments === $incomingAttachments;
	}

	private function loadAttachments(int $draftId): array
	{
		return $this->loadAttachmentsByDraftIds([$draftId])[$draftId] ?? [];
	}

	private function loadAvailableAttachmentCountsByDraftIds(array $draftIds): array
	{
		$draftIds = array_values(array_unique(array_map('intval', $draftIds)));
		if ($draftIds === [])
		{
			return [];
		}

		$countsByDraftId = array_fill_keys($draftIds, 0);
		$rows = DraftAttachmentTable::query()
			->setSelect(['DRAFT_ID', 'FILE_ID'])
			->whereIn('DRAFT_ID', $draftIds)
			->fetchAll()
		;
		if ($rows === [])
		{
			return $countsByDraftId;
		}

		$fileIds = array_values(array_unique(array_map(
			static fn(array $row): int => (int)$row['FILE_ID'],
			$rows,
		)));
		$availableFileIds = array_fill_keys(
			$this->filterAvailableFileIds($fileIds),
			true,
		);
		foreach ($rows as $row)
		{
			if (isset($availableFileIds[(int)$row['FILE_ID']]))
			{
				$countsByDraftId[(int)$row['DRAFT_ID']]++;
			}
		}

		return $countsByDraftId;
	}

	private function filterAvailableFileIds(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));
		if ($fileIds === [])
		{
			return [];
		}

		$storage = Storage::getStorage();
		if ($storage === false)
		{
			return [];
		}

		return array_map(
			'intval',
			array_column(
				ObjectTable::query()
					->setSelect(['FILE_ID'])
					->where('STORAGE_ID', $storage->getId())
					->where('TYPE', ObjectTable::TYPE_FILE)
					->whereIn('FILE_ID', $fileIds)
					->fetchAll(),
				'FILE_ID',
			),
		);
	}

	private function loadAttachmentsByDraftIds(array $draftIds): array
	{
		$draftIds = array_values(array_unique(array_map('intval', $draftIds)));
		if ($draftIds === [])
		{
			return [];
		}

		$attachmentsByDraftId = array_fill_keys($draftIds, []);
		$rows = DraftAttachmentTable::query()
			->setSelect(['*'])
			->whereIn('DRAFT_ID', $draftIds)
			->setOrder(['DRAFT_ID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
		if ($rows === [])
		{
			return $attachmentsByDraftId;
		}

		$storage = Storage::getStorage();
		if ($storage === false)
		{
			return $attachmentsByDraftId;
		}
		$fileIds = array_values(array_unique(array_map(
			static fn(array $row): int => (int)$row['FILE_ID'],
			$rows,
		)));
		$objectsByFileId = [];
		foreach (File::getModelList([
			'filter' => [
				'=STORAGE_ID' => $storage->getId(),
				'=TYPE' => ObjectTable::TYPE_FILE,
				'@FILE_ID' => $fileIds,
			],
		]) as $object)
		{
			$objectsByFileId[(int)$object->getFileId()] = $object;
		}

		// One read of the file rows for the whole set: the preview link of every attachment needs to know
		// whether the file is an image, and the number of attachments of a draft is not limited.
		$imageFlags = Storage::getImageFlagsByObjectId(array_values($objectsByFileId));

		foreach ($rows as $row)
		{
			$fileId = (int)$row['FILE_ID'];
			if (!isset($objectsByFileId[$fileId]))
			{
				continue;
			}
			$object = $objectsByFileId[$fileId];
			$objectId = (int)$object->getId();
			$row['OBJECT_ID'] = $objectId;
			$row = array_merge($row, DraftView::buildAttachmentLinks($object, $imageFlags[$objectId] ?? null));
			$attachmentsByDraftId[(int)$row['DRAFT_ID']][] = $row;
		}

		return $attachmentsByDraftId;
	}

	private function loadAttachmentRows(int $draftId): array
	{
		return DraftAttachmentTable::query()
			->setSelect(['*'])
			->where('DRAFT_ID', $draftId)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
	}

	private function replaceAttachments(int $draftId, array $attachments): void
	{
		$storedRows = $this->loadAttachmentRows($draftId);
		if ($this->isSameAttachmentComposition($storedRows, $attachments))
		{
			return;
		}

		foreach ($storedRows as $attachment)
		{
			$this->deleteBindingOrFail((int)$attachment['ID']);
		}
		foreach ($attachments as $attachment)
		{
			$addResult = DraftAttachmentTable::add($attachment + ['DRAFT_ID' => $draftId]);
			if (!$addResult->isSuccess())
			{
				throw new \RuntimeException('Draft attachment binding was not added.');
			}
		}
	}

	/**
	 * FILE_ID is compared apart from the fingerprint: a fresh copy of the same source file keeps the fingerprint
	 * but must replace the binding, otherwise the retained one would point to an already unregistered file.
	 */
	private function isSameAttachmentComposition(array $storedRows, array $attachments): bool
	{
		if (count($storedRows) !== count($attachments))
		{
			return false;
		}

		$storedRows = array_values($storedRows);
		$attachments = array_values($attachments);
		$fileIds = static fn(array $rows): array => array_map(
			static fn(array $row): int => (int)$row['FILE_ID'],
			$rows,
		);

		return $fileIds($storedRows) === $fileIds($attachments)
			&& DraftAttachmentFingerprint::fromRows($storedRows)
				=== DraftAttachmentFingerprint::fromRows($attachments);
	}

	private function deleteBindingOrFail(int $bindingId): void
	{
		$deleteResult = DraftAttachmentTable::delete($bindingId);
		if (!$deleteResult->isSuccess())
		{
			throw new \RuntimeException('Draft attachment binding was not deleted.');
		}
	}

	private function unregisterAttachmentFiles(array $attachments): void
	{
		foreach ($attachments as $attachment)
		{
			try
			{
				Storage::unregisterAttachment((int)$attachment['FILE_ID']);
			}
			catch (\Throwable)
			{
				// Orphan cleanup retries the owned file later.
			}
		}
	}

	private function snapshotToFields(DraftSnapshot $snapshot): array
	{
		return [
			'CLIENT_ID' => $snapshot->clientId,
			'COMPOSE_MODE' => $snapshot->mode,
			'PARENT_MESSAGE_ID' => $snapshot->parentMessageId,
			'SENDER_DATA' => $snapshot->sender !== null ? Json::encode($snapshot->sender) : null,
			'RECIPIENTS_DATA' => Json::encode([
				'to' => $snapshot->to,
				'cc' => $snapshot->cc,
				'bcc' => $snapshot->bcc,
			]),
			'TO_RECIPIENTS_SEARCH' => self::buildToRecipientsSearch($snapshot->to),
			'SUBJECT' => $snapshot->subject,
			'BODY' => $snapshot->body,
			'BODY_PREVIEW' => self::buildBodyPreview($snapshot->body),
			'BODY_FORMAT' => $snapshot->bodyFormat,
			'LARGE_ATTACHMENTS_DATA' => $snapshot->largeAttachments !== []
				? Json::encode($snapshot->largeAttachments)
				: null,
		];
	}

	private static function buildToRecipientsSearch(array $recipients): ?string
	{
		if ($recipients === [])
		{
			return null;
		}

		return implode("\n", array_map(
			static fn(array $recipient): string => mb_strtolower(trim(
				(string)($recipient['name'] ?? '') . ' ' . (string)($recipient['email'] ?? ''),
			)),
			$recipients,
		));
	}

	private static function escapeLikeLiteral(string $value): string
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	private static function buildBodyPreview(string $body): string
	{
		// Decode first: stripping first would turn escaped entities back into live markup.
		$plainBody = trim(strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5)));

		return mb_substr($plainBody, 0, 200);
	}

	/**
	 * Converted large attachment sets the claimed draft carries, or an empty list when the claim does not
	 * hold. The claim is the same statement the attachment copies are released under, so a send that may
	 * not have the files of a draft may not learn about its sets either.
	 *
	 * A set is described by what a send needs to recognise it: its token, the public address the message
	 * body has to carry and the files it holds. The read model of the form is not built here.
	 *
	 * @return list<array{token: string, publicUrl: string, fileIds: int[], sourceFileIds: int[]}>
	 */
	public function findClaimedLargeAttachments(
		int $userId,
		int $draftId,
		string $contextType,
		int $expectedRevision,
		?string $expectedClientId = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
	): array
	{
		if ($userId <= 0 || $draftId <= 0 || $expectedRevision <= 0)
		{
			return [];
		}

		$query = DraftTable::query()
			->setSelect(['LARGE_ATTACHMENTS_DATA'])
			->where('ID', $draftId)
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', $contextType)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
			->where('REVISION', $expectedRevision)
		;
		if ($expectedClientId !== null && $expectedClientId !== '')
		{
			$query->where('CLIENT_ID', $expectedClientId);
		}
		if ($crmEntityTypeId !== null)
		{
			$query
				->where('CRM_ENTITY_TYPE_ID', $crmEntityTypeId)
				->where('CRM_ENTITY_ID', $crmEntityId)
			;
		}

		$row = $query->fetch();
		if ($row === false || ($row['LARGE_ATTACHMENTS_DATA'] ?? null) === null)
		{
			return [];
		}

		return self::decodeLargeAttachments((string)$row['LARGE_ATTACHMENTS_DATA']);
	}

	/**
	 * @return list<array{token: string, publicUrl: string, fileIds: int[], sourceFileIds: int[]}>
	 */
	private static function decodeLargeAttachments(string $data): array
	{
		try
		{
			$decoded = Json::decode($data);
		}
		catch (\Throwable $exception)
		{
			(new LoggerFactory())->createById('mail.Draft')?->error(
				'Could not read the large attachments of a draft: {error}',
				['error' => $exception->getMessage()],
			);

			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		$largeAttachments = [];
		foreach ($decoded as $largeAttachment)
		{
			if (!is_array($largeAttachment))
			{
				continue;
			}

			$token = trim((string)($largeAttachment['token'] ?? ''));
			$fileIds = array_values(array_unique(array_map('intval', (array)($largeAttachment['fileIds'] ?? []))));
			if ($token === '' || $fileIds === [])
			{
				continue;
			}

			$largeAttachments[] = [
				'token' => $token,
				'publicUrl' => trim((string)($largeAttachment['publicUrl'] ?? '')),
				'fileIds' => $fileIds,
				'sourceFileIds' => array_values(array_unique(array_map(
					'intval',
					(array)($largeAttachment['sourceFileIds'] ?? $fileIds),
				))),
			];
		}

		return $largeAttachments;
	}
}
