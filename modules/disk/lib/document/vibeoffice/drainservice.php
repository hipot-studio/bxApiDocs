<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Driver;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;

/**
 * Safe drain of active vibeoffice sessions on rollback.
 *
 * This path first persists in-flight edits with `forcesave`, waits until the corresponding
 * `document.saved` delivery is applied, and only then deletes the local session row. A
 * `not_active` platform response is the only failure path that can skip the wait because
 * there is no active platform session left to save.
 *
 * The protocol has no close-session command. After a confirmed save, the drain revokes grants
 * of all local participants for the document key and then finalizes its local sessions together.
 * Participant revoke progress is persisted so a later agent run resumes after the last attempted user.
 */
final class DrainService
{
	/** Self-rescheduling drain agent name; single source of truth for register() and drainAgent(). */
	private const AGENT_NAME = self::class . '::drainAgent();';
	private const CURSOR_OPTION = 'vibeoffice_drain_last_session_id';
	private const PARTICIPANT_PROGRESS_OPTION = 'vibeoffice_drain_participant_progress';
	private const SESSION_BATCH_SIZE = 10;
	private const RUN_TIME_BUDGET_SECONDS = 30.0;

	private ProtocolClientInterface $protocolClient;
	private Configuration $configuration;
	private SavedDeliveryWaiterInterface $savedDeliveryWaiter;
	private TerminalSessionFinalizerInterface $terminalSessionFinalizer;
	private Closure $clock;

	/**
	 * @param ProtocolClientInterface|null $protocolClient injectable for tests; built from
	 *        the configuration by default.
	 * @param Configuration|null $configuration injectable for tests.
	 * @param SavedDeliveryWaiterInterface|null $savedDeliveryWaiter injectable for tests.
	 * @param TerminalSessionFinalizerInterface|null $terminalSessionFinalizer injectable for tests.
	 * @param Closure|null $clock monotonic clock injectable for tests.
	 *
	 * @throws \Bitrix\Main\Config\ConfigurationException when the client cannot be assembled
	 */
	public function __construct(
		?ProtocolClientInterface $protocolClient = null,
		?Configuration $configuration = null,
		?SavedDeliveryWaiterInterface $savedDeliveryWaiter = null,
		?TerminalSessionFinalizerInterface $terminalSessionFinalizer = null,
		?Closure $clock = null,
	)
	{
		$this->protocolClient = $protocolClient ?? ProtocolClientFactory::create();
		$this->configuration = $configuration ?? ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
		$this->savedDeliveryWaiter = $savedDeliveryWaiter ?? new SavedDeliveryWaiter();
		$this->terminalSessionFinalizer = $terminalSessionFinalizer ?? new TerminalSessionFinalizer();
		$this->clock = $clock ?? static fn(): float => microtime(true);
	}

	/**
	 * Registers the self-rescheduling drain agent from the hot vibeoffice-open path.
	 *
	 * The agent is not installed unconditionally at module install/update anymore: on portals
	 * that never enabled vibeoffice it would otherwise probe the hot, shared
	 * `b_disk_document_session` table hourly for nothing. Instead it is created the first time a
	 * vibeoffice session is actually opened, so it exists ONLY where vibeoffice was really used.
	 *
	 * Idempotent: {@see \CAgent::AddAgent()} de-duplicates by NAME (with `existError: false` it
	 * returns the existing agent id without a duplicate and without throwing), so calling this on every
	 * open is cheap and safe. It also re-installs the agent after {@see self::drainAgent()} has
	 * self-unregistered on an idle portal. The next time vibeoffice is enabled/used, register()
	 * brings the drain reconciler back.
	 */
	public static function register(): void
	{
		\CAgent::AddAgent(self::AGENT_NAME, 'disk', 'N', 3600, existError: false);
	}

