<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Mail\FileUploader\SenderAvatarUploaderController;
use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarResourceLifecycle;
use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarResourceStorage;
use Bitrix\Mail\Internals\SenderAvatarResourceTable;
use Bitrix\Mail\Internals\SenderAvatarTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\File\Internal\FileDuplicateTable;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;

final class SenderAvatarResourceService implements AvatarResourceStorage
{
	public const ERROR_ACCESS_DENIED = 'MAIL_AVATAR_ACCESS_DENIED';
	public const ERROR_NOT_SUPPORTED = 'MAIL_AVATAR_NOT_SUPPORTED';
	public const ERROR_UPLOAD_INVALID = AvatarImageProcessor::ERROR_UPLOAD_INVALID;
	public const ERROR_SAVE_FAILED = AvatarImageProcessor::ERROR_SAVE_FAILED;

	private AvatarImageProcessor $processor;
	private AvatarResourceLifecycle $lifecycle;
	private \Closure $resolveUploadedFile;
	private \Closure $saveFile;
	private \Closure $addResource;
	private \Closure $deleteFile;
	private \Closure $currentUserId;
	private ?\Closure $lockResources;

	public function __construct(
		?AvatarImageProcessor $processor = null,
		?\Closure $resolveUploadedFile = null,
		?\Closure $saveFile = null,
		?\Closure $addResource = null,
		?\Closure $deleteFile = null,
		?AvatarResourceLifecycle $lifecycle = null,
		?\Closure $currentUserId = null,
		?\Closure $lockResources = null,
	)
	{
		$this->processor = $processor ?? new AvatarImageProcessor();
		$this->resolveUploadedFile = $resolveUploadedFile ?? $this->resolveUploadedFile(...);
		$this->saveFile = $saveFile ?? static fn(array $file): int|false =>
			\CFile::SaveFile($file, 'mail/sender-avatar', true);
		$this->addResource = $addResource ?? static fn(array $fields): AddResult =>
			SenderAvatarResourceTable::add($fields);
		$this->deleteFile = $deleteFile ?? static function (int $fileId): void {
			\CFile::Delete($fileId);
		};
		$this->lifecycle = $lifecycle ?? new AvatarResourceLifecycle($this);
		$this->currentUserId = $currentUserId ?? static fn(): int => (int)CurrentUser::get()?->getId();
		$this->lockResources = $lockResources;
	}

