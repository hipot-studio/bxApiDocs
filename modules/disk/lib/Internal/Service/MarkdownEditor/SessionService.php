<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The lock of a Markdown file while somebody edits it: an exclusive edit session of the service "markdown".
 *
 * A session lives while neither of its deadlines has passed: liveness - {@see LIVENESS_SECONDS} since the last
 * pulse or write of its tab, and idle - {@see IDLE_SECONDS} since the last input, cursor move or selection of the
 * person. The nearer one is kept as expiresAt; a session past it is put out lazily, under the lock of the row of
 * the object, by whoever comes next. Any other active edit session of the object (html, office) blocks as well,
 * and is never put out here.
 *
 * Only a logged in user holds a session: the guest of a public link is logged in before entering editing.
 */
final class SessionService
{
	public const LIVENESS_SECONDS = 60;
	public const IDLE_SECONDS = 300;
	public const HEARTBEAT_SECONDS = 20;

	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_SESSION_BUSY = 'session_busy';
	public const ERROR_CONTENT_CHANGED = 'content_changed';
	public const ERROR_SESSION_EXPIRED = 'session_expired';

	/** The pull command telling the viewers of the object that its md session was let go of. */
	public const PULL_COMMAND_RELEASED = 'markdownEditorReleased';

	private const MAX_IDLE_SECONDS = 86400;

	private \Closure $clock;
	private \Closure $contentReader;

	/**
	 * @param callable(): int|null $clock
	 * @param callable(File): ?string|null $contentReader Reads the bytes of the head revision of the file.
	 */
	public function __construct(?callable $clock = null, ?callable $contentReader = null)
	{
		$this->clock = $clock !== null ? $clock(...) : static fn(): int => time();
		$this->contentReader = $contentReader !== null
			? $contentReader(...)
			: static fn(File $file): ?string => self::readHeadBytes($file)
		;
	}

	public function now(): int
	{
		return ($this->clock)();
	}

	/**
	 * @return Result data: 'session' {@see DocumentSession}, 'state' {@see SessionState}, 'content' - the bytes
	 *         of the head revision the session is based on.
	 */
	public function acquire(EditorIdentity $who): Result
	{
		if ($who->userId <= 0)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$file = $this->loadRealFile($who->file);
		if ($file === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}
		$objectId = (int)$file->getId();
		if ($this->findBlockingSession($objectId) !== null)
		{
			return $this->error(self::ERROR_SESSION_BUSY);
		}

		$content = ($this->contentReader)($file);
		if ($content === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->lockObject($objectId);
			$lockedFile = $this->loadRealFile($file);
			if ($lockedFile === null || !$this->isStillAllowed($who, $lockedFile))
			{
				$this->rollback($connection);

				return $this->error(self::ERROR_ACCESS_DENIED);
			}

			$this->expireMarkdownSessions($objectId);
			if ($this->findBlockingSession($objectId) !== null)
			{
				$this->rollback($connection);

				return $this->error(self::ERROR_SESSION_BUSY);
			}

			// The bytes were read before the lock; a version written in between is read once more under it.
			if ($this->isOtherContent($lockedFile, $file))
			{
				$content = ($this->contentReader)($lockedFile);
				$recheckedFile = $this->loadRealFile($lockedFile);
				if ($content === null || $recheckedFile === null || $this->isOtherContent($recheckedFile, $lockedFile))
				{
					$this->rollback($connection);

					return $this->error(self::ERROR_CONTENT_CHANGED);
				}
			}
			// The page judged an older revision maybe: the bytes the session is based on are judged themselves.
			if (strlen($content) > Configuration::getMaxSizeForMarkdownRender())
			{
				$this->rollback($connection);

				return $this->error(ReadinessService::ERROR_TOO_LARGE);
			}
			if (!mb_check_encoding($content, 'UTF-8'))
			{
				$this->rollback($connection);

				return $this->error(ReadinessService::ERROR_ENCODING_UNSUPPORTED);
			}

			$now = $this->now();
			$context = $who->externalLink !== null
				? DocumentSessionContext::buildByExternalLink($who->externalLink)
				: DocumentSessionContext::buildByFile($lockedFile)
			;
			$context->setMarkdownEditorState(
				(int)$lockedFile->getGlobalContentVersion(),
				$now,
				$now,
				$this->calculateExpiresAt($now, $now),
			);
			$addResult = DocumentSessionTable::add([
				'SERVICE' => DocumentService::MarkdownEditor->value,
				'OBJECT_ID' => $objectId,
				'VERSION_ID' => null,
				'USER_ID' => $who->userId,
				'OWNER_ID' => $who->userId,
				'IS_EXCLUSIVE' => true,
				'TYPE' => DocumentSession::TYPE_EDIT,
				'STATUS' => DocumentSession::STATUS_ACTIVE,
				'EXTERNAL_LINK_ID' => $who->externalLink?->getId(),
				'CONTEXT' => $context->toJson(),
			]);
			if (!$addResult->isSuccess())
			{
				$this->rollback($connection);

				return (new Result())->addErrors($addResult->getErrors());
			}

			$session = DocumentSession::loadById((int)$addResult->getId());
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			throw $exception;
		}

		return (new Result())->setData([
			'session' => $session,
			'state' => $this->makeState($session),
			'content' => $content,
		]);
	}