	/**
	 * Background entry point for the rollback drain.
	 *
	 * The drain is a rollback-only procedure whose first step is {@see Configuration::disableOnPortal()},
	 * so it must NEVER run while vibeoffice is live on the portal. There is no admin call site
	 * that runs {@see self::drain()}; disabling vibeoffice is a plain option flip. Without
	 * this agent a manual roll-back would leave the already-open sessions to lose their final
	 * `document.saved`. This agent reconciles that state: once the portal flag is already
	 * off, it finishes draining the sessions that are still active. It is a no-op while the flag
	 * is on (normal operation).
	 *
	 * Lazy lifecycle: the agent is (re)installed on demand by {@see self::register()} from the
	 * hot vibeoffice-open path, so it exists only on portals that actually used vibeoffice. When the
	 * flag is off AND nothing is left to drain the agent SELF-UNREGISTERS (returns ''), so an idle
	 * portal keeps no hourly probe of the hot shared session table; the next open re-registers it.
	 * While the flag is on the probe is skipped entirely (early return BEFORE any table scan or
	 * protocol client build).
	 *
	 * @return string the agent re-registration call, or '' to self-unregister when idle.
	 */
	public static function drainAgent(): string
	{
		/** @var Configuration $configuration */
		$configuration = ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');

		// Never drain while vibeoffice is live: the drain's first step disables the portal flag.
		if (Feature::isEnabledOnPortal())
		{
			return self::AGENT_NAME;
		}

		// Flag already off and nothing left to drain: the portal is idle. Self-unregister before
		// constructing a protocol client, which can fail on an unconfigured portal.
		if (!self::hasActiveSessions())
		{
			Option::delete(Driver::INTERNAL_MODULE_ID, ['name' => self::CURSOR_OPTION]);
			Option::delete(Driver::INTERNAL_MODULE_ID, ['name' => self::PARTICIPANT_PROGRESS_OPTION]);

			return '';
		}

		try
		{
			(new self(null, $configuration))->drain();
		}
		catch (ConfigurationException)
		{
			// Platform integration is not configured, so the in-flight edits cannot be
			// persisted from here; leave the sessions in place and retry on the next run.
		}

		return self::AGENT_NAME;
	}

