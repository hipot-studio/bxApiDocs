<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Disk\Document\Vibeoffice\ConflictCopyService;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformNamespace;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseErrorDiagnostic;
use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseFailureResult;
use Bitrix\Disk\Document\Vibeoffice\MissingDocumentRefusal;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\SavedDocumentWriterInterface;
use Bitrix\Disk\Document\Vibeoffice\SavedDocumentWriter;
use Bitrix\Disk\Document\Vibeoffice\SavedNotificationAgent;
use Bitrix\Disk\Document\Vibeoffice\SavedVersionJoinPolicy;
use Bitrix\Disk\Document\Vibeoffice\SavedSnapshotHasher;
use Bitrix\Disk\Document\Vibeoffice\SavedSnapshotCompletion;
use Bitrix\Disk\Document\Vibeoffice\Internal\SavedSnapshotProofResolver;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizer;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizerInterface;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Version;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Public\Service\Restrictions\WriteErrorTransportMap;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Disk\Document\Vibeoffice\Logging\DiagnosticContext;
use Bitrix\Disk\Document\Vibeoffice\Logging\SaveFailureDiagnostic;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Closure;
use Throwable;

/**
 * Dispatches verified vibeoffice webhooks.
 *
 * The HMAC signature, timestamp window and content type are already enforced by the
 * controller prefilters. This processor adds idempotency by `X-VO-Delivery` and dispatches
 * by `X-VO-Event`.
 *
 *  - `document.saved`         → download `file_url` and persist a new Disk version through
 *                               {@see File::uploadVersion()} (P5.T2): the version becomes a
 *                               regular Disk file in the shared `b_disk_version` chain, which
 *                               is exactly why no migration is needed on rollback (ADR §3).
 *  - `document.failover`      → refresh the stored vo-metadata of the session (Q-3); the
 *                               frontend helper restores itself over SSE. When the failover
 *                               restored nothing, its empty key cannot identify the lost sessions;
 *                               the delivery is acknowledged without finalizing them.
 *  - `document.session-closed`→ deactivate the local sessions for the document key.
 *
 * The processor returns a {@see Result}; a successful `document.saved` carries an `ack` only
 * after the durable delivery row proves that the version was applied. The controller responds
 * 2xx only when that receipt is present. SavedNotificationAgent retries notifications for
 * negotiated saves independently of the webhook.
 * Acknowledging a delivery is not the same as storing a version: a write the model refuses for good
 * is acknowledged so the platform stops retrying it, and the refusal is recorded as such; a write
 * that merely did not go through this time is answered with an error and delivered again - see
 * {@see self::answerRefusedWrite()}.
 *
 * This path never touches the OnlyOffice webhook handler; it is wholly additive.
 *
 * Pinned document state uses the durable saved writer and exact UUID finalization.
 * Historical sessions without pinned state retain the legacy upload/finalization path.
 */
final class WebhookProcessor
{
	public const EVENT_SAVED = 'document.saved';
	public const EVENT_FAILOVER = 'document.failover';
	public const EVENT_SESSION_CLOSED = 'document.session-closed';

	/**
	 * Ledger event of a delivery the write node refused. The delivery is acknowledged
	 * under it - the platform must not re-deliver a save that would be refused identically every
	 * time - but it is deliberately NOT recorded as {@see self::EVENT_SAVED}: a refusal must never
	 * pass for the save confirmation {@see \Bitrix\Disk\Document\Vibeoffice\DrainService} waits
	 * for before it drops a local session.
	 */
	public const EVENT_SAVE_REFUSED = 'document.saved:refused';

	/** Result key a handler names the ledger event of its delivery with. */
	private const RESULT_KEY_LEDGER_EVENT = 'ledgerEvent';

	/** terminal sources that finish editing → deactivate the local session. */
	/** Stages of a `document.saved` that is applied in several steps. */
	private const SAVED_STAGE_VERSION_APPLIED = 'version-applied';
	private const SAVED_STAGE_SIDE_EFFECTS_APPLIED = 'side-effects-applied';

	/** Persistent storage key prefix of the per-delivery saved-stage checkpoint. */
	private const SAVED_STAGE_KEY_PREFIX = 'disk_vo_saved_delivery_stage_';

	/** Legacy checkpoint lifetime; durable deliveries do not use this cache. */
	private const SAVED_STAGE_TTL = 24 * 3600;

	/** Terminal sources that finish editing and deactivate local sessions. */
	private const TERMINAL_SOURCES = ['save', 'forgotten'];

	/** Named application lock prefix that serialises processing of one delivery. */
	private const LOCK_NAME_PREFIX = 'disk_vo_delivery_';

	/** Seconds to wait for the per-delivery lock before giving up (concurrent retry in flight). */
	private const LOCK_TIMEOUT = 15;

	/** Diagnostic channel of the asynchronous delivery path; `.settings.php` redirects it by id. */
	private const LOGGER_ID = 'disk.vibeoffice.webhook';

	private TerminalSessionFinalizerInterface $terminalSessionFinalizer;
	private Closure $deliveryMarkerWriter;
	private Closure $savedVersionUploader;
	private Closure $savedSideEffectsHandler;
	private SessionRegistry $registry;
	private WebhookScopeResolver $scopeResolver;
	private array $deliveryObservation = ['result' => null, 'outcome' => null];
	private ?int $activeDeliveryId = null;
	private ?string $activeEvent = null;
	private ?string $activeDocumentCorrelationId = null;
	private string $diagnosticStage = 'dispatch';
	private ?string $diagnosticReason = null;
	private array $activeDiagnosticFields = [];
	private SavedDocumentWriterInterface $savedDocumentWriter;
	private readonly bool $savedDocumentWriterInjected;
	private ?PlatformSource $source = null;
	private readonly bool $legacyUploaderInjected;
	private readonly SavedSnapshotProofResolver $snapshotProofResolver;
	private readonly MissingDocumentRefusal $missingDocumentRefusal;
	private readonly DocumentStateRepository $documentStateRepository;

	/**
	 * @param callable(array): Result|null $deliveryMarkerWriter Internal ORM failure seam for tests.
	 * @param callable(array, string): Result|null $savedVersionUploader Internal saved-version seam for tests.
	 * @param callable(array): Result|null $savedSideEffectsHandler Internal saved side-effects seam for tests.
	 */
	public function __construct(
		?TerminalSessionFinalizerInterface $terminalSessionFinalizer = null,
		?callable $deliveryMarkerWriter = null,
		?callable $savedVersionUploader = null,
		?callable $savedSideEffectsHandler = null,
		?SavedDocumentWriterInterface $savedDocumentWriter = null,
		?DocumentStateRepository $documentStateRepository = null,
		private readonly bool $supportsSavedApplyAck = false,
		private readonly SavedSnapshotHasher $snapshotHasher = new SavedSnapshotHasher(),
		private readonly SavedVersionJoinPolicy $joinPolicy = new SavedVersionJoinPolicy(),
		private readonly array $sources = [],
	)
	{
		$documentStateRepository ??= new DocumentStateRepository();
		$this->documentStateRepository = $documentStateRepository;
		$this->savedDocumentWriterInjected = $savedDocumentWriter !== null;
		$this->missingDocumentRefusal = new MissingDocumentRefusal($documentStateRepository);
		$this->snapshotProofResolver = new SavedSnapshotProofResolver($this->snapshotHasher);
		if ($savedDocumentWriter !== null)
		{
			$this->savedDocumentWriter = $savedDocumentWriter;
		}
		$this->legacyUploaderInjected = $savedVersionUploader !== null;
		$this->registry = new SessionRegistry($documentStateRepository);
		$this->scopeResolver = new WebhookScopeResolver($documentStateRepository, $this->registry);
		$this->terminalSessionFinalizer = $terminalSessionFinalizer ?? new TerminalSessionFinalizer(repository: $documentStateRepository);
		$this->deliveryMarkerWriter = Closure::fromCallable(
			$deliveryMarkerWriter
			?? static function (array $fields): Result {
				$id = $fields['ID'] ?? null;
				unset($fields['ID']);

				return $id === null ? DeliveryTable::add($fields) : DeliveryTable::update((int)$id, $fields);
			}
		);
		$this->savedVersionUploader = Closure::fromCallable(
			$savedVersionUploader
			?? fn(array $payload, string $externalHash): Result => $this->uploadSavedVersion($payload, $externalHash)
		);
		$this->savedSideEffectsHandler = Closure::fromCallable(
			$savedSideEffectsHandler
			?? fn(array $data): Result => $this->dispatchSavedSideEffects($data)
		);
	}

	public function getDeliveryObservation(): array
	{
		return $this->deliveryObservation;
	}

	public function getSelectedSource(): ?PlatformSource
	{
		return $this->source;
	}

