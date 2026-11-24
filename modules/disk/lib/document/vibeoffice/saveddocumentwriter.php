<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseFailureResult;
use Bitrix\Disk\Document\Vibeoffice\Logging\DiagnosticContext;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\Logging\SaveFailureDiagnostic;
use Bitrix\Disk\Document\Vibeoffice\Internal\SavedSnapshotProofResolver;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookEventPolicy;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookScopeResolver;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Psr\Log\LoggerInterface;

final class SavedDocumentWriter implements SavedDocumentWriterInterface
{
	private const FALLBACK_SESSION_BATCH_SIZE = 100;
	private const NOTIFICATION_LOCK_CONNECTION = 'disk_vo_notification';

	private readonly WebhookScopeResolver $scopeResolver;
	private readonly SavedSnapshotProofResolver $snapshotProofResolver;

	private array $ownerCandidates = [];
	private int $ownerCandidatesExamined = 0;

	public function __construct(
		private readonly DocumentStateRepository $repository = new DocumentStateRepository(),
		private readonly ConflictCopyService $copyService = new ConflictCopyService(),
		private readonly ConflictNotifier $notifier = new ConflictNotifier(),
		private readonly ?Configuration $configuration = null,
		private readonly ?LoggerInterface $diagnosticLogger = null,
		private readonly ?\Closure $deliveryLoader = null,
		private readonly SavedSnapshotHasher $snapshotHasher = new SavedSnapshotHasher(),
		private readonly SavedVersionJoinPolicy $joinPolicy = new SavedVersionJoinPolicy(),
		private readonly bool $supportsSavedApplyAck = false,
		private readonly ?PlatformSource $source = null,
	)
	{
		$this->scopeResolver = new WebhookScopeResolver($repository, new SessionRegistry($repository));
		$this->snapshotProofResolver = new SavedSnapshotProofResolver($this->snapshotHasher);
	}

	public function prepare(int $ledgerId, array $payload): Result
	{
		$delivery = $this->getDelivery($ledgerId);
		if (!$this->matchesDelivery($delivery, $payload))
		{
			return $this->failure('Invalid saved delivery.', 'validation', $delivery, 'validation', 'validation_failed');
		}
		$state = $this->repository->getByKey($delivery['HOST_DOC_KEY']);
		if (!$state || (int)($state['EDIT_FENCE_STATE'] ?? 0) === 2)
		{
			return $this->failure('The saved document state is unavailable.', delivery: $delivery, reason: 'state_missing');
		}
		if ($this->isApplied($delivery) || $this->getPreviousSnapshot($state, $delivery)
			|| $this->isStaleSnapshot($state, $delivery))
		{
			return (new Result())->setData(['stagedFile' => []]);
		}
		if ((int)$delivery['STAGED_FILE_ID'] > 0)
		{
			$staged = \CFile::GetFileArray((int)$delivery['STAGED_FILE_ID']);
			if ($staged)
			{
				return (new Result())->setData(['stagedFile' => $staged]);
			}
		}
		$file = File::loadById((int)$state['OBJECT_ID']);
		if (!$file)
		{
			return $this->failure('The saved document is unavailable.', delivery: $delivery, reason: 'file_missing')
				->setData(['originalFileMissing' => true])
			;
		}
		$url = (string)($payload['file_url'] ?? '');
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock())
		{
			return $this->failure(
				'Saved download requires an unlocked caller.', 'vibeoffice_download_locked', $delivery,
			);
		}
		if (!($this->source ?? PlatformSource::current(
			$this->getConfiguration()->getServer(), (string)$this->getConfiguration()->getWebhookSecret(),
		))->allowsFileUrl($url))
		{
			return $this->failure(
				'The saved document URL is not allowed.', 'ssrf_blocked', $delivery, 'download', 'url_rejected',
			);
		}
		$tmp = \CTempFile::getFileName(Random::getString(16));
		checkDirPath($tmp);
		try
		{
			$client = new HttpClient();
			$client->setRedirect(false);
			if (!$client->download($url, $tmp) || $client->getStatus() !== 200)
			{
				return $this->failure(
					'Could not download the saved document.', 'download_failed', $delivery, 'download', 'download_failed',
				);
			}
			$array = \CFile::MakeFileArray($tmp);
			if (!is_array($array) || empty($array['tmp_name']))
			{
				return $this->failure(
					'The saved document is not readable.', 'download_failed', $delivery,
					'download', 'downloaded_file_unreadable',
				);
			}
			$maxSize = $this->getConfiguration()->getMaxFileSize();
			if ($maxSize > 0 && (int)$array['size'] > $maxSize)
			{
				return $this->failure(
					'The saved document exceeds the file size limit.', 'vibeoffice_file_too_large', $delivery,
					'download', 'file_too_large',
				);
			}
			$documentName = $file->getName();
			$extension = $file->getExtension();
			$stagingName = 'vibeoffice-saved';
			if ($extension !== '' && strlen($extension) <= 255 - strlen($stagingName) - 1)
			{
				$stagingName .= '.' . $extension;
			}
			$array['name'] = strlen($documentName) > 255 ? $stagingName : $documentName;
			$array['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
			if (in_array($array['type'] ?? null, ['application/encrypted', 'application/zip'], true))
			{
				$array['type'] = MimeType::getByFileExtension($file->getExtension());
			}
			$array['type'] = TypeFile::normalizeMimeType($array['type'], $documentName);
			$fileId = (int)\CFile::SaveFile($array, Driver::INTERNAL_MODULE_ID, true, true);
			$staged = $fileId > 0 ? \CFile::GetFileArray($fileId) : false;
			if (!$staged)
			{
				if ($fileId > 0)
				{
					\CFile::Delete($fileId);
				}
				return $this->failure(
					'Could not stage the saved document.', delivery: $delivery,
					stage: 'stage_file', reason: 'stage_file_failed',
				);
			}
			try
			{
				$update = DeliveryTable::update($ledgerId, [
					'STAGED_FILE_ID' => $fileId, 'STATUS' => DeliveryTable::STATUS_STAGED,
				]);
				if (!$update->isSuccess())
				{
					\CFile::Delete($fileId);

					$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

					return (new Result())->addErrors($update->getErrors());
				}
			}
			catch (\Throwable $exception)
			{
				\CFile::Delete($fileId);
				$failure = $this->getLedgerExceptionResult($exception);
				if ($failure !== null)
				{
					return $failure;
				}
				$this->logFailure($delivery, 'ledger', 'unknown_failure', exception: $exception);

				throw $exception;
			}

			return (new Result())->setData(['stagedFile' => $staged]);
		}
		finally
		{
			if (is_file($tmp))
			{
				unlink($tmp);
			}
		}
	}

