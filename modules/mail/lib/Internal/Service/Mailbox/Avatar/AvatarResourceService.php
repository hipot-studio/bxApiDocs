<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox\Avatar;

use Bitrix\Mail\FileUploader\MailboxAvatarUploaderController;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarResourceLifecycle;
use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarResourceStorage;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internals\MailboxAvatarResourceTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\FileTable;
use Bitrix\Main\File\Internal\FileDuplicateTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;

final class AvatarResourceService implements AvatarResourceStorage
{
	public const ERROR_ACCESS_DENIED = 'MAIL_AVATAR_ACCESS_DENIED';
	public const ERROR_NOT_SUPPORTED = 'MAIL_AVATAR_NOT_SUPPORTED';
	public const ERROR_UPLOAD_INVALID = AvatarImageProcessor::ERROR_UPLOAD_INVALID;
	public const ERROR_SAVE_FAILED = AvatarImageProcessor::ERROR_SAVE_FAILED;

	/** Consecutive resources the reconciliation walk reads by primary key in one run. */
	public const RECONCILE_WINDOW = 1000;

	/** Cleanup attempts held back for the reconciliation walk, so a crowded state cannot stall it. */
	private const RECONCILE_RESERVE = 20;

	private AvatarImageProcessor $processor;
	private \Closure $saveFile;
	private \Closure $addResource;
	private \Closure $deleteFile;
	private AvatarResourceLifecycle $lifecycle;

	public function __construct(
		?AvatarImageProcessor $processor = null,
		?\Closure $saveFile = null,
		?\Closure $addResource = null,
		?\Closure $deleteFile = null,
		?AvatarResourceLifecycle $lifecycle = null,
	)
	{
		$this->processor = $processor ?? new AvatarImageProcessor();
		$this->saveFile = $saveFile ?? static function (array $file): int|false {
			return \CFile::SaveFile($file, 'mail/mailbox-avatar', true);
		};
		$this->addResource = $addResource ?? static function (array $fields): AddResult {
			return MailboxAvatarResourceTable::add($fields);
		};
		$this->deleteFile = $deleteFile ?? static function (int $fileId): void {
			\CFile::Delete($fileId);
		};
		$this->lifecycle = $lifecycle ?? new AvatarResourceLifecycle($this);
	}

