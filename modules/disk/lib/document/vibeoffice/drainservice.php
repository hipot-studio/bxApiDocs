<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Safe drain of active vibeoffice sessions on rollback (P5.T3, ALG-DRAIN).
 *
 * This is a SEPARATE rollback path, distinct from {@see \Bitrix\Disk\Document\Models\DocumentSessionTable::deleteByService()}
 * and from {@see \Bitrix\Disk\Document\SessionTerminationServiceFactory} /
 * {@see Service\VibeofficeSessionTerminationService}: those drop local rows (or revoke a
 * grant) without first persisting the in-flight edits, which on a rollback would silently
 * lose unsaved changes. The termination service only fires on a rights de-sync, where
 * rights are *invalid*; on rollback rights are still valid, so it must not be reused.
 *
 * The order is MANDATORY (ALG-DRAIN — breaking it loses unsaved edits, ADR §3 invariant):
 *
 *   disableOnPortal()                                  # step 1: new opens stop routing here
 *   for session in activeSessions(SERVICE='vibeoffice'):
 *       docKey = session.EXTERNAL_HASH
 *       res = forcesave(docKey)                        # step 2a
 *       if res == not_active:                          # structural platform code, NOT a bare 409
 *           deleteLocalSession(session)                # nothing to save → drop the row
 *           continue
 *       waitForSavedWebhook(docKey)                    # step 2b: wait for document.saved(forcesave)
 *                                                      #   -> uploadVersion already done in P5.T2
 *       closeSessionOnPlatform(docKey)                 # step 2c (platform closes / deactivates)
 *       deleteLocalSession(session)                    # step 3: only after a confirmed save
 *
 * The wait is a short, BOUNDED poll for the host-side forced-save confirmation. A drain
 * forcesave is a `document.saved(source=forcesave)`, which (B1) does NOT deactivate the live
 * session — so the confirmation signal here is NOT session deactivation but the applied
 * saved-delivery itself: the saved-webhook handler records every applied save in
 * {@see DeliveryTable} with this `EXTERNAL_HASH`, so a fresh `document.saved` row for the key
 * (newer than the moment we issued forcesave) is the explicit proof that `uploadVersion` ran.
 * NOTE (V2): this is intentionally a lightweight synchronous wait, not a full queue — the
 * confirmation arrives as a SEPARATE webhook HTTP request that commits the version, so the
 * wait must run OUTSIDE any drain transaction to observe that commit. The per-session budget
 * is capped (WAIT_TOTAL_BUDGET_USEC) and the loop exits early the moment the save is seen,
 * keeping the worst-case worker time bounded. The whole drain is re-runnable (idempotent):
 * already-saved/deactivated/deleted sessions are skipped on a repeat run, so a timed-out
 * session is simply retried by a subsequent drain rather than blocking indefinitely.
 *
 * NOTE (V1): the {@see ProtocolClientInterface} has no "close session" call, only a
 * per-user `revokeGrant`. A live document may have several co-editors (one local session row
 * per user, same `EXTERNAL_HASH`). Step 2c therefore revokes the grants of ALL participants
 * of the document key (best-effort); the platform performs the actual session close when the
 * document is deactivated after the forced save. Revoking only the row owner would leave
 * co-editors editing on the platform.
 */
final class DrainService
{
	/** Self-rescheduling drain agent name; single source of truth for register() and drainAgent(). */
	private const AGENT_NAME = self::class . '::drainAgent();';

	/** Poll attempts while waiting for the document.saved webhook to land. */
	private const WAIT_MAX_ATTEMPTS = 15;

	/** Delay between poll attempts, microseconds (≈1s). */
	private const WAIT_INTERVAL_USEC = 1_000_000;

	/** Hard cap on the total time spent waiting per session (≈15s). */
	private const WAIT_TOTAL_BUDGET_USEC = self::WAIT_MAX_ATTEMPTS * self::WAIT_INTERVAL_USEC;

	private ProtocolClientInterface $protocolClient;
	private Configuration $configuration;