	/**
	 * Whether any vibeoffice session is still active.
	 * to avoid building a protocol client (and re-running the drain) when there is nothing to do.
	 */
	private static function hasActiveSessions(): bool
	{
		$row = DocumentSessionTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'=STATUS' => DocumentSessionTable::STATUS_ACTIVE,
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	/**
	 * Runs the full drain. Returns a {@see Result} aggregating any per-session errors
	 * (the drain is best-effort across sessions: a failure on one session does not abort
	 * the rest, but every unsaved-edit loss is surfaced as an error).
	 */
	public function drain(): Result
	{
		$result = new Result();
		$startedAt = ($this->clock)();

		// Step 1: stop routing new opens to vibeoffice.
		$this->configuration->disableOnPortal();
		$this->pruneParticipantProgress();

		// Step 2: drain a bounded keyset page without starting new work past the run budget.
		$lastSessionId = $this->getCursor();
		$cursorAdvanced = false;
		$cursorBlocked = false;
		$drainedDocumentHashes = [];
		$sessions = $this->getActiveSessions($lastSessionId, self::SESSION_BATCH_SIZE);
		if ($sessions === [] && $lastSessionId > 0)
		{
			$lastSessionId = 0;
			$this->saveCursor($lastSessionId);
			$sessions = $this->getActiveSessions($lastSessionId, self::SESSION_BATCH_SIZE);
		}

		foreach ($sessions as $session)
		{
			if (!$this->hasTimeBudget($startedAt))
			{
				break;
			}

			$documentHash = $session->getExternalHash();
			if (!isset($drainedDocumentHashes[$documentHash]))
			{
				$drainedDocumentHashes[$documentHash] = $this->drainSession($session, $result, $startedAt);
			}

			// The rest of the page is still attempted, but the cursor stops at the first session
			// whose edits are not persisted yet: the next run has to retry it instead of meeting
			// it again only after a full pass of the table.
			$cursorBlocked = $cursorBlocked || !$drainedDocumentHashes[$documentHash];
			if ($cursorBlocked)
			{
				continue;
			}

			$lastSessionId = (int)$session->getId();
			$cursorAdvanced = true;
		}

		if ($cursorAdvanced)
		{
			$this->saveCursor($lastSessionId);
		}

		return $result;
	}

	private function hasTimeBudget(float $startedAt): bool
	{
		return ($this->clock)() - $startedAt < self::RUN_TIME_BUDGET_SECONDS;
	}

	/**
	 * @return bool whether the document is fully drained; false leaves local rows in place for a retry.
	 */
	private function drainSession(DocumentSession $session, Result $result, ?float $startedAt = null): bool
	{
		$documentHash = $session->getExternalHash();
		$startedAt ??= ($this->clock)();
		$sessionsByKey = [];
		foreach ($this->getSessionsByHash($documentHash) as $participant)
		{
			$sessionsByKey[HostDocumentKey::resolve($participant)][] = $participant;
		}

		$saved = true;
		$keysToRevoke = [];
		foreach (array_keys($sessionsByKey) as $docKey)
		{
			if (!$this->hasTimeBudget($startedAt))
			{
				$result->addError(new Error('Vibeoffice drain: run budget exhausted.', 'drain_budget_exhausted'));

				return false;
			}

			$saveResult = $this->saveDocumentScope($docKey);
			if (!$saveResult->isSuccess())
			{
				$result->addErrors($saveResult->getErrors());
				$saved = false;
			}
			elseif ($saveResult->getData()['requiresRevoke'])
			{
				$keysToRevoke[] = $docKey;
			}
		}
		if (!$saved)
		{
			return false;
		}

		foreach ($keysToRevoke as $docKey)
		{
			if (!$this->revokeAllGrants($documentHash, $docKey, $sessionsByKey[$docKey], $startedAt))
			{
				$result->addError(new Error(
					"Vibeoffice drain: run budget exhausted while revoking grants for {$docKey}.",
					'drain_budget_exhausted',
				));

				return false;
			}
		}

		return $this->finalizeDocument($documentHash, $result);
	}

	private function saveDocumentScope(string $docKey): Result
	{
		$result = new Result();

		// Capture the monotonic ledger boundary before forcesave so stale same-second
		// saved deliveries cannot confirm this drain attempt.
		$savedDeliveryIdBoundary = $this->savedDeliveryWaiter->getProcessedDeliveryIdBoundary();

		// Step 2a: ask the platform to persist the current state.
		$forcesaveResult = $this->protocolClient->forcesave($docKey, null);
		if (!$forcesaveResult->isSuccess())
		{
			if ($this->isNotActive($forcesaveResult))
			{
				return $result->setData(['requiresRevoke' => false]);
			}

			// A real forcesave failure: do NOT delete the local row. That would risk
			// dropping a session whose edits were never persisted.
			return $result->addError(new Error(
				"Vibeoffice drain: forcesave failed for {$docKey}.",
				'forcesave_failed',
				['errors' => $forcesaveResult->getErrors()],
			));
		}

		// Step 2b: wait until the document.saved(source=forcesave) webhook has landed and
		// uploadVersion was applied (signalled by a fresh applied saved-delivery for the key).
		if (
			$this->savedDeliveryWaiter->wait($docKey, $savedDeliveryIdBoundary)
			!== SavedDeliveryWaiterInterface::OUTCOME_SAVED
		)
		{
			return $result->addError(new Error(
				"Vibeoffice drain: timed out waiting for the saved webhook for {$docKey}.",
				'saved_timeout',
			));
		}

		return $result->setData(['requiresRevoke' => true]);
	}

	/**
	 * Revokes the grants of every participant of the document key. Participants are the
	 * distinct user ids of the scope, including registered sessions with another local hash. Best-effort:
	 * a failed revoke is non-fatal (the platform still closes the session on deactivation).
	 *
	 * Progress is written once per document instead of once per participant: an option write
	 * drops the whole option cache of the module.
	 */
	private function revokeAllGrants(
		string $documentHash,
		string $docKey,
		array $participants,
		float $startedAt,
	): bool
	{
		$processedUserIds = array_fill_keys($this->getParticipantProgress($documentHash, $docKey), true);
		$userIds = $this->getParticipantUserIds($docKey, $participants);
		$revoked = false;
		foreach ($userIds as $userId)
		{
			if (isset($processedUserIds[$userId]))
			{
				continue;
			}

			if (!$this->hasTimeBudget($startedAt))
			{
				if ($revoked)
				{
					$this->saveParticipantProgress($documentHash, $docKey, array_keys($processedUserIds));
				}

				return false;
			}

			$this->protocolClient->revokeGrant($docKey, (string)$userId);
			$processedUserIds[$userId] = true;
			$revoked = true;
		}

		if ($revoked)
		{
			$this->saveParticipantProgress($documentHash, $docKey, array_keys($processedUserIds));
		}

		return true;
	}

	/** @param DocumentSession[] $participants */
	private function getParticipantUserIds(string $docKey, array $participants): array
	{
		$userIds = [];
		foreach ($participants as $session)
		{
			$userId = $session->getUserId();
			if ($userId > 0)
			{
				$userIds[$userId] = true;
			}
		}

		$sessionIds = (new SessionRegistry())->getSessionIds($docKey);
		foreach (array_chunk($sessionIds, 300) as $chunk)
		{
			$rows = DocumentSessionTable::query()
				->setSelect(['USER_ID'])
				->where('SERVICE', DocumentService::Vibeoffice->value)
				->whereIn('ID', $chunk)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$userId = (int)$row['USER_ID'];
				if ($userId > 0)
				{
					$userIds[$userId] = true;
				}
			}
		}

		$userIds = array_keys($userIds);
		sort($userIds, SORT_NUMERIC);

		return $userIds;
	}

