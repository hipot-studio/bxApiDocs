<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internal\Service\Restrictions\EditingRestrictionResolver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SessionState
{
	public const STATUS_ACTIVE = 'active';
	public const STATUS_EXPIRED = 'expired';

	public function __construct(
		private readonly string $sessionHash,
		private readonly int $globalContentVersion,
		private readonly int $expiresAt,
		private readonly string $status,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'sessionHash' => $this->sessionHash,
			'globalContentVersion' => $this->globalContentVersion,
			'expiresAt' => gmdate('c', $this->expiresAt),
			'status' => $this->status,
		];
	}
}

final class SessionService
{
	public const TTL = 600;
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_SESSION_BUSY = 'session_busy';
	public const ERROR_SESSION_EXPIRED = 'session_expired';
	public const ERROR_FEATURE_DISABLED = 'feature_disabled';
	public const ERROR_UNSUPPORTED_FORMAT = 'unsupported_format';
	public const ERROR_FILE_LOCKED = 'file_locked';
	public const ERROR_FORMAT_RESTRICTED = 'format_restricted';
	public const ERROR_PERSONAL_QUOTA_EXCEEDED = 'personal_quota_exceeded';

	private \Closure $clock;

	public function __construct(?callable $clock = null)
	{
		$this->clock = $clock !== null ? $clock(...) : static fn(): int => time();
	}

	public function acquire(
		File $file,
		SecurityContext $securityContext,
		?AttachedObject $attachedObject = null,
	): Result
	{
		return $this->acquireInternal($file, $securityContext, $attachedObject, false);
	}

	public function acquireWithContent(
		File $file,
		SecurityContext $securityContext,
		?AttachedObject $attachedObject = null,
		bool $shouldCheckUnifiedLink = false,
	): Result
	{
		return $this->acquireInternal($file, $securityContext, $attachedObject, true, $shouldCheckUnifiedLink);
	}