	/**
	 * @param ProtocolClientInterface|null $protocolClient injectable for tests; built from
	 *        the configuration by default.
	 * @param Configuration|null $configuration injectable for tests.
	 *
	 * @throws \Bitrix\Main\Config\ConfigurationException when the client cannot be assembled
	 */
	public function __construct(
		?ProtocolClientInterface $protocolClient = null,
		?Configuration $configuration = null,
	)
	{
		$this->protocolClient = $protocolClient ?? ProtocolClientFactory::create();
		$this->configuration = $configuration ?? ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Lazily (re)registers the self-rescheduling drain agent from the hot vibeoffice-open path.
	 *
	 * The agent is NOT installed unconditionally at module install/update anymore (A′): on portals
	 * that never enabled vibeoffice it would otherwise probe the hot, shared
	 * `b_disk_document_session` table hourly for nothing. Instead it is created the first time a
	 * vibeoffice session is actually opened, so it exists ONLY where vibeoffice was really used.
	 *
	 * Idempotent: {@see \CAgent::AddAgent()} de-duplicates by NAME (with `existError: false` it
	 * returns the existing agent id without a duplicate and without throwing), so calling this on every
	 * open is cheap and safe. It also re-installs the agent after {@see self::drainAgent()} has
	 * self-unregistered on an idle portal — the next time vibeoffice is enabled/used, register()
	 * brings the drain reconciler back.
	 */
	public static function register(): void
	{
		\CAgent::AddAgent(self::AGENT_NAME, 'disk', 'N', 3600, existError: false);
	}

	/**
	 * Background entry point for the rollback drain (P5.T3, ALG-DRAIN; review V5/B1).
	 *
	 * The drain is a rollback-only procedure whose first step is {@see Configuration::disableOnPortal()},
	 * so it must NEVER run while vibeoffice is live on the portal. There is no admin call site
	 * that runs {@see self::drain()} — disabling vibeoffice is a plain option flip — so without
	 * this agent a manual roll-back would leave the already-open sessions to lose their final
	 * `document.saved` (B1). This agent reconciles that state: once the portal flag is already
	 * off, it finishes draining the sessions that are still active. It is a no-op while the flag
	 * is on (normal operation).
	 *
	 * Lazy lifecycle (A′): the agent is (re)installed on demand by {@see self::register()} from the
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
		if ($configuration->isEnabledOnPortal())
		{
			return self::AGENT_NAME;
		}

		// Flag already off and nothing left to drain: the portal is idle. Self-unregister instead
		// of probing the hot shared session table every hour forever — the next vibeoffice open
		// re-installs the agent via register(). Skip before constructing a protocol client (which
		// throws on an unconfigured portal).
		if (!self::hasActiveSessions())
		{
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
	 * Whether any vibeoffice session is still active — a cheap probe used by {@see self::drainAgent()}
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

		// Step 1: stop routing new opens to vibeoffice.
		$this->configuration->disableOnPortal();

		// Step 2: drain each active vibeoffice session, in order.
		foreach ($this->getActiveSessions() as $session)
		{
			$this->drainSession($session, $result);
		}

		return $result;
	}

	private function drainSession(DocumentSession $session, Result $result): void
	{
		$docKey = $session->getExternalHash();

		// Capture the boundary BEFORE forcesave: only a saved-delivery applied at/after this
		// instant counts as the confirmation of THIS forced save (not a stale earlier save).
		$forcesaveIssuedAt = new DateTime();

		// Step 2a: ask the platform to persist the current state.
		$forcesaveResult = $this->protocolClient->forcesave($docKey, null);
		if (!$forcesaveResult->isSuccess())
		{
			if ($this->isNotActive($forcesaveResult))
			{
				// Nothing to save on the platform (session already inactive / closed):
				// drop the local row directly (ALG-DRAIN: structural not_active → delete).
				$this->deleteLocalSession($session);

				return;
			}

			// A real forcesave failure: do NOT delete the local row — that would risk
			// dropping a session whose edits were never persisted.
			$result->addError(new Error(
				"Vibeoffice drain: forcesave failed for {$docKey}.",
				'forcesave_failed',
				['errors' => $forcesaveResult->getErrors()],
			));

			return;
		}

		// Step 2b: wait until the document.saved(source=forcesave) webhook has landed and
		// uploadVersion was applied (signalled by a fresh applied saved-delivery for the key).
		if (!$this->waitForSaved($docKey, $forcesaveIssuedAt))
		{
			$result->addError(new Error(
				"Vibeoffice drain: timed out waiting for the saved webhook for {$docKey}.",
				'saved_timeout',
			));

			// Save not confirmed → keep the local row so the unsaved state is not lost.
			return;
		}

		// Step 2c: close the session on the platform. The protocol has no close-session
		// call, so revoke the grants of ALL participants of this document key (not just the
		// row owner) — a multi-editor document keeps editing on the platform otherwise (V1).
		// The platform finishes the actual close on document deactivation after the forced
		// save; this revoke is best-effort and idempotent.
		$this->revokeAllGrants($docKey);

		// Step 3: only now, after a confirmed save, drop the local row.
		$this->deleteLocalSession($session);
	}

	/**
	 * Revokes the grants of every participant of the document key (V1). Participants are the
	 * distinct user ids across the local sessions sharing the `EXTERNAL_HASH`. Best-effort:
	 * a failed revoke is non-fatal (the platform still closes the session on deactivation).
	 */
	private function revokeAllGrants(string $docKey): void
	{
		$userIds = [];
		foreach ($this->getSessionsByHash($docKey) as $session)
		{
			$userId = $session->getUserId();
			if ($userId > 0)
			{
				$userIds[$userId] = true;
			}
		}

		foreach (array_keys($userIds) as $userId)
		{
			$this->protocolClient->revokeGrant($docKey, (string)$userId);
		}
	}

	/**
	 * Polls for the host-side confirmation that the forced save was applied: a
	 * `document.saved` delivery for this key recorded at/after the forcesave was issued (B1 —
	 * a forcesave does NOT deactivate the session, so session state is not a reliable signal).
	 * The wait is bounded by a hard time budget (V2) and exits early on the first observed
	 * confirmation; each iteration re-queries {@see DeliveryTable} so the commit from the
	 * separate saved-webhook request is visible (the drain must not hold a transaction).
	 * Idempotent: an already-applied save returns true immediately.
	 */
	private function waitForSaved(string $docKey, DateTime $issuedAt): bool
	{
		$deadline = microtime(true) + (self::WAIT_TOTAL_BUDGET_USEC / 1_000_000);

		while (true)
		{
			if ($this->wasSavedSince($docKey, $issuedAt))
			{
				return true;
			}

			if (microtime(true) >= $deadline)
			{
				return false;
			}

			usleep(self::WAIT_INTERVAL_USEC);
		}
	}

	/**
	 * Whether a `document.saved` delivery for the key was applied at/after $issuedAt. This is
	 * the explicit forced-save completion marker (the saved-webhook handler writes the
	 * DeliveryTable row only AFTER uploadVersion succeeded).
	 */
	private function wasSavedSince(string $docKey, DateTime $issuedAt): bool
	{
		$row = DeliveryTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=EXTERNAL_HASH' => $docKey,
				'=EVENT' => WebhookProcessor::EVENT_SAVED,
				'>=PROCESSED_TIME' => $issuedAt,
			],
		]);

		return $row !== null;
	}

	private function deleteLocalSession(DocumentSession $session): void
	{
		$session->delete();
	}

	/**
	 * Detects the platform's "session is not active" outcome: forcesave requires an active
	 * session, so this means there is nothing to save and the local row can be dropped.
	 *
	 * The signal is the STRUCTURAL platform error code `not_active` from the response body, which
	 * {@see \Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClient::mapError()} surfaces in the
	 * error message ("… Code: not_active."). Detection is deliberately INDEPENDENT of the HTTP
	 * status: on the wire {@see Error::getCode()} carries the HTTP status (e.g. 409), and 409 is a
	 * GENERAL conflict — "operation in progress or document is full" (doc_full), NOT not_active.
	 * Treating a bare 409 as not_active would delete a session whose in-flight edits were never
	 * persisted (data loss). An ambiguous 409 without the not_active marker is therefore reported
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
	 * The active vibeoffice sessions to drain. SERVICE/STATUS are not indexed on
	 * b_disk_document_session (only EXTERNAL_HASH and OBJECT_ID,USER_ID are), so this is a
	 * table scan — accepted for a rare, manual rollback path; the select is kept to the two
	 * columns the drain actually consumes (ID for the delete, EXTERNAL_HASH for the doc key)
	 * so the CONTEXT text blob is not fetched for every scanned row.
	 *
	 * @return DocumentSession[]
	 */
	private function getActiveSessions(): array
	{
		return DocumentSession::getModelList([
			'select' => ['ID', 'EXTERNAL_HASH'],
			'filter' => [
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
			],
		]);
	}

	/**
	 * All local sessions sharing the document key (one row per participant) — used to revoke
	 * every co-editor's grant during the platform close (V1). Filtered by the indexed
	 * EXTERNAL_HASH; only USER_ID (the grant target) is selected besides the id.
	 *
	 * @return DocumentSession[]
	 */
	private function getSessionsByHash(string $externalHash): array
	{
		return DocumentSession::getModelList([
			'select' => ['ID', 'USER_ID'],
			'filter' => [
				'=EXTERNAL_HASH' => $externalHash,
			],
		]);
	}
}
