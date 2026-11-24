<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Throwable;

final class AccessRevocation
{
	private const RETRY_SECONDS = 30;

	public function __construct(
		private readonly DocumentStateRepository $repository = new DocumentStateRepository(),
		private readonly ?ProtocolClientInterface $protocolClient = null,
	)
	{
	}

	/** The source transaction already owns the real object row; do not acquire a document mutex here. */
	public function markExternalSourceLocked(int $realObjectId, int $externalLinkId, bool $isFolder = false): Result
	{
		if (!DocumentStateRepository::hasOpenTransaction() || $realObjectId <= 0 || $externalLinkId <= 0)
		{
			return $this->failure('The source transaction and real file lock are required.');
		}
		// A caller may retain an older negative Option cache or transaction snapshot.
		$used = SharedEditingUsage::hasBeenUsed();
		if (!$used)
		{
			$used = SharedEditingUsage::isPersistedFresh();
		}
		if ($used)
		{
			if (!SharedEditingSchema::hasSourceRevision())
			{
				return $this->failure('The editing source revoke revision is unavailable.');
			}
			// The caller owns the real file and source row; this fence commits with the source change.
			$connection = Application::getConnection();
			$connection->queryExecute('UPDATE b_disk_external_link SET VIBEOFFICE_REVOKE_REV=VIBEOFFICE_REVOKE_REV+1'
				. ' WHERE ID=' . $externalLinkId . ' AND OBJECT_ID IN (SELECT ID FROM b_disk_object'
				. ' WHERE ID=' . $realObjectId . ' OR REAL_OBJECT_ID=' . $realObjectId . ')'
				. ' AND VIBEOFFICE_REVOKE_REV>=0 AND VIBEOFFICE_REVOKE_REV<2147483647');
			if ((int)$connection->getAffectedRowsCount() !== 1)
			{
				return $this->failure('The editing source revoke revision is unavailable.');
			}
		}
		if (!SharedEditingSchema::hasColumns())
		{
			return (new Result())->setData(['registryIds' => []]);
		}
		$ids = [];
		$registry = new SessionRegistry($this->repository);
		$applyObjectFilter = static fn($query) => $isFolder
			? $query->whereIn(
				'OBJECT_ID',
				ObjectTable::query()
					->setSelect(['REAL_OBJECT_ID'])
					->where('TYPE', ObjectTable::TYPE_FILE)
					->where('PATH_CHILD.PARENT_ID', $realObjectId),
			)
			: $query->where('OBJECT_ID', $realObjectId);
		$eligibleDocuments = $applyObjectFilter(
			DocumentStateTable::query()->setSelect(['HOST_DOC_KEY'])
				->where('EDIT_SCOPE_VERSION', 1)
				->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)
				->where('VERSION_ID', 0),
		);
		// New grants, pending revokes and unfinished opens have independent bounded source pages.
		foreach (['external', 'unified'] as $sourceType)
		{
			$query = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
				->where('ACCESS_SOURCE_TYPE', $sourceType)->where('ACCESS_SOURCE_ID', $externalLinkId)
				->setOrder(['ID' => 'DESC'])->setLimit(50);
			$candidates = [];
			// Snapshot every page before marking, so new debt cannot consume the pending reservation.
			foreach ([0, 1] as $revokeState)
			{
				$page = $isFolder
					? (clone $query)->where('ACCESS_REVOKE_STATE', $revokeState)->fetchAll()
					: DocumentStateRepository::fetchAllForUpdate((clone $query)->where('ACCESS_REVOKE_STATE', $revokeState));
				foreach ($page as $row)
				{
					$candidates[(int)$row['ID']] = $row;
				}
			}
			$remaining = 50;
			foreach ([PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_ACTIVE] as $status)
			{
				if ($remaining === 0) { break; }
				// Equality on both document and status keeps terminal history outside the index prefix.
				$statusQuery = (clone $query)->where('ACCESS_REVOKE_STATE', 2)
					->where('STATUS', $status)->whereIn('HOST_DOC_KEY', clone $eligibleDocuments)->setLimit($remaining);
				$page = $isFolder
					? $statusQuery->fetchAll()
					: DocumentStateRepository::fetchAllForUpdate($statusQuery);
				$remaining -= count($page);
				foreach ($page as $row)
				{
					$candidates[(int)$row['ID']] = $row;
				}
			}
			if (!$candidates) { continue; }
			$documentRows = $applyObjectFilter(
				DocumentStateTable::query()->setSelect(['ID', 'HOST_DOC_KEY', 'OBJECT_ID'])
					->whereIn('HOST_DOC_KEY', array_unique(array_column($candidates, 'HOST_DOC_KEY')))
					->where('EDIT_SCOPE_VERSION', 1)
					->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)
					->where('VERSION_ID', 0)
					->setOrder(['ID' => 'ASC']),
			)->fetchAll();
			if (!$documentRows) { continue; }
			if ($isFolder)
			{
				$objectIds = array_values(array_unique(array_filter(
					array_map(static fn(array $doc): int => (int)($doc['OBJECT_ID'] ?? 0), $documentRows),
					static fn(int $id): bool => $id > 0,
				)));
				if ($objectIds !== [])
				{
					sort($objectIds, SORT_NUMERIC);
					DocumentStateRepository::fetchAllForUpdate(
						ObjectTable::query()->setSelect(['ID'])->whereIn('ID', $objectIds)->setOrder(['ID' => 'ASC']),
					);
				}
				$lockedDocuments = DocumentStateRepository::fetchAllForUpdate(
					$applyObjectFilter(
						DocumentStateTable::query()->setSelect(['ID', 'HOST_DOC_KEY', 'OBJECT_ID'])
							->whereIn('HOST_DOC_KEY', array_unique(array_column($candidates, 'HOST_DOC_KEY')))
							->where('EDIT_SCOPE_VERSION', 1)
							->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)
							->where('VERSION_ID', 0)
							->setOrder(['ID' => 'ASC']),
					),
				);
				$documents = array_fill_keys(array_column($lockedDocuments, 'HOST_DOC_KEY'), true);
				if (!$documents) { continue; }
				$eligibleIds = [];
				$sessionIds = [];
				foreach ($candidates as $candidate)
				{
					if (!isset($documents[$candidate['HOST_DOC_KEY']])) { continue; }
					$eligibleIds[] = (int)$candidate['ID'];
					$sessionId = (int)($candidate['DOCUMENT_SESSION_ID'] ?? 0);
					if ($sessionId > 0) { $sessionIds[$sessionId] = $sessionId; }
				}
				if (!$eligibleIds) { continue; }
				if ($sessionIds !== [])
				{
					ksort($sessionIds, SORT_NUMERIC);
					DocumentStateRepository::fetchAllForUpdate(
						DocumentSessionTable::query()->setSelect(['ID'])
							->whereIn('ID', array_values($sessionIds))->setOrder(['ID' => 'ASC']),
					);
				}
				sort($eligibleIds, SORT_NUMERIC);
				$lockedById = array_column(
					DocumentStateRepository::fetchAllForUpdate(
						PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
							->whereIn('ID', $eligibleIds)->setOrder(['ID' => 'ASC']),
					),
					null,
					'ID',
				);
				$rows = [];
				foreach (array_keys($candidates) as $candidateId)
				{
					if (isset($lockedById[$candidateId]))
					{
						$rows[$candidateId] = $lockedById[$candidateId];
					}
				}
			}
			else
			{
				$documents = array_fill_keys(array_column($documentRows, 'HOST_DOC_KEY'), true);
				$rows = $candidates;
			}
			foreach ($rows as $row)
			{
				if (!isset($documents[$row['HOST_DOC_KEY']])) { continue; }
				$result = $registry->markRevokeLocked($row);
				if (!$result->isSuccess()) { return $result; }
				if ($result->getData()['pending']) { $ids[] = (int)$row['ID']; }
			}
		}

		sort($ids, SORT_NUMERIC);

		return (new Result())->setData(['registryIds' => $ids]);
	}

	/** At most one primary-key lookup per requested source; the shared ExternalLink ORM map stays legacy-safe. */
	public static function sourceRevisions(array $sourceIds, bool $forUpdate = false): ?array
	{
		$sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $id): bool => $id > 0)));
		if (!$sourceIds) { return []; }
		if (count($sourceIds) > 100) { throw new \LogicException('Source revision pages are bounded.'); }
		if ($forUpdate && !DocumentStateRepository::hasOpenTransaction())
		{
			throw new \LogicException('Locked source revisions require a transaction.');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			if (!SharedEditingSchema::hasSourceRevision()) { return null; }
			$result = [];
			foreach (Application::getConnection()->query('SELECT ID,VIBEOFFICE_REVOKE_REV FROM b_disk_external_link WHERE ID IN ('
				. implode(',', $sourceIds) . ')' . ($forUpdate ? ' FOR UPDATE' : ''))->fetchAll() as $row)
			{
				$row = array_change_key_case($row, CASE_UPPER);
				if (preg_match('/^[0-9]+$/D', (string)$row['VIBEOFFICE_REVOKE_REV']) === 1)
				{
					$result[(int)$row['ID']] = (int)$row['VIBEOFFICE_REVOKE_REV'];
				}
			}
			return $result;
		}
		finally { $pool->useMasterOnly(false); }
	}

	/** Missing revisions belong to the original source generation; a malformed snapshot stays unknown. */
	public static function hasCurrentSourceRevision(array $row, ?int $revision): bool
	{
		$access = SharedSessionScope::access($row);
		if ($access === null || $revision === null) { return false; }
		$fingerprints = $access['context']['confirmationFingerprints'] ?? [];
		if (!is_array($fingerprints)) { return false; }
		if ($revision === 0) { return !array_key_exists('sourceRevision', $fingerprints); }
		return is_string($fingerprints['sourceRevision'] ?? null)
			&& hash_equals(self::sourceRevisionFingerprint($revision), $fingerprints['sourceRevision']);
	}

	public static function sourceRevisionFingerprint(int $revision): string
	{
		return hash('sha256', 'disk.vo.source-revision.v1|' . $revision);
	}

	/** Preparation and finalization own separate transactions; the transport owns neither. */
	public function processBinding(int $registryId, ?float $deadline = null): Result
	{
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock())
		{
			return $this->failure('Revoke processing requires its own transaction.');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$lookup = PlatformSessionTable::query()->setSelect(['HOST_DOC_KEY'])->where('ID', $registryId)->fetch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
		if (!$lookup)
		{
			return new Result();
		}
		$key = $lookup['HOST_DOC_KEY'];
		$prepared = $this->repository->withLockedDocument($key, function (array $state) use ($registryId): Result {
			$row = DocumentStateRepository::fetchForUpdate(PlatformSessionTable::query()
				->setSelect(SharedEditingSchema::sessionFields())->where('ID', $registryId));
			if (!$row || $row['HOST_DOC_KEY'] !== $state['HOST_DOC_KEY'])
			{
				return new Result();
			}
			$version = SharedSessionScope::version($state);
			if ($version === 1 && !$this->repository->isInCurrentNamespace($state))
			{
				return $this->failure('The editing grant belongs to a previous platform.');
			}
			if (!in_array($version, [0, 1], true))
			{
				return $this->failure('Unknown editing scope version.');
			}
			$canConfirm = false;
			if ($version === 1)
			{
				if ((int)$row['ACCESS_REVOKE_STATE'] !== 1)
				{
					return new Result();
				}
				$subject = $row['PLATFORM_USER_ID'];
				if (!is_string($subject) || preg_match('/^vp1_[a-f0-9]{32}$/D', $subject) !== 1)
				{
					return $this->failure('Unknown revoke subject.');
				}
				if ($this->hasAbandonedSaveDebtLocked($state['HOST_DOC_KEY'], $subject))
				{
					$pendingRows = DocumentStateRepository::fetchAllForUpdate(PlatformSessionTable::query()
						->setSelect(['ID'])->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])
						->where('PLATFORM_USER_ID', $subject)->where('ACCESS_REVOKE_STATE', 1));
					foreach ($pendingRows as $pendingRow)
					{
						$updated = PlatformSessionTable::update($pendingRow['ID'], [
							'REVOKE_RETRY_AT' => DateTime::createFromTimestamp(time() + self::RETRY_SECONDS),
							'UPDATE_TIME' => new DateTime(),
						]);
						if (!$updated->isSuccess())
						{
							return (new Result())->addErrors($updated->getErrors());
						}
					}

					return (new Result())->setData([
						'confirmed' => false, 'changed' => false, 'requestSeconds' => 0.0,
					]);
				}
				$canConfirm = $this->canConfirmSubjectLocked($state['HOST_DOC_KEY'], $subject);
			}
			else
			{
				$session = DocumentSessionTable::query()->setSelect(['USER_ID'])
					->where('ID', (int)$row['DOCUMENT_SESSION_ID'])
					->where('SERVICE', DocumentService::Vibeoffice->value)->fetch()
				;
				if (!$session)
				{
					return new Result();
				}
				$subject = (string)$session['USER_ID'];
			}

			return (new Result())->setData([
				'subject' => $subject, 'version' => $version, 'canConfirm' => $canConfirm,
			]);
		}, deadline: $deadline);
		if (!$prepared->isSuccess() || !isset($prepared->getData()['subject']))
		{
			return $prepared;
		}
		$subject = $prepared->getData()['subject'];
		$version = $prepared->getData()['version'];
		$canConfirm = $prepared->getData()['canConfirm'];
		$requestStarted = hrtime(true) / 1e9;
		try
		{
			$client = $this->protocolClient ?? ProtocolClientFactory::create();
			$response = $version === 1
				? $client->revokeGrantConfirmed($key, $subject, $deadline)
				: $client->revokeGrant($key, $subject);
		}
		catch (Throwable)
		{
			$response = $this->failure('The revoke outcome is unknown.');
		}
		$requestSeconds = hrtime(true) / 1e9 - $requestStarted;
		if ($version === 0)
		{
			return $response;
		}

		$result = $this->repository->withLockedDocument($key, function (array $state) use (
			$key, $subject, $response, $canConfirm
		): Result {
			if (!$this->repository->isInCurrentNamespace($state))
			{
				return $this->failure('The editing grant belongs to a previous platform.');
			}
			$changed = false;
			if ($canConfirm && $response->isSuccess())
			{
				$confirmed = (new SessionRegistry($this->repository))->confirmRevokeLocked(
					$key, $subject, $response->getData(),
				);
				if (!$confirmed->isSuccess())
				{
					return $confirmed;
				}
				$changed = ($confirmed->getData()['confirmedCount'] ?? 0) > 0;
			}
			$rows = DocumentStateRepository::fetchAllForUpdate(PlatformSessionTable::query()
				->setSelect(['ID'])->where('HOST_DOC_KEY', $key)->where('PLATFORM_USER_ID', $subject)
				->where('ACCESS_REVOKE_STATE', 1));
			foreach ($rows as $row)
			{
				$result = PlatformSessionTable::update($row['ID'], [
					'REVOKE_RETRY_AT' => DateTime::createFromTimestamp(time() + self::RETRY_SECONDS),
					'UPDATE_TIME' => new DateTime(),
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
			}

			return (new Result())->setData(['confirmed' => $rows === [], 'changed' => $changed]);
		}, deadline: $deadline);
		return $result->setData($result->getData() + ['requestSeconds' => $requestSeconds]);
	}

	/** Pending revoke blocks new sends; an ACK must follow the settlement of all previously issued sends. */
	private function canConfirmSubjectLocked(string $hostDocKey, string $subject): bool
	{
		$rows = DocumentStateRepository::fetchAllForUpdate(PlatformSessionTable::query()
			->setSelect(SharedEditingSchema::sessionFields())->where('HOST_DOC_KEY', $hostDocKey)
			->where('PLATFORM_USER_ID', $subject)->setOrder(['ID' => 'ASC']));
		foreach ($rows as $row)
		{
			if (!in_array((int)$row['ACCESS_REVOKE_STATE'], [1, 2], true) || !is_string($row['OPEN_TOKEN']))
			{
				return false;
			}
			$local = DocumentStateRepository::fetchForUpdate(DocumentSessionTable::query()
				->setSelect(['*'])->where('ID', (int)$row['DOCUMENT_SESSION_ID']));
			$session = $local ? DocumentSession::buildFromArray($local) : null;
			if (!$session && $row['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				// A completed open no longer needs a carrier to establish that its send settled.
				continue;
			}
			if (!$session || !SharedSessionScope::matchesBinding($session, $row)
				|| DocumentSessionManager::hasAbandonedSavePending($session))
			{
				return false;
			}
			$map = OpenAttemptState::readMap(DocumentSessionManager::extractVoMetadata($session));
			$attempt = $map[$row['OPEN_TOKEN']] ?? null;
			if (!OpenAttemptState::isValid($attempt) || $attempt['outstanding'] !== 0)
			{
				return false;
			}
		}

		return $rows !== [];
	}

	private function hasAbandonedSaveDebtLocked(string $hostDocKey, string $subject): bool
	{
		$rows = DocumentStateRepository::fetchAllForUpdate(PlatformSessionTable::query()
			->setSelect(SharedEditingSchema::sessionFields())->where('HOST_DOC_KEY', $hostDocKey)
			->where('PLATFORM_USER_ID', $subject)->setOrder(['ID' => 'ASC']));
		foreach ($rows as $row)
		{
			$local = DocumentStateRepository::fetchForUpdate(DocumentSessionTable::query()
				->setSelect(['*'])->where('ID', (int)$row['DOCUMENT_SESSION_ID']));
			$session = $local ? DocumentSession::buildFromArray($local) : null;
			if ($session && SharedSessionScope::matchesBinding($session, $row)
				&& DocumentSessionManager::hasAbandonedSavePending($session))
			{
				return true;
			}
		}

		return false;
	}

	private function failure(string $message): Result
	{
		return (new Result())->addError(new Error($message, 'vibeoffice_revoke_pending'));
	}
}