	public function apply(int $ledgerId, array $payload, array $stagedFile): Result
	{
		$delivery = $this->getDelivery($ledgerId);
		if (!$this->matchesDelivery($delivery, $payload))
		{
			return $this->failure('Invalid saved delivery.', 'validation', $delivery, 'validation', 'validation_failed');
		}
		$key = $delivery['HOST_DOC_KEY'];
		if (DocumentStateRepository::isDocumentLocked($key))
		{
			return $this->failure(
				'The saved writer requires the object lock first.', 'vibeoffice_lock_order', $delivery,
				'write_version', 'scope_unwritable',
			);
		}

		$hashedFileId = (int)($stagedFile['ID'] ?? 0);
		$hash = $hashedFileId > 0 && $hashedFileId === (int)$delivery['STAGED_FILE_ID']
			? $this->snapshotHasher->hash($hashedFileId) : null;
		$canRecordRefusal = $this->supportsSavedApplyAck
			&& ($payload['source'] ?? null) === 'save'
			&& !DocumentStateRepository::hasOpenTransaction();

		$writingLedger = false;
		$result = $this->repository->withLockedDocument($key, function (array $state, ?File $file) use (
			$ledgerId, $payload, $stagedFile, $hashedFileId, $hash, $canRecordRefusal, &$writingLedger,
		): Result {
			$delivery = $this->getDelivery($ledgerId, true);
			if ($canRecordRefusal && (int)$state['SESSION_TYPE'] === DocumentSession::TYPE_VIEW && $hash?->isSuccess()
				&& $this->matchesDelivery($delivery, $payload) && (int)$delivery['STAGED_FILE_ID'] === $hashedFileId
				&& $file && (int)$file->getType() === ObjectTable::TYPE_FILE && !$file->isDeleted()
				&& $this->canRefuseReadOnlySnapshot($state, $delivery, $payload))
			{
				$writingLedger = true;
				return DeliveryTable::update($ledgerId, [
					'STATUS' => DeliveryTable::STATUS_DONE,
					'OUTCOME' => DeliveryTable::OUTCOME_REFUSED,
					'REASON' => 'validation',
					'PROCESSED_TIME' => new DateTime(),
				]);
			}

			if ((int)($state['EDIT_FENCE_STATE'] ?? 0) === 2
				|| !$this->matchesDelivery($delivery, $payload) || !$file || $file->isDeleted()
				|| (int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT || (int)$state['VERSION_ID'] > 0)
			{
				return $this->failure(
					'The saved document scope is not writable.', delivery: $delivery,
					stage: 'write_version', reason: 'scope_unwritable',
				);
			}
			if ($this->isApplied($delivery))
			{
				return new Result();
			}
			$previous = $this->getPreviousSnapshot($state, $delivery);
			if ($previous)
			{
				$writingLedger = true;
				return $this->markApplied($ledgerId, $previous, $delivery);
			}
			if ($this->isStaleSnapshot($state, $delivery))
			{
				$writingLedger = true;
				return $this->markIgnored($ledgerId, 'stale_snapshot', $delivery);
			}
			$classification = (new WebhookEventPolicy())->classify($delivery['EVENT'], $payload);
			$placement = $classification->isSuccess() ? $classification->getData() : [];
			if (($placement['identityKnown'] ?? false) && !$placement['legacy'])
			{
				$rejection = $this->repository->getPlacementRejection($state, $placement);
				if ($rejection !== null)
				{
					$writingLedger = true;
					return $this->markIgnored($ledgerId, $rejection, $delivery);
				}
			}
			$fileId = (int)($stagedFile['ID'] ?? 0);
			if ($fileId <= 0 || $fileId !== (int)$delivery['STAGED_FILE_ID'] || $hashedFileId !== $fileId || $hash === null)
			{
				return $this->failure(
					'The staged saved document is unavailable.', delivery: $delivery,
					stage: 'write_version', reason: 'staged_file_missing',
				);
			}
			if (!$hash->isSuccess())
			{
				return $hash;
			}
			$sha256 = $hash->getData()['sha256'];
			$exact = $delivery['SNAPSHOT_SEQ'] !== null
				? $this->repository->getSnapshot($state['HOST_DOC_KEY'], (int)$delivery['SNAPSHOT_SEQ'])
				: null;
			if ($exact && $this->isProvenFinalForcesave($payload, $exact, $sha256))
			{
				$writingLedger = true;
				return $this->markApplied($ledgerId, $exact, $delivery);
			}
			if ($exact)
			{
				return $this->failure(
					'Snapshot belongs to another delivery.', 'snapshot_ownership_unknown', $delivery,
					'write_version', 'snapshot_identity_mismatch',
				);
			}
			$stagedFile = \CFile::GetFileArray($fileId);
			if (!$stagedFile)
			{
				return $this->failure('The staged saved document is unavailable.', delivery: $delivery,
					stage: 'write_version', reason: 'staged_file_missing');
			}
			$scope = $this->scopeResolver->resolveState($state['HOST_DOC_KEY']);
			if (!$scope->isSuccess())
			{
				$this->logFailure($delivery, 'scope', 'unknown_failure', fn(): array => $scope->getErrors());

				return $scope;
			}
			$ownerId = $this->resolveOwner($file, $this->scopeResolver->iterateSessions($state['HOST_DOC_KEY'], $state));
			$classification = (new WebhookEventPolicy())->classify($delivery['EVENT'], $payload);
			$policy = $classification->isSuccess() ? $classification->getData() : [];
			$participantFallbackUsed = false;
			if ($ownerId <= 0)
			{
				if ($policy['identityKnown'] ?? false)
				{
					$ownerId = $this->resolveFallbackOwner(
						$file,
						$this->scopeResolver->iterateGenerationSessions(
							$state['HOST_DOC_KEY'], $state, $policy['editorKey'], $policy['rev'],
						),
					);
				}
				if ($ownerId <= 0)
				{
					$ownerId = $this->resolveFallbackOwner(
						$file,
						$this->scopeResolver->iterateSessions($state['HOST_DOC_KEY'], $state),
					);
				}
				$participantFallbackUsed = $ownerId > 0;
			}
			if ($ownerId <= 0)
			{
				return $this->failure(
					'A trusted saved document owner is unavailable.', delivery: $delivery,
					stage: 'write_version', reason: 'owner_missing',
					details: fn(): array => $this->scopeDetails($state) + [
						'owner_candidates' => $this->getOwnerCandidatesAfterFallback(),
						'owner_candidates_truncated' => $this->ownerCandidatesExamined > count($this->ownerCandidates),
						'candidates_total_examined' => $this->ownerCandidatesExamined,
					],
				);
			}
			if (($placement['identityKnown'] ?? false) && !$placement['legacy'])
			{
				$advanced = (new SessionRegistry($this->repository))->advanceTerminalPlacementLocked(
					$state, $placement['editorKey'], $placement['rev'],
				);
				if (!$advanced->isSuccess())
				{
					return $advanced;
				}
				if (!($advanced->getData()['accepted'] ?? false))
				{
					return $this->markIgnored($ledgerId, $advanced->getData()['reason'] ?? 'foreign_scope', $delivery);
				}
			}
			$late = $this->source?->departing === true
				&& $this->repository->hasEditStateInCurrentNamespace((int)$file->getRealObjectId());
			$conflict = $late || (bool)$state['CONFLICTED'] || (int)$state['EXPECTED_FILE_ID'] !== (int)$file->getFileId()
				|| (int)$state['EXPECTED_CONTENT_VERSION'] !== (int)$file->getGlobalContentVersion()
				|| (string)$state['EXPECTED_ETAG'] !== (string)$file->getEtag();
			$stateFields = ['UPDATED_TIME' => new DateTime()];
			$disableJoin = $late || ($this->source?->departing === true && $conflict)
				|| !$this->joinPolicy->isAllowed($state['HOST_DOC_KEY'], (int)$file->getRealObjectId());
			if (array_key_exists('TERMINAL_PROOF', $state))
			{
				$stateFields['TERMINAL_PROOF'] = null;
			}
			if ($conflict)
			{
				$save = $this->saveConflictCopy(
					$file,
					$stagedFile,
					$ownerId,
					(int)$state['CONFLICT_OBJECT_ID'],
					$participantFallbackUsed,
					$disableJoin,
				);
				if (!$save->isSuccess())
				{
					$this->logFailure($delivery, 'write_version', 'unknown_failure', fn(): array => $save->getErrors());

					return $save;
				}
				$saved = $save->getData();
				$resultFile = $saved['file'];
				$versionId = $saved['versionId'];
				$stateFields += ['CONFLICTED' => 1, 'CONFLICT_OBJECT_ID' => (int)$resultFile->getId()];
			}
			else
			{
				$authorId = SharedSessionScope::version($state) === 1
					? WebhookEventPolicy::resolveSharedVersionAuthorId(
						$payload, new SessionRegistry($this->repository), $ownerId,
					)
					: WebhookEventPolicy::resolveVersionAuthorId($payload, $this->participantUserIds($state), $ownerId)
				;
				$writeOptions = $this->versionWriteOptions($state, $policy);
				$version = $file->addVersion($stagedFile, $authorId, $disableJoin, $writeOptions);
				if (!$version || (int)$file->getFileId() !== $fileId)
				{
					$this->logFailure(
						$delivery, 'write_version', 'version_write_failed',
						!$version ? fn(): array => $file->getErrors() : null,
					);

					return (new Result())->addError(new Error(
						'Could not apply the saved document version.', 'vibeoffice_saved_write_failed',
					));
				}
				$resultFile = $file;
				$versionId = (int)$version->getId();
				$stateFields += [
					'EXPECTED_FILE_ID' => (int)$file->getFileId(),
					'EXPECTED_CONTENT_VERSION' => (int)$file->getGlobalContentVersion(),
					'EXPECTED_ETAG' => (string)$file->getEtag(),
				];
			}
			$receipt = [
				'RESULT_OBJECT_ID' => (int)$resultFile->getId(),
				'RESULT_VERSION_ID' => $versionId,
				'RESULT_CONTENT_VERSION' => (int)$resultFile->getGlobalContentVersion(),
				'CONTENT_SHA256' => $sha256,
			];
			$writingLedger = true;
			if ($delivery['SNAPSHOT_SEQ'] !== null)
			{
				$insert = SavedSnapshotTable::add($receipt + [
					'HOST_DOC_KEY' => $state['HOST_DOC_KEY'], 'SNAPSHOT_SEQ' => (int)$delivery['SNAPSHOT_SEQ'],
					'DELIVERY_ID' => (int)$delivery['DELIVERY_ID'],
					'SOURCE' => $payload['source'] ?? 'legacy',
				]);
				if (!$insert->isSuccess())
				{
					$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

					return (new Result())->addErrors($insert->getErrors());
				}
				$stateFields['LAST_SNAPSHOT_SEQ'] = (int)$delivery['SNAPSHOT_SEQ'];
			}
			$update = DocumentStateTable::update((int)$state['ID'], $stateFields);
			if (!$update->isSuccess())
			{
				$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

				return (new Result())->addErrors($update->getErrors());
			}

			return $this->markApplied($ledgerId, $receipt, $delivery);
		}, lockObject: true, auditFileChanges: true);

		return $writingLedger && $result instanceof DatabaseFailureResult ? $result->atStage('ledger') : $result;
	}

	public function complete(int $ledgerId, ?array $generation = null): Result
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$delivery = $this->getDelivery($ledgerId);
			if (!$delivery || !$this->isApplied($delivery) || (int)$delivery['NOTIFIED'] === 1
				|| DeliveryTable::isSavedNotificationSkipped($delivery))
			{
				return $this->completeNotification($ledgerId, $generation, $delivery);
			}

			// MySQL 5.6 keeps one named lock per connection. Notification can also lock the document on primary.
			$connection = $pool->getConnection(self::NOTIFICATION_LOCK_CONNECTION)
				?? $pool->cloneConnection(ConnectionPool::DEFAULT_CONNECTION_NAME, self::NOTIFICATION_LOCK_CONNECTION);
			$lockName = SavedSnapshotCompletion::getNotificationLockName($delivery);
			if (!$connection->lock($lockName, 0))
			{
				return (new Result())->addError(new Error('Saved notification is already in progress.', 'lock_timeout'));
			}
			try
			{
				return $this->completeNotification($ledgerId, $generation, $this->getDelivery($ledgerId));
			}
			finally
			{
				$connection->unlock($lockName);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function completeNotification(int $ledgerId, ?array $generation, ?array $delivery): Result
	{
		if (!$delivery || !$this->isApplied($delivery))
		{
			return $this->failure(
				'The saved document has not been applied.', delivery: $delivery,
				stage: 'notify', reason: 'scope_unwritable',
			);
		}
		if ($generation !== null && (
			($generation['hostDocKey'] ?? null) !== $delivery['HOST_DOC_KEY']
			|| !is_string($generation['editorKey'] ?? null) || $generation['editorKey'] === ''
			|| strlen($generation['editorKey']) > 255 || !is_int($generation['rev'] ?? null)
			|| $generation['rev'] < 0 || $generation['rev'] > 2_147_483_647
		))
		{
			return $this->failure(
				'Invalid saved notification generation.', delivery: $delivery,
				stage: 'notify', reason: 'validation_failed',
			);
		}
		if (DocumentStateRepository::isDocumentLocked($delivery['HOST_DOC_KEY']))
		{
			return $this->failure(
				'Saved notifications require the content commit.', delivery: $delivery,
				stage: 'notify', reason: 'scope_unwritable',
			);
		}
		if (DeliveryTable::isSavedNotificationSkipped($delivery))
		{
			return new Result();
		}
		if (!(int)$delivery['NOTIFIED'])
		{
			$resolved = (new SavedSnapshotCompletion())->resolve($delivery);
			if (!$resolved->isSuccess())
			{
				return $resolved;
			}
			$completion = $resolved->getData();
			if (!$completion['notified'])
			{
				$resultObjectId = (int)$delivery['RESULT_OBJECT_ID'];
				if ($generation !== null && !File::loadById($resultObjectId))
				{
					$skip = $this->completeMissingNotification($delivery);
					if ($skip !== null)
					{
						return $skip;
					}
				}
				$scope = $generation === null
					? $this->scopeResolver->resolve($delivery['HOST_DOC_KEY'])
					: $this->scopeResolver->resolveRecipients($delivery['HOST_DOC_KEY'], $generation['editorKey'], $generation['rev']);
				if (!$scope->isSuccess())
				{
					$this->logFailure($delivery, 'notify', 'unknown_failure', fn(): array => $scope->getErrors());

					return $scope;
				}
				$state = $scope->getData()['state'];
				$sessions = $scope->getData()['sessions'];
				$originalObjectId = (int)($state['OBJECT_ID'] ?? 0);
				$original = $originalObjectId > 0 ? File::loadById($originalObjectId) : null;
				$file = $resultObjectId === $originalObjectId ? $original : File::loadById($resultObjectId);
				if (!$file && $generation !== null && $resultObjectId === $originalObjectId)
				{
					$skip = $this->completeMissingNotification($delivery);
					if ($skip !== null)
					{
						return $skip;
					}
				}
				if (!$file || $originalObjectId <= 0)
				{
					return $this->failure(
						'The saved notification document is unavailable.', delivery: $delivery,
						stage: 'notify', reason: 'file_missing',
						details: fn(): array => $this->notificationDetails($state, $delivery, $original, $file, $generation),
					);
				}
				if ((int)$file->getId() !== $originalObjectId)
				{
					$notify = $this->notifier->notify($original, $file, $sessions, $originalObjectId);
					if (!$notify->isSuccess())
					{
						$this->logFailure(
							$delivery, 'notify', 'notification_failed',
							details: fn(): array => $this->notificationDetails($state, $delivery, $original, $file, $generation),
						);

						return $notify;
					}
				}
				else
				{
					$versionId = (int)$delivery['RESULT_VERSION_ID'];
					Application::getInstance()->addBackgroundJob(static function () use ($file, $versionId): void {
						$version = $versionId > 0 ? Version::loadById($versionId) : null;
						if ($version)
						{
							self::commentAttachedVersion($file, $version);
						}
					});
					$addresses = [];
					foreach ($sessions as $session)
					{
						$address = $session->getUserId() . ':' . $session->getExternalHash();
						if (isset($addresses[$address]))
						{
							continue;
						}
						$addresses[$address] = true;
						Driver::getInstance()->sendEvent(
							$session->getUserId(), 'vibeoffice', $this->buildSavedNotificationPayload($file, $session),
						);
					}
				}
			}
			try
			{
				if (!$completion['notified'] && empty($completion['skipped']) && $completion['ownerLedgerId'] !== $ledgerId)
				{
					$marked = $this->markNotificationOwner($completion['ownerLedgerId'], (string)$delivery['HOST_DOC_KEY']);
					if ($marked instanceof DatabaseFailureResult)
					{
						return $marked->atStage('ledger');
					}
					if (!$marked->isSuccess())
					{
						$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

						return (new Result())->addErrors($marked->getErrors());
					}
				}
				$update = DeliveryTable::update($ledgerId, ['NOTIFIED' => 1]);
			}
			catch (\Throwable $exception)
			{
				$failure = $this->getLedgerExceptionResult($exception);
				if ($failure !== null)
				{
					return $failure;
				}

				throw $exception;
			}
			if (!$update->isSuccess())
			{
				$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

				return (new Result())->addErrors($update->getErrors());
			}
		}

		return new Result();
	}

	private static function commentAttachedVersion(File $file, Version $version): void
	{
		global $USER;
		$previousUser = $USER;
		if (!$USER instanceof \CUser)
		{
			// Legacy connectors expect a user object, even when the agent runs on an authenticated hit.
			$USER = new class extends \CUser {
				private array $parameters = ['USER_ID' => '0'];

				public function GetParam($name)
				{
					return $this->parameters[$name] ?? null;
				}

				public function SetParam($name, $value)
				{
					$this->parameters[$name] = $value;
				}
			};
		}

		try
		{
			$file->commentAttachedObjects($version);
		}
		finally
		{
			$USER = $previousUser;
		}
	}

	private function markNotificationOwner(int $ownerLedgerId, string $key): Result
	{
		if (!HostDocumentKey::isStable($key))
		{
			return DeliveryTable::update($ownerLedgerId, ['NOTIFIED' => 1]);
		}

		return $this->repository->withPrimaryTransaction(function () use ($ownerLedgerId): Result {
			$owner = $this->getDelivery($ownerLedgerId, lock: true);
			if ($owner && DeliveryTable::isSavedNotificationSkipped($owner))
			{
				return new Result();
			}

			return DeliveryTable::update($ownerLedgerId, ['NOTIFIED' => 1]);
		});
	}

	private function completeMissingNotification(array $delivery): ?Result
	{
		$result = (new SavedNotificationSkip($this->repository))->trySkip($delivery);
		if (!$result->isSuccess())
		{
			return $result;
		}
		if (!$result->getData()['skipped'])
		{
			return null;
		}
		$this->logNotificationSkipped($delivery);

		return new Result();
	}

	private function logNotificationSkipped(array $delivery): void
	{
		try
		{
			($this->diagnosticLogger ?? LoggerProvider::get('disk.vibeoffice.webhook'))->info(
				'vibeoffice webhook diagnostic',
				[
					'operation' => 'saved',
					'code' => 'notification_skipped',
					'event' => WebhookProcessor::EVENT_SAVED,
					'stage' => 'notify',
					'reason_code' => DeliveryTable::REASON_NOTIFICATION_RESULT_DELETED,
					'delivery_id' => $delivery['DELIVERY_ID'],
					'delivery_outcome' => $delivery['OUTCOME'],
					'delivery_notified' => false,
					'snapshot_seq' => $delivery['SNAPSHOT_SEQ'],
					'result_object_id' => $delivery['RESULT_OBJECT_ID'],
					'result_version_id' => $delivery['RESULT_VERSION_ID'],
				],
			);
		}
		catch (\Throwable)
		{
			// Diagnostics must not change the saved document result.
		}
	}

	private function buildSavedNotificationPayload(File $file, DocumentSession $session): array
	{
		return [
			'object' => ['id' => (int)$file->getId()],
			'documentSession' => ['hash' => $session->getExternalHash()],
			'event' => 'saved',
		];
	}

	private function markApplied(int $ledgerId, array $receipt, array $delivery): Result
	{
		$update = DeliveryTable::update($ledgerId, [
			'STATUS' => DeliveryTable::STATUS_APPLIED,
			'OUTCOME' => DeliveryTable::OUTCOME_APPLIED,
			'RESULT_OBJECT_ID' => (int)$receipt['RESULT_OBJECT_ID'],
			'RESULT_VERSION_ID' => $receipt['RESULT_VERSION_ID'],
			'RESULT_CONTENT_VERSION' => $receipt['RESULT_CONTENT_VERSION'] ?? null,
			'NOTIFIED' => 0,
		]);

		if ($update->isSuccess())
		{
			return new Result();
		}
		$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

		return (new Result())->addErrors($update->getErrors());
	}

	private function getPreviousSnapshot(array $state, array $delivery): ?array
	{
		$seq = $delivery['SNAPSHOT_SEQ'];
		if ($seq === null)
		{
			return null;
		}
		$receipt = $this->repository->getSnapshot($state['HOST_DOC_KEY'], (int)$seq);

		return $receipt && (int)$receipt['DELIVERY_ID'] === (int)$delivery['DELIVERY_ID'] ? $receipt : null;
	}

	private function isStaleSnapshot(array $state, array $delivery): bool
	{
		return $delivery['SNAPSHOT_SEQ'] !== null
			&& $state['LAST_SNAPSHOT_SEQ'] !== null
			&& (int)$delivery['SNAPSHOT_SEQ'] < (int)$state['LAST_SNAPSHOT_SEQ'];
	}

	private function isProvenFinalForcesave(array $payload, array $receipt, string $sha256): bool
	{
		if (($receipt['DELIVERY_ID'] ?? null) === null || $receipt['SOURCE'] !== 'forcesave'
			|| !in_array(strtolower((string)($payload['source'] ?? '')), ['save', 'forgotten'], true)
			|| (int)($receipt['RESULT_OBJECT_ID'] ?? 0) <= 0)
		{
			return false;
		}
		return $this->snapshotProofResolver->matches($receipt, $sha256);
	}

	private function markIgnored(int $ledgerId, string $reason, array $delivery): Result
	{
		$update = DeliveryTable::update($ledgerId, [
			'STATUS' => DeliveryTable::STATUS_DONE,
			'OUTCOME' => DeliveryTable::OUTCOME_IGNORED,
			'REASON' => $reason,
		]);

		if ($update->isSuccess())
		{
			return new Result();
		}
		$this->logFailure($delivery, 'ledger', 'ledger_write_failed');

		return (new Result())->addErrors($update->getErrors());
	}

	private function versionWriteOptions(array $state, array $policy): array
	{
		$options = ['commentAttachedObjects' => false];
		if (!($policy['identityKnown'] ?? false) || !enum_exists(WriteOperationContext::class))
		{
			return $options;
		}
		$session = $this->scopeResolver->findOriginatingEditSession($state, $policy['editorKey'], $policy['rev']);
		if ($session === null)
		{
			return $options;
		}
		$openingUserId = $session->getUserId();
		if ($openingUserId <= 0 && SharedSessionScope::version($state) === 1)
		{
			$openingUserId = $session->getOwnerId();
		}

		return $options + [
			WriteOperationContext::OPTION_KEY => WriteOperationContext::EditingSession,
			WriteOperationContext::OPTION_KEY_EDITING_SESSION_STARTED_AT => $session->getCreateTime()->getTimestamp(),
			WriteOperationContext::OPTION_KEY_EDITING_SESSION_OWNER_ID => $openingUserId,
		];
	}

	/**
	 * Writes the editor result into the conflict copy the state is bound to, and into a fresh copy
	 * of its own when that binding stopped accepting writes. Without the fallback a removed copy
	 * would freeze every later save of the document.
	 */
	private function saveConflictCopy(
		File $file,
		array $stagedFile,
		int $ownerId,
		int $copyId,
		bool $participantFallbackUsed,
		bool $disableJoin,
	): Result
	{
		$boundOwnerId = $copyId > 0 ? (int)(File::loadById($copyId)?->getCreatedBy() ?? 0) : 0;
		if ($boundOwnerId > 0 && (!$participantFallbackUsed || $boundOwnerId === $ownerId))
		{
			$save = $this->copyService->save($file, $stagedFile, $boundOwnerId, $copyId, $disableJoin);
			if ($save->isSuccess()
				|| !$save->getErrorCollection()->getErrorByCode(ConflictCopyService::ERROR_STALE_COPY))
			{
				return $save;
			}
		}

		return $this->copyService->save($file, $stagedFile, $ownerId, null, $disableJoin);
	}

	/**
	 * Users participating in the document, in the scope the state pins. Lazy on purpose: only a
	 * payload naming an author reads them.
	 *
	 * @return \Generator<int>
	 */
	private function participantUserIds(array $state): \Generator
	{
		foreach ($this->scopeResolver->iterateSessions($state['HOST_DOC_KEY'], $state) as $session)
		{
			yield (int)$session->getUserId();
		}
	}

	/** @param iterable<DocumentSession> $sessions */
	private function resolveOwner(File $file, iterable $sessions): int
	{
		$this->ownerCandidates = [];
		$this->ownerCandidatesExamined = 0;
		foreach ($sessions as $session)
		{
			$id = (int)$session->getOwnerId();
			if ($id <= 0)
			{
				$this->recordOwnerCandidate([
					'source' => 'session_owner', 'candidate_id' => $id, 'rejection' => 'invalid_id',
				], $session);

				continue;
			}
			if (!UserTable::getById($id)->fetch())
			{
				$this->recordOwnerCandidate([
					'source' => 'session_owner', 'candidate_id' => $id,
					'existence' => 'missing', 'rejection' => 'user_missing',
				], $session);

				continue;
			}
			$context = $file->getStorage()->getSecurityContext($id);
			$fileRead = $file->canRead($context);
			$sessionRead = $fileRead ? null : $session->canRead($context);
			$this->recordOwnerCandidate([
				'source' => 'session_owner', 'candidate_id' => $id, 'existence' => 'present',
				'file_read' => $fileRead ? 'allowed' : 'denied',
				'session_read' => $sessionRead === null ? 'not_checked' : ($sessionRead ? 'allowed' : 'denied'),
				'rejection' => $fileRead || $sessionRead ? 'none' : 'read_denied',
			], $session);
			if ($fileRead || $sessionRead)
			{
				return $id;
			}
		}
		$id = (int)$file->getCreatedBy();
		if ($id <= 0)
		{
			$this->recordOwnerCandidate([
				'source' => 'file_created_by', 'candidate_id' => $id, 'rejection' => 'invalid_id',
			]);

			return 0;
		}
		if (!UserTable::getById($id)->fetch())
		{
			$this->recordOwnerCandidate([
				'source' => 'file_created_by', 'candidate_id' => $id,
				'existence' => 'missing', 'rejection' => 'user_missing',
			]);

			return 0;
		}
		$fileRead = $file->canRead($file->getStorage()->getSecurityContext($id));
		$this->recordOwnerCandidate([
			'source' => 'file_created_by', 'candidate_id' => $id, 'existence' => 'present',
			'file_read' => $fileRead ? 'allowed' : 'denied', 'rejection' => $fileRead ? 'none' : 'read_denied',
		]);

		return $fileRead ? $id : 0;
	}

	private function recordOwnerCandidate(array $candidate, ?DocumentSession $session = null): void
	{
		try
		{
			$this->appendOwnerCandidate($candidate + ['session_id' => $session?->getId()]);
			if ($session !== null)
			{
				$this->appendOwnerCandidate([
					'source' => 'session_user',
					'candidate_id' => $session->getUserId(),
					'session_id' => $session->getId(),
					'rejection' => 'not_evaluated_by_algorithm',
				]);
			}
		}
		catch (\Throwable)
		{
			// Candidate observation must not change owner selection.
		}
	}

	private function getOwnerCandidatesAfterFallback(): array
	{
		return array_map(static function (array $candidate): array {
			if ($candidate['source'] === 'session_user')
			{
				// Fallback evaluated these users; its edit and eligibility checks have no diagnostic facets.
				$candidate['rejection'] = (int)$candidate['candidate_id'] > 0 ? 'not_checked' : 'invalid_id';
			}

			return $candidate;
		}, $this->ownerCandidates);
	}

	private function appendOwnerCandidate(array $candidate): void
	{
		$this->ownerCandidatesExamined++;
		if (count($this->ownerCandidates) < 20)
		{
			$this->ownerCandidates[] = $candidate + [
				'existence' => 'not_checked',
				'eligibility' => 'not_checked',
				'file_read' => 'not_checked',
				'session_read' => 'not_checked',
			];
		}
	}

	private function scopeDetails(array $state): array
	{
		return [
			'source_object_id' => $state['OBJECT_ID'] ?? null,
			'scope_session_type' => $state['SESSION_TYPE'] ?? null,
			'scope_version_id' => $state['VERSION_ID'] ?? null,
			'scope_external_link_id' => $state['EXTERNAL_LINK_ID'] ?? null,
			'scope_generation_present' => !empty($state['CONTENT_GENERATION']),
			'generation_correlation_id' => DiagnosticContext::generationCorrelationId($state['CONTENT_GENERATION'] ?? null),
		];
	}

	private function notificationDetails(
		array $state,
		array $delivery,
		?File $original,
		?File $file,
		?array $generation,
	): array
	{
		$originalId = (int)($state['OBJECT_ID'] ?? 0);
		$resultId = (int)($delivery['RESULT_OBJECT_ID'] ?? 0);

		return $this->scopeDetails($state) + [
			'source_id_valid' => $originalId > 0,
			'source_found' => $original !== null,
			'result_found' => $file !== null,
			'missing_object' => $original === null
				? ($file === null ? 'both' : 'source')
				: ($file === null ? 'result' : 'none'),
			'result_kind' => $originalId <= 0 || $resultId <= 0 ? 'unknown'
				: ($resultId !== $originalId ? 'conflictcopy'
					: ((int)($delivery['RESULT_VERSION_ID'] ?? 0) > 0 ? 'newversion' : 'original')),
			'generation_rev' => $generation['rev'] ?? null,
		];
	}

	/** @param iterable<DocumentSession> $sessions */
	private function resolveFallbackOwner(File $file, iterable $sessions): int
	{
		$unifiedAccess = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		foreach ($this->getFallbackSessionBatches($sessions) as $batch)
		{
			$ids = [];
			foreach ($batch as $session)
			{
				$id = (int)$session->getUserId();
				if ($id > 0)
				{
					$ids[$id] = $id;
				}
			}
			if ($ids === [])
			{
				continue;
			}
			$users = [];
			$rows = UserTable::query()->setSelect(['ID', 'EXTERNAL_AUTH_ID'])
				->whereIn('ID', array_values($ids))->where('IS_REAL_USER', true)->exec();
			while ($user = $rows->fetch())
			{
				if ($user['EXTERNAL_AUTH_ID'] !== DocumentEditorUser::EXTERNAL_AUTH_ID)
				{
					$users[(int)$user['ID']] = true;
				}
			}
			foreach ($batch as $session)
			{
				$id = (int)$session->getUserId();
				if (!isset($users[$id]))
				{
					continue;
				}
				$context = $file->getStorage()->getSecurityContext($id);
				if ($file->canUpdate($context) || $unifiedAccess->checkUnifiedLinkSource($file, $id)->canEdit())
				{
					return $id;
				}
				if ((int)$session->getExternalLinkId() !== 0 || !$session->getContextRaw())
				{
					continue;
				}
				try
				{
					$opening = $session->getContext();
				}
				catch (SystemException)
				{
					continue;
				}
				if (!$opening || (int)$opening->getExternalLinkId() !== 0)
				{
					continue;
				}
				$openingFile = $opening->getObject();
				if (
					$openingFile
					&& (int)$openingFile->getRealObjectId() === (int)$file->getRealObjectId()
					&& $opening->getAttachedObject()
					&& $session->canEdit($context)
				)
				{
					return $id;
				}
			}
		}

		return 0;
	}

	/** @param iterable<DocumentSession> $sessions */
	private function getFallbackSessionBatches(iterable $sessions): \Generator
	{
		$batch = [];
		foreach ($sessions as $session)
		{
			$batch[] = $session;
			if (count($batch) === self::FALLBACK_SESSION_BATCH_SIZE)
			{
				yield $batch;
				$batch = [];
			}
		}
		if ($batch !== [])
		{
			yield $batch;
		}
	}

	private function getDelivery(int $id, bool $lock = false): ?array
	{
		if ($this->deliveryLoader !== null)
		{
			return ($this->deliveryLoader)($id, $lock);
		}
		$query = DeliveryTable::query()->setSelect(['*'])->where('ID', $id);

		return $lock ? DocumentStateRepository::fetchForUpdate($query) : ($query->fetch() ?: null);
	}

	private function matchesDelivery(?array $delivery, array $payload): bool
	{
		return $delivery && $delivery['HOST_DOC_KEY'] === ($payload['host_doc_key'] ?? null)
			&& (string)$delivery['SNAPSHOT_SEQ'] === (string)($payload['snapshot_seq'] ?? null);
	}

	private function canRefuseReadOnlySnapshot(array $state, array $delivery, array $payload): bool
	{
		$key = $delivery['HOST_DOC_KEY'];
		$generation = $state['CONTENT_GENERATION'] ?? null;
		if ($state['KEY_MODE'] !== HostDocumentKey::MODE_STABLE || $state['HOST_DOC_KEY'] !== $key
			|| (int)$state['OBJECT_ID'] <= 0 || (int)($state['VERSION_ID'] ?? -1) < 0
			|| (int)($state['EXTERNAL_LINK_ID'] ?? -1) < 0
			|| !is_string($generation) || preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1
			|| !HostDocumentKey::isStable($key) || HostDocumentKey::create($state, $generation) !== $key
			|| $delivery['EVENT'] !== WebhookProcessor::EVENT_SAVED || $delivery['STATUS'] !== DeliveryTable::STATUS_STAGED
			|| $delivery['OUTCOME'] !== null || $delivery['REASON'] !== null || (int)$delivery['NOTIFIED'] !== 0
			|| !empty($delivery['RESULT_OBJECT_ID']) || !empty($delivery['RESULT_VERSION_ID'])
			|| !empty($delivery['RESULT_CONTENT_VERSION']))
		{
			return false;
		}

		$classification = (new WebhookEventPolicy())->classify(WebhookProcessor::EVENT_SAVED, $payload);
		$policy = $classification->getData();
		$seq = (int)$delivery['SNAPSHOT_SEQ'];

		return $classification->isSuccess() && !$policy['legacy'] && $policy['identityKnown']
			&& $policy['snapshotSeq'] === $seq && $seq > (int)$state['LAST_SNAPSHOT_SEQ']
			&& $this->repository->getSnapshot($key, $seq) === null;
	}

	private function isApplied(array $delivery): bool
	{
		return in_array($delivery['STATUS'], [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true)
			&& ($delivery['OUTCOME'] ?? null) === DeliveryTable::OUTCOME_APPLIED
			&& (int)$delivery['RESULT_OBJECT_ID'] > 0;
	}

	private function getConfiguration(): Configuration
	{
		return $this->configuration ?? ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	private function failure(
		string $message,
		string $code = 'vibeoffice_saved_write_failed',
		?array $delivery = null,
		string $stage = 'scope',
		string $reason = 'unknown_failure',
		?callable $details = null,
	): Result
	{
		$this->logFailure($delivery, $stage, $reason, details: $details);

		return (new Result())->addError(new Error($message, $code));
	}

	private function getLedgerExceptionResult(\Throwable $exception): ?DatabaseFailureResult
	{
		$result = DatabaseFailureResult::fromException(
			$exception, new Error('Could not process the Vibeoffice delivery.', 'vibeoffice_delivery_failed'),
		);

		return $result instanceof DatabaseFailureResult ? $result->atStage('ledger') : null;
	}

	private function logFailure(
		?array $delivery,
		string $stage,
		string $reason,
		?callable $errors = null,
		?callable $details = null,
		?\Throwable $exception = null,
	): void
	{
		try
		{
			if ($errors !== null)
			{
				$reason = SaveFailureDiagnostic::reasonFromErrors($errors());
			}
			($this->diagnosticLogger ?? LoggerProvider::get('disk.vibeoffice.webhook'))->log(
				$exception === null ? 'warning' : 'error',
				'vibeoffice webhook diagnostic',
				[
					'operation' => 'saved',
					'code' => $exception === null ? 'save_refused' : 'delivery_exception',
					'exception_class' => $exception !== null ? $exception::class : null,
					'event' => 'document.saved',
					'delivery_id' => $delivery['DELIVERY_ID'] ?? null,
					'document_correlation_id' => DiagnosticContext::documentCorrelationId($delivery['HOST_DOC_KEY'] ?? null),
					'stage' => $stage,
					'reason_code' => $reason,
					'result_object_id' => $delivery['RESULT_OBJECT_ID'] ?? null,
					'result_version_id' => $delivery['RESULT_VERSION_ID'] ?? null,
					'snapshot_seq' => $delivery['SNAPSHOT_SEQ'] ?? null,
					'delivery_status' => $delivery['STATUS'] ?? null,
					'delivery_outcome' => $delivery['OUTCOME'] ?? null,
					'delivery_notified' => isset($delivery['NOTIFIED']) ? (bool)$delivery['NOTIFIED'] : null,
				] + ($details !== null ? $details() : []),
			);
		}
		catch (\Throwable)
		{
			// Diagnostics must not change the saved document result.
		}
	}
}