	/**
	 * The pulse of the editing tab. A live session gets both deadlines moved; an own session already put out is
	 * taken again while the person acted less than {@see IDLE_SECONDS} ago, nobody else edits and the file did
	 * not change; with a longer inactivity, or for a session its tab released, the answer is the expired state.
	 *
	 * @param int $idleSeconds Seconds since the last input, cursor move or selection.
	 * @return Result data: 'session', 'state'.
	 */
	public function heartbeat(EditorIdentity $who, string $hash, int $idleSeconds): Result
	{
		if ($who->userId <= 0)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$file = $this->loadRealFile($who->file);
		if ($file === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->lockObject((int)$file->getId());
			$result = $this->touch($who, $hash, null, $idleSeconds, true);
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
			}
			else
			{
				$this->rollback($connection);
			}
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			throw $exception;
		}

		return $result;
	}

	/**
	 * For a write that already holds the lock of the row of the object: moves the deadlines of the own session,
	 * or takes it again when it was put out. Refused when the content version differs from the base of the
	 * session or from the one the client wrote over, and for a session its tab released.
	 *
	 * @return Result data: 'session', 'state'.
	 */
	public function retakeOrTouch(EditorIdentity $who, string $hash, int $baseVersion, int $idleSeconds): Result
	{
		if ($who->userId <= 0)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		return $this->touch($who, $hash, $baseVersion, $idleSeconds, false);
	}

	/**
	 * After a write under the lock: the session is based on the new content version and both deadlines count
	 * from now.
	 */
	public function rebase(DocumentSession $session, int $baseVersion): Result
	{
		$context = $session->getContext();
		if ($context === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$now = $this->now();
		$context->setMarkdownEditorState($baseVersion, $now, $now, $this->calculateExpiresAt($now, $now));
		$updateResult = DocumentSessionTable::update($session->getId(), ['CONTEXT' => $context->toJson()]);
		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		$session = DocumentSession::loadById($session->getId());

		return (new Result())->setData(['session' => $session, 'state' => $this->makeState($session)]);
	}

	/**
	 * Puts out the own session addressed by the hash for good: a late pulse or write of its tab does not take it
	 * again. Idempotent; a hash of somebody else or an unknown one changes nothing.
	 *
	 * @return Result data: 'released' - whether the hash addressed an own session.
	 */
	public function release(EditorIdentity $who, string $hash): Result
	{
		$session = $who->userId > 0 ? $this->findOwnSession($who, $hash) : null;
		if ($session === null)
		{
			return (new Result())->setData(['released' => false]);
		}

		$wasAlive = false;
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// Under the lock a pulse running meanwhile cannot write its old context over the mark.
			$this->lockObject((int)$session->getObjectId());
			$session = DocumentSession::loadById($session->getId());
			$context = $session?->getContext();
			if ($session !== null && $context !== null && !$context->isMarkdownEditorReleased())
			{
				$wasAlive = $session->isActive() && $this->getExpiresAt($session) > $this->now();
				$updateResult = DocumentSessionTable::update($session->getId(), [
					'STATUS' => DocumentSession::STATUS_NON_ACTIVE,
					'CONTEXT' => $context->markMarkdownEditorReleased()->toJson(),
				]);
				if (!$updateResult->isSuccess())
				{
					$this->rollback($connection);

					return (new Result())->addErrors($updateResult->getErrors());
				}
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			throw $exception;
		}

		// Only a session that kept others out frees anything; the pages waiting on it hear so after the commit.
		if ($wasAlive)
		{
			$this->notifyReleased($who->file);
		}

		return (new Result())->setData(['released' => true]);
	}

	/**
	 * Seconds left until the session blocking the object goes out by itself, should its tab stop pulsing;
	 * null for a session of another service, which this service never puts out.
	 */
	public function secondsUntilExpiry(DocumentSession $session): ?int
	{
		if ($session->getService() !== DocumentService::MarkdownEditor)
		{
			return null;
		}

		return max(0, $this->getExpiresAt($session) - $this->now());
	}

	/**
	 * The edit session that keeps the object from being edited now: a live md session or any other active edit
	 * session. Reads only, so the page may ask it without the lock.
	 */
	public function findBlockingSession(int $objectId): ?DocumentSession
	{
		$now = $this->now();
		foreach ($this->getActiveEditSessions($objectId) as $session)
		{
			if ($session->getService() !== DocumentService::MarkdownEditor || $this->getExpiresAt($session) > $now)
			{
				return $session;
			}
		}

		return null;
	}

	private function touch(
		EditorIdentity $who,
		string $hash,
		?int $baseVersion,
		int $idleSeconds,
		bool $mayAnswerExpired,
	): Result
	{
		$session = $this->findOwnSession($who, $hash);
		$state = $session?->getContext()?->getMarkdownEditorState();
		$file = $this->loadRealFile($who->file);
		if ($session === null || $state === null || $file === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}
		if ($session->getContext()->isMarkdownEditorReleased())
		{
			if ($session->isActive())
			{
				$session->setAsNonActive();
			}

			return $mayAnswerExpired
				? (new Result())->setData(['session' => $session, 'state' => $this->makeState($session)])
				: $this->error(self::ERROR_SESSION_EXPIRED)
			;
		}

		$now = $this->now();
		$idleSeconds = max(0, min($idleSeconds, self::MAX_IDLE_SECONDS));
		$lastActivityAt = max($state['lastActivityAt'], $now - $idleSeconds);
		$globalContentVersion = (int)$file->getGlobalContentVersion();
		$isAlive = $session->isActive() && $state['expiresAt'] > $now;

		if (!$isAlive)
		{
			if ($session->isActive())
			{
				$session->setAsNonActive();
			}
			if ($mayAnswerExpired && $lastActivityAt + self::IDLE_SECONDS <= $now)
			{
				return (new Result())->setData(['session' => $session, 'state' => $this->makeState($session)]);
			}
			if ($this->findBlockingSession((int)$file->getId()) !== null)
			{
				return $this->error(self::ERROR_SESSION_BUSY);
			}
			if (!$this->isStillAllowed($who, $file))
			{
				return $this->error(self::ERROR_ACCESS_DENIED);
			}
			if ($globalContentVersion !== $state['baseVersion'])
			{
				return $this->error(self::ERROR_CONTENT_CHANGED);
			}
		}
		if ($baseVersion !== null && $globalContentVersion !== $baseVersion)
		{
			return $this->error(self::ERROR_CONTENT_CHANGED);
		}

		$expiresAt = $this->calculateExpiresAt($now, $lastActivityAt);
		$context = $session->getContext()->setMarkdownEditorState(
			$state['baseVersion'],
			$now,
			$lastActivityAt,
			$expiresAt,
		);
		$fields = ['CONTEXT' => $context->toJson()];
		if ($expiresAt <= $now)
		{
			$fields['STATUS'] = DocumentSession::STATUS_NON_ACTIVE;
		}
		elseif (!$isAlive)
		{
			$fields['STATUS'] = DocumentSession::STATUS_ACTIVE;
		}
		$updateResult = DocumentSessionTable::update($session->getId(), $fields);
		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		$session = DocumentSession::loadById($session->getId());

		return (new Result())->setData(['session' => $session, 'state' => $this->makeState($session)]);
	}

	/**
	 * The event goes to the tag of the object, as the one of a new content does; it names nobody, only the
	 * object: the page asks the server itself whether it may edit now.
	 */
	private function notifyReleased(File $file): void
	{
		$objectId = (int)$file->getRealObjectId();
		$file
			->makeObjectEvent(self::PULL_COMMAND_RELEASED, ['objectId' => $objectId])
			->sendToObjectChannel()
		;
	}

	private function calculateExpiresAt(int $lastSeenAt, int $lastActivityAt): int
	{
		return min($lastSeenAt + self::LIVENESS_SECONDS, $lastActivityAt + self::IDLE_SECONDS);
	}

	private function makeState(DocumentSession $session): SessionState
	{
		$state = $session->getContext()?->getMarkdownEditorState();
		$expiresAt = (int)($state['expiresAt'] ?? 0);

		return new SessionState(
			$session->getExternalHash(),
			(int)($state['baseVersion'] ?? 0),
			$expiresAt,
			(int)($state['lastActivityAt'] ?? 0) + self::IDLE_SECONDS,
			$session->isActive() && $expiresAt > $this->now()
				? SessionState::STATUS_ACTIVE
				: SessionState::STATUS_EXPIRED
			,
		);
	}

	private function expireMarkdownSessions(int $objectId): void
	{
		$now = $this->now();
		foreach ($this->getActiveEditSessions($objectId) as $session)
		{
			if ($session->getService() === DocumentService::MarkdownEditor && $this->getExpiresAt($session) <= $now)
			{
				$session->setAsNonActive();
			}
		}
	}

	/**
	 * @return DocumentSession[]
	 */
	private function getActiveEditSessions(int $objectId): array
	{
		return DocumentSession::getModelList([
			'filter' => [
				'=OBJECT_ID' => $objectId,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
			],
			'order' => ['ID' => 'ASC'],
		]);
	}

	private function getExpiresAt(DocumentSession $session): int
	{
		return (int)($session->getContext()?->getMarkdownEditorState()['expiresAt'] ?? 0);
	}

	private function findOwnSession(EditorIdentity $who, string $hash): ?DocumentSession
	{
		if ($hash === '')
		{
			return null;
		}

		return DocumentSession::load([
			'=OBJECT_ID' => (int)$who->file->getRealObjectId(),
			'=EXTERNAL_HASH' => $hash,
			'=USER_ID' => $who->userId,
			'=SERVICE' => DocumentService::MarkdownEditor->value,
			'=TYPE' => DocumentSession::TYPE_EDIT,
		]);
	}

	private function isStillAllowed(EditorIdentity $who, File $file): bool
	{
		return (new AccessResolver())
			->resolve($file, $who->userId, $who->uniqueCode, $who->externalLink)
			->isSuccess()
		;
	}

	private function isOtherContent(File $file, File $readFile): bool
	{
		return (int)$file->getFileId() !== (int)$readFile->getFileId()
			|| (int)$file->getGlobalContentVersion() !== (int)$readFile->getGlobalContentVersion()
		;
	}

	private function loadRealFile(File $file): ?File
	{
		$realFile = File::loadById((int)$file->getRealObjectId());

		return $realFile instanceof File && !$realFile->isDeleted() ? $realFile : null;
	}

	private static function readHeadBytes(File $file): ?string
	{
		if ((int)$file->getSize() === 0)
		{
			return '';
		}

		$fileArray = \CFile::makeFileArray((int)$file->getFileId());
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return null;
		}
		$bytes = file_get_contents($fileArray['tmp_name']);

		return $bytes === false ? null : $bytes;
	}

	private function lockObject(int $objectId): void
	{
		$table = ObjectTable::getTableName();
		Application::getConnection()->queryExecute("SELECT ID FROM {$table} WHERE ID = {$objectId} FOR UPDATE");
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