	public function prepare(int $mailboxId, string $uploadToken): Result
	{
		try
		{
			$userId = (int)CurrentUser::get()?->getId();
			if ($mailboxId <= 0 || $userId <= 0 || !MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
			{
				return $this->failure(self::ERROR_ACCESS_DENIED);
			}

			$mailbox = MailboxTable::query()
				->setSelect(['ID', 'ACTIVE', 'SERVER_TYPE'])
				->where('ID', $mailboxId)
				->fetch()
			;
			if (!$mailbox)
			{
				return $this->failure(self::ERROR_ACCESS_DENIED);
			}
			if ($mailbox['ACTIVE'] !== 'Y' || $mailbox['SERVER_TYPE'] !== 'imap')
			{
				return $this->failure(self::ERROR_NOT_SUPPORTED);
			}

			$file = $this->resolveUploadedFile($mailboxId, $userId, $uploadToken);
			if ($file === null)
			{
				return $this->failure(self::ERROR_UPLOAD_INVALID);
			}

			$processingResult = $this->processor->process($file);
			if (!$processingResult->isSuccess())
			{
				return $processingResult;
			}

			return $this->createPendingResource($mailboxId, $userId, $processingResult->getData()['file']);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
	}

	/** Call after the mailbox and all required settings rows have been locked. */
	public function applyWithinTransaction(array $mailbox, AvatarChange $change, ?int $resourceId): Result
	{
		if ((int)$mailbox['AVATAR_REVISION'] !== $change->expectedRevision)
		{
			return $this->failure(AvatarService::ERROR_VERSION_CONFLICT);
		}
		$mailboxId = (int)$mailbox['ID'];
		$previousId = (int)$mailbox['AVATAR_RESOURCE_ID'];
		$ids = array_values(array_unique(array_filter([$previousId, $resourceId])));
		sort($ids, SORT_NUMERIC);
		$resources = $this->loadResourcesForValidation($ids);
		foreach ($ids as $id)
		{
			if (
				!isset($resources[$id])
				|| (int)$resources[$id]['MAILBOX_ID'] !== $mailboxId
				|| $resources[$id]['STATE'] === AvatarResourceStorage::STATE_DELETING
			)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}
		}
		if ($change->operation === AvatarChange::OPERATION_REPLACE)
		{
			$candidate = $resources[$resourceId] ?? null;
			if ($candidate === null || $resourceId === $previousId
				|| $candidate['STATE'] !== AvatarResourceStorage::STATE_PENDING
				|| (int)$candidate['CREATED_BY'] !== (int)CurrentUser::get()?->getId()
				|| (int)$candidate['FILE_ID'] <= 0)
			{
				return $this->failure(self::ERROR_UPLOAD_INVALID);
			}
		}
		$revision = (int)$mailbox['AVATAR_REVISION'] + 1;
		$updatePointer = static function (?int $newResourceId) use ($mailboxId, $revision): Result {
			try
			{
				Application::getConnection()->queryExecute((new SqlExpression(
					'UPDATE ?# SET AVATAR_RESOURCE_ID = ?i, AVATAR_REVISION = ?i WHERE ID = ?i',
					MailboxTable::getTableName(),
					$newResourceId,
					$revision,
					$mailboxId,
				))->compile());
			}
			catch (\Throwable)
			{
				return (new Result())->addError(new Error(
					'The mailbox settings were not saved',
					SettingsWriteCoordinator::ERROR_WRITE_FAILED,
				));
			}

			return new Result();
		};
		$result = $change->operation === AvatarChange::OPERATION_REPLACE
			? $this->lifecycle->attach((int)$resourceId, $previousId, $updatePointer)
			: $this->lifecycle->detach($previousId, $updatePointer)
		;
		if (!$result->isSuccess())
		{
			return $result;
		}

		return (new Result())->setData(['previousResourceId' => $previousId, 'revision' => $revision]);
	}

	/**
	 * Collects at most $limit cleanup candidates, with every read bounded independently of the table size.
	 *
	 * Resources that state alone marks for cleanup — orphaned, deleting and expired pending — are taken through
	 * IX_B_MAIL_MAILBOX_AVATAR_RESOURCE_CLEANUP, so they are collected on one of the next runs whatever the
	 * table size. Detachment from the mailbox is not expressed by state and no index can show it, so attached
	 * resources are reconciled by a sliding window of $windowSize consecutive ones; $nextId receives that window
	 * cursor: the last scanned ID, or 0 once the window is exhausted. The walk takes its window even when the
	 * states left it no budget, so a batch smaller than the reserve moves it slower but never stops it.
	 */
	public function getCleanupResourceIds(
		int $afterId = 0,
		int $limit = 100,
		?int &$nextId = null,
		int $windowSize = self::RECONCILE_WINDOW,
	): array
	{
		return $this->findCleanupResourceIds($afterId, $limit, $nextId, $windowSize);
	}

	public function findCleanupResourceIds(
		int $cursor,
		int $limit,
		?int &$nextCursor = null,
		int $windowSize = self::RECONCILE_WINDOW,
	): array
	{
		$limit = max(1, min(100, $limit));
		$nextCursor = $cursor;
		$candidates = $this->findResourcesMarkedByState(max(1, $limit - self::RECONCILE_RESERVE));

		return array_merge(
			$candidates,
			$this->findResourcesDetachedFromMailbox(
				$cursor,
				$limit - count($candidates),
				max(1, $windowSize),
				$nextCursor,
			),
		);
	}

	/** @return int[] */
	private function findResourcesMarkedByState(int $limit): array
	{
		return $this->takeInTurn(
			[
				$this->findResourcesInState(MailboxAvatarResourceTable::STATE_DELETING, null, $limit),
				$this->findResourcesInState(MailboxAvatarResourceTable::STATE_ORPHANED, null, $limit),
				$this->findResourcesInState(
					MailboxAvatarResourceTable::STATE_PENDING,
					(new DateTime())->add('-1 day'),
					$limit,
				),
			],
			$limit,
		);
	}

	/**
	 * Equality on STATE and the order by LAST_ATTEMPT_AT are the left prefix of the cleanup index
	 * IX_B_MAIL_MAILBOX_AVATAR_RESOURCE_CLEANUP, and its third column continues the order, so one state costs
	 * a scan of $limit index entries without a filesort. The order is by attempt and not by creation on
	 * purpose: a resource whose cleanup keeps failing goes to the tail of its state instead of holding the
	 * head of the queue on every run. Expiry still asks about the creation, and the first entries of the state
	 * carry the oldest resources, so the reads stay bounded.
	 *
	 * @return int[]
	 */
	private function findResourcesInState(string $state, ?DateTime $createdBefore, int $limit): array
	{
		$query = MailboxAvatarResourceTable::query()
			->setSelect(['ID'])
			->where('STATE', $state)
			->setOrder(['LAST_ATTEMPT_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
		;
		if ($createdBefore !== null)
		{
			$query->where('CREATED_AT', '<', $createdBefore);
		}

		return array_map('intval', array_column($query->fetchAll(), 'ID'));
	}

	/**
	 * Takes one resource from each state in turn: a backlog in one state then delays the others by runs, while
	 * a single crowded state still fills the whole batch on its own.
	 *
	 * @param int[][] $lists
	 * @return int[]
	 */
	private function takeInTurn(array $lists, int $limit): array
	{
		$taken = [];
		for ($offset = 0; count($taken) < $limit; $offset++)
		{
			$exhausted = true;
			foreach ($lists as $ids)
			{
				if (!isset($ids[$offset]))
				{
					continue;
				}
				$exhausted = false;
				$taken[] = $ids[$offset];
				if (count($taken) >= $limit)
				{
					break;
				}
			}
			if ($exhausted)
			{
				break;
			}
		}

		return $taken;
	}

	/**
	 * Only an attached resource can lose its binding without a state change: the mailbox may be gone or may
	 * already point elsewhere. Neither is visible to an index on this table, so the window is taken by primary
	 * key first and the mailbox is joined only for the resources inside it. Without budget left the window is
	 * still taken and only the cursor moves: a walk that stands still would never reach the rest of the table.
	 *
	 * @return int[]
	 */
	private function findResourcesDetachedFromMailbox(
		int $afterId,
		int $limit,
		int $windowSize,
		?int &$nextId,
	): array
	{
		$window = array_map('intval', array_column(
			MailboxAvatarResourceTable::query()
				->setSelect(['ID'])
				->where('ID', '>', $afterId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($windowSize)
				->fetchAll(),
			'ID',
		));
		if ($window === [])
		{
			$nextId = 0;

			return [];
		}
		$scannedTo = (int)$window[count($window) - 1];
		$nextId = count($window) >= $windowSize ? $scannedTo : 0;
		if ($limit <= 0)
		{
			return [];
		}

		$sql = (new SqlExpression(
			'SELECT R.ID FROM ?# R LEFT JOIN ?# M ON M.ID = R.MAILBOX_ID '
			. 'WHERE R.ID > ?i AND R.ID <= ?i AND R.STATE = ?s '
			. 'AND (M.ID IS NULL OR M.AVATAR_RESOURCE_ID IS NULL OR M.AVATAR_RESOURCE_ID <> R.ID) '
			. 'ORDER BY R.ID',
			MailboxAvatarResourceTable::getTableName(),
			MailboxTable::getTableName(),
			$afterId,
			$scannedTo,
			MailboxAvatarResourceTable::STATE_ATTACHED,
		))->compile();
		$found = array_map('intval', array_column(
			Application::getConnection()->query($sql, $limit + 1)->fetchAll(),
			'ID',
		));
		if (count($found) > $limit)
		{
			$found = array_slice($found, 0, $limit);
			$nextId = $found[$limit - 1];
		}

		return $found;
	}

	public function cleanup(int $resourceId, bool $discardPending = false): Result
	{
		return $this->lifecycle->cleanup($resourceId, $discardPending);
	}

	public function lock(array $resourceIds, callable $operation): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->lockResourceMailboxes($resourceIds);
			$result = $operation($this->loadLockedResources($resourceIds));
			if (!$result instanceof Result)
			{
				$result = $this->failure(self::ERROR_SAVE_FAILED);
			}
			if (!$result->isSuccess())
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (TransactionException $exception)
				{
					if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
					{
						throw $exception;
					}
				}

				return $result;
			}
			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable $rollbackException)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($rollbackException);
			}
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
	}