	private function finalizeDocument(string $documentHash, Result $result): bool
	{
		$finalizationResult = $this->terminalSessionFinalizer->finalizeDrainDocument($documentHash);
		if (!$finalizationResult->isSuccess())
		{
			$result->addErrors($finalizationResult->getErrors());

			return false;
		}

		$this->clearParticipantProgress($documentHash);

		return true;
	}

	/**
	 * Detects the platform's "session is not active" outcome: forcesave requires an active
	 * session, so this means there is nothing to save and the local row can be dropped.
	 *
	 * The signal is the STRUCTURAL platform error code `not_active` from the response body, which
	 * {@see \Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClient::mapError()} surfaces in the
	 * error message ("... Code: not_active."). Detection is deliberately independent of the HTTP
	 * status: on the wire {@see Error::getCode()} carries the HTTP status (e.g. 409), and 409 is a
	 * general conflict, "operation in progress or document is full" (doc_full), not not_active.
	 * Treating a bare 409 as not_active would delete a session whose in-flight edits were never
	 * persisted. An ambiguous 409 without the not_active marker is therefore reported
	 * as an ordinary forcesave failure by {@see self::drainSession()}: the row is kept and the
	 * next hourly drain retries it (the drain is idempotent).
	 */
	private function isNotActive(Result $forcesaveResult): bool
	{
		foreach ($forcesaveResult->getErrors() as $error)
		{
			if (!($error instanceof Error))
			{
				continue;
			}

			if (mb_stripos($error->getMessage(), 'not_active') !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The next bounded keyset page of active vibeoffice sessions. SERVICE/STATUS are not indexed on
	 * Only EXTERNAL_HASH and OBJECT_ID,USER_ID are indexed in b_disk_document_session, so this
	 * is a table scan accepted for a rare, manual rollback path; the select is kept to the two
	 * columns needed for local grouping. CONTEXT is read only for the group being drained.
	 *
	 * @return DocumentSession[]
	 */
	private function getActiveSessions(int $lastSessionId, int $limit): array
	{
		return DocumentSession::getModelList([
			'select' => ['ID', 'EXTERNAL_HASH'],
			'filter' => [
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
				'>ID' => $lastSessionId,
			],
			'order' => ['ID' => 'ASC'],
			'limit' => $limit,
		]);
	}

	private function getCursor(): int
	{
		return max(0, (int)Option::get(Driver::INTERNAL_MODULE_ID, self::CURSOR_OPTION, 0));
	}

	private function saveCursor(int $sessionId): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, self::CURSOR_OPTION, $sessionId);
	}

	/** @return int[] */
	private function getParticipantProgress(string $documentHash, string $docKey): array
	{
		$progress = $this->getParticipantProgressMap();
		$scopes = $this->getDocumentProgress($progress, $documentHash);

		return array_map('intval', $scopes[$docKey] ?? []);
	}

	/** @param int[] $userIds */
	private function saveParticipantProgress(string $documentHash, string $docKey, array $userIds): void
	{
		$progress = $this->getParticipantProgressMap();
		$scopes = $this->getDocumentProgress($progress, $documentHash);
		$scopes[$docKey] = array_values(array_unique(array_map('intval', $userIds)));
		$progress[$documentHash] = $scopes;
		$this->saveParticipantProgressMap($progress);
	}

	private function getDocumentProgress(array $progress, string $documentHash): array
	{
		$scopes = $progress[$documentHash] ?? [];

		return array_is_list($scopes) ? [$documentHash => $scopes] : $scopes;
	}

	private function clearParticipantProgress(string $documentHash): void
	{
		$progress = $this->getParticipantProgressMap();
		if (!isset($progress[$documentHash]))
		{
			return;
		}

		unset($progress[$documentHash]);
		$this->saveParticipantProgressMap($progress);
	}

	private function pruneParticipantProgress(): void
	{
		$progress = $this->getParticipantProgressMap();
		if ($progress === [])
		{
			return;
		}

		$activeHashes = [];
		foreach (array_chunk(array_keys($progress), 100) as $hashes)
		{
			$rows = DocumentSessionTable::query()
				->setSelect(['EXTERNAL_HASH'])
				->where('SERVICE', DocumentService::Vibeoffice->value)
				->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
				->whereIn('EXTERNAL_HASH', $hashes)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$activeHashes[(string)$row['EXTERNAL_HASH']] = true;
			}
		}

		$activeProgress = array_intersect_key($progress, $activeHashes);
		if ($activeProgress !== $progress)
		{
			$this->saveParticipantProgressMap($activeProgress);
		}
	}

