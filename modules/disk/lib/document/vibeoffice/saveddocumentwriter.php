<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookEventPolicy;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookScopeResolver;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;

final class SavedDocumentWriter implements SavedDocumentWriterInterface
{
	private readonly WebhookScopeResolver $scopeResolver;

	public function __construct(
		private readonly DocumentStateRepository $repository = new DocumentStateRepository(),
		private readonly ConflictCopyService $copyService = new ConflictCopyService(),
		private readonly ConflictNotifier $notifier = new ConflictNotifier(),
		private readonly ?Configuration $configuration = null,
	)
	{
		$this->scopeResolver = new WebhookScopeResolver($repository, new SessionRegistry($repository));
	}

	public function prepare(int $ledgerId, array $payload): Result
	{
		$delivery = $this->getDelivery($ledgerId);
		if (!$this->matchesDelivery($delivery, $payload))
		{
			return $this->failure('Invalid saved delivery.', 'validation');
		}
		$state = $this->repository->getByKey($delivery['HOST_DOC_KEY']);
		if (!$state)
		{
			return $this->failure('The saved document state is unavailable.');
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
			return $this->failure('The saved document is unavailable.');
		}
		$url = (string)($payload['file_url'] ?? '');
		if (!$this->isAllowedFileUrl($url))
		{
			return $this->failure('The saved document URL is not allowed.', 'ssrf_blocked');
		}
		$tmp = \CTempFile::getFileName(Random::getString(16));
		checkDirPath($tmp);
		try
		{
			$client = new HttpClient();
			$client->setRedirect(false);
			if (!$client->download($url, $tmp) || $client->getStatus() !== 200)
			{
				return $this->failure('Could not download the saved document.', 'download_failed');
			}
			$array = \CFile::MakeFileArray($tmp);
			if (!is_array($array) || empty($array['tmp_name']))
			{
				return $this->failure('The saved document is not readable.', 'download_failed');
			}
			$maxSize = $this->getConfiguration()->getMaxFileSize();
			if ($maxSize > 0 && (int)$array['size'] > $maxSize)
			{
				return $this->failure('The saved document exceeds the file size limit.', 'vibeoffice_file_too_large');
			}
			$array['name'] = $file->getName();
			$array['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
			if (in_array($array['type'] ?? null, ['application/encrypted', 'application/zip'], true))
			{
				$array['type'] = MimeType::getByFileExtension($file->getExtension());
			}
			$array['type'] = TypeFile::normalizeMimeType($array['type'], $array['name']);
			$fileId = (int)\CFile::SaveFile($array, Driver::INTERNAL_MODULE_ID, true, true);
			$staged = $fileId > 0 ? \CFile::GetFileArray($fileId) : false;
			if (!$staged)
			{
				if ($fileId > 0)
				{
					\CFile::Delete($fileId);
				}
				return $this->failure('Could not stage the saved document.');
			}
			try
			{
				$update = DeliveryTable::update($ledgerId, [
					'STAGED_FILE_ID' => $fileId, 'STATUS' => DeliveryTable::STATUS_STAGED,
				]);
				if (!$update->isSuccess())
				{
					\CFile::Delete($fileId);

					return (new Result())->addErrors($update->getErrors());
				}
			}
			catch (\Throwable $exception)
			{
				\CFile::Delete($fileId);
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
			return $this->failure('Invalid saved delivery.', 'validation');
		}
		$key = $delivery['HOST_DOC_KEY'];
		if (DocumentStateRepository::isDocumentLocked($key))
		{
			return $this->failure('The saved writer requires the object lock first.', 'vibeoffice_lock_order');
		}

		return $this->repository->withLockedDocument($key, function (array $state, ?File $file) use ($ledgerId, $payload, $stagedFile): Result {
			$delivery = $this->getDelivery($ledgerId, true);
			if (!$this->matchesDelivery($delivery, $payload) || !$file || $file->isDeleted()
				|| (int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT || (int)$state['VERSION_ID'] > 0)
			{
				return $this->failure('The saved document scope is not writable.');
			}
			if ($this->isApplied($delivery))
			{
				return new Result();
			}
			$previous = $this->getPreviousSnapshot($state, $delivery);
			if ($previous)
			{
				return $this->markApplied($ledgerId, $previous);
			}
			if ($this->isStaleSnapshot($state, $delivery))
			{
				return $this->markIgnored($ledgerId, 'stale_snapshot');
			}
			$exact = $delivery['SNAPSHOT_SEQ'] !== null
				? $this->repository->getSnapshot($state['HOST_DOC_KEY'], (int)$delivery['SNAPSHOT_SEQ'])
				: null;
			if ($exact && $this->isProvenFinalForcesave($delivery, $payload, $exact, $stagedFile))
			{
				return $this->markApplied($ledgerId, $exact);
			}
			if ($exact)
			{
				return $this->failure('Snapshot belongs to another delivery.', 'snapshot_ownership_unknown');
			}
			$fileId = (int)($stagedFile['ID'] ?? 0);
			if ($fileId <= 0 || $fileId !== (int)$delivery['STAGED_FILE_ID'] || !($stagedFile = \CFile::GetFileArray($fileId)))
			{
				return $this->failure('The staged saved document is unavailable.');
			}
			$scope = $this->scopeResolver->resolveState($state['HOST_DOC_KEY']);
			if (!$scope->isSuccess())
			{
				return $scope;
			}
			$ownerId = $this->resolveOwner($file, $this->scopeResolver->iterateSessions($state['HOST_DOC_KEY'], $state));
			if ($ownerId <= 0)
			{
				return $this->failure('A trusted saved document owner is unavailable.');
			}
			$conflict = (bool)$state['CONFLICTED'] || (int)$state['EXPECTED_FILE_ID'] !== (int)$file->getFileId()
				|| (int)$state['EXPECTED_CONTENT_VERSION'] !== (int)$file->getGlobalContentVersion()
				|| (string)$state['EXPECTED_ETAG'] !== (string)$file->getEtag();
			$stateFields = ['UPDATED_TIME' => new DateTime()];
			if ($conflict)
			{
				$save = $this->saveConflictCopy($file, $stagedFile, $ownerId, (int)$state['CONFLICT_OBJECT_ID']);
				if (!$save->isSuccess())
				{
					return $save;
				}
				$saved = $save->getData();
				$resultFile = $saved['file'];
				$versionId = $saved['versionId'];
				$stateFields += ['CONFLICTED' => 1, 'CONFLICT_OBJECT_ID' => (int)$resultFile->getId()];
			}
			else
			{
				$authorId = WebhookEventPolicy::resolveVersionAuthorId(
					$payload,
					$this->participantUserIds($state),
					$ownerId,
				);
				$version = $file->addVersion($stagedFile, $authorId, true, ['commentAttachedObjects' => false]);
				if (!$version || (int)$file->getFileId() !== $fileId)
				{
					return $this->failure('Could not apply the saved document version.');
				}
				$resultFile = $file;
				$versionId = (int)$version->getId();
				$stateFields += [
					'EXPECTED_FILE_ID' => (int)$file->getFileId(),
					'EXPECTED_CONTENT_VERSION' => (int)$file->getGlobalContentVersion(),
					'EXPECTED_ETAG' => (string)$file->getEtag(),
				];
			}
			$receipt = ['RESULT_OBJECT_ID' => (int)$resultFile->getId(), 'RESULT_VERSION_ID' => $versionId];
			if ($delivery['SNAPSHOT_SEQ'] !== null)
			{
				$insert = SavedSnapshotTable::add($receipt + [
					'HOST_DOC_KEY' => $state['HOST_DOC_KEY'], 'SNAPSHOT_SEQ' => (int)$delivery['SNAPSHOT_SEQ'],
					'DELIVERY_ID' => (int)$delivery['DELIVERY_ID'],
					'SOURCE' => $payload['source'] ?? 'legacy',
				]);
				if (!$insert->isSuccess())
				{
					return (new Result())->addErrors($insert->getErrors());
				}
				$stateFields['LAST_SNAPSHOT_SEQ'] = (int)$delivery['SNAPSHOT_SEQ'];
			}
			$update = DocumentStateTable::update((int)$state['ID'], $stateFields);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}

			return $this->markApplied($ledgerId, $receipt);
		}, lockObject: true, auditFileChanges: true);
	}

	public function complete(int $ledgerId, ?array $generation = null): Result
	{
		$delivery = $this->getDelivery($ledgerId);
		if (!$delivery || !$this->isApplied($delivery))
		{
			return $this->failure('The saved document has not been applied.');
		}
		if ($generation !== null && (
			($generation['hostDocKey'] ?? null) !== $delivery['HOST_DOC_KEY']
			|| !is_string($generation['editorKey'] ?? null) || $generation['editorKey'] === ''
			|| strlen($generation['editorKey']) > 255 || !is_int($generation['rev'] ?? null)
			|| $generation['rev'] < 0 || $generation['rev'] > 2_147_483_647
		))
		{
			return $this->failure('Invalid saved notification generation.');
		}
		if (DocumentStateRepository::isDocumentLocked($delivery['HOST_DOC_KEY']))
		{
			return $this->failure('Saved notifications require the content commit.');
		}
		if (!(int)$delivery['NOTIFIED'])
		{
			$query = DeliveryTable::query()->setSelect(['ID'])
				->where('HOST_DOC_KEY', $delivery['HOST_DOC_KEY'])
				->where('RESULT_OBJECT_ID', (int)$delivery['RESULT_OBJECT_ID'])
				->whereIn('STATUS', [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE])
				->setOrder(['ID' => 'ASC'])->setLimit(1);
			if ($delivery['RESULT_VERSION_ID'] === null)
			{
				$query->whereNull('RESULT_VERSION_ID');
			}
			else
			{
				$query->where('RESULT_VERSION_ID', (int)$delivery['RESULT_VERSION_ID']);
			}
			$first = $query->fetch();
			if ($first && (int)$first['ID'] !== $ledgerId)
			{
				// The earlier delivery may belong to another generation; do not reuse this event's identity.
				$complete = $this->complete((int)$first['ID']);
				if (!$complete->isSuccess())
				{
					return $complete;
				}
			}
			else
			{
				$scope = $generation === null
					? $this->scopeResolver->resolve($delivery['HOST_DOC_KEY'])
					: $this->scopeResolver->resolveRecipients($delivery['HOST_DOC_KEY'], $generation['editorKey'], $generation['rev']);
				if (!$scope->isSuccess())
				{
					return $scope;
				}
				$state = $scope->getData()['state'];
				$sessions = $scope->getData()['sessions'];
				$original = $state ? File::loadById((int)$state['OBJECT_ID']) : null;
				$file = File::loadById((int)$delivery['RESULT_OBJECT_ID']);
				if (!$file || !$original)
				{
					return $this->failure('The saved notification document is unavailable.');
				}
				if ((int)$file->getId() !== (int)$original->getId())
				{
					$notify = $this->notifier->notify($original, $file, $sessions);
					if (!$notify->isSuccess())
					{
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
							$file->commentAttachedObjects($version);
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
						Driver::getInstance()->sendEvent($session->getUserId(), 'vibeoffice', [
							'object' => ['id' => (int)$file->getId()],
							'documentSession' => ['hash' => $session->getExternalHash()], 'event' => 'saved',
						]);
					}
				}
			}
			$update = DeliveryTable::update($ledgerId, ['NOTIFIED' => 1]);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}
		}

		return new Result();
	}

	private function markApplied(int $ledgerId, array $receipt): Result
	{
		$update = DeliveryTable::update($ledgerId, [
			'STATUS' => DeliveryTable::STATUS_APPLIED,
			'OUTCOME' => DeliveryTable::OUTCOME_APPLIED,
			'RESULT_OBJECT_ID' => (int)$receipt['RESULT_OBJECT_ID'],
			'RESULT_VERSION_ID' => $receipt['RESULT_VERSION_ID'],
			'NOTIFIED' => 0,
		]);

		return $update->isSuccess() ? new Result() : (new Result())->addErrors($update->getErrors());
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

	private function isProvenFinalForcesave(array $delivery, array $payload, array $receipt, array $stagedFile): bool
	{
		if (($receipt['DELIVERY_ID'] ?? null) === null || $receipt['SOURCE'] !== 'forcesave'
			|| !in_array(strtolower((string)($payload['source'] ?? '')), ['save', 'forgotten'], true)
			|| (int)($receipt['RESULT_OBJECT_ID'] ?? 0) <= 0
			|| (int)($stagedFile['ID'] ?? 0) !== (int)$delivery['STAGED_FILE_ID'])
		{
			return false;
		}
		$versionId = (int)$receipt['RESULT_VERSION_ID'];
		$receiptFileId = $versionId > 0
			? (int)(Version::loadById($versionId)?->getFileId() ?? 0)
			: (int)(File::loadById((int)$receipt['RESULT_OBJECT_ID'])?->getFileId() ?? 0);
		$previous = $receiptFileId > 0 ? \CFile::GetFileArray($receiptFileId) : false;
		$current = \CFile::GetFileArray((int)$stagedFile['ID']);
		if (!$previous || !$current)
		{
			return false;
		}
		$previousPath = Application::getDocumentRoot() . $previous['SRC'];
		$currentPath = Application::getDocumentRoot() . $current['SRC'];

		return is_file($previousPath) && is_file($currentPath)
			&& ($previousHash = hash_file('sha256', $previousPath)) !== false
			&& $previousHash === hash_file('sha256', $currentPath);
	}

	private function markIgnored(int $ledgerId, string $reason): Result
	{
		$update = DeliveryTable::update($ledgerId, [
			'STATUS' => DeliveryTable::STATUS_DONE,
			'OUTCOME' => DeliveryTable::OUTCOME_IGNORED,
			'REASON' => $reason,
		]);

		return $update->isSuccess() ? new Result() : (new Result())->addErrors($update->getErrors());
	}

	/**
	 * Writes the editor result into the conflict copy the state is bound to, and into a fresh copy
	 * of its own when that binding stopped accepting writes. Without the fallback a removed copy
	 * would freeze every later save of the document.
	 */
	private function saveConflictCopy(File $file, array $stagedFile, int $ownerId, int $copyId): Result
	{
		$boundOwnerId = $copyId > 0 ? (int)(File::loadById($copyId)?->getCreatedBy() ?? 0) : 0;
		if ($boundOwnerId > 0)
		{
			$save = $this->copyService->save($file, $stagedFile, $boundOwnerId, $copyId);
			if ($save->isSuccess()
				|| !$save->getErrorCollection()->getErrorByCode(ConflictCopyService::ERROR_STALE_COPY))
			{
				return $save;
			}
		}

		return $this->copyService->save($file, $stagedFile, $ownerId, null);
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
		foreach ($sessions as $session)
		{
			$id = (int)$session->getOwnerId();
			if ($id <= 0 || !UserTable::getById($id)->fetch())
			{
				continue;
			}
			$context = $file->getStorage()->getSecurityContext($id);
			if ($file->canRead($context) || $session->canRead($context))
			{
				return $id;
			}
		}
		$id = (int)$file->getCreatedBy();

		return $id > 0 && UserTable::getById($id)->fetch()
			&& $file->canRead($file->getStorage()->getSecurityContext($id)) ? $id : 0;
	}

	private function getDelivery(int $id, bool $lock = false): ?array
	{
		$query = DeliveryTable::query()->setSelect(['*'])->where('ID', $id);

		return $lock ? DocumentStateRepository::fetchForUpdate($query) : ($query->fetch() ?: null);
	}

	private function matchesDelivery(?array $delivery, array $payload): bool
	{
		return $delivery && $delivery['HOST_DOC_KEY'] === ($payload['host_doc_key'] ?? null)
			&& (string)$delivery['SNAPSHOT_SEQ'] === (string)($payload['snapshot_seq'] ?? null);
	}

	private function isApplied(array $delivery): bool
	{
		return in_array($delivery['STATUS'], [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true)
			&& ($delivery['OUTCOME'] ?? null) === DeliveryTable::OUTCOME_APPLIED
			&& (int)$delivery['RESULT_OBJECT_ID'] > 0;
	}

	private function isAllowedFileUrl(string $url): bool
	{
		$actual = new Uri($url);
		$expected = new Uri((string)$this->getConfiguration()->getServer());

		return in_array(mb_strtolower($actual->getScheme()), ['http', 'https'], true)
			&& $expected->getHost() !== '' && mb_strtolower($actual->getHost()) === mb_strtolower($expected->getHost())
			&& $actual->getPort() === $expected->getPort();
	}

	private function getConfiguration(): Configuration
	{
		return $this->configuration ?? ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	private function failure(string $message, string $code = 'vibeoffice_saved_write_failed'): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
