<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SaveService
{
	public function __construct(
		private readonly ?ChangeSetApplier $applier = null,
		private readonly ?SessionService $sessionService = null,
	)
	{
	}

	public function save(
		File $file,
		SecurityContext $securityContext,
		string $sessionHash,
		int $globalContentVersion,
		array $changeSet,
		bool $shouldCheckUnifiedLink = false,
	): Result
	{
		$auditTransaction = new AuditTransaction(Application::getConnection());
		if (!$auditTransaction->canStartRootTransaction())
		{
			return $this->error('access_denied');
		}

		$sessionService = $this->sessionService ?? new SessionService();
		$active = $sessionService->resolveActive($file, $securityContext, $sessionHash);
		if (!$active->isSuccess())
		{
			return $active;
		}

		$realObjectId = (int)$file->getRealObjectId();
		$preparedFile = File::loadById($realObjectId);
		if (!$preparedFile)
		{
			return $this->error('access_denied');
		}
		$baseline = $this->readContent((int)$preparedFile->getFileId());
		if ($baseline === null)
		{
			return $this->error('access_denied');
		}
		$applyResult = ($this->applier ?? new ChangeSetApplier())->apply($baseline, $changeSet);
		if (!$applyResult->isSuccess())
		{
			return $applyResult;
		}

		$temporaryFile = null;
		$stagedFileId = 0;
		$cleanupDelegated = false;
		$finishSessionAfterRollback = false;
		try
		{
			$temporaryFile = tempnam(sys_get_temp_dir(), 'disk-html-save-');
			if (
				!is_string($temporaryFile)
				|| file_put_contents($temporaryFile, $applyResult->getData()['html']) === false
			)
			{
				return $this->error('access_denied');
			}
			$fileArray = \CFile::makeFileArray($temporaryFile);
			$fileArray['name'] = $preparedFile->getName();
			$fileArray['type'] = 'text/html';
			$fileArray['size'] = filesize($temporaryFile);
			$fileArray['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
			$stagedFileId = (int)\CFile::saveFile($fileArray, Driver::INTERNAL_MODULE_ID, true, true);
			if ($stagedFileId <= 0)
			{
				return $this->error('access_denied');
			}
			$stagedFileArray = \CFile::getFileArray($stagedFileId);
			if (!$stagedFileArray)
			{
				return $this->error('access_denied');
			}

			$transactionResult = $auditTransaction->executeRoot(
				function (AuditTransaction $transaction) use (
					$realObjectId,
					$securityContext,
					$sessionHash,
					$globalContentVersion,
					$sessionService,
					$stagedFileId,
					$stagedFileArray,
					$applyResult,
					$shouldCheckUnifiedLink,
					&$cleanupDelegated,
					&$finishSessionAfterRollback,
				): AuditTransactionOutcome {
					$transaction->addCompensation(static function () use ($stagedFileId): void {
						\CFile::delete($stagedFileId);
					});
					$cleanupDelegated = true;
					$this->lockObject($realObjectId);
					$lockedFile = File::loadById($realObjectId);
					if (!$lockedFile)
					{
						return AuditTransactionOutcome::failure($this->error('access_denied'));
					}

					$lockedSecurityContext = $lockedFile->getStorage()?->getSecurityContext(
						$securityContext->getUserId(),
						$shouldCheckUnifiedLink,
					);
					if (!$lockedSecurityContext)
					{
						return AuditTransactionOutcome::failure($this->error('access_denied'));
					}
					$active = $sessionService->resolveActive($lockedFile, $lockedSecurityContext, $sessionHash);
					if (!$active->isSuccess())
					{
						$finishSessionAfterRollback = true;

						return AuditTransactionOutcome::failure($active);
					}
					/** @var DocumentSession $session */
					$session = $active->getData()['session'];
					$sessionBaseline = $session->getContext()?->getHtmlBaselineVersion();
					if (
						(int)$lockedFile->getGlobalContentVersion() !== $globalContentVersion
						|| $sessionBaseline !== $globalContentVersion
					)
					{
						return AuditTransactionOutcome::failure($this->error('content_version_conflict'));
					}

					$version = $lockedFile->addVersion(
						$stagedFileArray,
						$securityContext->getUserId(),
						false,
						[
							'commentAttachedObjects' => false,
							WriteOperationContext::OPTION_KEY => WriteOperationContext::EditingSession,
							WriteOperationContext::OPTION_KEY_EDITING_SESSION_STARTED_AT =>
								$session->getCreateTime()->getTimestamp(),
						],
						$transaction,
					);
					if (!$version)
					{
						return AuditTransactionOutcome::failure($this->mapWriteError($lockedFile));
					}

					$updatedFile = File::loadById($realObjectId);
					if (!$updatedFile)
					{
						return AuditTransactionOutcome::failure($this->error('access_denied'));
					}
					$touch = $sessionService->touchAfterSave(
						$updatedFile,
						$lockedSecurityContext,
						$sessionHash,
						(int)$updatedFile->getGlobalContentVersion(),
					);
					if (!$touch->isSuccess())
					{
						return AuditTransactionOutcome::failure($touch);
					}

					return AuditTransactionOutcome::success((new Result())->setData([
						'ok' => true,
						'version' => $applyResult->getData()['version'],
						'contentBase64' => base64_encode($applyResult->getData()['html']),
						'state' => $touch->getData()['state'],
					]));
				},
			);

			$result = $transactionResult->getOutcome()->getValue();
			if ($transactionResult->isRollbackConfirmed() && $finishSessionAfterRollback)
			{
				$sessionService->finishOwned($preparedFile, $securityContext, $sessionHash);
			}
			if ($result instanceof Result && ($transactionResult->isSuccess() || $transactionResult->isRollbackConfirmed()))
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

			return $this->error('access_denied');
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

	private function lockObject(int $objectId): void
	{
		$table = ObjectTable::getTableName();
		Application::getConnection()->queryExecute("SELECT ID FROM {$table} WHERE ID = {$objectId} FOR UPDATE");
	}

	private function mapWriteError(File $file): Result
	{
		if ($file->getErrorByCode(File::ERROR_EXCLUSIVE_LOCK))
		{
			return $this->error('file_locked');
		}
		if ($file->getErrorByCode(File::ERROR_FORMAT_RESTRICTED))
		{
			return $this->error('format_restricted');
		}
		if ($file->getErrorByCode(File::ERROR_PERSONAL_QUOTA_EXCEEDED))
		{
			return $this->error('personal_quota_exceeded');
		}

		return $this->error('access_denied');
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