	private function acquireInternal(
		File $file,
		SecurityContext $securityContext,
		?AttachedObject $attachedObject,
		bool $withContent,
		bool $shouldCheckUnifiedLink = false,
	): Result
	{
		$realFile = $this->resolveRealFile($file);
		if ($realFile === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$content = null;
		if ($withContent)
		{
			$realFile = File::loadById((int)$realFile->getId());
			if (!$realFile)
			{
				return $this->error(self::ERROR_ACCESS_DENIED);
			}
			if ($this->hasUnexpiredEditSession((int)$realFile->getId(), ($this->clock)()))
			{
				return $this->error(self::ERROR_SESSION_BUSY);
			}
			$availability = $this->validateAvailability($realFile, $securityContext, $attachedObject);
			if (!$availability->isSuccess())
			{
				return $availability;
			}
			if ((int)$realFile->getSize() > Configuration::getMaxSizeForHtmlViewer())
			{
				return $this->error('content_too_large');
			}
			$content = $this->readContent((int)$realFile->getFileId());
			if ($content === null)
			{
				return $this->error(self::ERROR_ACCESS_DENIED);
			}
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->lockObject((int)$realFile->getId());
			$lockedFile = File::loadById((int)$realFile->getId());
			if (!$lockedFile)
			{
				$this->rollback($connection);

				return $this->error(self::ERROR_ACCESS_DENIED);
			}
			$now = ($this->clock)();
			$this->expireHtmlSessions((int)$lockedFile->getId(), $now);

			if ($this->hasActiveEditSession((int)$lockedFile->getId()))
			{
				$this->rollback($connection);

				return $this->error(self::ERROR_SESSION_BUSY);
			}

			$lockedSecurityContext = $lockedFile->getStorage()?->getSecurityContext(
				$securityContext->getUserId(),
				$shouldCheckUnifiedLink,
			);
			if (!$lockedSecurityContext)
			{
				$this->rollback($connection);

				return $this->error(self::ERROR_ACCESS_DENIED);
			}
			$availability = $this->validateAvailability($lockedFile, $lockedSecurityContext, $attachedObject);
			if (!$availability->isSuccess())
			{
				$this->rollback($connection);

				return $availability;
			}
			if (
				$withContent
				&& (
					(int)$lockedFile->getFileId() !== (int)$realFile->getFileId()
					|| (int)$lockedFile->getGlobalContentVersion() !== (int)$realFile->getGlobalContentVersion()
				)
			)
			{
				$this->rollback($connection);

				return $this->error('content_version_conflict');
			}

			$expiresAt = $now + self::TTL;
			$context = DocumentSessionContext::tryBuildByAttachedObject($attachedObject, $lockedFile)
				->setHtmlEditorState((int)$lockedFile->getGlobalContentVersion(), $expiresAt)
			;
			$addResult = DocumentSessionTable::add([
				'SERVICE' => DocumentService::HtmlEditor->value,
				'OBJECT_ID' => (int)$lockedFile->getId(),
				'VERSION_ID' => null,
				'USER_ID' => $securityContext->getUserId(),
				'OWNER_ID' => $securityContext->getUserId(),
				'IS_EXCLUSIVE' => true,
				'TYPE' => DocumentSession::TYPE_EDIT,
				'STATUS' => DocumentSession::STATUS_ACTIVE,
				'CONTEXT' => $context->toJson(),
			]);
			if (!$addResult->isSuccess())
			{
				$this->rollback($connection);

				return (new Result())->addErrors($addResult->getErrors());
			}

			$session = DocumentSession::loadById((int)$addResult->getId());
			$connection->commitTransaction();

			$data = [
				'session' => $session,
				'state' => $this->makeState($session, $context),
			];
			if ($withContent)
			{
				$data['content'] = $content;
			}

			return (new Result())->setData($data);
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			throw $exception;
		}
	}

	public function resolveActive(File $file, SecurityContext $securityContext, string $sessionHash): Result
	{
		$session = $this->findOwnedSession($file, $securityContext, $sessionHash);
		if ($session === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$context = $session->getContext();
		if (
			$session->getStatus() !== DocumentSession::STATUS_ACTIVE
			|| $context === null
			|| $this->isExpired($context)
		)
		{
			$session->setAsNonActive();

			return $this->error(self::ERROR_SESSION_EXPIRED);
		}

		$availability = $this->validateAvailability(
			$session->getFile(),
			$securityContext,
			$context->getAttachedObject(),
		);
		if (!$availability->isSuccess())
		{
			$session->setAsNonActive();

			return $availability;
		}

		return (new Result())->setData([
			'session' => $session,
			'state' => $this->makeState($session, $context),
		]);
	}

	public function finishOwned(File $file, SecurityContext $securityContext, string $sessionHash): Result
	{
		$session = $this->findOwnedSession($file, $securityContext, $sessionHash);
		if ($session === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$context = $session->getContext();
		if (
			$session->getStatus() !== DocumentSession::STATUS_ACTIVE
			|| $context === null
			|| $this->isExpired($context)
		)
		{
			$session->setAsNonActive();

			return $this->error(self::ERROR_SESSION_EXPIRED);
		}

		$session->setAsNonActive();

		return (new Result())->setData(['finished' => true]);
	}

	public function touchAfterSave(
		File $file,
		SecurityContext $securityContext,
		string $sessionHash,
		int $globalContentVersion,
	): Result
	{
		$result = $this->resolveActive($file, $securityContext, $sessionHash);
		if (!$result->isSuccess())
		{
			return $result;
		}

		/** @var DocumentSession $session */
		$session = $result->getData()['session'];
		$context = $session->getContext()?->setHtmlEditorState(
			$globalContentVersion,
			($this->clock)() + self::TTL,
		);
		$updateResult = DocumentSessionTable::update($session->getId(), ['CONTEXT' => $context?->toJson()]);
		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		return (new Result())->setData(['state' => $this->makeState($session, $context)]);
	}

	private function validateAvailability(
		File $file,
		SecurityContext $securityContext,
		?AttachedObject $attachedObject,
	): Result
	{
		if (!Configuration::isEnabledHtmlEditor())
		{
			return $this->error(self::ERROR_FEATURE_DISABLED);
		}
		if ($file->isDeleted())
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}
		if (
			!$file->canUpdate($securityContext)
			&& !($attachedObject && $attachedObject->canUpdate($securityContext->getUserId()))
		)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}
		if (!HtmlViewerPolicy::isViewableFile($file))
		{
			return $this->error(self::ERROR_UNSUPPORTED_FORMAT);
		}
		$lock = Configuration::isEnabledObjectLock() ? $file->getLock() : null;
		if ($lock && $lock->isExclusive() && !$lock->canUnlock($securityContext->getUserId()))
		{
			return $this->error(self::ERROR_FILE_LOCKED);
		}

		$refusal = (new EditingRestrictionResolver())->findRefusal($file->getName(), $file);
		if ($refusal?->getCode() === File::ERROR_FORMAT_RESTRICTED)
		{
			return $this->error(self::ERROR_FORMAT_RESTRICTED);
		}
		if ($refusal?->getCode() === File::ERROR_PERSONAL_QUOTA_EXCEEDED)
		{
			return $this->error(self::ERROR_PERSONAL_QUOTA_EXCEEDED);
		}

		return new Result();
	}

	private function expireHtmlSessions(int $objectId, int $now): void
	{
		$sessions = DocumentSession::getModelList([
			'filter' => [
				'=OBJECT_ID' => $objectId,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
				'=SERVICE' => DocumentService::HtmlEditor->value,
			],
		]);
		foreach ($sessions as $session)
		{
			if (($session->getContext()?->getHtmlExpiresAt() ?? 0) <= $now)
			{
				$session->setAsNonActive();
			}
		}
	}

	private function hasUnexpiredEditSession(int $objectId, int $now): bool
	{
		$sessions = DocumentSession::getModelList([
			'filter' => [
				'=OBJECT_ID' => $objectId,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
			],
		]);
		foreach ($sessions as $session)
		{
			if (
				$session->getService() !== DocumentService::HtmlEditor
				|| ($session->getContext()?->getHtmlExpiresAt() ?? 0) > $now
			)
			{
				return true;
			}
		}

		return false;
	}

	private function hasActiveEditSession(int $objectId): bool
	{
		return DocumentSessionTable::getCount([
			'=OBJECT_ID' => $objectId,
			'=TYPE' => DocumentSession::TYPE_EDIT,
			'=STATUS' => DocumentSession::STATUS_ACTIVE,
		]) > 0;
	}

	private function findOwnedSession(
		File $file,
		SecurityContext $securityContext,
		string $sessionHash,
	): ?DocumentSession
	{
		$realFile = $this->resolveRealFile($file);
		if ($realFile === null)
		{
			return null;
		}

		return DocumentSession::load([
			'=OBJECT_ID' => (int)$realFile->getId(),
			'=EXTERNAL_HASH' => $sessionHash,
			'=USER_ID' => $securityContext->getUserId(),
			'=SERVICE' => DocumentService::HtmlEditor->value,
			'=TYPE' => DocumentSession::TYPE_EDIT,
		]);
	}

	private function makeState(DocumentSession $session, DocumentSessionContext $context): SessionState
	{
		return new SessionState(
			$session->getExternalHash(),
			(int)$context->getHtmlBaselineVersion(),
			(int)$context->getHtmlExpiresAt(),
			$this->isExpired($context) ? SessionState::STATUS_EXPIRED : SessionState::STATUS_ACTIVE,
		);
	}

	private function isExpired(DocumentSessionContext $context): bool
	{
		return ($context->getHtmlExpiresAt() ?? 0) <= ($this->clock)();
	}

	private function lockObject(int $objectId): void
	{
		$table = ObjectTable::getTableName();
		Application::getConnection()->queryExecute("SELECT ID FROM {$table} WHERE ID = {$objectId} FOR UPDATE");
	}

	private function resolveRealFile(File $file): ?File
	{
		$realObject = $file->getRealObject();

		return $realObject instanceof File ? $realObject : null;
	}

	private function readContent(int $fileId): ?string
	{
		$fileArray = \CFile::makeFileArray($fileId);
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return null;
		}
		$content = file_get_contents($fileArray['tmp_name']);

		return $content === false ? null : $content;
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			// The core throws after successfully rolling back a nested savepoint.
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