	public function prepare(string $email, int $messageId, string $token): Result
	{
		$email = (new EmailNormalizer())->normalize($email) ?? '';
		$userId = ($this->currentUserId)();
		if ($email === '' || $messageId <= 0 || $userId <= 0 || trim($token) === '')
		{
			return $this->failure(self::ERROR_ACCESS_DENIED);
		}

		try
		{
			$file = ($this->resolveUploadedFile)($messageId, $userId, $token);
			if (!is_array($file))
			{
				return $this->failure(self::ERROR_UPLOAD_INVALID);
			}
			$processed = $this->processor->process($file);
			if (!$processed->isSuccess())
			{
				return $processed;
			}

			return $this->createPendingResource($email, $userId, $processed->getData()['file']);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
	}

	public function attach(
		string $email,
		int $candidateResourceId,
		?int $previousResourceId,
		int $userId,
		callable $updatePointer,
	): Result
	{
		$ids = array_values(array_filter([$previousResourceId, $candidateResourceId]));
		$resources = $this->loadResourcesForValidation($ids);
		foreach ($resources as $resource)
		{
			if (
				($resource['EMAIL_NORMALIZED'] ?? null) !== $email
				|| ($resource['STATE'] ?? null) === self::STATE_DELETING
			)
			{
				return $this->failure(self::ERROR_UPLOAD_INVALID);
			}
		}
		$candidate = $resources[$candidateResourceId] ?? null;
		if (
			$candidate === null
			|| ($candidate['STATE'] ?? null) !== self::STATE_PENDING
			|| (int)($candidate['CREATED_BY'] ?? 0) !== $userId
			|| (int)($candidate['FILE_ID'] ?? 0) <= 0
		)
		{
			return $this->failure(self::ERROR_UPLOAD_INVALID);
		}

		return $this->lifecycle->attach($candidateResourceId, $previousResourceId, $updatePointer);
	}

	public function detach(string $email, ?int $previousResourceId, callable $updatePointer): Result
	{
		if ($previousResourceId === null || $previousResourceId <= 0)
		{
			return $this->lifecycle->detach(null, $updatePointer);
		}

		$resources = $this->loadResourcesForValidation([$previousResourceId]);
		if (($resources[$previousResourceId]['EMAIL_NORMALIZED'] ?? null) !== $email)
		{
			return $this->failure(self::ERROR_SAVE_FAILED);
		}

		return $this->lifecycle->detach($previousResourceId, $updatePointer);
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
			$this->lockSenderAvatars($resourceIds);
			$result = $operation($this->loadLockedResources($resourceIds));
			if (!$result instanceof Result || !$result->isSuccess())
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

				return $result instanceof Result ? $result : $this->failure(self::ERROR_SAVE_FAILED);
			}
			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_SAVE_FAILED);
		}
	}

	public function changeState(int $resourceId, string $state): Result
	{
		$result = SenderAvatarResourceTable::update($resourceId, ['STATE' => $state]);

		return $result->isSuccess() ? new Result() : $this->failure(self::ERROR_SAVE_FAILED);
	}

	public function isBound(int $resourceId): bool
	{
		return (bool)SenderAvatarTable::query()
			->setSelect(['ID'])
			->where('RESOURCE_ID', $resourceId)
			->setLimit(1)
			->fetch()
		;
	}

	public function canDelete(int $resourceId, array $resource, bool $discardPending): bool
	{
		if (($resource['STATE'] ?? null) !== self::STATE_PENDING)
		{
			return true;
		}
		if ($discardPending && (int)($resource['CREATED_BY'] ?? 0) === ($this->currentUserId)())
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

	public function findCleanupResourceIds(int $cursor, int $limit, ?int &$nextCursor = null): array
	{
		$limit = max(1, min(100, $limit));
		$nextCursor = 0;
		$lists = [
			$this->findInState(self::STATE_DELETING, null, $limit),
			$this->findInState(self::STATE_ORPHANED, null, $limit),
			$this->findInState(self::STATE_PENDING, (new DateTime())->add('-1 day'), $limit),
		];
		$result = [];
		for ($offset = 0; count($result) < $limit; $offset++)
		{
			$found = false;
			foreach ($lists as $ids)
			{
				if (isset($ids[$offset]))
				{
					$found = true;
					$result[] = $ids[$offset];
				}
				if (count($result) >= $limit)
				{
					break;
				}
			}
			if (!$found)
			{
				break;
			}
		}

		return $result;
	}

	public function markAttempt(int $resourceId): void
	{
		SenderAvatarResourceTable::update($resourceId, ['LAST_ATTEMPT_AT' => new DateTime()]);
	}

	public function delete(int $resourceId): Result
	{
		$connection = Application::getConnection();
		$lockName = 'mail_sender_avatar_cleanup_' . $resourceId;
		$locked = false;
		try
		{
			$locked = $connection->lock($lockName, 0);
			if (!$locked)
			{
				return new Result();
			}
			$resource = SenderAvatarResourceTable::query()
				->setSelect(['FILE_ID', 'STATE'])
				->where('ID', $resourceId)
				->fetch()
			;
			if (!$resource)
			{
				return new Result();
			}
			if ($resource['STATE'] !== self::STATE_DELETING)
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
			$result = SenderAvatarResourceTable::delete($resourceId);

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
		if ($this->lockResources !== null)
		{
			$result = ($this->lockResources)(
				$resourceIds,
				static fn(array $resources): Result => (new Result())->setData(['resources' => $resources]),
			);

			return $result instanceof Result ? ($result->getData()['resources'] ?? []) : [];
		}

		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		if ($resourceIds === [])
		{
			return [];
		}
		$resources = [];
		$rows = SenderAvatarResourceTable::query()
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
		foreach ($resourceIds as $resourceId)
		{
			$resource = Application::getConnection()->query((new SqlExpression(
				'SELECT * FROM ?# WHERE ID = ?i FOR UPDATE',
				SenderAvatarResourceTable::getTableName(),
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

	private function lockSenderAvatars(array $resourceIds): void
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		if ($resourceIds === [])
		{
			return;
		}
		$emails = array_column(
			SenderAvatarResourceTable::query()
				->setSelect(['EMAIL_NORMALIZED'])
				->whereIn('ID', $resourceIds)
				->fetchAll(),
			'EMAIL_NORMALIZED',
		);
		sort($emails, SORT_STRING);
		foreach (array_unique($emails) as $email)
		{
			Application::getConnection()->query((new SqlExpression(
				'SELECT ID FROM ?# WHERE EMAIL_NORMALIZED = ?s FOR UPDATE',
				SenderAvatarTable::getTableName(),
				$email,
			))->compile())->fetch();
		}
	}

	private function findInState(string $state, ?DateTime $createdBefore, int $limit): array
	{
		$query = SenderAvatarResourceTable::query()
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

	private function resolveUploadedFile(int $messageId, int $userId, string $token): ?array
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}
		$controller = new SenderAvatarUploaderController($messageId);
		try
		{
			$pendingFile = (new Uploader($controller))->getPendingFiles([$token])->get($token);
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

	private function createPendingResource(string $email, int $userId, array $file): Result
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
				'EMAIL_NORMALIZED' => $email,
				'FILE_ID' => $fileId,
				'CREATED_BY' => $userId,
				'STATE' => self::STATE_PENDING,
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
				($this->deleteFile)($fileId);
			}
		}
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
