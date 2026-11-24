<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

/**
 * Writes the bytes the Markdown editor sent as a new version of the file, exactly as they came: a BOM, line ends,
 * tabs and trailing spaces are not touched.
 *
 * The write goes the default way of {@see File::addVersion()}, not as an editing session: the format policy and
 * the personal quota judge every write, and the versions of one author are joined by the rules of Disk. Under the
 * lock of the row of the object the editor is let once more, the own md session is touched or taken again, and
 * the content version has to be the one both the client and the session are based on - so nothing written by
 * another path meanwhile is overwritten.
 */
final class WriteService
{
	private const MIME_TYPE = 'text/markdown';

	private readonly SessionService $sessions;
	private readonly ReadinessService $readiness;

	public function __construct(?SessionService $sessions = null)
	{
		$this->sessions = $sessions ?? new SessionService();
		$this->readiness = new ReadinessService($this->sessions);
	}

	/**
	 * @param string $contentBase64 The full bytes of the file, base64.
	 * @return Result data: 'state' {@see SessionState}, 'revision' - {id, contentUrl, downloadUrl} of the head
	 *         revision with signed addresses.
	 */
	public function write(EditorIdentity $who, string $hash, int $baseVersion, string $contentBase64): Result
	{
		$maxSize = Configuration::getMaxSizeForMarkdownRender();
		if (strlen($contentBase64) > 4 * (int)ceil($maxSize / 3))
		{
			return $this->refuse(ReadinessService::ERROR_TOO_LARGE, $who);
		}
		$bytes = base64_decode($contentBase64, true);
		if ($bytes === false)
		{
			return $this->refuse(ReadinessService::ERROR_ENCODING_UNSUPPORTED, $who);
		}
		if (strlen($bytes) > $maxSize)
		{
			return $this->refuse(ReadinessService::ERROR_TOO_LARGE, $who);
		}
		if (!mb_check_encoding($bytes, 'UTF-8'))
		{
			return $this->refuse(ReadinessService::ERROR_ENCODING_UNSUPPORTED, $who);
		}

		$auditTransaction = new AuditTransaction(Application::getConnection());
		if ($who->userId <= 0 || !$auditTransaction->canStartRootTransaction())
		{
			return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
		}
		$realObjectId = (int)$who->file->getRealObjectId();

		$temporaryFile = null;
		$stagedFileId = 0;
		$cleanupDelegated = false;
		$written = null;
		try
		{
			$temporaryFile = tempnam(sys_get_temp_dir(), 'disk-md-save-');
			if (!is_string($temporaryFile) || file_put_contents($temporaryFile, $bytes) === false)
			{
				return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
			}
			$stagedFileId = $this->stage($temporaryFile, $who->file, strlen($bytes));
			$stagedFileArray = $stagedFileId > 0 ? \CFile::getFileArray($stagedFileId) : false;
			if (!$stagedFileArray)
			{
				return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
			}
			$stagedFileArray['FILE_SIZE'] = strlen($bytes);

			$transactionResult = $auditTransaction->executeRoot(
				function (AuditTransaction $transaction) use (
					$who,
					$hash,
					$baseVersion,
					$realObjectId,
					$stagedFileId,
					$stagedFileArray,
					&$cleanupDelegated,
					&$written,
				): AuditTransactionOutcome {
					$transaction->addCompensation(static function () use ($stagedFileId): void {
						\CFile::delete($stagedFileId);
					});
					$cleanupDelegated = true;

					$written = $this->writeUnderLock(
						$who,
						$hash,
						$baseVersion,
						$realObjectId,
						$stagedFileArray,
						$transaction,
					);

					return $written->isSuccess()
						? AuditTransactionOutcome::success($written)
						: AuditTransactionOutcome::failure($written)
					;
				},
			);

			// A failed write of the file rolls back with the outcome of its own, which carries no reason.
			$result = $transactionResult->isSuccess()
				? $transactionResult->getOutcome()->getValue()
				: ($written instanceof Result && !$written->isSuccess() ? $written : null)
			;
			$isSettled = $transactionResult->isSuccess() || $transactionResult->isRollbackConfirmed();
			if ($result instanceof Result && $isSettled)
			{
				foreach ($transactionResult->getExceptions() as $exception)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				}

				return $result;
			}

			$exception = $transactionResult->getExceptions()[0] ?? null;
			if ($exception !== null)
			{
				throw $exception;
			}

			return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
		}
		finally
		{
			if ($stagedFileId > 0 && !$cleanupDelegated)
			{
				\CFile::delete($stagedFileId);
			}
			if (is_string($temporaryFile) && is_file($temporaryFile))
			{
				unlink($temporaryFile);
			}
		}
	}

	private function writeUnderLock(
		EditorIdentity $who,
		string $hash,
		int $baseVersion,
		int $realObjectId,
		array $stagedFileArray,
		AuditTransaction $transaction,
	): Result
	{
		$this->lockObject($realObjectId);
		$lockedFile = File::loadById($realObjectId, ['STORAGE']);
		if (!$lockedFile instanceof File || $lockedFile->isDeleted())
		{
			return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
		}

		$access = (new AccessResolver())->resolve($lockedFile, $who->userId, $who->uniqueCode, $who->externalLink);
		if (!$access->isSuccess())
		{
			return $this->refuse($this->firstCode($access), $who);
		}
		/** @var EditorIdentity $lockedWho */
		$lockedWho = $access->getData()['identity'];

		$touch = $this->sessions->retakeOrTouch($lockedWho, $hash, $baseVersion, 0);
		if (!$touch->isSuccess())
		{
			return $this->describeTouchRefusal($touch, $lockedWho);
		}
		/** @var DocumentSession $session */
		$session = $touch->getData()['session'];
		$sessionBase = $session->getContext()?->getMarkdownEditorState()['baseVersion'] ?? null;
		if ($sessionBase !== (int)$lockedFile->getGlobalContentVersion())
		{
			return $this->refuse(SessionService::ERROR_CONTENT_CHANGED, $who);
		}

		$version = $lockedFile->addVersion(
			$stagedFileArray,
			$who->userId,
			false,
			['commentAttachedObjects' => false],
			$transaction,
		);
		if (!$version instanceof Version)
		{
			return $this->describeWriteError($lockedFile, $who);
		}

		$writtenFile = File::loadById($realObjectId);
		if (!$writtenFile instanceof File)
		{
			return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
		}
		$rebase = $this->sessions->rebase($session, (int)$writtenFile->getGlobalContentVersion());
		if (!$rebase->isSuccess())
		{
			return $rebase;
		}

		return (new Result())->setData([
			'state' => $rebase->getData()['state'],
			'revision' => $this->describeRevision($writtenFile, $version),
		]);
	}

	/**
	 * The addresses of the revision the write made or joined into, signed as on the page: a join rewrites the
	 * revision under the same id, so the page has to take them from here rather than from what it was built with.
	 *
	 * @return array{id: int, contentUrl: string, downloadUrl: string}
	 */
	private function describeRevision(File $file, Version $version): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$versionId = (int)$version->getId();
		$signature = (new UnifiedLinkSignature())->getUlsForVersion((int)$file->getId(), $versionId);

		return [
			'id' => $versionId,
			'contentUrl' => $this->sign($urlManager->getUrlForShowMarkdownVersion($versionId), $signature),
			'downloadUrl' => $this->sign((string)$urlManager->getUrlForDownloadVersion($version), $signature),
		];
	}

	private function stage(string $path, File $file, int $size): int
	{
		$fileArray = \CFile::makeFileArray($path);
		if (!is_array($fileArray))
		{
			return 0;
		}
		$fileArray['name'] = $file->getName();
		$fileArray['type'] = self::MIME_TYPE;
		$fileArray['size'] = $size;
		// The clouds module takes the size of an empty upload from here.
		$fileArray['file_size'] = $size;
		$fileArray['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;

		return (int)\CFile::saveFile($fileArray, Driver::INTERNAL_MODULE_ID, true, true);
	}

	/**
	 * An own session taken over by somebody else while it was out is, for the one who writes, a file changed
	 * under them: one message (AC-033), the code still telling the lock is held.
	 */
	private function describeTouchRefusal(Result $touch, EditorIdentity $who): Result
	{
		$code = $this->firstCode($touch);
		if ($code !== SessionService::ERROR_SESSION_BUSY)
		{
			return $this->refuse($code, $who);
		}

		$busy = $this->readiness->describeRefusal(
			$code,
			$this->sessions->findBlockingSession((int)$who->file->getRealObjectId()),
			$who->userId,
			$who->isGuest(),
		);
		$changed = $this->readiness->describeRefusal(SessionService::ERROR_CONTENT_CHANGED, null, $who->userId);

		return (new Result())->addError(new Error($changed->getMessage(), $code, $busy->getCustomData()));
	}

	private function describeWriteError(File $file, EditorIdentity $who): Result
	{
		$restrictionCodes = [
			File::ERROR_FORMAT_RESTRICTED => ReadinessService::ERROR_FORMAT_RESTRICTED,
			File::ERROR_PERSONAL_QUOTA_EXCEEDED => ReadinessService::ERROR_PERSONAL_QUOTA_EXCEEDED,
		];
		foreach ($restrictionCodes as $fileCode => $code)
		{
			$error = $file->getErrorByCode($fileCode);
			if ($error !== null)
			{
				// The file names the reason of its own refusal best, with the figures of the quota.
				$message = (string)$error->getMessage();

				return $message !== ''
					? (new Result())->addError(new Error($message, $code, $error->getCustomData()))
					: $this->refuse($code, $who)
				;
			}
		}
		if ($file->getErrorByCode(File::ERROR_EXCLUSIVE_LOCK))
		{
			return $this->refuse(ReadinessService::ERROR_FILE_LOCKED, $who);
		}

		return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, $who);
	}

	private function firstCode(Result $result): string
	{
		$error = $result->getErrors()[0] ?? null;

		return $error !== null ? (string)$error->getCode() : AccessResolver::ERROR_ACCESS_DENIED;
	}

	private function lockObject(int $objectId): void
	{
		$table = ObjectTable::getTableName();
		Application::getConnection()->queryExecute("SELECT ID FROM {$table} WHERE ID = {$objectId} FOR UPDATE");
	}

	private function sign(string $url, string $signature): string
	{
		return (string)(new Uri($url))->addParams(['_uls' => $signature]);
	}

	private function refuse(string $code, EditorIdentity $who): Result
	{
		return (new Result())->addError($this->readiness->describeRefusal($code, null, $who->userId));
	}
}