	public function changeState(int $resourceId, string $state): Result
	{
		$result = MailboxAvatarResourceTable::update($resourceId, ['STATE' => $state]);

		return $result->isSuccess() ? new Result() : $this->failure(self::ERROR_SAVE_FAILED);
	}

	public function isBound(int $resourceId): bool
	{
		return (bool)MailboxTable::query()
			->setSelect(['ID'])
			->where('AVATAR_RESOURCE_ID', $resourceId)
			->setLimit(1)
			->fetch()
		;
	}

	public function canDelete(int $resourceId, array $resource, bool $discardPending): bool
	{
		if (($resource['STATE'] ?? null) !== AvatarResourceStorage::STATE_PENDING)
		{
			return true;
		}

		$mailboxExists = (bool)MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', (int)($resource['MAILBOX_ID'] ?? 0))
			->fetch()
		;
		if (!$mailboxExists)
		{
			return true;
		}

		if ($discardPending && (int)($resource['CREATED_BY'] ?? 0) === (int)CurrentUser::get()?->getId())
		{
			return true;
		}

		$createdAt = $resource['CREATED_AT'] ?? null;
		if (!$createdAt instanceof DateTime)
		{
			$createdAt = new DateTime((string)$createdAt, 'Y-m-d H:i:s');
		}

		return $createdAt->getTimestamp() < time() - 86400;
	}