	private function getParticipantProgressMap(): array
	{
		$value = Option::get(Driver::INTERNAL_MODULE_ID, self::PARTICIPANT_PROGRESS_OPTION, '{}');
		$progress = json_decode((string)$value, true);

		return is_array($progress) ? $progress : [];
	}

	private function saveParticipantProgressMap(array $progress): void
	{
		if ($progress === [])
		{
			Option::delete(Driver::INTERNAL_MODULE_ID, ['name' => self::PARTICIPANT_PROGRESS_OPTION]);

			return;
		}

		Option::set(
			Driver::INTERNAL_MODULE_ID,
			self::PARTICIPANT_PROGRESS_OPTION,
			json_encode($progress, JSON_THROW_ON_ERROR),
		);
	}

	/**
	 * Local sessions are selected by the indexed hash; their saved context resolves platform scopes.
	 *
	 * @return DocumentSession[]
	 */
	private function getSessionsByHash(string $externalHash): array
	{
		return DocumentSession::getModelList([
			'select' => ['ID', 'USER_ID', 'EXTERNAL_HASH', 'CONTEXT'],
			'filter' => [
				'=EXTERNAL_HASH' => $externalHash,
				'=SERVICE' => DocumentService::Vibeoffice->value,
			],
			'order' => ['ID' => 'ASC'],
		]);
	}
}