	public function process(int $deliveryId, string $event, array $payload): Result
	{
		$configuration = $this->getConfiguration();
		$this->source = PlatformSource::select($this->sources ?: [PlatformSource::current(
			$configuration->getServer(), (string)$configuration->getWebhookSecret(),
		)], $event, $payload);
		if (!$this->savedDocumentWriterInjected)
		{
			$this->savedDocumentWriter = new SavedDocumentWriter(
				repository: $this->documentStateRepository, snapshotHasher: $this->snapshotHasher,
				joinPolicy: $this->joinPolicy, supportsSavedApplyAck: $this->supportsSavedApplyAck, source: $this->source,
			);
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $this->processDelivery($deliveryId, $event, $payload);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function processDelivery(int $deliveryId, string $event, array $payload): Result
	{
		$this->initializeDeliveryObservation($deliveryId, $event, $payload);
		$result = $this->dispatch($deliveryId, $event, $payload);
		if ($result->isSuccess())
		{
			return $event === self::EVENT_SAVED
				? $this->attachSavedApplyAcknowledgement($result, $deliveryId, $payload)
				: $result;
		}
		if ($result instanceof DatabaseFailureResult)
		{
			$this->logWebhookDiagnostic('dispatch', 'delivery_exception', databaseFailure: $result);
		}
		if ($event === self::EVENT_SAVED)
		{
			$missingReason = $result->getData()['documentNotFoundReason'] ?? null;
			if ($missingReason !== null && $missingReason !== $this->diagnosticReason)
			{
				$this->observeFailure($result, $this->diagnosticStage);
			}
			$refusal = $this->persistProvenSavedRefusal($deliveryId, $payload, $result);

			return $refusal === null
				? $result
				: $this->attachSavedApplyAcknowledgement($refusal, $deliveryId, $payload);
		}
		if (!WebhookEventPolicy::isPermanentFailure($result))
		{
			return $result;
		}
		$reasons = array_map(
			static fn(Error $error): string => $error->getCode() . ': ' . $error->getMessage(),
			$result->getErrors(),
		);

		return $this->acknowledgePermanent(
			"Vibeoffice webhook: permanent failure; event={$event}; delivery={$deliveryId}; "
			. implode(' ', $reasons)
		);
	}

	private function initializeDeliveryObservation(int $deliveryId, string $event, array $payload): void
	{
		$this->deliveryObservation = ['result' => null, 'outcome' => null];
		$this->activeDeliveryId = $deliveryId > 0 ? $deliveryId : null;
		$this->activeEvent = $event;
		$this->activeDocumentCorrelationId = null;
		$this->diagnosticStage = 'validation';
		$this->diagnosticReason = null;
		$this->activeDiagnosticFields = [];
		try
		{
			$key = $payload['host_doc_key'] ?? null;
			$this->activeDocumentCorrelationId = DiagnosticContext::documentCorrelationId(
				is_string($key) ? $key : null,
			);
		}
		catch (Throwable)
		{
			// A diagnostic failure must not reject an authenticated delivery.
		}
	}

	private function persistProvenSavedRefusal(int $deliveryId, array $payload, Result $failure): ?Result
	{
		$errors = $failure->getErrors();
		if (count($errors) !== 1)
		{
			return null;
		}
		$reason = (string)$errors[0]->getCode();
		if (!in_array($reason, ['ssrf_blocked', 'vibeoffice_file_too_large', 'format_restricted'], true))
		{
			return null;
		}
		if (DocumentStateRepository::holdsAnyLock())
		{
			return (new Result())->addError(new Error(
				'Delivery requires an unlocked caller.', 'vibeoffice_delivery_locked',
			));
		}
		$lockName = self::LOCK_NAME_PREFIX . $deliveryId;
		$connection = $this->acquireDeliveryLock($lockName);
		if ($connection === null)
		{
			return null;
		}
		try
		{
			$delivery = $this->getDelivery($deliveryId);
			if (!$delivery || $delivery['EVENT'] !== self::EVENT_SAVED
				|| $delivery['HOST_DOC_KEY'] !== ($payload['host_doc_key'] ?? null)
				|| !is_int($payload['snapshot_seq'] ?? null)
				|| (int)$delivery['SNAPSHOT_SEQ'] !== $payload['snapshot_seq']
				|| !in_array($delivery['STATUS'], [DeliveryTable::STATUS_PENDING, DeliveryTable::STATUS_STAGED], true))
			{
				return null;
			}
			$updated = DeliveryTable::update((int)$delivery['ID'], [
				'STATUS' => DeliveryTable::STATUS_DONE,
				'OUTCOME' => DeliveryTable::OUTCOME_REFUSED,
				'REASON' => $reason,
				'PROCESSED_TIME' => new DateTime(),
			]);

			if ($updated->isSuccess())
			{
				$this->deliveryObservation = ['result' => 'refused', 'outcome' => DeliveryTable::OUTCOME_REFUSED];
			}
			return $updated->isSuccess() ? new Result() : null;
		}
		finally
		{
			try
			{
				$connection->unlock($lockName);
			}
			finally
			{
				if ($connection->getType() === 'mysql')
				{
					$connection->disconnect();
				}
			}
		}
	}

	private function attachSavedApplyAcknowledgement(Result $result, int $deliveryId, array $payload): Result
	{
		$hostDocKey = $payload['host_doc_key'] ?? null;
		$snapshotSeq = $payload['snapshot_seq'] ?? null;
		$delivery = $this->getDelivery($deliveryId);
		if (
			!is_string($hostDocKey)
			|| !is_int($snapshotSeq)
			|| $delivery === null
			|| $delivery['EVENT'] !== self::EVENT_SAVED
			|| $delivery['STATUS'] !== DeliveryTable::STATUS_DONE
			|| $delivery['HOST_DOC_KEY'] !== $hostDocKey
			|| ($delivery['SNAPSHOT_SEQ'] === null ? null : (int)$delivery['SNAPSHOT_SEQ']) !== $snapshotSeq
		)
		{
			return $result;
		}

		$outcome = $delivery['OUTCOME'] ?? null;
		if ($this->deliveryObservation['result'] === null
			&& in_array($outcome, [
				DeliveryTable::OUTCOME_APPLIED, DeliveryTable::OUTCOME_REFUSED, DeliveryTable::OUTCOME_IGNORED,
			], true))
		{
			$this->deliveryObservation = ['result' => $outcome, 'outcome' => $outcome];
		}
		$ack = ['deliveryId' => $deliveryId, 'outcome' => $outcome];
		if ($outcome === DeliveryTable::OUTCOME_APPLIED
			&& (int)$delivery['RESULT_OBJECT_ID'] > 0
			&& ($delivery['RESULT_VERSION_ID'] === null || (int)$delivery['RESULT_VERSION_ID'] > 0))
		{
			$ack['objectId'] = (int)$delivery['RESULT_OBJECT_ID'];
			$ack['versionId'] = $delivery['RESULT_VERSION_ID'] === null
				? null : (int)$delivery['RESULT_VERSION_ID'];
		}
		elseif (in_array($outcome, [DeliveryTable::OUTCOME_REFUSED, DeliveryTable::OUTCOME_IGNORED], true)
			&& is_string($delivery['REASON']) && $delivery['REASON'] !== '')
		{
			$ack['reason'] = $delivery['REASON'];
		}
		else
		{
			return $result;
		}
		$ack['snapshotSeq'] = $snapshotSeq;

		return $result->setData($result->getData() + ['ack' => $ack]);
	}

	private function dispatch(int $deliveryId, string $event, array $payload): Result
	{
		if ($deliveryId <= 0)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Invalid delivery ID.', 'validation')),
				'validation', 'validation_failed',
			);
		}
		$this->diagnosticStage = 'ledger';
		if (DocumentStateRepository::holdsAnyLock())
		{
			return (new Result())->addError(new Error(
				'Delivery requires an unlocked caller.', 'vibeoffice_delivery_locked',
			));
		}
		$lockName = self::LOCK_NAME_PREFIX . $deliveryId;
		$connection = $this->acquireDeliveryLock($lockName);
		if ($connection === null)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Could not acquire the delivery lock.', 'lock_timeout')),
				'ledger', 'lock_timeout',
			);
		}

		try
		{
			$delivery = $this->getDelivery($deliveryId);
			if ($delivery && ($delivery['EVENT'] !== $event || (
				$delivery['HOST_DOC_KEY'] !== null && $delivery['HOST_DOC_KEY'] !== ($payload['host_doc_key'] ?? null)
			)))
			{
				return $this->observeFailure(
				(new Result())->addError(new Error('Delivery identity changed.', 'validation')),
				'validation', 'snapshot_identity_mismatch',
			);
			}
			if ($delivery && $event === self::EVENT_SAVED
				&& $delivery['STATUS'] === DeliveryTable::STATUS_DONE
				&& $delivery['OUTCOME'] === DeliveryTable::OUTCOME_REFUSED
				&& in_array($delivery['REASON'], ['file_missing', 'validation'], true))
			{
				if ($delivery['REASON'] === 'validation'
					&& (!$this->supportsSavedApplyAck || ($payload['source'] ?? null) !== 'save'))
				{
					return $this->observeFailure(
						(new Result())->addError(new Error('The saved refusal cannot be acknowledged.', 'validation')),
						'validation', 'validation_failed',
					);
				}
				$classification = (new WebhookEventPolicy())->classify($event, $payload);
				$policy = $classification->getData();
				$state = $classification->isSuccess() && !HostDocumentKey::isStable($policy['hostDocKey'])
					? $this->documentStateRepository->getByKey($policy['hostDocKey']) : null;
				if (!$classification->isSuccess() || $policy['legacy'] || !$policy['identityKnown']
					|| (!HostDocumentKey::isStable($policy['hostDocKey'])
						&& (!$state || !HostDocumentKey::matchesSharedEditScope($state, $policy['hostDocKey'])))
					|| $policy['snapshotSeq'] !== (int)$delivery['SNAPSHOT_SEQ'])
				{
					return $this->observeFailure(
						(new Result())->addError(new Error('Delivery snapshot changed.', 'validation')),
						'validation', 'snapshot_identity_mismatch',
					);
				}
				$this->deliveryObservation = ['result' => 'duplicate', 'outcome' => DeliveryTable::OUTCOME_REFUSED];

				return new Result();
			}
			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE
				&& ($event !== self::EVENT_SAVED || $delivery['HOST_DOC_KEY'] === null || (int)$delivery['RESULT_OBJECT_ID'] <= 0))
			{
				$this->deliveryObservation = [
					'result' => 'duplicate',
					'outcome' => $this->knownDeliveryOutcome($delivery['OUTCOME'] ?? null),
				];
				return new Result();
			}

			if (!in_array($event, [self::EVENT_SAVED, self::EVENT_FAILOVER, self::EVENT_SESSION_CLOSED], true))
			{
				$this->handleUnsupportedEvent($event);
				$this->deliveryObservation = ['result' => 'ignored', 'outcome' => DeliveryTable::OUTCOME_IGNORED];

				return $this->markDeliveryProcessed($deliveryId, $event, $this->extractExternalHash($payload));
			}

			$this->diagnosticStage = 'validation';
			$classification = (new WebhookEventPolicy())->classify($event, $payload);
			if (!$classification->isSuccess())
			{
				return $this->observeFailure($classification, 'validation');
			}
			$policy = $classification->getData();
			$key = $policy['hostDocKey'];
			if ($delivery && DeliveryTable::isSavedNotificationSkipped($delivery))
			{
				$state = !HostDocumentKey::isStable($key) ? $this->documentStateRepository->getByKey($key) : null;
				if ($policy['legacy'] || !$policy['identityKnown']
					|| (!HostDocumentKey::isStable($key) && (!$state || !HostDocumentKey::matchesSharedEditScope($state, $key)))
					|| $policy['snapshotSeq'] !== (int)$delivery['SNAPSHOT_SEQ'])
				{
					return $this->observeFailure(
						(new Result())->addError(new Error('Delivery snapshot changed.', 'validation')),
						'validation', 'snapshot_identity_mismatch',
					);
				}
				$this->deliveryObservation = ['result' => 'duplicate', 'outcome' => DeliveryTable::OUTCOME_APPLIED];

				return new Result();
			}
			$this->diagnosticStage = 'scope';
			$scopeResult = $this->scopeResolver->resolveState($key);
			if ($event === self::EVENT_SAVED && !$scopeResult->isSuccess())
			{
				$state = $this->documentStateRepository->getByKey($key);
				if ((!$state && HostDocumentKey::isStable($key))
					|| ($state && (int)$state['EDIT_SCOPE_VERSION'] === 1 && (int)$state['EDIT_FENCE_STATE'] === 2))
				{
					return (new Result())->addError(new Error('The shared registry is unavailable.', 'vibeoffice_registry_busy'));
				}
			}
			if ($scopeResult->isSuccess() && (!$scopeResult->getData()['state']
				|| ($policy['legacy'] && $scopeResult->getData()['legacy'])))
			{
				$scopeResult = $this->scopeResolver->resolve($key);
			}
			if (!$scopeResult->isSuccess())
			{
				return $this->ignoreDelivery($deliveryId, $event, $key, 'scope_unknown');
			}
			$scope = $scopeResult->getData();
			$this->observeScope($scope['state'] ?? null);
			$this->activeDiagnosticFields['generation_rev'] = $policy['rev'] ?? null;
			// "There is no placement" is a known fact and not a lost identity: that form of the empty
			// key is handled below instead of being thrown away with the delivery.
			$identityUnknown = !$policy['identityKnown'] && !$policy['placementAbsent'];
			if (($identityUnknown && (!$policy['legacy'] || $event === self::EVENT_FAILOVER))
				|| ($policy['legacy'] && !$scope['legacy'] && $event !== self::EVENT_FAILOVER)
				|| (!$scope['state'] && (!$policy['legacy'] || $scope['sessions'] === [])))
			{
				return $this->ignoreDelivery($deliveryId, $event, $key, 'origin_unknown');
			}

			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE)
			{
				if (($delivery['SNAPSHOT_SEQ'] === null ? null : (int)$delivery['SNAPSHOT_SEQ']) !== $policy['snapshotSeq'])
				{
					return $this->observeFailure(
				(new Result())->addError(new Error('Delivery snapshot changed.', 'validation')),
				'validation', 'snapshot_identity_mismatch',
			);
				}
				$notified = (int)$delivery['NOTIFIED'] === 1;
				if ($notified)
				{
					$this->deliveryObservation = [
						'result' => 'duplicate',
						'outcome' => $this->knownDeliveryOutcome($delivery['OUTCOME'] ?? null),
					];
				}

				return $this->completeSavedDelivery((int)$delivery['ID'], $policy, $notified);
			}

			if ($event === self::EVENT_SAVED && $scope['state'])
			{
				return $this->processSavedDelivery($deliveryId, $payload, $policy, $scope, $delivery);
			}
			if ($event === self::EVENT_SAVED && !$scope['state'] && !$this->legacyUploaderInjected)
			{
				return $this->processLegacySavedDelivery($deliveryId, $payload, $policy, $scope, $delivery);
			}
			if ($event === self::EVENT_SESSION_CLOSED && !$policy['legacy'])
			{
				$this->diagnosticStage = 'finalize';
				return $this->terminalSessionFinalizer->withDocumentTransaction(
					$key,
					function () use ($deliveryId, $event, $key, $policy, $payload): Result {
						$state = $this->documentStateRepository->getByKey($key);
						$shared = $state && \Bitrix\Disk\Document\Vibeoffice\SharedSessionScope::version($state) === 1;
						$closed = $this->registry->applyClosed(
							$key, $policy['terminated'], $policy['retained'],
							$policy['identityKnown'] ? $policy['editorKey'] : null,
							$policy['identityKnown'] ? $policy['rev'] : null,
						);
						if (!$closed->isSuccess())
						{
							return $closed;
						}
						$finalized = $this->terminalSessionFinalizer->finalizeSessions($closed->getData()['sessionIds']);
						if ($finalized->isSuccess() && ($closed->getData()['placementAccepted'] ?? true)
							&& ($payload['notmodified'] ?? null) === true
							&& $policy['identityKnown'] && $policy['retained'] === []
							&& ($payload['reason'] ?? null) !== 'ds_session_lost')
						{
							if ($shared)
							{
								$advanced = $this->registry->advanceTerminalPlacementLocked($state, $policy['editorKey'], $policy['rev']);
								if (!$advanced->isSuccess())
								{
									return $advanced;
								}
								if (!($advanced->getData()['accepted'] ?? false))
								{
									return $this->ignoreDelivery($deliveryId, $event, $key, 'placement_stale');
								}
								$closed = $this->registry->terminateEditorGeneration($key, $policy['editorKey'], $policy['rev']);
								$finalized = $closed->isSuccess()
									? $this->terminalSessionFinalizer->finalizeSessions($closed->getData()['sessionIds'])
									: $closed;
							}
							if ($finalized->isSuccess())
							{
								$finalized = $this->documentStateRepository->storeTerminalProof($key, $policy, $deliveryId);
							}
						}

						return $finalized->isSuccess()
							? $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key])
							: $finalized;
					},
				);
			}
			// An empty placement identifies neither a lost editor generation nor its sessions.
			// Local timestamps cannot establish that association across retries and clock drift.
			// Acknowledge it without terminating sessions or overwriting their metadata.
			if ($policy['placementAbsent'])
			{
				return $this->ignoreDelivery($deliveryId, $event, $key, 'placement_unassociated');
			}
			if ($event === self::EVENT_FAILOVER && $scope['state'])
			{
				$this->diagnosticStage = 'finalize';
				return $this->terminalSessionFinalizer->withDocumentTransaction(
					$key,
					function () use ($payload, $scope, $deliveryId, $event, $key): Result {
						$recipients = $scope['legacy'] && $scope['sessions'] !== []
							? (new Result())->setData(['sessions' => $scope['sessions']])
							: $this->scopeResolver->resolveRecipients($key, $payload['editor_key'], $payload['rev']);
						if (!$recipients->isSuccess())
						{
							return $recipients;
						}
						$placement = $this->registry->advanceActivePlacement($key, $payload['editor_key'], $payload['rev']);
						if (!$placement->isSuccess())
						{
							return $placement;
						}
						if (!($placement->getData()['accepted'] ?? false))
						{
							return $this->ignoreDelivery($deliveryId, $event, $key, 'placement_stale');
						}
						$updated = $this->updateFailoverMetadata($payload, $recipients->getData()['sessions']);

						return $updated->isSuccess()
							? $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key]) : $updated;
					},
				);
			}

			$processed = match ($event)
			{
				self::EVENT_SAVED => $this->handleSaved($deliveryId, $payload),
				self::EVENT_SESSION_CLOSED => $this->handleSessionClosed($payload),
				default => $this->updateFailoverMetadata($payload, $scope['sessions']),
			};
			if (!$processed->isSuccess())
			{
				return $processed;
			}
			$fields = $scope['state'] || ($processed->getData()['savedContentApplied'] ?? true) === false
				? ['HOST_DOC_KEY' => $key] : [];

			return $this->markDeliveryProcessed($deliveryId, $this->resolveLedgerEvent($event, $processed), $key, $fields);
		}
		catch (Throwable $exception)
		{
			$this->logDeliveryException($exception);

			return (new Result())->addError(new Error('Could not process the Vibeoffice delivery.', 'vibeoffice_delivery_failed'));
		}
		finally
		{
			try
			{
				$connection->unlock($lockName);
			}
			finally
			{
				if ($connection->getType() === 'mysql')
				{
					$connection->disconnect();
				}
			}
		}
	}

	private function acquireDeliveryLock(string $lockName): ?Connection
	{
		$connection = $this->getDeliveryLockConnection();
		$locked = false;
		try
		{
			$locked = $connection->lock($lockName, self::LOCK_TIMEOUT);

			return $locked ? $connection : null;
		}
		finally
		{
			if (!$locked && $connection->getType() === 'mysql')
			{
				$connection->disconnect();
			}
		}
	}

	private function getDeliveryLockConnection(): Connection
	{
		$pool = Application::getInstance()->getConnectionPool();
		$primary = $pool->getConnection(ConnectionPool::DEFAULT_CONNECTION_NAME);
		// PostgreSQL advisory locks coexist. Its pg_connect may reuse the primary native handle,
		// so a cloned wrapper must not disconnect it. MySQL needs its own physical connection.
		return $primary->getType() === 'mysql'
			? $pool->cloneConnection(ConnectionPool::DEFAULT_CONNECTION_NAME, 'disk_vo_delivery', [
				'options' => 0, 'persistent' => false,
			])
			: $primary;
	}

	private function getDelivery(int $deliveryId): ?array
	{
		$namespaces = new PlatformNamespace($this->getConfiguration());
		$delivery = DeliveryTable::query()->setSelect(['*'])->where('DELIVERY_ID', $deliveryId)
			->whereIn('NAMESPACE', $namespaces->deliveryValues($this->source->namespace))->fetch() ?: null
		;
		$this->observeDelivery($delivery);

		return $delivery;
	}

	/**
	 * The event the ledger row is written with: the delivered event by default, or the one the
	 * handler named for an outcome that must not be read as that event afterwards (a refused
	 * write is acknowledged, but it is no `document.saved`).
	 */
	private function resolveLedgerEvent(string $event, Result $processResult): string
	{
		$ledgerEvent = $processResult->getData()[self::RESULT_KEY_LEDGER_EVENT] ?? null;

		return is_string($ledgerEvent) && $ledgerEvent !== '' ? $ledgerEvent : $event;
	}

	/**
	 * Writes the final "done" marker for a successfully applied delivery. The UNIQUE
	 * delivery ID and namespace constraint remains the last-resort guard against a race; under the
	 * per-delivery lock a duplicate here is not expected, so any error propagates to the
	 * caller's failure path (the platform then retries and the existence check short-circuits).
	 * The delivery ID is unique within its namespace, so an existing ledger row - a saved delivery that
	 * already reached PENDING and then took another branch on a retry - has to be updated, never
	 * inserted a second time.
	 */
	private function knownDeliveryOutcome(mixed $outcome): ?string
	{
		return in_array($outcome, [
			DeliveryTable::OUTCOME_APPLIED, DeliveryTable::OUTCOME_REFUSED, DeliveryTable::OUTCOME_IGNORED,
		], true) ? $outcome : null;
	}

	private function markDeliveryProcessed(int $deliveryId, string $event, ?string $externalHash, array $extra = []): Result
	{
		$existing = $this->getDelivery($deliveryId);
		if ($existing)
		{
			$extra['ID'] = (int)$existing['ID'];
		}
		else
		{
			$extra['NAMESPACE'] = $this->source->storedNamespace();
		}

		$result = $this->writeDelivery($extra + [
			'DELIVERY_ID' => $deliveryId,
			'EXTERNAL_HASH' => $externalHash,
			'EVENT' => $event,
			'STATUS' => DeliveryTable::STATUS_DONE,
			'PROCESSED_TIME' => new DateTime(),
		]);
		if ($result->isSuccess() && ($extra['STATUS'] ?? DeliveryTable::STATUS_DONE) === DeliveryTable::STATUS_DONE
			&& $this->deliveryObservation['result'] === null)
		{
			$outcome = $this->knownDeliveryOutcome($extra['OUTCOME'] ?? $existing['OUTCOME'] ?? null);
			$this->deliveryObservation = [
				'result' => $outcome ?? 'applied',
				'outcome' => $outcome ?? DeliveryTable::OUTCOME_APPLIED,
			];
		}

		return $result;
	}

	private function writeDelivery(array $fields): Result
	{
		try
		{
			$this->diagnosticStage = 'ledger';
			$result = ($this->deliveryMarkerWriter)($fields);

			return $result->isSuccess() ? $result : $this->observeFailure($result, 'ledger', 'ledger_write_failed');
		}
		catch (Throwable $exception)
		{
			$this->logWebhookDiagnostic('saved', 'delivery_exception', $exception, 'ledger', 'ledger_write_failed');
			return (new Result())->addError(new Error('Could not persist the delivery.', 'delivery_marker_failed'));
		}
	}

	private function ignoreDelivery(int $deliveryId, string $event, string $key, string $reason): Result
	{
		if ($event === self::EVENT_SAVED)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Saved document scope is unknown.', $reason)),
				'scope', 'scope_unwritable',
			);
		}
		$this->acknowledgePermanent(
			"Vibeoffice webhook: {$reason}; event={$event}; delivery={$deliveryId}.",
			'ignored',
		);
		$this->deliveryObservation = ['result' => 'ignored', 'outcome' => DeliveryTable::OUTCOME_IGNORED];

		return $this->markDeliveryProcessed($deliveryId, $event, null, ['HOST_DOC_KEY' => $key]);
	}

	private function processSavedDelivery(int $deliveryId, array $payload, array $policy, array $scope, ?array $delivery): Result
	{
		$key = $policy['hostDocKey'];
		if ((int)($scope['state']['EDIT_SCOPE_VERSION'] ?? 0) === 1)
		{
			$registered = $this->documentStateRepository->registerSharedSavedDelivery($key,
				function (array $_state) use ($deliveryId, $key, $policy): Result {
					$current = $this->getDelivery($deliveryId);
					if ($current && ($current['EVENT'] !== self::EVENT_SAVED
						|| ($current['HOST_DOC_KEY'] ?? $current['EXTERNAL_HASH']) !== $key
						|| (int)$current['SNAPSHOT_SEQ'] !== $policy['snapshotSeq']))
					{
						return (new Result())->addError(new Error('Delivery snapshot changed.', 'validation'));
					}
					if (!$current)
					{
						$created = $this->markDeliveryProcessed($deliveryId, self::EVENT_SAVED, null, [
							'HOST_DOC_KEY' => $key, 'STATUS' => DeliveryTable::STATUS_PENDING,
							'SNAPSHOT_SEQ' => $policy['snapshotSeq'],
						]);
						if (!$created->isSuccess()) { return $created; }
						$current = $this->getDelivery($deliveryId);
					}
					return $current
						? (new Result())->setData(['ledgerId' => (int)$current['ID']])
						: (new Result())->addError(new Error('Saved delivery was not persisted.', 'delivery_marker_failed'));
				});
			if (!$registered->isSuccess()) { return $registered; }
			$delivery = $this->getDelivery($deliveryId);
		}
		if ($delivery === null)
		{
			$created = $this->markDeliveryProcessed($deliveryId, self::EVENT_SAVED, null, [
				'HOST_DOC_KEY' => $key,
				'STATUS' => DeliveryTable::STATUS_PENDING,
				'SNAPSHOT_SEQ' => $policy['snapshotSeq'],
			]);
			if (!$created->isSuccess())
			{
				return $created;
			}
			$delivery = $this->getDelivery($deliveryId);
		}
		if ($delivery === null)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Saved delivery was not persisted.', 'delivery_marker_failed')),
				'ledger', 'ledger_write_failed',
			);
		}
		if (($delivery['SNAPSHOT_SEQ'] === null ? null : (int)$delivery['SNAPSHOT_SEQ']) !== $policy['snapshotSeq'])
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Delivery snapshot changed.', 'validation')),
				'validation', 'snapshot_identity_mismatch',
			);
		}
		$ledgerId = (int)$delivery['ID'];
		if ($delivery['STATUS'] !== DeliveryTable::STATUS_APPLIED)
		{
			$this->diagnosticStage = 'stage_file';
			$prepared = $this->savedDocumentWriter->prepare($ledgerId, $payload);
			if (!$prepared->isSuccess())
			{
				return ($prepared->getData()['originalFileMissing'] ?? null) === true
					? $this->refuseMissingDocument($ledgerId, $deliveryId, $payload, $prepared)
					: $prepared;
			}
			$this->diagnosticStage = 'write_version';
			$applied = $this->savedDocumentWriter->apply($ledgerId, $payload, $prepared->getData()['stagedFile']);
			if (!$applied->isSuccess())
			{
				$errors = $applied->getErrors();

				return count($errors) === 1 && $errors[0]->getCode() === 'vibeoffice_document_not_found'
					? $this->refuseMissingDocument($ledgerId, $deliveryId, $payload, $applied)
					: $applied;
			}
			$delivery = $this->getDelivery($deliveryId);
			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE
				&& $delivery['OUTCOME'] === DeliveryTable::OUTCOME_REFUSED && $delivery['REASON'] === 'validation')
			{
				$this->deliveryObservation = ['result' => 'refused', 'outcome' => DeliveryTable::OUTCOME_REFUSED];
				$this->logWebhookDiagnostic('saved', 'save_refused', reason: 'read_only_scope');

				return new Result();
			}
			if ($delivery && $delivery['STATUS'] === DeliveryTable::STATUS_DONE
				&& $delivery['OUTCOME'] === DeliveryTable::OUTCOME_IGNORED)
			{
				return new Result();
			}
		}

		$this->diagnosticStage = 'finalize';
		$finished = $this->terminalSessionFinalizer->withDocumentTransaction(
			$key,
			function () use ($deliveryId, $ledgerId, $key, $policy, $scope): Result {
				$delivery = $this->getDelivery($deliveryId);
				if ($delivery['STATUS'] !== DeliveryTable::STATUS_APPLIED || (int)$delivery['RESULT_OBJECT_ID'] <= 0)
				{
					return $this->observeFailure(
				(new Result())->addError(new Error('Saved content is not committed.', 'vibeoffice_saved_not_applied')),
				'ledger', 'ledger_write_failed',
			);
				}
				if ($policy['identityKnown'] && !$policy['legacy'])
				{
					$placement = $this->registry->advanceActivePlacement($key, $policy['editorKey'], $policy['rev']);
					if (!$placement->isSuccess())
					{
						return $placement;
					}
				}
				$finalization = $this->finalizeSavedSessions($policy, $scope);
				if (!$finalization->isSuccess())
				{
					return $this->observeFailure($finalization, 'finalize', 'finalization_failed');
				}
				if ($policy['identityKnown'] && !$policy['legacy']
					&& in_array($policy['source'], self::TERMINAL_SOURCES, true)
					&& in_array($policy['finalization'], [
						WebhookEventPolicy::FINALIZATION_THIS_EVENT, WebhookEventPolicy::FINALIZATION_SEPARATE,
					], true))
				{
					$receipt = $this->documentStateRepository->getSnapshot($key, (int)$policy['snapshotSeq']);
					if ($receipt && (int)$receipt['RESULT_OBJECT_ID'] > 0
						&& (int)$receipt['RESULT_OBJECT_ID'] === (int)$delivery['RESULT_OBJECT_ID']
						&& $receipt['RESULT_VERSION_ID'] === $delivery['RESULT_VERSION_ID']
						&& $receipt['RESULT_CONTENT_VERSION'] === $delivery['RESULT_CONTENT_VERSION'])
					{
						$proof = $this->documentStateRepository->storeTerminalProof(
							$key, $policy, $deliveryId, $receipt,
						);
						if (!$proof->isSuccess())
						{
							return $proof;
						}
					}
				}

				return $this->writeDelivery([
					'ID' => $ledgerId,
					'STATUS' => DeliveryTable::STATUS_DONE,
					'PROCESSED_TIME' => new DateTime(),
				]);
			},
		);

		return $finished->isSuccess() ? $this->completeSavedDelivery($ledgerId, $policy) : $finished;
	}

	private function refuseMissingDocument(int $ledgerId, int $deliveryId, array $payload, Result $failure): Result
	{
		if (!$this->supportsSavedApplyAck)
		{
			$this->activeDiagnosticFields['refusal_check'] = 'ack_not_negotiated';

			return $this->observeFailure($failure, $this->diagnosticStage, 'file_missing');
		}
		$refusal = $this->missingDocumentRefusal->tryRefuse($ledgerId, $deliveryId, $payload);
		$this->activeDiagnosticFields['refusal_check'] = $refusal->getData()['refusalCheck'] ?? null;
		if (!$refusal->isSuccess())
		{
			return $this->observeFailure(
				$refusal, $this->diagnosticStage, $failure->getData()['documentNotFoundReason'] ?? 'file_missing',
			);
		}
		if (!($refusal->getData()['refused'] ?? false))
		{
			return $this->observeFailure($failure, $this->diagnosticStage, 'file_missing');
		}
		$this->deliveryObservation = ['result' => 'refused', 'outcome' => DeliveryTable::OUTCOME_REFUSED];
		$this->logWebhookDiagnostic('saved', 'save_refused', reason: 'file_missing');

		return new Result();
	}

	private function finalizeSavedSessions(array $policy, array $scope): Result
	{
		if (!in_array($policy['finalization'], [WebhookEventPolicy::FINALIZATION_THIS_EVENT, WebhookEventPolicy::FINALIZATION_LEGACY], true))
		{
			return new Result();
		}
		$key = $policy['hostDocKey'];
		if ($policy['finalization'] === WebhookEventPolicy::FINALIZATION_THIS_EVENT)
		{
			// Forgotten and a delayed retained decision may arrive in either order.
			$rows = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
				->where('HOST_DOC_KEY', $key)->where('EDITOR_KEY', $policy['editorKey'])->where('REV', $policy['rev'])
				->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)->whereNotNull('API_SESSION_ID')->fetchAll();

			$ids = array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'));

			return $this->terminalSessionFinalizer->finalizeSessions($ids);
		}
		if ($policy['identityKnown'])
		{
			$terminated = $this->registry->terminateEditorGeneration($key, $policy['editorKey'], $policy['rev']);
		}
		else
		{
			$rows = PlatformSessionTable::query()->setSelect(['API_SESSION_ID'])
				->where('HOST_DOC_KEY', $key)->whereNotNull('API_SESSION_ID')->fetchAll();
			$terminated = $this->registry->applyClosed($key, array_column($rows, 'API_SESSION_ID'), []);
		}
		if (!$terminated->isSuccess())
		{
			return $terminated;
		}
		$ids = $terminated->getData()['sessionIds'];
		if ($policy['finalization'] === WebhookEventPolicy::FINALIZATION_LEGACY && !$policy['identityKnown'])
		{
			$ids = array_merge($ids, array_map(static fn(DocumentSession $session): int => (int)$session->getId(), $scope['sessions']));
		}

		return $this->terminalSessionFinalizer->finalizeSessions($ids);
	}

	private function completeSavedDelivery(int $ledgerId, array $policy, bool $notified = false): Result
	{
		$generation = $policy['identityKnown'] && !$policy['legacy'] ? [
			'hostDocKey' => $policy['hostDocKey'], 'editorKey' => $policy['editorKey'], 'rev' => $policy['rev'],
		] : null;

		$this->diagnosticStage = 'notify';
		if ($this->supportsSavedApplyAck && $generation !== null)
		{
			// Persist the retry before acknowledging the committed version.
			if ($notified || SavedNotificationAgent::schedule($ledgerId, $generation['editorKey'], $generation['rev']))
			{
				return new Result();
			}

			return $this->observeFailure(
				(new Result())->addError(new Error(
					'Could not schedule the saved notification.', 'vibeoffice_notification_queue',
				)),
				'notify', 'notification_failed',
			);
		}

		return $this->savedDocumentWriter->complete($ledgerId, $generation);
	}

	private function processLegacySavedDelivery(
		int $deliveryId,
		array $payload,
		array $policy,
		array $scope,
		?array $delivery,
	): Result
	{
		$key = $policy['hostDocKey'];
		$seq = $policy['snapshotSeq'];
		if (!is_int($seq))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy save requires a durable snapshot.', 'vibeoffice_audit_unavailable',
				)),
				'validation', 'validation_failed',
			);
		}
		$file = $this->legacyScopeFile($key, $scope['sessions']);
		if (!$file)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved scope is not proven.', 'scope_unknown')),
				'scope', 'scope_unwritable',
			);
		}
		if ($delivery === null)
		{
			$created = $this->markDeliveryProcessed($deliveryId, self::EVENT_SAVED, $key, [
				'HOST_DOC_KEY' => $key,
				'SNAPSHOT_SEQ' => $seq,
				'STATUS' => DeliveryTable::STATUS_PENDING,
			]);
			if (!$created->isSuccess())
			{
				return $created;
			}
			$delivery = $this->getDelivery($deliveryId);
		}
		if (!$delivery || (int)$delivery['SNAPSHOT_SEQ'] !== $seq)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved delivery identity changed.', 'validation')),
				'validation', 'snapshot_identity_mismatch',
			);
		}
		if ($delivery['STATUS'] === DeliveryTable::STATUS_DONE)
		{
			return new Result();
		}
		if ($delivery['STATUS'] !== DeliveryTable::STATUS_APPLIED)
		{
			$this->diagnosticStage = 'ledger';
			$ordered = $this->legacySnapshotOrder($key, $seq, $deliveryId, $file);
			if (!$ordered->isSuccess())
			{
				return $ordered;
			}
			if (($ordered->getData()['settled'] ?? false) === false)
			{
				$staged = $this->stageLegacySavedFile($delivery, $payload, $file);
				if (!$staged->isSuccess())
				{
					return $staged;
				}
			}
			$this->diagnosticStage = 'write_version';
			$applied = $this->applyLegacySavedFile($deliveryId, $payload, $file);
			if (!$applied->isSuccess())
			{
				return $applied;
			}
			$delivery = $this->getDelivery($deliveryId);
		}
		if ($delivery['OUTCOME'] === DeliveryTable::OUTCOME_IGNORED)
		{
			return new Result();
		}
		if ($delivery['OUTCOME'] !== DeliveryTable::OUTCOME_APPLIED || (int)$delivery['RESULT_OBJECT_ID'] <= 0)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy saved content is not proven.', 'vibeoffice_saved_not_applied',
				)),
				'ledger', 'ledger_write_failed',
			);
		}
		if (!(int)$delivery['NOTIFIED'])
		{
			$resolved = (new SavedSnapshotCompletion())->resolve($delivery);
			if (!$resolved->isSuccess())
			{
				return $this->observeFailure($resolved, 'notify', 'snapshot_identity_mismatch');
			}
			$completion = $resolved->getData();
		}
		if (in_array($policy['source'], self::TERMINAL_SOURCES, true))
		{
			$this->diagnosticStage = 'finalize';
			$finalized = $this->terminalSessionFinalizer->finalizeDocument($key);
			if (!$finalized->isSuccess())
			{
				return $this->observeFailure($finalized, 'finalize', 'finalization_failed');
			}
		}
		if (!(int)$delivery['NOTIFIED'])
		{
			if (!$completion['notified'])
			{
				$this->diagnosticStage = 'notify';
				$effects = $this->dispatchSavedSideEffects([
					'file' => $file, 'sessions' => $scope['sessions'], 'versionId' => $delivery['RESULT_VERSION_ID'],
				]);
				if (!$effects->isSuccess())
				{
					return $this->observeFailure($effects, 'notify', 'notification_failed');
				}
				if ($completion['ownerLedgerId'] !== (int)$delivery['ID'])
				{
					$marked = $this->writeDelivery(['ID' => $completion['ownerLedgerId'], 'NOTIFIED' => 1]);
					if (!$marked->isSuccess())
					{
						return $this->observeFailure($marked, 'ledger', 'ledger_write_failed');
					}
				}
			}
		}

		return $this->writeDelivery([
			'ID' => (int)$delivery['ID'],
			'STATUS' => DeliveryTable::STATUS_DONE,
			'NOTIFIED' => 1,
			'PROCESSED_TIME' => new DateTime(),
		]);
	}

	private function legacyScopeFile(string $key, array $sessions): ?File
	{
		$file = null;
		$hasEditSession = false;
		foreach ($sessions as $session)
		{
			if (!$session instanceof DocumentSession || $session->getExternalHash() !== $key
				|| (int)$session->getVersionId() > 0)
			{
				return null;
			}
			$candidate = $session->getFile();
			if (!$candidate instanceof File || $candidate->isDeleted()
				|| ($file && (int)$candidate->getRealObjectId() !== (int)$file->getRealObjectId()))
			{
				return null;
			}
			$file ??= $candidate;
			$hasEditSession = $hasEditSession || $session->isEdit();
		}

		return $hasEditSession ? $file : null;
	}

	private function legacySnapshotOrder(string $key, int $seq, int $deliveryId, File $file): Result
	{
		$exact = SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $key)->where('SNAPSHOT_SEQ', $seq)->fetch();
		$head = SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $key)->setOrder(['SNAPSHOT_SEQ' => 'DESC'])->setLimit(1)->fetch();
		if (!$this->source->departing && (
			($exact && (int)$exact['RESULT_OBJECT_ID'] !== (int)$file->getRealObjectId())
			|| ($head && (int)$head['RESULT_OBJECT_ID'] !== (int)$file->getRealObjectId())
		))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy snapshot object is unknown.', 'scope_unknown')),
				'scope', 'scope_unwritable',
			);
		}
		if ($exact && (int)$exact['DELIVERY_ID'] === $deliveryId)
		{
			return (new Result())->setData(['settled' => true]);
		}
		if ($exact && $exact['DELIVERY_ID'] === null)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy snapshot ownership is unknown.', 'snapshot_ownership_unknown',
				)),
				'ledger', 'snapshot_identity_mismatch',
			);
		}
		if ($head && $seq < (int)$head['SNAPSHOT_SEQ'])
		{
			return (new Result())->setData(['settled' => true]);
		}

		return new Result();
	}

	private function stageLegacySavedFile(array $delivery, array $payload, File $file): Result
	{
		$this->observeDelivery($delivery);
		$this->diagnosticStage = 'stage_file';
		$stagedId = (int)$delivery['STAGED_FILE_ID'];
		if ($stagedId > 0 && \CFile::GetList([], ['ID' => $stagedId])->Fetch())
		{
			return new Result();
		}
		$url = (string)($payload['file_url'] ?? '');
		if (!$this->source->allowsFileUrl($url))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved URL is not allowed.', 'ssrf_blocked')),
				'download', 'url_rejected',
			);
		}
		$this->diagnosticStage = 'download';
		$download = $this->downloadFromPlatform($url);
		if (!$download->isSuccess())
		{
			return $download;
		}
		$tmp = $download->getData()['file'];
		try
		{
			$array = \CFile::MakeFileArray($tmp);
			if (!is_array($array) || empty($array['tmp_name']))
			{
				return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved file is not readable.', 'download_failed')),
				'download', 'downloaded_file_unreadable',
			);
			}
			$limit = $this->getConfiguration()->getMaxFileSize();
			if ($limit > 0 && (int)$array['size'] > $limit)
			{
				return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy saved file exceeds the limit.', 'vibeoffice_file_too_large',
				)),
				'download', 'file_too_large',
			);
			}
			$array['name'] = $file->getName();
			$array['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
			$this->diagnosticStage = 'stage_file';
			$stagedId = (int)\CFile::SaveFile($array, Driver::INTERNAL_MODULE_ID, true, true);
			if ($stagedId <= 0 || !\CFile::GetFileArray($stagedId))
			{
				return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy saved file could not be staged.', 'vibeoffice_saved_write_failed',
				)),
				'stage_file', 'stage_file_failed',
			);
			}
			$this->diagnosticStage = 'ledger';
			$updated = DeliveryTable::update((int)$delivery['ID'], [
				'STAGED_FILE_ID' => $stagedId,
				'STATUS' => DeliveryTable::STATUS_STAGED,
			]);
			if (!$updated->isSuccess())
			{
				\CFile::Delete($stagedId);

				return $this->observeFailure(
					(new Result())->addErrors($updated->getErrors()),
					'ledger', 'ledger_write_failed',
				);
			}

			return new Result();
		}
		finally
		{
			if (is_file($tmp))
			{
				unlink($tmp);
			}
		}
	}

	private function applyLegacySavedFile(int $deliveryId, array $payload, File $scopeFile): Result
	{
		$key = $payload['host_doc_key'];
		$delivery = $this->getDelivery($deliveryId);
		$hashedFileId = (int)($delivery['STAGED_FILE_ID'] ?? 0);
		$hash = $hashedFileId > 0 ? $this->snapshotHasher->hash($hashedFileId) : null;
		$connection = Application::getConnection();
		$lockName = DocumentStateRepository::getDocumentLockName($key);
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Could not lock the legacy document.', 'vibeoffice_lock_timeout')),
				'write_version', 'lock_timeout',
			);
		}
		try
		{
			// Update lines shipped without the Disk audit (26.1300.0) have no AuditTransaction class.
			if (!class_exists(AuditTransaction::class))
			{
				return $this->applyLegacySavedFileInTransaction(
					$connection, $deliveryId, $payload, $scopeFile, $hashedFileId, $hash,
				);
			}

			$audit = new AuditTransaction($connection);
			$auditResult = $audit->execute(function () use (
				$deliveryId, $payload, $scopeFile, $hashedFileId, $hash,
			): AuditTransactionOutcome {
				$result = $this->applyLegacySavedFileInAudit($deliveryId, $payload, $scopeFile, $hashedFileId, $hash);

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

			return $this->observeFailure(
				DatabaseFailureResult::fromException(
					new AuditTransactionException($auditResult),
					new Error('Legacy audit could not prove the file state.', 'vibeoffice_audit_failed'),
				),
				$this->diagnosticStage, 'unknown_failure',
			);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function applyLegacySavedFileInTransaction(
		Connection $connection,
		int $deliveryId,
		array $payload,
		File $scopeFile,
		int $hashedFileId,
		?Result $hash,
	): Result
	{
		$connection->startTransaction();
		try
		{
			$result = $this->applyLegacySavedFileInAudit($deliveryId, $payload, $scopeFile, $hashedFileId, $hash);
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
		if ($result->isSuccess())
		{
			$connection->commitTransaction();
		}
		else
		{
			$connection->rollbackTransaction();
		}

		return $result;
	}

	private function applyLegacySavedFileInAudit(
		int $deliveryId,
		array $payload,
		File $scopeFile,
		int $hashedFileId,
		?Result $hash,
	): Result
	{
		$key = $payload['host_doc_key'];
		$seq = $payload['snapshot_seq'];
		$delivery = $this->getDelivery($deliveryId);
		if (!$delivery || $delivery['HOST_DOC_KEY'] !== $key || (int)$delivery['SNAPSHOT_SEQ'] !== $seq)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved delivery changed.', 'validation')),
				'validation', 'snapshot_identity_mismatch',
			);
		}
		$locked = DocumentStateRepository::fetchForUpdate(ObjectTable::query()
			->setSelect(['ID'])->where('ID', $scopeFile->getRealObjectId()));
		$file = $locked ? File::loadById((int)$locked['ID']) : null;
		if (!$file || $file->isDeleted()
			|| (int)$file->getRealObjectId() !== (int)$scopeFile->getRealObjectId())
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved file is unavailable.', 'scope_unknown')),
				'scope', 'file_missing',
			);
		}
		$scope = $this->scopeResolver->resolve($key);
		if (!$scope->isSuccess() || !$this->legacyScopeFile($key, $scope->getData()['sessions']))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy saved scope changed.', 'scope_unknown')),
				'scope', 'scope_unwritable',
			);
		}
		$exact = SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $key)->where('SNAPSHOT_SEQ', $seq)->fetch()
		;
		$head = SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $key)->setOrder(['SNAPSHOT_SEQ' => 'DESC'])->setLimit(1)->fetch()
		;
		if (!$this->source->departing && (
			($exact && (int)$exact['RESULT_OBJECT_ID'] !== (int)$file->getId())
			|| ($head && (int)$head['RESULT_OBJECT_ID'] !== (int)$file->getId())
		))
		{
			return $this->observeFailure(
				(new Result())->addError(new Error('Legacy snapshot belongs to another object.', 'scope_unknown')),
				'ledger', 'snapshot_identity_mismatch',
			);
		}
		if ($exact && (int)$exact['DELIVERY_ID'] === $deliveryId)
		{
			return $this->markLegacyApplied($delivery, $exact);
		}
		if ($head && $seq < (int)$head['SNAPSHOT_SEQ'])
		{
			$this->diagnosticStage = 'ledger';
			$updated = DeliveryTable::update((int)$delivery['ID'], [
				'STATUS' => DeliveryTable::STATUS_DONE,
				'OUTCOME' => DeliveryTable::OUTCOME_IGNORED,
				'REASON' => 'stale_snapshot',
			]);

			return $updated->isSuccess() ? new Result()
				: $this->observeFailure(
					(new Result())->addErrors($updated->getErrors()),
					'ledger', 'ledger_write_failed',
				);
		}
		$stagedId = (int)$delivery['STAGED_FILE_ID'];
		if ($stagedId !== $hashedFileId || $hash === null)
		{
			return (new Result())->addError(new Error('Legacy staged file changed.', 'vibeoffice_saved_write_failed'));
		}
		if (!$hash->isSuccess())
		{
			return $hash;
		}
		$sha256 = $hash->getData()['sha256'];
		if ($exact)
		{
			$source = strtolower((string)($payload['source'] ?? ''));
			if ($exact['DELIVERY_ID'] === null || $exact['SOURCE'] !== 'forcesave'
				|| !in_array($source, self::TERMINAL_SOURCES, true)
				|| !$this->snapshotProofResolver->matches($exact, $sha256))
			{
				return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy snapshot belongs to another delivery.', 'snapshot_ownership_unknown',
				)),
				'ledger', 'snapshot_identity_mismatch',
			);
			}

			return $this->markLegacyApplied($delivery, $exact);
		}
		$staged = $stagedId > 0 ? \CFile::GetFileArray($stagedId) : false;
		if (!$staged)
		{
			return $this->observeFailure(
				(new Result())->addError(new Error(
					'Legacy staged file is unavailable.', 'vibeoffice_saved_write_failed',
				)),
				'stage_file', 'staged_file_missing',
			);
		}
		$session = $scope->getData()['sessions'][0];
		$author = WebhookEventPolicy::resolveVersionAuthorId(
			$payload,
			array_map(static fn(DocumentSession $item): int => $item->getUserId(), $scope->getData()['sessions']),
			$session->getUserId() > 0 ? $session->getUserId() : SystemUser::SYSTEM_USER_ID,
		);
		$this->diagnosticStage = 'write_version';
		if ($this->source->departing)
		{
			$copyId = $head && (int)$head['RESULT_OBJECT_ID'] !== (int)$file->getId()
				? (int)$head['RESULT_OBJECT_ID'] : null;
			$copyService = new ConflictCopyService();
			$saved = $copyService->save($file, $staged, $author, $copyId, true);
			if (!$saved->isSuccess() && $saved->getErrorCollection()->getErrorByCode(ConflictCopyService::ERROR_STALE_COPY))
			{
				$saved = $copyService->save($file, $staged, $author, null, true);
			}
			if (!$saved->isSuccess())
			{
				return $this->observeFailure($saved, 'write_version');
			}
			$resultFile = $saved->getData()['file'];
			$versionId = $saved->getData()['versionId'];
		}
		else
		{
			$version = $file->addVersion(
				$staged, $author, !$this->joinPolicy->isAllowed($key, (int)$file->getRealObjectId()),
				$this->getEditingSessionWriteOptions($session),
			);
			if (!$version || (int)$file->getFileId() !== $stagedId)
			{
				try
				{
					$this->observeFailure((new Result())->addErrors($file->getErrors()), 'write_version');
				}
				catch (Throwable)
				{
					// Reading model errors for diagnostics must not change the write refusal.
				}
				$finalRefusal = $this->findFinalRefusalCode($file);

				return (new Result())->addError($finalRefusal !== null && $finalRefusal === File::ERROR_FORMAT_RESTRICTED
					? new Error('Legacy saved version was refused.', 'format_restricted')
					: new Error('Legacy saved version failed.', 'write_failed'));
			}
			$resultFile = $file;
			$versionId = (int)$version->getId();
		}
		$receipt = [
			'HOST_DOC_KEY' => $key,
			'SNAPSHOT_SEQ' => $seq,
			'RESULT_OBJECT_ID' => (int)$resultFile->getId(),
			'RESULT_VERSION_ID' => $versionId,
			'RESULT_CONTENT_VERSION' => (int)$resultFile->getGlobalContentVersion(),
			'CONTENT_SHA256' => $sha256,
			'DELIVERY_ID' => $deliveryId,
			'SOURCE' => (string)($payload['source'] ?? 'legacy'),
		];
		$this->diagnosticStage = 'ledger';
		$insert = SavedSnapshotTable::add($receipt);
		if (!$insert->isSuccess())
		{
			return $this->observeFailure(
				(new Result())->addErrors($insert->getErrors()),
				'ledger', 'ledger_write_failed',
			);
		}

		return $this->markLegacyApplied($delivery, $receipt);
	}

	private function markLegacyApplied(array $delivery, array $receipt): Result
	{
		$this->diagnosticStage = 'ledger';
		$updated = DeliveryTable::update((int)$delivery['ID'], [
			'STATUS' => DeliveryTable::STATUS_APPLIED,
			'OUTCOME' => DeliveryTable::OUTCOME_APPLIED,
			'RESULT_OBJECT_ID' => (int)$receipt['RESULT_OBJECT_ID'],
			'RESULT_VERSION_ID' => $receipt['RESULT_VERSION_ID'],
			'RESULT_CONTENT_VERSION' => $receipt['RESULT_CONTENT_VERSION'] ?? null,
		]);

		return $updated->isSuccess() ? new Result()
				: $this->observeFailure(
					(new Result())->addErrors($updated->getErrors()),
					'ledger', 'ledger_write_failed',
				);
	}

	/** Historical sessions without pinned state use the legacy source rules and cache checkpoint. */
	private function handleSaved(int $deliveryId, array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $this->observeFailure(
				$result->addError(new Error('Missing host_doc_key in document.saved webhook.', 'validation')),
				'validation', 'validation_failed',
			);
		}

		$source = mb_strtolower((string)($payload['source'] ?? 'save'));
		$this->diagnosticStage = 'ledger';
		$savedDeliveryStage = $this->getSavedDeliveryStage($deliveryId);
		$savedData = [];

		if ($savedDeliveryStage === null)
		{
			$this->diagnosticStage = 'write_version';
			$uploadResult = ($this->savedVersionUploader)($payload, $externalHash);
			if (!$uploadResult->isSuccess())
			{
				return $this->diagnosticReason !== null ? $result->addErrors($uploadResult->getErrors())
					: $this->observeFailure($result->addErrors($uploadResult->getErrors()), $this->diagnosticStage);
			}

			$savedData = $uploadResult->getData();
			if (($savedData['savedContentApplied'] ?? true) === false)
			{
				return $result->setData(['savedContentApplied' => false]);
			}

			$this->diagnosticStage = 'ledger';
			$versionMarkerResult = $this->markSavedDeliveryStage($deliveryId, self::SAVED_STAGE_VERSION_APPLIED);
			if (!$versionMarkerResult->isSuccess())
			{
				return $this->observeFailure(
					$versionMarkerResult,
					'ledger', 'ledger_write_failed',
				);
			}

			$savedDeliveryStage = self::SAVED_STAGE_VERSION_APPLIED;
		}

		if (in_array($source, self::TERMINAL_SOURCES, true))
		{
			$this->diagnosticStage = 'finalize';
			$finalizationResult = $this->terminalSessionFinalizer->finalizeDocument($externalHash);
			if (!$finalizationResult->isSuccess())
			{
				return $this->observeFailure(
					$finalizationResult instanceof DatabaseFailureResult
						? $finalizationResult : $result->addErrors($finalizationResult->getErrors()),
					'finalize', 'finalization_failed',
				);
			}
		}

		if ($savedDeliveryStage !== self::SAVED_STAGE_SIDE_EFFECTS_APPLIED)
		{
			$this->diagnosticStage = 'notify';
			if ($savedData === [])
			{
				$savedData = $this->loadSavedSideEffectsData($externalHash);
			}

			$sideEffectsResult = ($this->savedSideEffectsHandler)($savedData);
			if (!$sideEffectsResult->isSuccess())
			{
				return $this->observeFailure(
					$result->addErrors($sideEffectsResult->getErrors()),
					'notify', 'notification_failed',
				);
			}

			$this->diagnosticStage = 'ledger';
			$sideEffectsMarkerResult = $this->markSavedDeliveryStage(
				$deliveryId,
				self::SAVED_STAGE_SIDE_EFFECTS_APPLIED,
			);
			if (!$sideEffectsMarkerResult->isSuccess())
			{
				return $this->observeFailure(
					$sideEffectsMarkerResult,
					'ledger', 'ledger_write_failed',
				);
			}
		}

		return $result;
	}

	private function uploadSavedVersion(array $payload, string $externalHash): Result
	{
		$result = new Result();

		$fileUrl = (string)($payload['file_url'] ?? '');
		if ($fileUrl === '')
		{
			return $this->observeFailure(
				$result->addError(new Error('Missing file_url in document.saved webhook.', 'validation')),
				'validation', 'validation_failed',
			);
		}

		// `file_url` is attacker-influenceable payload data, so never dereference it blindly.
		// Accept only http(s) URLs pointing at the configured
		// platform host before it is downloaded and stored as a Disk version.
		if (!$this->source->allowsFileUrl($fileUrl))
		{
			return $this->observeFailure(
				$result->addError(new Error('Refusing to download file_url outside the configured platform host.', 'ssrf_blocked')),
				'download', 'url_rejected',
			);
		}

		$this->diagnosticStage = 'scope';
		$sessions = $this->getSessionsByHash($externalHash);
		$session = $sessions[0] ?? null;
		if (!$session)
		{
			// Permanent not-found: the local session was drained/deleted while a late
			// save still arrived. Retrying can never resolve it, so acknowledge (2xx + ledger
			// row) and log instead of a non-2xx that triggers an endless retry storm. Mirrors
			// self::handleUnsupportedEvent(). Transient failures (download) stay retryable below.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no local session for document.saved key '{$externalHash}', acknowledging.",
				'session_missing',
			)->setData(['savedContentApplied' => false]);
		}

		$file = $session->getFile();
		if (!($file instanceof File))
		{
			// Permanent not-found: the session's file was removed. Acknowledge + log; a
			// retry would fail identically forever.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no file for document.saved session (key '{$externalHash}'), acknowledging.",
				'file_missing',
			)->setData(['savedContentApplied' => false]);
		}

		$this->diagnosticStage = 'download';
		$downloadResult = $this->downloadFromPlatform($fileUrl);
		if (!$downloadResult->isSuccess())
		{
			return $result->addErrors($downloadResult->getErrors());
		}

		$tmpFile = $downloadResult->getData()['file'];
		$tmpFileArray = \CFile::makeFileArray($tmpFile);
		if (!is_array($tmpFileArray) || empty($tmpFileArray['tmp_name']))
		{
			// Missing or empty temp files make makeFileArray return null/false.
			// bail out with a clear error (the platform retries) instead of feeding an
			// invalid array into uploadVersion and emitting a PHP warning.
			return $this->observeFailure(
				$result->addError(new Error('Downloaded document is not a readable file.', 'download_failed')),
				'download', 'downloaded_file_unreadable',
			);
		}

		// Cap the platform-supplied file against getMaxFileSize() before storing it as a Disk
		// version. An oversized file is a permanent condition (the same bytes
		// would download every retry), so acknowledge + log instead of an endless retry.
		$maxFileSize = $this->getConfiguration()->getMaxFileSize();
		$fileSize = (int)($tmpFileArray['size'] ?? 0);
		if ($fileSize <= 0)
		{
			$fileSize = (int)@filesize($tmpFileArray['tmp_name']);
		}
		if ($maxFileSize > 0 && $fileSize > $maxFileSize)
		{
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: downloaded document ({$fileSize} bytes) exceeds the max file size"
				. " ({$maxFileSize} bytes) for key '{$externalHash}'; skipping upload.",
				'file_too_large',
			)->setData(['savedContentApplied' => false]);
		}

		if (in_array($tmpFileArray['type'] ?? null, ['application/encrypted', 'application/zip'], true))
		{
			$fileType = (string)($payload['filetype'] ?? $file->getExtension());
			$tmpFileArray['type'] = MimeType::getByFileExtension($fileType);
		}

		$this->diagnosticStage = 'write_version';
		$userId = $this->resolveSavingUserId($payload, $sessions, $session);

		// commentAttachedObjects is run separately (on background) below, matching OnlyOffice.
		if (!$file->uploadVersion($tmpFileArray, $userId, $this->getEditingSessionWriteOptions($session)))
		{
			return $this->answerRefusedWrite($file, $externalHash);
		}

		return $result->setData([
			'savedContentApplied' => true,
			'file' => $file,
			'sessions' => $sessions,
		]);
	}

	private function dispatchSavedSideEffects(array $data): Result
	{
		$sessions = $data['sessions'] ?? [];
		$file = $data['file'] ?? null;

		if ($file instanceof File)
		{
			$this->commentAttachedObjectsOnBackground($file, isset($data['versionId']) ? (int)$data['versionId'] : null);
		}

		if (is_array($sessions))
		{
			$this->sendEventToParticipants($sessions, 'saved');
		}

		return new Result();
	}

	private function loadSavedSideEffectsData(string $externalHash): array
	{
		$sessions = $this->getSessionsByHash($externalHash);
		$session = $sessions[0] ?? null;
		$file = $session?->getFile();

		return [
			'file' => $file instanceof File ? $file : null,
			'sessions' => $sessions,
		];
	}

	/**
	 * How far a `document.saved` that is still being applied has got.
	 *
	 * A saved is applied in two steps (the version, then the side effects), and a failure in
	 * between makes the platform re-deliver it, so the reached step is checkpointed. The delivery
	 * ledger is not the place for it: {@see DeliveryTable} holds one row per real platform
	 * delivery and its `EVENT` is the protocol event. The checkpoint lives in the shared
	 * persistent storage instead, where its own {@see self::SAVED_STAGE_TTL} applies; if it does
	 * expire before a very late retry, the retry simply repeats the upload.
	 */
	private function getSavedDeliveryStage(int $deliveryId): ?string
	{
		$stage = $this->getSavedDeliveryStageStorage()->get($this->getSavedDeliveryStageKey($deliveryId));
		if (
			$stage === self::SAVED_STAGE_VERSION_APPLIED
			|| $stage === self::SAVED_STAGE_SIDE_EFFECTS_APPLIED
		)
		{
			return $stage;
		}

		return null;
	}

	private function markSavedDeliveryStage(int $deliveryId, string $stage): Result
	{
		$result = new Result();

		try
		{
			$stored = $this->getSavedDeliveryStageStorage()->set(
				$this->getSavedDeliveryStageKey($deliveryId),
				$stage,
				self::SAVED_STAGE_TTL,
			);
		}
		catch (Throwable $exception)
		{
			return DatabaseFailureResult::fromException(
				$exception, new Error($exception->getMessage(), 'saved_delivery_stage_marker_failed'),
			);
		}

		if (!$stored)
		{
			return $result->addError(new Error(
				'Could not store the saved delivery stage.',
				'saved_delivery_stage_marker_failed',
			));
		}

		return $result;
	}

	private function getSavedDeliveryStageKey(int $deliveryId): string
	{
		return self::SAVED_STAGE_KEY_PREFIX . $deliveryId;
	}

	private function getSavedDeliveryStageStorage(): PersistentStorageInterface
	{
		return ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	private function updateFailoverMetadata(array $payload, array $sessions): Result
	{
		$metadata = ['editor_key' => $payload['editor_key'], 'rev' => $payload['rev']];
		$manager = new DocumentSessionManager();
		foreach ($sessions as $session)
		{
			$stored = DocumentSessionManager::extractVoMetadata($session);
			if (($stored['editor_key'] ?? null) === $metadata['editor_key'] && ($stored['rev'] ?? null) === $metadata['rev'])
			{
				continue;
			}
			if (!$manager->persistSessionMetadata($session, $metadata))
			{
				return (new Result())->addError(new Error('Could not persist failover metadata.', 'vibeoffice_metadata_failed'));
			}
		}

		return new Result();
	}

	/**
	 * Deactivates the local sessions for the document key. A strict
	 * `notmodified:true` is a global terminal outcome and also releases document restrictions.
	 * revoked or otherwise ambiguous closes keep the restrictions for drain.
	 */
	private function handleSessionClosed(array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.session-closed webhook.', 'validation'));
		}

		if (($payload['notmodified'] ?? null) === true)
		{
			$this->diagnosticStage = 'finalize';
			$finalizationResult = $this->terminalSessionFinalizer->finalizeDocument($externalHash);
			if (!$finalizationResult->isSuccess())
			{
				return $finalizationResult instanceof DatabaseFailureResult
					? $finalizationResult : $result->addErrors($finalizationResult->getErrors());
			}
		}
		else
		{
			return $this->deactivateSessions($externalHash);
		}

		return $result;
	}

	/**
	 * Resolves the user the new version is attributed to: the claim of the payload when it names a
	 * participant of the `EXTERNAL_HASH` sessions, otherwise the session owner and finally the
	 * system user. See {@see WebhookEventPolicy::resolveVersionAuthorId()}.
	 *
	 * @param DocumentSession[] $sessions
	 */
	private function resolveSavingUserId(array $payload, array $sessions, DocumentSession $session): int
	{
		$sessionUserId = $session->getUserId();

		return WebhookEventPolicy::resolveVersionAuthorId(
			$payload,
			$this->collectSessionUserIds($sessions),
			$sessionUserId > 0 ? $sessionUserId : SystemUser::SYSTEM_USER_ID,
		);
	}

	/**
	 * User ids that participate in the given document sessions (the trusted set of possible
	 * version authors. See {@see self::resolveSavingUserId()}.
	 *
	 * @param DocumentSession[] $sessions
	 * @return int[]
	 */
	private function collectSessionUserIds(array $sessions): array
	{
		$ids = [];
		foreach ($sessions as $session)
		{
			$userId = $session->getUserId();
			if ($userId > 0)
			{
				$ids[] = $userId;
			}
		}

		return $ids;
	}

	/**
	 * Resolves the shared vibeoffice {@see Configuration} through the registered singleton
	 * service instead of `new Configuration()`, so the php_interface override file is
	 * stat'ed/required once per request rather than on every hot-path call.
	 */
	private function getConfiguration(): Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Acknowledges a PERMANENTLY unprocessable delivery (the target session/file no longer
	 * exists, the downloaded document violates a hard limit, or the envelope itself is rejected -
	 * see {@see WebhookEventPolicy::isPermanentFailure()}): logs it and returns a successful
	 * Result so the caller answers 2xx. A permanent condition can never succeed on retry, so a
	 * non-2xx here would only cause an endless retry storm and log spam. Mirrors
	 * {@see self::handleUnsupportedEvent()}.
	 */
	private function acknowledgePermanent(string $_message, string $code = 'permanent_refusal'): Result
	{
		$this->deliveryObservation = ['result' => 'refused', 'outcome' => null];
		$this->logWebhookDiagnostic('acknowledge', $code);

		return new Result();
	}

	/**
	 * The write node stored no version. Whether that ends the delivery is not for this channel to
	 * decide by itself: {@see WriteErrorTransportMap} owns the answer to "would the very same bytes
	 * be refused again", and only a refusal it names as final is acknowledged.
	 *
	 * Everything else - content that did not reach the storage, a limit of the portal that may be
	 * lifted, a failure of the database - is an obstacle of this attempt alone. Such a delivery is
	 * answered with an error, so the platform sends it again and the edits of the user keep their
	 * chance to be stored; an acknowledgement here would lose them silently behind a 2xx.
	 */
	private function answerRefusedWrite(File $file, string $externalHash): Result
	{
		$this->diagnosticStage = 'write_version';
		try
		{
			$this->diagnosticReason = SaveFailureDiagnostic::reasonFromErrors($file->getErrors());
		}
		catch (Throwable)
		{
			$this->diagnosticReason = 'unknown_failure';
		}
		if ($this->isRefusalFinal($file))
		{
			return $this->acknowledgeRefusedWrite($file, $externalHash);
		}

		$this->logWebhookDiagnostic('saved', 'save_refused', stage: 'write_version', reason: $this->diagnosticReason);
		$reason = $this->describeRefusal($file);

		return (new Result())->addError(new Error(
			"Could not store the new version for key '{$externalHash}' ({$reason}).",
			'write_failed',
		));
	}

	/**
	 * Whether the model left a refusal a retry could not get past.
	 */
	private function isRefusalFinal(File $file): bool
	{
		return $this->findFinalRefusalCode($file) !== null;
	}

	/**
	 * Update lines shipped without the Disk format restrictions (26.1300.0) have no
	 * WriteErrorTransportMap: no refusal is final there, every failed write is delivered again.
	 */
	private function findFinalRefusalCode(File $file): ?string
	{
		if (!class_exists(WriteErrorTransportMap::class))
		{
			return null;
		}

		return WriteErrorTransportMap::findFinalRefusalCode($file->getErrors());
	}

	/**
	 * Update lines shipped without the Disk quotas (26.1300.0) have no WriteOperationContext:
	 * the version is written without the context of the editing session.
	 */
	private function getEditingSessionWriteOptions(DocumentSession $session): array
	{
		$options = ['commentAttachedObjects' => false];
		if (enum_exists(WriteOperationContext::class))
		{
			$options[WriteOperationContext::OPTION_KEY] = WriteOperationContext::EditingSession;
			$options[WriteOperationContext::OPTION_KEY_EDITING_SESSION_STARTED_AT] = $session->getCreateTime()->getTimestamp();
		}

		return $options;
	}

	/**
	 * The write node refused the new version for good: a format the portal forbids, or any other
	 * verdict {@see self::isRefusalFinal()} recognizes. Delivery and write are answered apart here.
	 *
	 * The DELIVERY is acknowledged: the Result is successful, so {@see self::process()} writes the
	 * ledger row and the caller answers 2xx. The refusal is a verdict on the very bytes the
	 * platform keeps re-sending, so a retry could only end the same way - a non-2xx would buy an
	 * endless retry-storm and nothing else.
	 *
	 * The WRITE is reported as refused, and nothing here says otherwise: the ledger row carries
	 * {@see self::EVENT_SAVE_REFUSED} instead of the saved event, so neither the drain nor any
	 * later reader takes the delivery for a stored version; the participants get no `saved` event
	 * and no comment is written for a version that does not exist; and the local sessions are left
	 * alone - a refused save closes no editing session, the platform's own
	 * `document.session-closed` still does that.
	 */
	private function acknowledgeRefusedWrite(File $file, string $externalHash): Result
	{
		$reason = $this->describeRefusal($file);

		return $this
			->acknowledgePermanent(
				"Vibeoffice webhook: the new version for key '{$externalHash}' was refused ({$reason}).",
				'save_refused',
			)
			->setData([
				self::RESULT_KEY_LEDGER_EVENT => self::EVENT_SAVE_REFUSED,
				'savedContentApplied' => false,
			])
		;
	}

	/**
	 * The refusal the model left behind, as one line for the log.
	 */
	private function describeRefusal(File $file): string
	{
		$reasons = [];
		foreach ($file->getErrors() as $error)
		{
			$reasons[] = "{$error->getCode()}: {$error->getMessage()}";
		}

		return $reasons ? implode('; ', $reasons) : 'no reason given';
	}

	/**
	 * Downloads the presigned platform `file_url` into a temp file, hardened against SSRF (RF-S1).
	 * Keeps a swallowed delivery failure diagnosable: a repeating non-2xx is otherwise the only
	 * trace of a deadlock, a lock timeout or a CFile failure. The record names the event, the
	 * delivery and the exception, and carries neither payload nor session hash.
	 */
	private function observeFailure(Result $result, string $stage, ?string $reason = null): Result
	{
		$this->diagnosticStage = $stage;
		if ($result instanceof DatabaseFailureResult)
		{
			return $result;
		}
		try
		{
			$reason = $result->getData()['documentNotFoundReason'] ?? $reason
				?? SaveFailureDiagnostic::reasonFromErrors($result->getErrors());
			$this->diagnosticReason = $reason;
			$this->logWebhookDiagnostic(
				$this->activeEvent === self::EVENT_SAVED ? 'saved' : 'dispatch',
				$this->activeEvent === self::EVENT_SAVED ? 'save_refused' : 'permanent_refusal',
				stage: $stage, reason: $reason,
			);
		}
		catch (Throwable)
		{
			// Observation returns the original Result even when diagnostic data cannot be read.
		}

		return $result;
	}

	private function observeDelivery(?array $delivery): void
	{
		try
		{
			$notified = $delivery['NOTIFIED'] ?? null;
			$this->activeDiagnosticFields = array_replace($this->activeDiagnosticFields, [
				'result_object_id' => $delivery['RESULT_OBJECT_ID'] ?? null,
				'result_version_id' => $delivery['RESULT_VERSION_ID'] ?? null,
				'snapshot_seq' => $delivery['SNAPSHOT_SEQ'] ?? null,
				'delivery_status' => $delivery['STATUS'] ?? null,
				'delivery_outcome' => $delivery['OUTCOME'] ?? null,
				'delivery_notified' => in_array($notified, [0, 1, '0', '1'], true)
					? ($notified === 1 || $notified === '1') : null,
			]);
		}
		catch (Throwable)
		{
			// Only fields from the existing delivery read may enrich diagnostics.
		}
	}

	private function observeScope(?array $state): void
	{
		try
		{
			$this->activeDiagnosticFields = array_replace($this->activeDiagnosticFields, [
				'source_object_id' => $state['OBJECT_ID'] ?? null,
				'source_id_valid' => isset($state['OBJECT_ID']) ? (int)$state['OBJECT_ID'] > 0 : null,
				'scope_session_type' => $state['SESSION_TYPE'] ?? null,
				'scope_version_id' => $state['VERSION_ID'] ?? null,
				'scope_external_link_id' => $state['EXTERNAL_LINK_ID'] ?? null,
				'scope_generation_present' => $state === null ? null : !empty($state['CONTENT_GENERATION']),
				'generation_correlation_id' => DiagnosticContext::generationCorrelationId(
					$state['CONTENT_GENERATION'] ?? null,
				),
			]);
		}
		catch (Throwable)
		{
			// Unknown scope facets remain absent; diagnostics do not resolve another scope.
		}
	}

	private function logDeliveryException(Throwable $exception): void
	{
		$this->logWebhookDiagnostic(
			'dispatch', 'delivery_exception', $exception, $this->diagnosticStage,
		);
	}

	private function logWebhookDiagnostic(
		string $operation,
		string $code,
		?Throwable $exception = null,
		?string $stage = null,
		?string $reason = null,
		?DatabaseFailureResult $databaseFailure = null,
	): void
	{
		try
		{
			$database = $databaseFailure?->getDiagnosticFields()
				?? ($exception === null ? [] : DatabaseErrorDiagnostic::fromException($exception));
			$exceptionClass = $database['exception_class'] ?? ($exception === null ? null : $exception::class);
			$reason ??= match ($code)
			{
				'delivery_exception' => $database === [] ? 'unknown_failure' : 'db_error',
				'session_missing', 'file_missing', 'file_too_large' => $this->diagnosticReason ?? $code,
				default => $this->diagnosticReason ?? 'unknown_failure',
			};
			LoggerProvider::get(self::LOGGER_ID)->log(
				$exceptionClass === null ? 'warning' : 'error',
				'vibeoffice webhook diagnostic',
				[
					'operation' => $operation,
					'code' => $code,
					'exception_class' => $exceptionClass,
					'delivery_id' => $this->activeDeliveryId,
					'document_correlation_id' => $this->activeDocumentCorrelationId,
					'event' => $this->activeEvent,
					'stage' => $stage ?? $databaseFailure?->getStage() ?? $this->diagnosticStage,
					'reason_code' => $reason,
					'httpStatus' => $code === 'delivery_exception' ? 500 : null,
				] + $this->activeDiagnosticFields + $database,
			);
		}
		catch (Throwable)
		{
			// Diagnostic failures do not change webhook acknowledgement or retry semantics.
		}
	}

	/**
	 * Downloads the presigned platform `file_url` into a temp file, hardened against SSRF.
	 *
	 * SSRF is contained by a host allowlist and by disabled redirects. The presigned platform
	 * URL serves the bytes directly, so a 3xx here is treated as a failure and a redirect to
	 * another address cannot be used to pivot. Private IP targets are deliberately allowed:
	 * the vibeoffice platform can be reachable at a private/internal address in box,
	 * on-premise or local development deployments. This path uses a no-redirect client so
	 * OnlyOffice download behavior stays unchanged.
	 */
	private function downloadFromPlatform(string $fileUrl): Result
	{
		$result = new Result();

		$tmpFile = \CTempFile::getFileName(Random::getString(16));
		checkDirPath($tmpFile);

		$httpClient = new HttpClient();
		$httpClient->setRedirect(false);

		if (!$httpClient->download($fileUrl, $tmpFile))
		{
			return $this->observeFailure(
				$result->addError(new Error('Could not download the document from the platform.', 'download_failed')),
				'download', 'download_failed',
			);
		}

		$status = $httpClient->getStatus();
		if ($status !== 200)
		{
			return $this->observeFailure(
				$result->addError(new Error(
					"Could not download the document from the platform. Got {$status}.", 'download_failed',
				)),
				'download', 'download_failed',
			);
		}

		return $result->setData(['file' => $tmpFile]);
	}

	/**
	 * Unsupported/unknown webhook event.
	 *
	 * Failing here would make the caller answer non-2xx and the platform would retry the
	 * same delivery forever. Instead we acknowledge it: the successful Result makes
	 * {@see self::process()} write the idempotency ledger row (so the delivery is retained),
	 * and we additionally log the event for visibility.
	 */
	private function handleUnsupportedEvent(string $event): Result
	{
		$this->logWebhookDiagnostic('unsupported', 'unsupported_event');

		return new Result();
	}

	private function deactivateSessions(string $externalHash): Result
	{
		foreach ($this->getSessionsByHash($externalHash) as $session)
		{
			$update = DocumentSessionTable::update((int)$session->getId(), ['STATUS' => DocumentSessionTable::STATUS_NON_ACTIVE]);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}
		}

		return new Result();
	}

	private function commentAttachedObjectsOnBackground(File $file, ?int $versionId = null): void
	{
		Application::getInstance()->addBackgroundJob(static function () use ($file, $versionId) {
			$lastVersion = $versionId === null ? $file->getLastVersion() : Version::loadById($versionId);
			if ($lastVersion)
			{
				$file->commentAttachedObjects($lastVersion);
			}
		});
	}

	/**
	 * @param DocumentSession[] $sessions
	 */
	private function sendEventToParticipants(array $sessions, string $event): void
	{
		foreach ($sessions as $session)
		{
			Driver::getInstance()->sendEvent($session->getUserId(), 'vibeoffice', [
				'object' => [
					'id' => $session->getObjectId(),
				],
				'documentSession' => [
					'hash' => $session->getExternalHash(),
				],
				'event' => $event,
			]);
		}
	}

	/**
	 * @return DocumentSession[]
	 */
	private function getSessionsByHash(string $externalHash): array
	{
		$result = $this->scopeResolver->resolve($externalHash);

		return $result->isSuccess() && $result->getData()['legacy'] ? $result->getData()['sessions'] : [];
	}

	private function extractExternalHash(array $payload): ?string
	{
		$hash = $payload['host_doc_key'] ?? null;

		return is_string($hash) && $hash !== '' ? $hash : null;
	}
}