	/**
	 * Written before the attempt and outside its transaction, so a resource keeps the mark whatever the attempt
	 * ends with: an undeletable one would otherwise stay at the head of its state and hold that slot every run.
	 */
	public function markAttempt(int $resourceId): void
	{
		MailboxAvatarResourceTable::update($resourceId, ['LAST_ATTEMPT_AT' => new DateTime()]);
	}

	public function delete(int $resourceId): Result
	{
		$connection = Application::getConnection();
		$lockName = 'mail_avatar_cleanup_' . $resourceId;
		$locked = false;
		try
		{
			$locked = $connection->lock($lockName, 0);
			if (!$locked)
			{
				return new Result();
			}

			$resource = MailboxAvatarResourceTable::query()
				->setSelect(['FILE_ID', 'STATE'])
				->where('ID', $resourceId)
				->fetch()
			;
			if (!$resource)
			{
				return new Result();
			}
			if ($resource['STATE'] !== AvatarResourceStorage::STATE_DELETING)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}

			$fileId = (int)$resource['FILE_ID'];
			($this->deleteFile)($fileId);
			if (
				FileTable::query()->setSelect(['ID'])->where('ID', $fileId)->fetch()
				&& !FileDuplicateTable::query()
					->setSelect(['ORIGINAL_ID'])
					->where('ORIGINAL_ID', $fileId)
					->where('ORIGINAL_DELETED', 'Y')
					->setLimit(1)
					->fetch()
			)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}

			$result = MailboxAvatarResourceTable::delete($resourceId);

