<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformNamespace;
use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseFailureResult;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;

class DocumentStateRepository
{
	public const SOURCE_DISCOVERY_CONNECTION_NAME = 'disk.vibeoffice.source_discovery';
	private const LOCK_TIMEOUT = 5;
	public const REASON_PLACEMENT_CONFLICT = 'placement_conflict';

	private const LOGGER_ID = 'disk.vibeoffice.state';

	private static array $heldLocks = [];
	private static array $freshDocumentTransactions = [];
	private static ?array $preLockedObjects = null;
	private static ?Connection $discoveryConnection = null;
	private static ?Connection $discoveryPrimaryConnection = null;
	private ?array $sharedAdmission = null;

	private LoggerInterface $diagnosticLogger;

	public function __construct(
		private readonly ?Configuration $configuration = null,
		?LoggerInterface $diagnosticLogger = null,
	)
	{
		$this->diagnosticLogger = $diagnosticLogger ?? LoggerProvider::get(self::LOGGER_ID);
	}

	public function getByKey(string $hostDocKey): ?array
	{
		return Models\DocumentStateTable::query()
			->setSelect(SharedEditingSchema::documentFields())
			->where('HOST_DOC_KEY', $hostDocKey)
			->fetch() ?: null
		;
	}

	/** The receipt is returned only after the transaction containing its reservation commits. */
	public function admitSharedEdit(
		array $localFields,
		array $access,
		\Closure $revalidate,
		OpeningBudget $budget,
	): Result
	{
		if ($budget->remainingMs() <= 0 || self::hasOpenTransaction() || self::holdsAnyLock())
		{
			return $this->sharedBusy();
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			if (self::hasOpenTransaction() || self::holdsAnyLock()
				|| !SharedEditingSchema::isReady() || !Feature::isSharedEditingEnabled()
				|| !is_array($localFields['fields'] ?? null)
				|| !array_key_exists('hasUploadCleanupMarker', $localFields)
				|| ($localFields['hasUploadCleanupMarker'] !== null
					&& !is_bool($localFields['hasUploadCleanupMarker'])))
			{
				return $this->sharedBusy();
			}
			$fields = $localFields['fields'];
			$objectId = (int)($fields['OBJECT_ID'] ?? 0);
			if (!$this->isValidSharedAccess($access, $objectId)
				|| (int)($fields['USER_ID'] ?? -1) !== (int)\Bitrix\Main\Engine\CurrentUser::get()->getId())
			{
				return $this->failure('Invalid editing access.', 'vibeoffice_access_denied');
			}
			if (!SharedEditingUsage::markUsed())
			{
				return $this->sharedBusy();
			}
			$transition = new SharedEditTransition($this);
			while ($budget->remainingMs() > 0)
			{
				$file = File::loadById($objectId);
				if (!$file || $file->isDeleted() || (int)$file->getRealObjectId() !== $objectId)
				{
					return $this->sharedBusy();
				}
				$local = DocumentSession::buildFromArray($fields);
				$scope = HostDocumentKey::createScope($local, $file, 1);
				if (!$this->matchesAccessChannel($local, $access))
				{
					return $this->failure('Invalid editing channel.', 'vibeoffice_access_denied');
				}
				if (!$this->findSharedMarker($objectId))
				{
					$prepared = $transition->prepare($objectId, $budget);
					if (!$prepared->isSuccess())
					{
						return $prepared;
					}
					$file = File::loadById($objectId);
					if (!$file || $file->isDeleted())
					{
						return $this->sharedBusy();
					}
				}
				$content = $this->liveContent($file);
				$candidate = $this->findSharedCandidate($objectId, $content);
				$generation = hash('sha256', 'shared-edit-v1:'
					. HostDocumentKey::contentGeneration($scope, $content['EXPECTED_FILE_ID'],
						$content['EXPECTED_CONTENT_VERSION']) . ':' . bin2hex(random_bytes(32)));
				$mode = ($this->configuration ?? new Configuration())->isStableDocumentKeyEnabled()
					? HostDocumentKey::MODE_STABLE : HostDocumentKey::MODE_LEGACY;
				$key = $candidate['HOST_DOC_KEY'] ?? ($mode === HostDocumentKey::MODE_STABLE
					? HostDocumentKey::create($scope, $generation) : bin2hex(random_bytes(16)));
				if ($budget->remainingMs() <= 0)
				{
					return $this->sharedBusy();
				}
				$selection = [
					'scope' => $scope, 'content' => $content, 'candidate' => $candidate,
					'generation' => $generation, 'mode' => $mode, 'key' => $key,
				];
				$result = $this->runLocked(
					self::getDocumentLockName($key),
					fn(): Result => $this->admitSharedEditLocked(
						$localFields, $access, $revalidate, $budget, $transition, $selection,
					),
					false,
					min(self::LOCK_TIMEOUT, intdiv($budget->remainingMs(), 1000)),
					$budget->deadlineForOpen(),
				);
				$errorCodes = array_map(static fn(Error $error) => $error->getCode(), $result->getErrors());
				if (!in_array('vibeoffice_candidate_changed', $errorCodes, true))
				{
					return $budget->remainingMs() <= 0
						|| array_intersect(['vibeoffice_lock_timeout', 'vibeoffice_state_failed'], $errorCodes) !== []
						? $this->sharedBusy() : $result;
				}
			}
			return $this->sharedBusy();
		}
		catch (\Throwable $exception)
		{
			$this->logStateException($exception);
			return $this->sharedBusy();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function admitSharedEditLocked(
		array $localFields,
		array $access,
		\Closure $revalidate,
		OpeningBudget $budget,
		SharedEditTransition $transition,
		array $selection,
	): Result
	{
		['scope' => $scope, 'content' => $content, 'candidate' => $candidate,
			'generation' => $generation, 'mode' => $mode, 'key' => $key] = $selection;
		$objectId = $scope['OBJECT_ID'];
		$fields = $localFields['fields'];
		// The SQL scope bounds native waits to one second; the mutex may have spent that remainder.
		if ($budget->remainingMs() < 1100)
		{
			return $this->sharedBusy();
		}
		$file = $this->loadLockedFile($objectId);
		if (!$file || $file->isDeleted() || (int)$file->getRealObjectId() !== $objectId
			|| $budget->remainingMs() <= 0 || !Feature::isSharedEditingEnabled())
		{
			return $this->sharedBusy();
		}
		$verified = $revalidate($file);
		if (!$verified->isSuccess())
		{
			return $verified;
		}
		$freshAccess = $verified->getData();
		if (!$this->isValidSharedAccess($freshAccess, $objectId)
			|| !$this->sameAccessVisitor($access, $freshAccess))
		{
			return $this->failure('The editing source changed.', 'vibeoffice_access_denied');
		}
		$currentContent = $this->liveContent($file);
		$current = $this->findSharedCandidate($objectId, $currentContent, true);
		if ($currentContent !== $content
			|| ($current['HOST_DOC_KEY'] ?? null) !== ($candidate['HOST_DOC_KEY'] ?? null))
		{
			return $this->failure('The editing candidate changed.', 'vibeoffice_candidate_changed');
		}
		$marker = $this->findSharedMarker($objectId, true);
		if (!$marker)
		{
			$settled = $transition->verifyLocked($file, $budget);
			if (!$settled->isSuccess())
			{
				return $budget->remainingMs() <= 0 ? $this->sharedBusy() : $settled;
			}
		}
		if ($budget->remainingMs() <= 0)
		{
			return $this->sharedBusy();
		}
		$owner = (new SharedEditingAccess())->resolveOwner(
			$file, $freshAccess, isset($fields['OWNER_ID']) ? (int)$fields['OWNER_ID'] : null,
		);
		if ($owner === null)
		{
			return $this->failure('The editing owner is unavailable.', 'vibeoffice_owner_unavailable');
		}
		if ($budget->remainingMs() <= 0)
		{
			return $this->sharedBusy();
		}
		if (!$current)
		{
			$added = Models\DocumentStateTable::add($scope + $content + [
				'HOST_DOC_KEY' => $key, 'KEY_MODE' => $mode, 'CONTENT_GENERATION' => $generation,
				'EDIT_FENCE_STATE' => $marker ? 0 : 1,
				'NAMESPACE' => $this->getPlatformNamespace()->current() ?? '',
			]);
			if (!$added->isSuccess())
			{
				return (new Result())->addErrors($added->getErrors());
			}
			$current = $this->findStateForUpdate($key);
		}
		elseif (!$marker)
		{
			$updated = Models\DocumentStateTable::update($current['ID'], ['EDIT_FENCE_STATE' => 1]);
			if (!$updated->isSuccess())
			{
				return (new Result())->addErrors($updated->getErrors());
			}
			$current['EDIT_FENCE_STATE'] = 1;
		}
		$result = $this->reserveSharedSession($localFields, $current, $freshAccess, $owner);
		return $budget->remainingMs() > 0 && $this->isValidSharedAccess($freshAccess, $objectId)
			? $result : $this->sharedBusy();
	}

	private function reserveSharedSession(array $localFields, array $state, array $access, int $owner): Result
	{
		$fields = $localFields['fields'];
		$key = $state['HOST_DOC_KEY'];
		$objectId = (int)$state['OBJECT_ID'];
		$content = [
			'EXPECTED_FILE_ID' => (int)$state['EXPECTED_FILE_ID'],
			'EXPECTED_CONTENT_VERSION' => (int)$state['EXPECTED_CONTENT_VERSION'],
		];
		$context = Json::decode($fields['CONTEXT']);
		unset($context[DocumentSessionManager::CONTEXT_VO_KEY]);
		$fields['CONTEXT'] = Json::encode($context);
		$fields['OWNER_ID'] = $owner;
		$fields['EXTERNAL_HASH'] = bin2hex(random_bytes(16));
		unset($fields['ID']);
		$errors = new \Bitrix\Disk\Internals\Error\ErrorCollection();
		$session = DocumentSession::add($fields, $errors, $localFields['hasUploadCleanupMarker']);
		if (!$session)
		{
			return $this->failure('Could not create an editing channel.', 'vibeoffice_session_failed');
		}
		self::fetchForUpdate(DocumentSessionTable::query()->setSelect(['ID'])->where('ID', $session->getId()));
		$metadata = [
			HostDocumentKey::CONTEXT_KEY => $key,
			DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY => $content['EXPECTED_CONTENT_VERSION'],
			'contentFileId' => $content['EXPECTED_FILE_ID'],
			DocumentSessionManager::CONTEXT_VO_SAVED_VERSION_ID_KEY =>
				DocumentSessionManager::getLatestSavedVersionId($objectId),
		];
		if (!(new DocumentSessionManager())->mergeVoMetadataLocked(
			$session, static fn(array $vo): array => array_merge($vo, $metadata),
		))
		{
			return $this->sharedBusy();
		}
		$session = DocumentSession::loadById((int)$session->getId());
		$this->sharedAdmission = ['key' => $key, 'access' => $access];
		try
		{
			$reserved = (new SessionRegistry($this))->reserveSharedOpenLocked($session, $state, $access);
		}
		finally
		{
			$this->sharedAdmission = null;
		}
		if (!$reserved->isSuccess())
		{
			return $reserved;
		}
		return (new Result())->setData($reserved->getData() + [
			'session' => DocumentSession::loadById((int)$session->getId()),
			'state' => $state, 'access' => $access,
		]);
	}

	/** Only the repository currently creating the durable receipt can reserve its shared token. */
	public function isSharedAdmissionLocked(string $key, array $access): bool
	{
		return $this->sharedAdmission === ['key' => $key, 'access' => $access]
			&& self::hasOpenTransaction() && self::isDocumentLocked($key);
	}

	public function isLegacyEditBlocked(int $objectId): bool
	{
		return Feature::isSharedEditingEnabled()
			|| $this->findSharedMarker($objectId, self::hasOpenTransaction()) !== null;
	}

	public function hasSharedEditMarker(int $objectId): bool
	{
		return $this->findSharedMarker($objectId, self::hasOpenTransaction()) !== null;
	}

	/** Direct legacy forks still serialize their local insert with the first shared marker. */
	public function createLegacyLocalSession(int $objectId, callable $factory): Result
	{
		return $this->withPrimaryTransaction(function () use ($objectId, $factory): Result {
			$this->loadLockedFile($objectId);
			if ($this->isLegacyEditBlocked($objectId))
			{
				return $this->sharedBusy();
			}
			$session = $factory();
			return $session instanceof DocumentSession
				? (new Result())->setData(['session' => $session]) : $this->sharedBusy();
		});
	}

	private function findSharedMarker(int $objectId, bool $locked = false): ?array
	{
		if (array_diff(['EDIT_SCOPE_VERSION', 'EDIT_FENCE_STATE'], SharedEditingSchema::documentFields()) !== [])
		{
			return null;
		}
		$query = Models\DocumentStateTable::query()->setSelect(['ID'])
			->where('OBJECT_ID', $objectId)->where('EDIT_SCOPE_VERSION', 1)
			->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)->where('VERSION_ID', 0)
			->whereIn('EDIT_FENCE_STATE', [1, 2])->setLimit(1);
		return $locked ? self::fetchForUpdate($query) : ($query->fetch() ?: null);
	}

	private function findSharedCandidate(int $objectId, array $content, bool $locked = false): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(SharedEditingSchema::documentFields())
			->where('OBJECT_ID', $objectId)->where('EDIT_SCOPE_VERSION', 1)
			->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)->where('VERSION_ID', 0)
			->where('EXTERNAL_LINK_ID', 0)->where('CONFLICTED', 0)->whereIn('EDIT_FENCE_STATE', [0, 1])
			->setOrder(['ID' => 'DESC'])->setLimit(1);
		$namespaces = $this->getPlatformNamespace()->documentValues();
		if ($namespaces !== null)
		{
			$query->whereIn('NAMESPACE', $namespaces);
		}
		foreach ($content as $field => $value)
		{
			$query->where($field, $value);
		}
		$state = $locked ? self::fetchForUpdate($query) : ($query->fetch() ?: null);
		return $state && $this->matchesContent($state, $content) ? $state : null;
	}

	private function isValidSharedAccess(array $access, int $objectId): bool
	{
		$context = $access['context'] ?? null;
		$source = $access['sourceType'] ?? null;
		$id = $access['sourceId'] ?? null;
		if (!is_array($context) || $objectId <= 0 || ($context['schemaVersion'] ?? null) !== 1
			|| array_diff(array_keys($context), [
				'schemaVersion', 'objectId', 'sourceType', 'sourceId', 'attachedObjectId',
				'versionId', 'visitorFingerprint', 'confirmationFingerprints',
			]) !== []
			|| ($context['objectId'] ?? null) !== $objectId || ($context['sourceType'] ?? null) !== $source
			|| ($context['sourceId'] ?? null) !== $id || !is_int($id)
			|| !in_array($source, ['internal', 'attached', 'external', 'unified'], true)
			|| !array_key_exists('actorUserId', $access)
			|| ($access['actorUserId'] !== null && (!is_int($access['actorUserId']) || $access['actorUserId'] <= 0))
			|| !array_key_exists('versionId', $context) || $context['versionId'] !== null
			|| !array_key_exists('attachedObjectId', $context)
			|| ($context['attachedObjectId'] !== null
				&& (!is_int($context['attachedObjectId']) || $context['attachedObjectId'] <= 0))
			|| !is_string($context['visitorFingerprint'] ?? null)
			|| preg_match('/^[a-f0-9]{64}$/D', $context['visitorFingerprint']) !== 1
			|| !is_array($context['confirmationFingerprints'] ?? null)
			|| !is_string($access['contextHash'] ?? null)
			|| !hash_equals(hash('sha256', Json::encode($context)), $access['contextHash'])
			|| !array_key_exists('expiresAt', $access)
			|| ($access['expiresAt'] !== null && (!$access['expiresAt'] instanceof DateTime
				|| $access['expiresAt']->getTimestamp() <= time())))
		{
			return false;
		}
		foreach ($context['confirmationFingerprints'] as $fingerprint)
		{
			if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1)
			{
				return false;
			}
		}
		return match ($source)
		{
			'internal' => $id === 0 && $context['attachedObjectId'] === null,
			'attached' => $id > 0 && $context['attachedObjectId'] === $id,
			default => $id > 0,
		};
	}

	private function sameAccessVisitor(array $before, array $after): bool
	{
		foreach (['actorUserId', 'sourceType', 'sourceId'] as $field)
		{
			if ($before[$field] !== $after[$field])
			{
				return false;
			}
		}
		foreach (['objectId', 'attachedObjectId', 'versionId', 'visitorFingerprint'] as $field)
		{
			if ($before['context'][$field] !== $after['context'][$field])
			{
				return false;
			}
		}
		return true;
	}

	private function matchesAccessChannel(DocumentSession $session, array $access): bool
	{
		return (int)$session->getExternalLinkId() ===
			(in_array($access['sourceType'], ['external', 'unified'], true) ? $access['sourceId'] : 0)
			&& $session->getContext()?->getAttachedObjectId() === $access['context']['attachedObjectId'];
	}

	private function sharedBusy(): Result
	{
		return $this->failure('Shared editing admission must be retried.', SessionRegistry::ERROR_CODE_BUSY);
	}

	public function isInCurrentNamespace(array $state): bool
	{
		return $this->getPlatformNamespace()->isCurrent((string)$state['NAMESPACE']);
	}

	public function currentNamespaceValues(): ?array
	{
		return $this->getPlatformNamespace()->documentValues();
	}

	public function hasEditStateInCurrentNamespace(int $objectId): bool
	{
		$namespaces = $this->getPlatformNamespace()->documentValues();
		if ($namespaces === null)
		{
			return false;
		}

		$query = Models\DocumentStateTable::query()
			->setSelect(['ID'])
			->where('OBJECT_ID', $objectId)
			->where('VERSION_ID', 0)
			->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)
			->whereIn('NAMESPACE', $namespaces)
			->setLimit(1)
		;

		return self::fetchForUpdate($query) !== null;
	}

	private function getPlatformNamespace(): PlatformNamespace
	{
		return new PlatformNamespace(
			$this->configuration ?? ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration'),
		);
	}

	public function getSnapshot(string $hostDocKey, int $snapshotSeq): ?array
	{
		return Models\SavedSnapshotTable::query()
			->setSelect(['*'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('SNAPSHOT_SEQ', $snapshotSeq)
			->fetch() ?: null
		;
	}

	/** One current census per transition phase; its local debt index never outlives that phase. */
	public function getOldEditCensus(int $realObjectId, ?OpeningBudget $budget = null): array
	{
		$states = $localDebt = [];
		$foreign = [];
		$namespaces = $this->getPlatformNamespace();
		$switched = $namespaces->hasSwitched();
		$query = Models\DocumentStateTable::query()->setSelect(SharedEditingSchema::documentFields())
			->where('OBJECT_ID', $realObjectId)->where('VERSION_ID', 0)
			->where('SESSION_TYPE', DocumentSession::TYPE_EDIT);
		foreach ($this->iterateRows($query, $budget) as $state)
		{
			if (!$namespaces->isCurrent((string)$state['NAMESPACE']))
			{
				$foreign[$state['HOST_DOC_KEY']] = true;
				continue;
			}
			$states[$state['HOST_DOC_KEY']] = $state;
		}
		$query = $this->localEditQuery($realObjectId);
		$lastId = 0;
		do
		{
			$this->checkCensusBudget($budget);
			$page = (clone $query)->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(300);
			$rows = self::hasOpenTransaction() ? self::fetchAllForUpdate($page) : $page->fetchAll();
			$this->checkCensusBudget($budget);
			$bindings = [];
			if ($rows !== [])
			{
				$registry = Models\PlatformSessionTable::query()
					->setSelect(['ID', 'HOST_DOC_KEY', 'DOCUMENT_SESSION_ID', 'STATUS'])
					->whereIn('DOCUMENT_SESSION_ID', array_column($rows, 'ID'));
				foreach ($this->iterateRows($registry, $budget) as $binding)
				{
					if (isset($foreign[$binding['HOST_DOC_KEY']])
						|| ($switched && !array_key_exists($binding['HOST_DOC_KEY'], $states)))
					{
						continue;
					}
					$bindings[(int)$binding['DOCUMENT_SESSION_ID']][$binding['HOST_DOC_KEY']] = true;
					if (in_array($binding['STATUS'], [
						Models\PlatformSessionTable::STATUS_ACTIVE, Models\PlatformSessionTable::STATUS_PENDING,
					], true) && !array_key_exists($binding['HOST_DOC_KEY'], $states))
					{
						$states[$binding['HOST_DOC_KEY']] = null;
					}
				}
			}
			foreach ($rows as $row)
			{
				$this->checkCensusBudget($budget);
				$lastId = (int)$row['ID'];
				$session = DocumentSession::buildFromArray($row);
				$key = HostDocumentKey::resolve($session);
				if (isset($foreign[$key]) || ($switched && !array_key_exists($key, $states)))
				{
					continue;
				}
				$vo = DocumentSessionManager::extractVoMetadata($session);
				$debt = OpenAttemptState::isFloorCorrupt($vo) || OpenAttemptState::protectsCarrier($vo)
					|| ($session->isActive() && !isset($bindings[$lastId][$key]));
				$localDebt[$key] = ($localDebt[$key] ?? false) || $debt;
				if (($session->isActive() || $debt) && !array_key_exists($key, $states))
				{
					$states[$key] = null;
				}
			}
		}
		while (count($rows) === 300);

		$this->checkCensusBudget($budget);
		return ['states' => $states, 'localDebt' => $localDebt];
	}

	private function localEditQuery(int $realObjectId): Query
	{
		return DocumentSessionTable::query()->setSelect(['*'])
			->where('OBJECT_ID', $realObjectId)->where('TYPE', DocumentSession::TYPE_EDIT)
			->where('SERVICE', DocumentService::Vibeoffice->value)
			->where(Query::filter()->logic('or')->whereNull('VERSION_ID')->where('VERSION_ID', 0));
	}

	/** @return \Generator<DocumentSession> */
	private function iterateLocalEditSessions(int $realObjectId, ?OpeningBudget $budget = null): \Generator
	{
		$query = $this->localEditQuery($realObjectId);
		foreach ($this->iterateRows($query, $budget) as $row)
		{
			yield DocumentSession::buildFromArray($row);
		}
	}

	/** Locking reads inside the final transaction avoid an earlier repeatable-read snapshot. */
	private function iterateRows(Query $query, ?OpeningBudget $budget = null): \Generator
	{
		$lastId = 0;
		do
		{
			$this->checkCensusBudget($budget);
			$page = (clone $query)->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(300);
			$rows = self::hasOpenTransaction() ? self::fetchAllForUpdate($page) : $page->fetchAll();
			$this->checkCensusBudget($budget);
			foreach ($rows as $row)
			{
				$this->checkCensusBudget($budget);
				$lastId = (int)$row['ID'];
				yield $row;
			}
		}
		while (count($rows) === 300);
	}

	private function checkCensusBudget(?OpeningBudget $budget): void
	{
		if ($budget !== null && $budget->remainingMs() <= 0)
		{
			throw new \RuntimeException('The editing transition census budget expired.');
		}
	}

	/** Nullable revisions are excluded explicitly because PostgreSQL sorts nulls first in DESC order. */
	public function getPlacementRejection(array $state, array $placement): ?string
	{
		$editorKey = $placement['editorKey'] ?? null;
		$rev = $placement['rev'] ?? null;
		if (!is_string($editorKey) || $editorKey === '' || strlen($editorKey) > 255
			|| !is_int($rev) || $rev < 0 || $rev > 2_147_483_647)
		{
			return 'foreign_scope';
		}
		$query = Models\PlatformSessionTable::query()->setSelect(['ID', 'EDITOR_KEY', 'REV'])
			->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])->whereNotNull('EDITOR_KEY')->whereNotNull('REV');
		$latest = (clone $query)->setOrder(['REV' => 'DESC', 'ID' => 'DESC'])->setLimit(1);
		$row = self::hasOpenTransaction() ? self::fetchForUpdate($latest) : $latest->fetch();
		if (!$row || (int)$row['REV'] < $rev)
		{
			return null;
		}
		if ((int)$row['REV'] > $rev)
		{
			return 'foreign_scope';
		}
		// A generation marker fences old events but does not admit an otherwise unknown editor key.
		foreach ($this->iterateRows($query->where('REV', $rev)->whereNotNull('API_SESSION_ID')) as $known)
		{
			if ($known['EDITOR_KEY'] === $editorKey)
			{
				return null;
			}
		}

		return self::REASON_PLACEMENT_CONFLICT;
	}

	public function acceptsPlacement(array $state, array $placement): bool
	{
		return $this->getPlacementRejection($state, $placement) === null;
	}

	/** The maximum known admitted revision, including terminated rows, identifies the terminal placement. */
	public function matchesTerminalPlacement(array $state, array $placement): bool
	{
		if (!$this->acceptsPlacement($state, $placement))
		{
			return false;
		}
		$query = Models\PlatformSessionTable::query()->setSelect(['ID', 'EDITOR_KEY', 'REV'])
			->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])->whereNotNull('EDITOR_KEY')->whereNotNull('REV')
			->setOrder(['REV' => 'DESC', 'ID' => 'DESC'])->setLimit(1);
		if (SharedSessionScope::version($state) === 1)
		{
			// A generation marker fences late responses, but cannot establish an admitted placement.
			$query->whereNotNull('PLATFORM_USER_ID')->whereNotNull('OPEN_TOKEN')
				->whereNotNull('API_SESSION_ID')->where('DOCUMENT_SESSION_ID', '>', 0);
		}
		$row = self::hasOpenTransaction() ? self::fetchForUpdate($query) : $query->fetch();

		return $row && $row['EDITOR_KEY'] === ($placement['editorKey'] ?? null)
			&& (int)$row['REV'] === ($placement['rev'] ?? null);
	}

	public function hasTerminalDebt(
		array $state,
		int $ownDeliveryId = 0,
		?array $savingPlacement = null,
		?bool $localDebt = null,
		?OpeningBudget $budget = null,
	): bool
	{
		$this->checkCensusBudget($budget);
		$key = $state['HOST_DOC_KEY'];
		$query = Models\PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
			->where('HOST_DOC_KEY', $key);
		foreach ($this->iterateRows($query, $budget) as $row)
		{
			if ($row['STATUS'] === Models\PlatformSessionTable::STATUS_PENDING)
			{
				return true;
			}
			if ($row['STATUS'] === Models\PlatformSessionTable::STATUS_ACTIVE && (
				$savingPlacement === null || $row['EDITOR_KEY'] !== $savingPlacement['editorKey']
				|| (int)$row['REV'] !== $savingPlacement['rev']
			))
			{
				return true;
			}
		}
		if ($localDebt === true)
		{
			return true;
		}
		if ($localDebt === null)
		{
			// Stable orphan keys live in CONTEXT, so discovery must stay current and complete.
			// The enclosing live EDIT transaction owns the real file before any child rows.
			foreach ($this->iterateLocalEditSessions((int)$state['OBJECT_ID'], $budget) as $session)
			{
				if (HostDocumentKey::resolve($session) !== $key)
				{
					continue;
				}
				$vo = DocumentSessionManager::extractVoMetadata($session);
				if (OpenAttemptState::isFloorCorrupt($vo) || OpenAttemptState::protectsCarrier($vo))
				{
					return true;
				}
				if ($session->isActive())
				{
					$this->checkCensusBudget($budget);
					$binding = Models\PlatformSessionTable::query()->setSelect(['ID'])
						->where('HOST_DOC_KEY', $key)
						->where('DOCUMENT_SESSION_ID', (int)$session->getId())->setLimit(1);
					if (!(self::hasOpenTransaction() ? self::fetchForUpdate($binding) : $binding->fetch()))
					{
						return true;
					}
				}
			}
		}
		$query = DeliveryTable::query()->setSelect(['*'])
			->where(Query::filter()->logic('or')->where('HOST_DOC_KEY', $key)
				->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $key)))
			->where('EVENT', WebhookProcessor::EVENT_SAVED);
		$supersedingSequence = null;
		foreach ($this->iterateRows($query, $budget) as $delivery)
		{
			if ((int)$delivery['DELIVERY_ID'] === $ownDeliveryId)
			{
				continue;
			}
			if (!in_array($delivery['STATUS'], [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true)
				|| ($delivery['OUTCOME'] ?? null) !== DeliveryTable::OUTCOME_APPLIED
				|| (int)$delivery['RESULT_OBJECT_ID'] <= 0)
			{
				$sequence = (int)($delivery['SNAPSHOT_SEQ'] ?? 0);
				if ($delivery['STATUS'] === DeliveryTable::STATUS_DONE
					&& in_array($delivery['OUTCOME'] ?? null, [DeliveryTable::OUTCOME_IGNORED, DeliveryTable::OUTCOME_REFUSED], true)
					&& $sequence > 0 && $sequence < (int)$state['LAST_SNAPSHOT_SEQ'])
				{
					$supersedingSequence ??= $this->getSupersedingSnapshotSequence($state, $budget);
					if ($sequence < $supersedingSequence)
					{
						continue;
					}
				}

				return true;
			}
		}

		$this->checkCensusBudget($budget);
		return false;
	}

	/** A completed refusal loses its byte obligation only to a later, durably applied snapshot. */
	private function getSupersedingSnapshotSequence(array $state, ?OpeningBudget $budget): int
	{
		$this->checkCensusBudget($budget);
		$sequence = (int)$state['LAST_SNAPSHOT_SEQ'];
		$query = Models\SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])->where('SNAPSHOT_SEQ', $sequence);
		$receipt = self::hasOpenTransaction() ? self::fetchForUpdate($query) : $query->fetch();
		if (!$receipt || (int)$receipt['DELIVERY_ID'] <= 0
			|| !(((int)$receipt['RESULT_OBJECT_ID'] === (int)$state['OBJECT_ID']
					&& (int)$receipt['RESULT_VERSION_ID'] > 0)
				|| ((int)$state['CONFLICTED'] === 1 && (int)$state['CONFLICT_OBJECT_ID'] > 0
					&& (int)$receipt['RESULT_OBJECT_ID'] === (int)$state['CONFLICT_OBJECT_ID'])))
		{
			return 0;
		}
		$this->checkCensusBudget($budget);
		$query = DeliveryTable::query()->setSelect(['*'])
			->where('DELIVERY_ID', (int)$receipt['DELIVERY_ID'])->where('SNAPSHOT_SEQ', $sequence)
			->where('EVENT', WebhookProcessor::EVENT_SAVED)
			->where(Query::filter()->logic('or')->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])
				->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $state['HOST_DOC_KEY'])));
		$canonical = self::hasOpenTransaction() ? self::fetchForUpdate($query) : $query->fetch();
		if (!$canonical || $canonical['OUTCOME'] !== DeliveryTable::OUTCOME_APPLIED
			|| !in_array($canonical['STATUS'], [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true))
		{
			return 0;
		}
		foreach (['RESULT_OBJECT_ID', 'RESULT_VERSION_ID', 'RESULT_CONTENT_VERSION'] as $field)
		{
			if (($receipt[$field] === null ? null : (int)$receipt[$field])
				!== ($canonical[$field] === null ? null : (int)$canonical[$field]))
			{
				return 0;
			}
		}

		return $sequence;
	}

	/** Called only by the signed terminal delivery transaction, after its content and registry writes. */
	public function storeTerminalProof(string $key, array $placement, int $deliveryId, ?array $receipt = null): Result
	{
		if (!self::isDocumentLocked($key) || !self::hasOpenTransaction())
		{
			return $this->failure('Terminal proof requires the document transaction.', 'vibeoffice_proof_unlocked');
		}
		$state = $this->findStateForUpdate($key);
		if (!$state || !array_key_exists('TERMINAL_PROOF', $state)
			|| !in_array(SharedSessionScope::version($state), [0, 1], true)
			|| (int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT || (int)$state['VERSION_ID'] !== 0
			|| !$this->loadLockedFile((int)$state['OBJECT_ID']))
		{
			return new Result();
		}
		if (!$this->matchesTerminalPlacement($state, $placement))
		{
			$pending = Models\PlatformSessionTable::query()->setSelect(['ID'])
				->where('HOST_DOC_KEY', $key)->where('STATUS', Models\PlatformSessionTable::STATUS_PENDING)
				->setLimit(1);
			// An in-flight open may supply this exact placement later. Keep the signed event retryable.
			return self::fetchForUpdate($pending)
				? $this->failure('Terminal placement is waiting for an open response.', 'vibeoffice_terminal_proof_busy')
				: new Result();
		}
		if ($this->hasTerminalDebt($state, $deliveryId, $receipt !== null ? $placement : null))
		{
			return $this->failure('Terminal evidence is waiting for editing debts.', 'vibeoffice_terminal_proof_busy');
		}
		$proof = $receipt === null
			? TerminalProof::fromNotModified($state, $placement, $deliveryId)
			: TerminalProof::fromSaved($state, $placement, $deliveryId, $receipt);

		return Models\DocumentStateTable::update((int)$state['ID'], ['TERMINAL_PROOF' => Json::encode($proof)]);
	}

	public function invalidateTerminalProof(string $key): Result
	{
		if (!self::isDocumentLocked($key) || !self::hasOpenTransaction())
		{
			return $this->failure('Proof invalidation requires the document transaction.', 'vibeoffice_proof_unlocked');
		}
		$state = $this->findStateForUpdate($key);
		return $state && array_key_exists('TERMINAL_PROOF', $state) && $state['TERMINAL_PROOF'] !== null
			? Models\DocumentStateTable::update((int)$state['ID'], ['TERMINAL_PROOF' => null])
			: new Result();
	}

	/**
	 * Lock order: document named lock, transaction, existing live EDIT file, state/session/ledger rows.
	 * The callback must not perform network I/O. Nested transactions leave the caller's commit intact.
	 * Required-file callers fail when it is absent; an old send can still settle after its file was removed.
	 * Audited file changes own the transaction and cannot join an external SQL transaction.
	 *
	 * @param callable(array, ?File): Result $mutation
	 */
	public function withLockedDocument(
		string $hostDocKey,
		callable $mutation,
		bool $lockObject = false,
		bool $auditFileChanges = false,
		?float $deadline = null,
	): Result
	{
		$lookup = null;
		return $this->runLocked(
			self::getDocumentLockName($hostDocKey),
			function () use ($hostDocKey, $mutation, $lockObject, &$lookup): Result {
				$file = null;
				$liveEdit = $lookup && (int)$lookup['SESSION_TYPE'] === DocumentSession::TYPE_EDIT
					&& (int)$lookup['VERSION_ID'] === 0;
				if ($lockObject)
				{
					if (!$lookup)
					{
						return $this->documentNotFound('state_missing');
					}
					$objectId = (int)($lookup['OBJECT_ID'] ?? 0);
					if ($objectId <= 0)
					{
						return $this->documentNotFound('invalid_object_id');
					}
					$file = $this->loadLockedFile($objectId);
					if (!$file)
					{
						return $this->documentNotFound('file_missing');
					}
				}
				elseif ($liveEdit)
				{
					// Missing files must not prevent the settlement of an outstanding send.
					$file = (int)($lookup['OBJECT_ID'] ?? 0) > 0
						? $this->loadLockedFile((int)$lookup['OBJECT_ID']) : null;
				}

				// All live EDIT writers take the existing real file before state/registry/local rows.
				$state = $lockObject || $liveEdit ? $this->findStateForUpdate($hostDocKey) : $lookup;
				if (!$state)
				{
					return $this->documentNotFound('state_missing');
				}

				return $mutation($state, $file);
			},
			$auditFileChanges,
			$deadline === null ? self::LOCK_TIMEOUT : 0,
			$deadline,
			function () use ($hostDocKey, &$lookup): void {
				// Identify the immutable scope before starting a new RR snapshot.
				$lookup = $this->getByKey($hostDocKey);
			},
		);
	}

	public static function getDocumentLockName(string $hostDocKey): string
	{
		return 'disk_vo_document_' . md5($hostDocKey);
	}

	public function withPrimaryTransaction(callable $mutation): Result
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $this->runTransaction($mutation);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	public function registerSharedSavedDelivery(string $hostDocKey, callable $register): Result
	{
		if (self::hasOpenTransaction() || self::holdsAnyLock())
		{
			return $this->failure('Saved registration requires its own transaction.', 'vibeoffice_registry_busy');
		}

		$lookup = $this->getByKey($hostDocKey);
		return $this->withPrimaryTransaction(function () use ($hostDocKey, $register, $lookup): Result {
			$objectId = (int)($lookup['OBJECT_ID'] ?? 0);
			if ($objectId > 0)
			{
				$this->loadLockedFile($objectId);
			}
			$state = $this->findStateForUpdate($hostDocKey);
			if (!$state || (int)$state['OBJECT_ID'] !== $objectId
				|| !HostDocumentKey::matchesSharedEditScope($state, $hostDocKey)
				|| !in_array((int)$state['EDIT_FENCE_STATE'], [0, 1], true))
			{
				return $this->failure('The shared registry is unavailable.', 'vibeoffice_registry_busy');
			}

			return $register($state);
		});
	}

	/** Locking read of an ORM query. The whole module goes through this method instead of its own SQL. */
	public static function fetchForUpdate(Query $query): ?array
	{
		return self::queryForUpdate($query)->fetch() ?: null;
	}

	/** @return array[] */
	public static function fetchAllForUpdate(Query $query): array
	{
		return self::queryForUpdate($query)->fetchAll();
	}

	/** Current reads bypass an older external RR snapshot without taking exclusive source locks. */
	public static function fetchAllForShare(Query $query): array
	{
		$connection = Application::getConnection();
		$suffix = $connection->getType() === 'pgsql' ? ' FOR SHARE' : ' LOCK IN SHARE MODE';
		return $connection->query($query->getQuery() . $suffix)->fetchAll();
	}

	public static function fetchForShare(Query $query): ?array
	{
		return self::fetchAllForShare((clone $query)->setLimit(1))[0] ?? null;
	}

	/**
	 * Orders candidate object IDs from ancestors to descendants using discovered path rows,
	 * breaking ties by ascending ID so parent folders are locked before their subtree items.
	 */
	public static function orderObjectIdsTopologically(array $candidateIds, array $discoveredPathRows): array
	{
		$nodes = [];
		foreach ($candidateIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$nodes[$id] = $id;
			}
		}
		ksort($nodes, SORT_NUMERIC);
		if (count($nodes) <= 1)
		{
			return array_values($nodes);
		}

		$children = array_fill_keys(array_keys($nodes), []);
		$inDegree = array_fill_keys(array_keys($nodes), 0);
		$addEdge = static function (int $fromId, int $toId) use (&$children, &$inDegree, $nodes): void {
			if (
				$fromId > 0
				&& $toId > 0
				&& $fromId !== $toId
				&& isset($nodes[$fromId], $nodes[$toId])
				&& !isset($children[$fromId][$toId])
			)
			{
				$children[$fromId][$toId] = $toId;
				$inDegree[$toId]++;
			}
		};
		$depthsByObject = [];
		foreach ($discoveredPathRows as $row)
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			$objectId = (int)($row['OBJECT_ID'] ?? 0);
			$addEdge($parentId, $objectId);
			if ($parentId > 0 && $objectId > 0 && array_key_exists('DEPTH_LEVEL', $row))
			{
				$depthsByObject[$objectId][] = ['parentId' => $parentId, 'depth' => (int)$row['DEPTH_LEVEL']];
			}
		}
		foreach ($depthsByObject as $entries)
		{
			usort(
				$entries,
				static fn(array $a, array $b): int => ($b['depth'] <=> $a['depth']) ?: ($a['parentId'] <=> $b['parentId']),
			);
			$levels = [];
			foreach ($entries as $entry)
			{
				$levels[$entry['depth']][$entry['parentId']] = $entry['parentId'];
			}
			$levelGroups = array_values($levels);
			$levelCount = count($levelGroups);
			for ($i = 1; $i < $levelCount; $i++)
			{
				foreach ($levelGroups[$i - 1] as $upperParentId)
				{
					foreach ($levelGroups[$i] as $lowerParentId)
					{
						$addEdge($upperParentId, $lowerParentId);
					}
				}
			}
		}

		$zeroQueue = new \SplPriorityQueue();
		$zeroQueue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
		foreach ($nodes as $id)
		{
			if ($inDegree[$id] === 0)
			{
				$zeroQueue->insert($id, -$id);
			}
		}

		$ordered = [];
		$remaining = $nodes;
		while ($remaining !== [])
		{
			$picked = null;
			while (!$zeroQueue->isEmpty())
			{
				$candidate = (int)$zeroQueue->extract();
				if (isset($remaining[$candidate]))
				{
					$picked = $candidate;
					break;
				}
			}
			if ($picked === null)
			{
				foreach ($remaining as $id)
				{
					if ($picked === null || $inDegree[$id] < $inDegree[$picked])
					{
						$picked = $id;
					}
				}
			}
			unset($remaining[$picked]);
			$ordered[] = $picked;
			foreach ($children[$picked] as $childId)
			{
				if (isset($remaining[$childId]))
				{
					$inDegree[$childId]--;
					if ($inDegree[$childId] <= 0)
					{
						$zeroQueue->insert($childId, -$childId);
					}
				}
			}
		}

		return $ordered;
	}

	/**
	 * Acquires shared locks on b_disk_object in a single query ordered by the given topological sequence,
	 * ensuring primary-key index scans cannot lock a descendant with a smaller ID before its ancestor.
	 */
	public static function lockObjectsForShare(array $orderedObjectIds, array $select = ['*']): array
	{
		$ids = [];
		$seen = [];
		$isAscending = true;
		$lastId = 0;
		foreach ($orderedObjectIds as $id)
		{
			$id = (int)$id;
			if ($id <= 0 || isset($seen[$id]))
			{
				continue;
			}
			$seen[$id] = true;
			if ($lastId > 0 && $id <= $lastId)
			{
				$isAscending = false;
			}
			$ids[] = $id;
			$lastId = $id;
		}
		if ($ids === [])
		{
			return [];
		}
		if (self::$preLockedObjects !== null)
		{
			$rows = [];
			foreach ($ids as $id)
			{
				if (isset(self::$preLockedObjects[$id]))
				{
					$rows[] = self::$preLockedObjects[$id];
				}
			}

			return $rows;
		}

		$connection = Application::getConnection();
		$isMysql = $connection->getType() === 'mysql';
		$suffix = $connection->getType() === 'pgsql' ? ' FOR SHARE' : ' LOCK IN SHARE MODE';
		if ($isAscending)
		{
			$sql = ObjectTable::query()
				->setSelect($select)
				->whereIn('ID', $ids)
				->setOrder(['ID' => 'ASC'])
				->getQuery();
			if ($isMysql)
			{
				$sql = preg_replace(
					'/(\bFROM\s+`?b_disk_object`?\s+`?[A-Za-z0-9_]+`?)/i',
					'$1 FORCE INDEX (PRIMARY)',
					$sql,
					1,
				) ?? $sql;
			}

			return $connection->query($sql . $suffix)->fetchAll();
		}

		$sql = ObjectTable::query()
			->setSelect($select)
			->whereIn('ID', $ids)
			->getQuery();
		if ($isMysql)
		{
			$unionParts = [];
			foreach ($ids as $seq => $id)
			{
				$unionParts[] = $seq === 0
					? 'SELECT ' . $id . ' AS `ORD_ID`, 0 AS `ORD_SEQ`'
					: 'UNION ALL SELECT ' . $id . ', ' . $seq;
			}
			$derived = '(' . implode(' ', $unionParts) . ') `disk_vo_ord`';
			$sql = preg_replace(
				'/\bFROM\s+`?b_disk_object`?\s+`?([A-Za-z0-9_]+)`?\s+WHERE\s+.*$/is',
				'FROM ' . $derived . ' STRAIGHT_JOIN `b_disk_object` `$1` FORCE INDEX (PRIMARY) ON `$1`.`ID` = `disk_vo_ord`.`ORD_ID` ORDER BY `disk_vo_ord`.`ORD_SEQ` ASC',
				$sql,
				1,
			) ?? $sql;

			return $connection->query($sql . $suffix)->fetchAll();
		}

		$cases = [];
		foreach ($ids as $seq => $id)
		{
			$cases[] = 'WHEN ' . $id . ' THEN ' . $seq;
		}
		$orderExpr = 'CASE "disk_internals_object"."id" ' . implode(' ', $cases) . ' ELSE ' . count($ids) . ' END ASC';

		return $connection->query($sql . ' ORDER BY ' . $orderExpr . $suffix)->fetchAll();
	}

	/**
	 * Pre-locks all candidate b_disk_object rows in a single topological query before any
	 * secondary table (b_disk_attached_object, b_disk_external_link, b_disk_object_path,
	 * b_disk_unified_link_access, b_disk_object_options, b_disk_version) is locked.
	 */
	public static function withPreLockedObjects(
		array $seedObjectIds,
		array $seedExternalLinkIds,
		array $seedShortCodes,
		array $seedAttachedIds,
		bool $includeObjectPaths,
		callable $callback,
	): mixed
	{
		if (self::$preLockedObjects !== null || !self::shouldBypassRepeatableRead())
		{
			return $callback();
		}

		$candidateObjectIds = [];
		$realFileIds = [];
		foreach ($seedObjectIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$candidateObjectIds[$id] = $id;
				$realFileIds[$id] = $id;
			}
		}

		$attachedIds = array_values(array_unique(array_filter(
			array_map('intval', $seedAttachedIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($attachedIds !== [])
		{
			sort($attachedIds, SORT_NUMERIC);
			$discoveredAttached = self::discoverRowsForFreshRead(
				\Bitrix\Disk\Internals\AttachedObjectTable::query()
					->setSelect(['ID', 'OBJECT_ID'])
					->whereIn('ID', $attachedIds)
					->setOrder(['ID' => 'ASC']),
			);
			if ($discoveredAttached === null)
			{
				self::$preLockedObjects = [];
				try
				{
					return $callback();
				}
				finally
				{
					self::$preLockedObjects = null;
				}
			}
			foreach ($discoveredAttached as $row)
			{
				$objectId = (int)($row['OBJECT_ID'] ?? 0);
				if ($objectId > 0)
				{
					$candidateObjectIds[$objectId] = $objectId;
					$realFileIds[$objectId] = $objectId;
				}
			}
		}

		$linkIds = array_values(array_unique(array_filter(
			array_map('intval', $seedExternalLinkIds),
			static fn(int $id): bool => $id > 0,
		)));
		$shortCodes = array_values(array_unique(array_filter(
			array_map('strval', $seedShortCodes),
			static fn(string $code): bool => $code !== '',
		)));
		$linkTargetIds = [];
		if ($linkIds !== [] || $shortCodes !== [])
		{
			sort($linkIds, SORT_NUMERIC);
			sort($shortCodes, SORT_STRING);
			$linkQuery = \Bitrix\Disk\Internals\ExternalLinkTable::query()
				->setSelect(['ID', 'OBJECT_ID'])
				->setOrder(['ID' => 'ASC']);
			if ($linkIds !== [] && $shortCodes !== [])
			{
				$linkQuery->where(
					Query::filter()
						->logic('or')
						->whereIn('ID', $linkIds)
						->whereIn('CODE', $shortCodes),
				);
			}
			elseif ($linkIds !== [])
			{
				$linkQuery->whereIn('ID', $linkIds);
			}
			else
			{
				$linkQuery->whereIn('CODE', $shortCodes);
			}
			$discoveredLinks = self::discoverRowsForFreshRead($linkQuery);
			if ($discoveredLinks === null)
			{
				self::$preLockedObjects = [];
				try
				{
					return $callback();
				}
				finally
				{
					self::$preLockedObjects = null;
				}
			}
			foreach ($discoveredLinks as $row)
			{
				$objectId = (int)($row['OBJECT_ID'] ?? 0);
				if ($objectId > 0)
				{
					$candidateObjectIds[$objectId] = $objectId;
					$linkTargetIds[$objectId] = $objectId;
				}
			}
		}

		$hasFolderLinkTarget = false;
		if ($candidateObjectIds !== [])
		{
			$baseIds = array_values($candidateObjectIds);
			sort($baseIds, SORT_NUMERIC);
			$discoveredBase = self::discoverRowsForFreshRead(
				ObjectTable::query()
					->setSelect(['ID', 'REAL_OBJECT_ID', 'TYPE'])
					->whereIn('ID', $baseIds)
					->setOrder(['ID' => 'ASC']),
			);
			if ($discoveredBase === null)
			{
				self::$preLockedObjects = [];
				try
				{
					return $callback();
				}
				finally
				{
					self::$preLockedObjects = null;
				}
			}
			foreach ($discoveredBase as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
				if ($id > 0)
				{
					$candidateObjectIds[$id] = $id;
				}
				if ($realId > 0)
				{
					$candidateObjectIds[$realId] = $realId;
					if (isset($realFileIds[$id]))
					{
						$realFileIds[$realId] = $realId;
					}
					if (isset($linkTargetIds[$id]))
					{
						$linkTargetIds[$realId] = $realId;
					}
				}
			}
			$extraRealIds = array_values(array_diff(array_keys($candidateObjectIds), $baseIds));
			if ($extraRealIds !== [])
			{
				sort($extraRealIds, SORT_NUMERIC);
				$discoveredExtra = self::discoverRowsForFreshRead(
					ObjectTable::query()
						->setSelect(['ID', 'REAL_OBJECT_ID', 'TYPE'])
						->whereIn('ID', $extraRealIds)
						->setOrder(['ID' => 'ASC']),
				);
				if ($discoveredExtra === null)
				{
					self::$preLockedObjects = [];
					try
					{
						return $callback();
					}
					finally
					{
						self::$preLockedObjects = null;
					}
				}
				$discoveredBase = array_merge($discoveredBase, $discoveredExtra);
			}
			foreach ($discoveredBase as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				$type = (int)($row['TYPE'] ?? 0);
				if ($id > 0 && $type === ObjectTable::TYPE_FILE)
				{
					$realFileIds[$id] = $id;
					$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
					if ($realId > 0)
					{
						$realFileIds[$realId] = $realId;
					}
				}
				if (isset($linkTargetIds[$id]) && $type === ObjectTable::TYPE_FOLDER)
				{
					$hasFolderLinkTarget = true;
				}
			}
		}

		$folderSubtreePathTargetIds = $linkTargetIds;
		if ($hasFolderLinkTarget && $realFileIds !== [] && $linkTargetIds !== [])
		{
			$discoveredFiles = self::discoverRowsForFreshRead(
				ObjectTable::query()
					->setSelect(['ID', 'REAL_OBJECT_ID'])
					->where('TYPE', ObjectTable::TYPE_FILE)
					->where(
						Query::filter()
							->logic('or')
							->whereIn('ID', array_values($realFileIds))
							->whereIn('REAL_OBJECT_ID', array_values($realFileIds)),
					)
					->whereIn('PATH_CHILD.PARENT_ID', array_values($linkTargetIds))
					->setOrder(['ID' => 'ASC']),
			);
			if ($discoveredFiles === null)
			{
				self::$preLockedObjects = [];
				try
				{
					return $callback();
				}
				finally
				{
					self::$preLockedObjects = null;
				}
			}
			foreach ($discoveredFiles as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
				if ($id > 0)
				{
					$candidateObjectIds[$id] = $id;
					$folderSubtreePathTargetIds[$id] = $id;
				}
				if ($realId > 0)
				{
					$candidateObjectIds[$realId] = $realId;
				}
			}
		}

		$discoveredPaths = [];
		$pathTargetIds = $includeObjectPaths
			? array_values($candidateObjectIds)
			: ($hasFolderLinkTarget ? array_values($folderSubtreePathTargetIds) : []);
		if ($pathTargetIds !== [])
		{
			sort($pathTargetIds, SORT_NUMERIC);
			$discoveredPaths = self::discoverRowsForFreshRead(
				ObjectPathTable::query()
					->setSelect(['OBJECT_ID', 'PARENT_ID', 'DEPTH_LEVEL'])
					->whereIn('OBJECT_ID', $pathTargetIds)
					->setOrder(['OBJECT_ID' => 'ASC', 'PARENT_ID' => 'ASC']),
			);
			if ($discoveredPaths === null)
			{
				self::$preLockedObjects = [];
				try
				{
					return $callback();
				}
				finally
				{
					self::$preLockedObjects = null;
				}
			}
			foreach ($discoveredPaths as $row)
			{
				$objectId = (int)($row['OBJECT_ID'] ?? 0);
				$parentId = (int)($row['PARENT_ID'] ?? 0);
				if ($objectId > 0)
				{
					$candidateObjectIds[$objectId] = $objectId;
				}
				if ($parentId > 0)
				{
					$candidateObjectIds[$parentId] = $parentId;
				}
			}
		}

		$orderedIds = self::orderObjectIdsTopologically($candidateObjectIds, $discoveredPaths);
		$lockedRows = self::lockObjectsForShare($orderedIds, ['*']);
		$preLocked = [];
		foreach ($lockedRows as $row)
		{
			$preLocked[(int)$row['ID']] = $row;
		}
		self::$preLockedObjects = $preLocked;
		try
		{
			return $callback();
		}
		finally
		{
			self::$preLockedObjects = null;
		}
	}

	/**
	 * Discovers candidate IDs without acquiring shared locks before b_disk_object.
	 * Reads both the primary connection (for uncommitted caller rows) and a separate
	 * non-transactional master connection (for rows committed after an older RR snapshot).
	 */
	public static function discoverRowsForFreshRead(Query $query): ?array
	{
		$sql = $query->getQuery();
		if (
			preg_match('/^\s*SELECT\b/i', $sql) !== 1
			|| preg_match('/\b(?:FOR\s+(?:SHARE|UPDATE)|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql) === 1
		)
		{
			throw new \InvalidArgumentException('Discovery queries must be non-locking SELECT statements.');
		}
		if (!self::shouldBypassRepeatableRead())
		{
			return $query->fetchAll();
		}

		$primary = Application::getConnection();
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$ownRows = $primary->query($sql)->fetchAll();
			$discovery = self::getSourceDiscoveryConnection($primary);
			$committedRows = $discovery->query($sql)->fetchAll();

			return array_merge($ownRows, $committedRows);
		}
		catch (\Throwable)
		{
			if (self::$discoveryConnection !== null)
			{
				try
				{
					self::$discoveryConnection->disconnect();
				}
				catch (\Throwable)
				{
				}
				self::$discoveryConnection = null;
				self::$discoveryPrimaryConnection = null;
			}

			return null;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function getSourceDiscoveryConnection(Connection $primary): Connection
	{
		$pool = Application::getInstance()->getConnectionPool();
		if (
			self::$discoveryConnection !== null
			&& self::$discoveryPrimaryConnection === $primary
			&& $pool->getConnection(self::SOURCE_DISCOVERY_CONNECTION_NAME) === self::$discoveryConnection
			&& self::$discoveryConnection->isConnected()
			&& self::$discoveryConnection->getResource() !== $primary->getResource()
			&& !self::hasOpenTransaction(self::$discoveryConnection)
		)
		{
			return self::$discoveryConnection;
		}
		if (self::$discoveryConnection !== null)
		{
			try
			{
				self::$discoveryConnection->disconnect();
			}
			catch (\Throwable)
			{
			}
			self::$discoveryConnection = null;
			self::$discoveryPrimaryConnection = null;
		}

		if ($primary->getType() === 'pgsql')
		{
			$config = $primary->getConfiguration();
			$charset = trim((string)($config['charset'] ?? 'utf8'));
			$parameters = [
				'charset' => ($charset !== '' ? $charset : 'utf8')
					. ' --application_name=' . self::SOURCE_DISCOVERY_CONNECTION_NAME,
			];
		}
		else
		{
			$parameters = ['options' => 0, 'persistent' => false];
		}

		$discovery = $pool->cloneConnection(
			\Bitrix\Main\Data\ConnectionPool::DEFAULT_CONNECTION_NAME,
			self::SOURCE_DISCOVERY_CONNECTION_NAME,
			$parameters,
		);
		if (!$discovery instanceof Connection)
		{
			throw new \RuntimeException('Could not clone the source discovery connection.');
		}
		$discovery->connect();
		if (
			$discovery === $primary
			|| !$discovery->isConnected()
			|| $discovery->getResource() === $primary->getResource()
			|| self::hasOpenTransaction($discovery)
		)
		{
			try
			{
				$discovery->disconnect();
			}
			catch (\Throwable)
			{
			}
			throw new \RuntimeException('Source discovery connection must own an independent non-transactional resource.');
		}

		self::$discoveryConnection = $discovery;
		self::$discoveryPrimaryConnection = $primary;

		return $discovery;
	}

	public static function shouldBypassRepeatableRead(): bool
	{
		return self::hasOpenTransaction() && !self::hasFreshDocumentTransaction();
	}

	/** Only a transaction opened after the document lookup has a fresh source snapshot. */
	public static function hasFreshDocumentTransaction(?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();
		return isset(self::$freshDocumentTransactions[spl_object_id($connection)])
			&& self::hasOpenTransaction($connection)
			&& self::$freshDocumentTransactions[spl_object_id($connection)] === $connection->getResource();
	}

	/**
	 * The core keeps its nesting level private, so the state of an external transaction is only
	 * readable through reflection. This is the single place in Vibeoffice that does it.
	 */
	public static function hasOpenTransaction(?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();
		$reflection = new \ReflectionObject($connection);
		do
		{
			if ($reflection->hasProperty('transactionLevel'))
			{
				return (int)$reflection->getProperty('transactionLevel')->getValue($connection) > 0;
			}
			$reflection = $reflection->getParentClass();
		}
		while ($reflection !== false);

		throw new \LogicException('Cannot determine the Vibeoffice connection transaction state.');
	}

	/** True when the connection holds any Vibeoffice named lock, a document or a scope one. */
	public static function holdsAnyLock(?Connection $connection = null): bool
	{
		$prefix = self::lockId($connection ?? Application::getConnection(), '');
		foreach (array_keys(self::$heldLocks) as $lockId)
		{
			if (str_starts_with((string)$lockId, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	public static function isDocumentLocked(string $hostDocKey): bool
	{
		$lockId = self::lockId(Application::getConnection(), self::getDocumentLockName($hostDocKey));

		return isset(self::$heldLocks[$lockId]);
	}

	/**
	 * Takes the document lock and registers it, so the guards asking isDocumentLocked() see it, but
	 * opens no transaction: the caller may need to remove a blob, which is irreversible.
	 * Returns false when the lock belongs to somebody else.
	 */
	public static function runUnderDocumentLock(string $hostDocKey, callable $work, int $timeout = 0): bool
	{
		$connection = Application::getConnection();
		$lockName = self::getDocumentLockName($hostDocKey);
		if (!self::acquireLock($connection, $lockName, $timeout))
		{
			return false;
		}

		try
		{
			$work();
		}
		finally
		{
			self::releaseLock($connection, $lockName);
		}

		return true;
	}

	/** The optional source preserves a shared local hash across a user fork, including legacy mode. */
	public function bindNewGeneration(DocumentSession $session, ?DocumentSession $source = null): Result
	{
		$file = File::loadById((int)$session->getObjectId());
		if (!$file || !$session->getId())
		{
			return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
		}

		try
		{
			$scope = HostDocumentKey::createScope($session, $file);
		}
		catch (\Throwable)
		{
			return $this->failure('Invalid Vibeoffice document scope.', 'vibeoffice_scope_invalid');
		}

		return $this->runLocked(
			'disk_vo_scope_' . md5(HostDocumentKey::scopeIdentity($scope)),
			function () use ($session, $source, $scope): Result {
				$file = $this->loadLockedFile($scope['OBJECT_ID']);
				if (!$file)
				{
					return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
				}

				$row = self::fetchForUpdate(DocumentSessionTable::query()
					->setSelect(['*'])->where('ID', (int)$session->getId()));
				$session = $row ? DocumentSession::buildFromArray($row) : null;
				if (!$file || !$session || HostDocumentKey::createScope($session, $file) !== $scope)
				{
					return $this->failure('Vibeoffice document scope changed.', 'vibeoffice_scope_invalid');
				}

				if (!$this->hasValidExternalLink($session, $file))
				{
					return $this->failure('Invalid Vibeoffice external link.', 'vibeoffice_scope_invalid');
				}

				$metadata = DocumentSessionManager::extractVoMetadata($session);
				$pinnedKey = $metadata[HostDocumentKey::CONTEXT_KEY] ?? null;
				if (is_string($pinnedKey) && $pinnedKey !== '')
				{
					$state = $this->findStateForUpdate($pinnedKey);
					if (!$state || (int)($state['EDIT_SCOPE_VERSION'] ?? 0) !== 0
						|| !$this->matchesScope($state, $scope))
					{
						return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
					}

					return $this->refreshBinding($session, $state);
				}
				if ($session->isEdit() && !$session->isVersion() && $this->isLegacyEditBlocked($scope['OBJECT_ID']))
				{
					return $this->sharedBusy();
				}

				$content = $this->readContent($session, $file);
				if ($content === null)
				{
					return $this->failure('Vibeoffice source version was not found.', 'vibeoffice_source_not_found');
				}

				$generation = HostDocumentKey::contentGeneration(
					$scope,
					$content['EXPECTED_FILE_ID'],
					$content['EXPECTED_CONTENT_VERSION'],
				);
				$mode = HostDocumentKey::MODE_LEGACY;
				$key = $session->getExternalHash();
				if ($source !== null)
				{
					if (
						$source->getExternalHash() !== $session->getExternalHash()
						|| HostDocumentKey::createScope($source, $file) !== $scope
					)
					{
						return $this->failure('Invalid Vibeoffice fork source.', 'vibeoffice_binding_invalid');
					}

					$key = HostDocumentKey::resolve($source);
					$mode = HostDocumentKey::isStable($key) ? HostDocumentKey::MODE_STABLE : $mode;
				}
				elseif (($this->configuration ?? new Configuration())->isStableDocumentKeyEnabled())
				{
					$mode = HostDocumentKey::MODE_STABLE;
					$state = $this->findLatestStable($scope);
					if ($state && $this->matchesContent($state, $content) && !(int)$state['CONFLICTED'])
					{
						$key = $state['HOST_DOC_KEY'];
					}
					else
					{
						if ($session->isEdit())
						{
							$generation = hash('sha256', $generation . ':' . UuidGenerator::generateV4());
						}
						$key = HostDocumentKey::create($scope, $generation);
					}
				}

				$state = $this->findStateForUpdate($key);
				if ($state !== null && !$this->matchesScope($state, $scope))
				{
					return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
				}
				$reusedState = $state !== null;
				if ($state === null)
				{
					if ($source !== null && $session->isEdit() && $this->getPlatformNamespace()->hasSwitched())
					{
						return $this->failure('Vibeoffice platform changed.', 'vibeoffice_namespace_changed');
					}

					if ($source !== null && $mode === HostDocumentKey::MODE_STABLE)
					{
						return $this->failure('Vibeoffice fork state was not found.', 'vibeoffice_binding_invalid');
					}

					$add = Models\DocumentStateTable::add($scope + $content + [
						'HOST_DOC_KEY' => $key,
						'NAMESPACE' => $this->getPlatformNamespace()->current() ?? '',
						'KEY_MODE' => $mode,
						'CONTENT_GENERATION' => $generation,
					]);
					if (!$add->isSuccess())
					{
						return (new Result())->addErrors($add->getErrors());
					}
					$state = $this->findStateForUpdate($key);
				}

				$metadata = [HostDocumentKey::CONTEXT_KEY => $key];
				if (!$session->isVersion())
				{
					$metadata[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] =
						$content['EXPECTED_CONTENT_VERSION'];
					$metadata['contentFileId'] = $content['EXPECTED_FILE_ID'];
					$metadata[DocumentSessionManager::CONTEXT_VO_SAVED_VERSION_ID_KEY] =
						DocumentSessionManager::getLatestSavedVersionId((int)$file->getRealObjectId());
				}
				if (!(new DocumentSessionManager())->mergeVoMetadataLocked(
					$session, static fn(array $vo): array => array_merge($vo, $metadata),
				))
				{
					return $this->failure('Could not pin the Vibeoffice document key.', 'vibeoffice_binding_failed');
				}

				$session = DocumentSession::loadById((int)$session->getId());

				return $reusedState ? $this->refreshBinding($session, $state) : $this->boundResult($session, $state);
			},
		);
	}

	/** Create and pin a local generation atomically, including direct model fork/clone paths. */
	public function createSession(
		callable $factory,
		?DocumentSession $source = null,
		?array $creationScope = null,
	): Result
	{
		$mutation = function () use ($factory, $source, $creationScope): Result {
			$file = $creationScope !== null ? $this->loadLockedFile($creationScope['OBJECT_ID']) : null;
			if ($creationScope !== null && !$file)
			{
				return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
			}
			if ($file && $this->isLegacyEditBlocked((int)$file->getRealObjectId()))
			{
				return $this->sharedBusy();
			}
			$session = $factory();
			if (!$session instanceof DocumentSession)
			{
				return $this->failure('Could not create a Vibeoffice session.', 'vibeoffice_session_failed');
			}
			if ($creationScope !== null && HostDocumentKey::createScope($session, $file) !== $creationScope)
			{
				return $this->failure('Vibeoffice creation scope changed.', 'vibeoffice_scope_invalid');
			}

			return $this->bindNewGeneration($session, $source);
		};
		if ($creationScope === null)
		{
			return $this->runTransaction($mutation);
		}
		if ((int)($creationScope['OBJECT_ID'] ?? 0) <= 0
			|| ($creationScope['SESSION_TYPE'] ?? null) !== DocumentSession::TYPE_EDIT
			|| ($creationScope['VERSION_ID'] ?? null) !== 0
			|| !is_int($creationScope['EXTERNAL_LINK_ID'] ?? null) || $creationScope['EXTERNAL_LINK_ID'] < 0)
		{
			return $this->failure('Invalid Vibeoffice creation scope.', 'vibeoffice_scope_invalid');
		}

		// Preserve scope -> file ordering; bindNewGeneration reuses this registered scope lock.
		return $this->runLocked('disk_vo_scope_' . md5(HostDocumentKey::scopeIdentity($creationScope)), $mutation);
	}

	public function ensureSessionState(DocumentSession $session): Result
	{
		return $this->resolvePinnedState($session) ?? $this->bindNewGeneration($session, $session);
	}

	public function isCurrentGeneration(DocumentSession $session, File $freshFile): bool
	{
		if ($session->isVersion())
		{
			return true;
		}

		$state = $this->getByKey(HostDocumentKey::resolve($session));
		if ($session->isEdit()
			&& ($state ? !$this->isInCurrentNamespace($state) : $this->getPlatformNamespace()->hasSwitched()))
		{
			return false;
		}
		if ($state)
		{
			return !(int)$state['CONFLICTED'] && $this->matchesContent($state, $this->liveContent($freshFile));
		}

		return !$session->isView() || !$session->isOutdatedByFileContent();
	}

	/** Called under the document lock, after taking the already pinned local session row. */
	public function isCurrentOpenGeneration(DocumentSession $session): bool
	{
		if ($session->isVersion())
		{
			return true;
		}

		$key = DocumentSessionManager::extractVoMetadata($session)[HostDocumentKey::CONTEXT_KEY] ?? null;
		if (!is_string($key) || $key === '')
		{
			return false;
		}

		// The current file read sees replacements committed after the transaction's snapshot.
		// Keep the session-before-file order of permanent deletion and leave the state row to maintenance.
		$file = $this->loadLockedFile((int)$session->getObjectId());
		if (!$file)
		{
			return false;
		}
		$state = $this->getByKey($key);

		return $state !== null && !(int)$state['CONFLICTED'] && $this->matchesContent($state, $this->liveContent($file));
	}

	/**
	 * A pinned key never changes, so a repeated open reads its state without the scope lock.
	 * Any doubt returns null: the locked path rechecks the binding.
	 */
	private function resolvePinnedState(DocumentSession $session): ?Result
	{
		$pinnedKey = DocumentSessionManager::extractVoMetadata($session)[HostDocumentKey::CONTEXT_KEY] ?? null;
		if (!is_string($pinnedKey) || $pinnedKey === '' || !$session->getId())
		{
			return null;
		}

		$file = File::loadById((int)$session->getObjectId());
		if (!$file)
		{
			return null;
		}

		$state = $this->getByKey($pinnedKey);
		$scopeVersion = (int)($state['EDIT_SCOPE_VERSION'] ?? 0);
		if ($state && !in_array($scopeVersion, [0, 1], true))
		{
			return $this->failure('Unknown editing scope version.', 'vibeoffice_binding_invalid');
		}
		try
		{
			$scope = HostDocumentKey::createScope($session, $file, $scopeVersion);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$state || !$this->matchesScope($state, $scope) || !$this->hasValidExternalLink($session, $file))
		{
			return null;
		}

		return $this->boundResult($session, $state);
	}

	private static function queryForUpdate(Query $query): \Bitrix\Main\DB\Result
	{
		return Application::getConnection()->query($query->getQuery() . ' FOR UPDATE');
	}

	private function findLatestStable(array $scope): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(SharedEditingSchema::documentFields());
		foreach ($scope as $field => $value)
		{
			$query->where($field, $value);
		}

		$namespaces = $this->getPlatformNamespace()->documentValues();
		if ($namespaces !== null)
		{
			$query->whereIn('NAMESPACE', $namespaces);
		}

		$query
			->where('KEY_MODE', HostDocumentKey::MODE_STABLE)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1);

		return self::fetchForUpdate($query);
	}

	private function findStateForUpdate(string $key): ?array
	{
		$query = Models\DocumentStateTable::query()
			->setSelect(SharedEditingSchema::documentFields())
			->where('HOST_DOC_KEY', $key)
		;

		return self::fetchForUpdate($query);
	}

	private function matchesScope(array $state, array $scope): bool
	{
		foreach ($scope as $field => $value)
		{
			if ((int)$state[$field] !== $value)
			{
				return false;
			}
		}

		return true;
	}

	private function matchesContent(array $state, array $content): bool
	{
		return (int)$state['EXPECTED_FILE_ID'] === $content['EXPECTED_FILE_ID']
			&& (int)$state['EXPECTED_CONTENT_VERSION'] === $content['EXPECTED_CONTENT_VERSION']
			&& ((int)$state['SESSION_TYPE'] === DocumentSession::TYPE_VIEW || (string)$state['EXPECTED_ETAG'] === $content['EXPECTED_ETAG']);
	}

	private function readContent(DocumentSession $session, File $file): ?array
	{
		if (!$session->isVersion())
		{
			return $this->liveContent($file);
		}

		$version = Version::loadById((int)$session->getVersionId());
		if (!$version || (int)$version->getObjectId() !== (int)$file->getRealObjectId())
		{
			return null;
		}

		return [
			'EXPECTED_FILE_ID' => (int)$version->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$version->getGlobalContentVersion(),
			'EXPECTED_ETAG' => '',
		];
	}

	private function liveContent(File $file): array
	{
		return [
			'EXPECTED_FILE_ID' => (int)$file->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$file->getGlobalContentVersion(),
			'EXPECTED_ETAG' => (string)$file->getEtag(),
		];
	}

	public static function externalLinkMatchesFile(ExternalLink $link, File $file): bool
	{
		if (self::shouldBypassRepeatableRead())
		{
			return self::externalLinkMatchesFileFresh($link, $file);
		}
		if ($file->isDeleted())
		{
			return false;
		}
		$realObjectId = (int)$file->getRealObjectId();
		if ((int)$link->getObjectId() === $realObjectId && (int)$file->getId() === $realObjectId)
		{
			return true;
		}
		$row = ObjectTable::query()->setSelect(['*'])->where('ID', (int)$link->getObjectId())->fetch();
		$source = $row ? \Bitrix\Disk\BaseObject::buildFromArray($row) : null;
		if (!$source || $source->isDeleted())
		{
			return false;
		}
		if ($source instanceof File)
		{
			return (int)$source->getRealObjectId() === $realObjectId;
		}
		if (!$source instanceof Folder)
		{
			return false;
		}

		// Match the live visible file or alias within the shared folder's subtree.
		$rows = ObjectTable::query()->setSelect(['ID', 'SOURCE_DEPTH' => 'PATH_CHILD.DEPTH_LEVEL'])
			->where('REAL_OBJECT_ID', $realObjectId)->where('TYPE', ObjectTable::TYPE_FILE)
			->where('DELETED_TYPE', ObjectTable::DELETED_TYPE_NONE)
			->where('PATH_CHILD.PARENT_ID', (int)$source->getRealObjectId())
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$ancestors = ObjectPathTable::query()->setSelect(['PARENT_ID', 'DELETED_TYPE' => 'PARENT.DELETED_TYPE'])
				->registerRuntimeField((new \Bitrix\Main\ORM\Fields\Relations\Reference(
					'PARENT', ObjectTable::class,
					\Bitrix\Main\ORM\Query\Join::on('this.PARENT_ID', 'ref.ID'),
				))->configureJoinType('INNER'))
				->where('OBJECT_ID', (int)$row['ID'])
				->where('DEPTH_LEVEL', '<=', (int)$row['SOURCE_DEPTH'])
				->fetchAll()
			;
			$alive = true;
			foreach ($ancestors as $ancestor)
			{
				if ((int)$ancestor['DELETED_TYPE'] !== ObjectTable::DELETED_TYPE_NONE)
				{
					$alive = false;
					break;
				}
			}
			if ($alive && count($ancestors) === (int)$row['SOURCE_DEPTH'] + 1) { return true; }
		}
		return false;
	}

	private static function externalLinkMatchesFileFresh(ExternalLink $link, File $file): bool
	{
		$fileId = (int)$file->getId();
		$realFileId = (int)($file->getRealObjectId() ?: $fileId);
		$linkObjectId = (int)$link->getObjectId();
		if ($fileId <= 0 || $realFileId <= 0 || $linkObjectId <= 0)
		{
			return false;
		}
		$baseIds = array_values(array_unique([$fileId, $realFileId, $linkObjectId]));
		sort($baseIds, SORT_NUMERIC);
		$discoveredBase = self::discoverRowsForFreshRead(
			ObjectTable::query()
				->setSelect(['ID', 'REAL_OBJECT_ID', 'TYPE'])
				->whereIn('ID', $baseIds)
				->setOrder(['ID' => 'ASC']),
		);
		if ($discoveredBase === null)
		{
			return false;
		}
		$realFileIds = [$fileId => $fileId, $realFileId => $realFileId];
		$sourceIds = [$linkObjectId => $linkObjectId];
		$candidateObjectIds = [];
		foreach ($baseIds as $id)
		{
			$candidateObjectIds[$id] = $id;
		}
		foreach ($discoveredBase as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
			if ($id > 0)
			{
				$candidateObjectIds[$id] = $id;
			}
			if ($realId > 0)
			{
				$candidateObjectIds[$realId] = $realId;
				if ($id === $fileId || $id === $realFileId)
				{
					$realFileIds[$realId] = $realId;
				}
				if ($id === $linkObjectId)
				{
					$sourceIds[$realId] = $realId;
				}
			}
		}
		$extraRealIds = array_values(array_diff(array_keys($candidateObjectIds), $baseIds));
		if ($extraRealIds !== [])
		{
			sort($extraRealIds, SORT_NUMERIC);
			$discoveredExtraBase = self::discoverRowsForFreshRead(
				ObjectTable::query()
					->setSelect(['ID', 'REAL_OBJECT_ID', 'TYPE'])
					->whereIn('ID', $extraRealIds)
					->setOrder(['ID' => 'ASC']),
			);
			if ($discoveredExtraBase === null)
			{
				return false;
			}
			$discoveredBase = array_merge($discoveredBase, $discoveredExtraBase);
		}
		$hasFolderSource = false;
		foreach ($discoveredBase as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if (isset($sourceIds[$id]) && (int)($row['TYPE'] ?? 0) === ObjectTable::TYPE_FOLDER)
			{
				$hasFolderSource = true;
				break;
			}
		}
		$discoveredPaths = [];
		if ($hasFolderSource)
		{
			$discoveredFiles = self::discoverRowsForFreshRead(
				ObjectTable::query()
					->setSelect(['ID', 'REAL_OBJECT_ID'])
					->where('TYPE', ObjectTable::TYPE_FILE)
					->where(
						Query::filter()
							->logic('or')
							->whereIn('ID', array_values($realFileIds))
							->whereIn('REAL_OBJECT_ID', array_values($realFileIds)),
					)
					->whereIn('PATH_CHILD.PARENT_ID', array_values($sourceIds))
					->setOrder(['ID' => 'ASC']),
			);
			if ($discoveredFiles === null)
			{
				return false;
			}
			$pathTargetIds = $sourceIds;
			foreach ($discoveredFiles as $row)
			{
				$id = (int)($row['ID'] ?? 0);
				$realId = (int)($row['REAL_OBJECT_ID'] ?? 0);
				if ($id > 0)
				{
					$candidateObjectIds[$id] = $id;
					$pathTargetIds[$id] = $id;
				}
				if ($realId > 0)
				{
					$candidateObjectIds[$realId] = $realId;
				}
			}
			ksort($pathTargetIds, SORT_NUMERIC);
			$discoveredPaths = self::discoverRowsForFreshRead(
				ObjectPathTable::query()
					->setSelect(['OBJECT_ID', 'PARENT_ID', 'DEPTH_LEVEL'])
					->whereIn('OBJECT_ID', array_values($pathTargetIds))
					->setOrder(['OBJECT_ID' => 'ASC', 'PARENT_ID' => 'ASC']),
			);
			if ($discoveredPaths === null)
			{
				return false;
			}
			foreach ($discoveredPaths as $row)
			{
				$objectId = (int)($row['OBJECT_ID'] ?? 0);
				$parentId = (int)($row['PARENT_ID'] ?? 0);
				if ($objectId > 0)
				{
					$candidateObjectIds[$objectId] = $objectId;
				}
				if ($parentId > 0)
				{
					$candidateObjectIds[$parentId] = $parentId;
				}
			}
		}
		$orderedObjectIds = self::orderObjectIdsTopologically($candidateObjectIds, $discoveredPaths);
		$lockedObjects = [];
		foreach (self::lockObjectsForShare($orderedObjectIds, ['*']) as $objectRow)
		{
			$lockedObjects[(int)$objectRow['ID']] = $objectRow;
		}

		$fileRow = $lockedObjects[$fileId] ?? null;
		$lockedFile = $fileRow ? \Bitrix\Disk\BaseObject::buildFromArray($fileRow) : null;
		if (!$lockedFile instanceof File || $lockedFile->isDeleted())
		{
			return false;
		}
		if ($lockedFile->isLink())
		{
			$realRow = $lockedObjects[(int)$lockedFile->getRealObjectId()] ?? null;
			$realFile = $realRow ? \Bitrix\Disk\BaseObject::buildFromArray($realRow) : null;
			if (!$realFile instanceof File || $realFile->isDeleted() || $realFile->isLink())
			{
				return false;
			}
		}
		$realObjectId = (int)($lockedFile->getRealObjectId() ?: $lockedFile->getId());
		if ($linkObjectId === $realObjectId && $fileId === $realObjectId)
		{
			return true;
		}
		$sourceRow = $lockedObjects[$linkObjectId] ?? null;
		$source = $sourceRow ? \Bitrix\Disk\BaseObject::buildFromArray($sourceRow) : null;
		if (!$source || $source->isDeleted())
		{
			return false;
		}
		if ($source instanceof File)
		{
			if ($source->isLink())
			{
				$sourceRealRow = $lockedObjects[(int)$source->getRealObjectId()] ?? null;
				$sourceReal = $sourceRealRow ? \Bitrix\Disk\BaseObject::buildFromArray($sourceRealRow) : null;
				if (!$sourceReal instanceof File || $sourceReal->isDeleted() || $sourceReal->isLink())
				{
					return false;
				}
			}

			return (int)($source->getRealObjectId() ?: $source->getId()) === $realObjectId;
		}
		if (!$source instanceof Folder || !$hasFolderSource)
		{
			return false;
		}
		$sourceId = (int)($source->getRealObjectId() ?: $source->getId());
		if (!isset($lockedObjects[$sourceId]) || (int)$lockedObjects[$sourceId]['DELETED_TYPE'] !== ObjectTable::DELETED_TYPE_NONE)
		{
			return false;
		}
		$validCandidateIds = [];
		foreach ($lockedObjects as $candidateId => $row)
		{
			if (
				(int)$row['TYPE'] === ObjectTable::TYPE_FILE
				&& (int)$row['DELETED_TYPE'] === ObjectTable::DELETED_TYPE_NONE
				&& (int)($row['REAL_OBJECT_ID'] ?: $row['ID']) === $realObjectId
			)
			{
				$validCandidateIds[] = $candidateId;
			}
		}
		if ($validCandidateIds === [])
		{
			return false;
		}

		$candidatePaths = self::fetchAllForShare(
			ObjectPathTable::query()
				->setSelect(['OBJECT_ID', 'DEPTH_LEVEL'])
				->where('PARENT_ID', $sourceId)
				->whereIn('OBJECT_ID', $validCandidateIds)
				->setOrder(['OBJECT_ID' => 'ASC']),
		);
		foreach ($candidatePaths as $candidatePath)
		{
			$candidateId = (int)$candidatePath['OBJECT_ID'];
			$sourceDepth = (int)$candidatePath['DEPTH_LEVEL'];
			if ($sourceDepth < 0)
			{
				continue;
			}
			$ancestors = self::fetchAllForShare(
				ObjectPathTable::query()
					->setSelect(['PARENT_ID'])
					->where('OBJECT_ID', $candidateId)
					->where('DEPTH_LEVEL', '<=', $sourceDepth)
					->setOrder(['PARENT_ID' => 'ASC']),
			);
			$alive = true;
			foreach ($ancestors as $ancestor)
			{
				$parentId = (int)$ancestor['PARENT_ID'];
				if (!isset($lockedObjects[$parentId]))
				{
					return false;
				}
				if ((int)$lockedObjects[$parentId]['DELETED_TYPE'] !== ObjectTable::DELETED_TYPE_NONE)
				{
					$alive = false;
				}
			}
			if ($alive && count($ancestors) === $sourceDepth + 1)
			{
				return true;
			}
		}

		return false;
	}

	private function hasValidExternalLink(DocumentSession $session, File $file): bool
	{
		if (!$session->getExternalLinkId())
		{
			return true;
		}

		$link = ExternalLink::loadById($session->getExternalLinkId());
		if (!$link || (int)$link->getVersionId() !== (int)$session->getVersionId())
		{
			return false;
		}

		$realObjectId = (int)$file->getRealObjectId();
		// A link to the real object itself needs no extra load of the linked object.
		if ((int)$link->getObjectId() === $realObjectId && (int)$file->getId() === $realObjectId)
		{
			return true;
		}

		return (int)$link->getObject()?->getRealObjectId() === $realObjectId;
	}

	private function loadLockedFile(int $objectId): ?File
	{
		$query = \Bitrix\Disk\Internals\ObjectTable::query()->setSelect(['*'])->where('ID', $objectId);
		$row = self::fetchForUpdate($query);

		return $row ? File::buildFromArray($row) : null;
	}

	private function refreshBinding(DocumentSession $session, array $state): Result
	{
		$state['UPDATED_TIME'] = new DateTime();
		$update = Models\DocumentStateTable::update((int)$state['ID'], ['UPDATED_TIME' => $state['UPDATED_TIME']]);

		return $update->isSuccess()
			? $this->boundResult($session, $state)
			: (new Result())->addErrors($update->getErrors());
	}

	private function boundResult(DocumentSession $session, array $state): Result
	{
		return (new Result())->setData([
			'hostDocKey' => $state['HOST_DOC_KEY'],
			'state' => $state,
			'session' => $session,
		]);
	}

	private function runLocked(
		string $lockName,
		callable $mutation,
		bool $auditFileChanges = false,
		int $timeout = self::LOCK_TIMEOUT,
		?float $deadline = null,
		?callable $beforeTransaction = null,
	): Result
	{
		if ($deadline !== null)
		{
			return SqlDeadlineScope::run($deadline,
				fn(): Result => $this->runLocked($lockName, $mutation, $auditFileChanges, $timeout, null, $beforeTransaction));
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$connection = Application::getConnection();
			if (!self::acquireLock($connection, $lockName, $timeout))
			{
				return $this->failure('Could not lock the Vibeoffice document.', 'vibeoffice_lock_timeout');
			}

			try
			{
				if ($beforeTransaction !== null)
				{
					if (self::hasOpenTransaction($connection))
					{
						// A failed lookup must roll back only its own nested scope, not abort the caller.
						$prepared = $this->runTransaction(static function () use ($beforeTransaction): Result {
							$beforeTransaction();
							return new Result();
						});
						if (!$prepared->isSuccess())
						{
							return $prepared;
						}
					}
					else
					{
						$beforeTransaction();
					}
				}
				// Update lines shipped without the Disk audit (26.1300.0) have no AuditTransaction class.
				$auditable = $auditFileChanges && class_exists(AuditTransaction::class);

				if (!self::hasOpenTransaction($connection))
				{
					$callback = $mutation;
					$mutation = static function () use ($connection, $callback): Result {
						$id = spl_object_id($connection);
						$previous = self::$freshDocumentTransactions[$id] ?? null;
						self::$freshDocumentTransactions[$id] = $connection->getResource();
						try { return $callback(); }
						finally
						{
							if ($previous === null) { unset(self::$freshDocumentTransactions[$id]); }
							else { self::$freshDocumentTransactions[$id] = $previous; }
						}
					};
				}
				return $auditable ? $this->runAuditTransaction($mutation) : $this->runTransaction($mutation);
			}
			catch (\Throwable $exception)
			{
				$this->logStateException($exception);

				return $this->transactionFailure($exception);
			}
			finally
			{
				self::releaseLock($connection, $lockName);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function lockId(Connection $connection, string $lockName): string
	{
		return spl_object_id($connection) . ':' . $lockName;
	}

	/** A lock already held by this connection is only counted: the core does not nest a named lock. */
	private static function acquireLock(Connection $connection, string $lockName, int $timeout): bool
	{
		$lockId = self::lockId($connection, $lockName);
		if (!isset(self::$heldLocks[$lockId]) && !$connection->lock($lockName, $timeout))
		{
			return false;
		}
		self::$heldLocks[$lockId] = (self::$heldLocks[$lockId] ?? 0) + 1;

		return true;
	}

	private static function releaseLock(Connection $connection, string $lockName): void
	{
		$lockId = self::lockId($connection, $lockName);
		if (--self::$heldLocks[$lockId] === 0)
		{
			unset(self::$heldLocks[$lockId]);
			$connection->unlock($lockName);
		}
	}

	private function runAuditTransaction(callable $mutation): Result
	{
		$transaction = new AuditTransaction(Application::getConnection());
		try
		{
			$auditResult = $transaction->execute(static function () use ($mutation): AuditTransactionOutcome {
				$result = $mutation();

				return $result->isSuccess()
					? AuditTransactionOutcome::success($result)
					: AuditTransactionOutcome::failure($result);
			});
			$result = $auditResult->getOutcome()->getValue();
			if ($result instanceof Result && ($auditResult->isSuccess()
				|| ($auditResult->isRollbackConfirmed() && !$result->isSuccess()
					&& $auditResult->getCallbackExceptions() === []
					&& $auditResult->getCompensationExceptions() === [])))
			{
				return $result;
			}

			throw new AuditTransactionException($auditResult);
		}
		catch (\Throwable $exception)
		{
			$this->logStateException($exception);

			return $this->transactionFailure($exception);
		}
	}

	private function runTransaction(callable $mutation): Result
	{
		$connection = Application::getConnection();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$result = $mutation();
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
			}
			else
			{
				self::rollbackOwnLevel($connection);
			}
			$transactionStarted = false;

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				self::rollbackOwnLevel($connection);
			}
			$this->logStateException($exception);

			return $this->transactionFailure($exception);
		}
	}

	/** The core undoes a nested level on its own and only then reports it by throwing. */
	private static function rollbackOwnLevel(Connection $connection): void
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
	}

	/**
	 * Keeps a rolled-back state mutation diagnosable: deadlocks and lock timeouts otherwise leave
	 * only the generic error. The record carries neither the document key nor a session hash.
	 */
	private function logStateException(\Throwable $exception): void
	{
		try
		{
			$this->diagnosticLogger->error('vibeoffice state', [
				'operation' => 'state',
				'code' => 'state_exception',
				'document_session_id' => null,
				'exception_class' => $exception::class,
			]);
		}
		catch (\Throwable)
		{
			// The diagnostic sink must not replace the state mutation result.
		}
	}

	private function documentNotFound(string $reason): Result
	{
		return $this->failure('Vibeoffice document was not found.', 'vibeoffice_document_not_found')
			->setData(['documentNotFoundReason' => $reason])
		;
	}

	private function transactionFailure(\Throwable $exception): Result
	{
		return DatabaseFailureResult::fromException(
			$exception, new Error('Could not persist Vibeoffice state.', 'vibeoffice_state_failed'),
		);
	}

	private function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