			return $result->isSuccess() ? new Result() : $this->failure(self::ERROR_SAVE_FAILED);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
		finally
		{
			if ($locked)
			{
				$connection->unlock($lockName);
			}
		}
	}

	private function loadResourcesForValidation(array $resourceIds): array
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		if ($resourceIds === [])
		{
			return [];
		}

		$resources = [];
		$rows = MailboxAvatarResourceTable::query()
			->setSelect(['*'])
			->whereIn('ID', $resourceIds)
			->fetchAll()
		;
		foreach ($rows as $resource)
		{
			$resources[(int)$resource['ID']] = $resource;
		}

		return $resources;
	}

	private function loadLockedResources(array $resourceIds): array
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		sort($resourceIds, SORT_NUMERIC);
		$resources = [];
		$connection = Application::getConnection();
		foreach ($resourceIds as $resourceId)
		{
			$resource = $connection->query((new SqlExpression(
				'SELECT * FROM ?# WHERE ID = ?i FOR UPDATE',
				MailboxAvatarResourceTable::getTableName(),
				$resourceId,
			))->compile())->fetch();
			if ($resource)
			{
				$resource['state'] = (string)$resource['STATE'];
				$resources[$resourceId] = $resource;
			}
		}

		return $resources;
	}

	private function lockResourceMailboxes(array $resourceIds): void
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		if ($resourceIds === [])
		{
			return;
		}

		$mailboxIds = array_values(array_unique(array_map(
			'intval',
			array_column(
				MailboxAvatarResourceTable::query()
					->setSelect(['MAILBOX_ID'])
					->whereIn('ID', $resourceIds)
					->fetchAll(),
				'MAILBOX_ID',
			),
		)));
		sort($mailboxIds, SORT_NUMERIC);
		$connection = Application::getConnection();
		foreach ($mailboxIds as $mailboxId)
		{
			$connection->query((new SqlExpression(
				'SELECT ID, AVATAR_RESOURCE_ID FROM ?# WHERE ID = ?i FOR UPDATE',
				MailboxTable::getTableName(),
				$mailboxId,
			))->compile())->fetch();
		}
	}

	private function resolveUploadedFile(int $mailboxId, int $userId, string $uploadToken): ?array
	{
		if ($uploadToken === '' || !Loader::includeModule('ui'))
		{
			return null;
		}

		$controller = new MailboxAvatarUploaderController($mailboxId);
		try
		{
			$pendingFile = (new Uploader($controller))->getPendingFiles([$uploadToken])->get($uploadToken);
		}
		catch (BadSignatureException)
		{
			return null;
		}
		if ($pendingFile === null || !$pendingFile->isValid() || (int)$pendingFile->getFileId() <= 0)
		{
			return null;
		}

		$tempFile = TempFileTable::query()
			->setSelect(['ID'])
			->where('GUID', $pendingFile->getGuid())
			->where('FILE_ID', $pendingFile->getFileId())
			->where('CREATED_BY', $userId)
			->where('UPLOADED', true)
			->where('CREATED_AT', '>=', (new DateTime())->add('-1 day'))
			->fetch()
		;
		if (!$tempFile)
		{
			return null;
		}

		$file = \CFile::makeFileArray($pendingFile->getFileId());

		return is_array($file) ? $file : null;
	}

	private function createPendingResource(int $mailboxId, int $userId, array $file): Result
	{
		$file['MODULE_ID'] = 'mail';
		$fileId = 0;
		$stored = false;
		try
		{
			$fileId = (int)($this->saveFile)($file);
			if ($fileId <= 0)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}

			$addResult = ($this->addResource)([
				'MAILBOX_ID' => $mailboxId,
				'FILE_ID' => $fileId,
				'CREATED_BY' => $userId,
				'STATE' => MailboxAvatarResourceTable::STATE_PENDING,
				'CREATED_AT' => new DateTime(),
			]);
			if (!$addResult instanceof AddResult || !$addResult->isSuccess() || (int)$addResult->getId() <= 0)
			{
				return $this->failure(self::ERROR_SAVE_FAILED);
			}
			$stored = true;

			return (new Result())->setData(['resourceId' => (int)$addResult->getId()]);
		}
		finally
		{
			if ($fileId > 0 && !$stored)
			{
				$this->compensateFile($fileId);
			}
		}
	}

	private function compensateFile(int $fileId): void
	{
		try
		{
			($this->deleteFile)($fileId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
