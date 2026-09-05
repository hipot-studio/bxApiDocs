<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;

/**
 * Dispatches verified vibeoffice webhooks (P5.T1 + P5.T2).
 *
 * The HMAC signature, timestamp window and content-type are already enforced by the
 * controller prefilters ({@see \Bitrix\Disk\Document\Vibeoffice\Filters\WebhookSignatureCheck}).
 * This processor adds idempotency by `X-VO-Delivery` and dispatches by `X-VO-Event`:
 *
 *  - `document.saved`         → download `file_url` and persist a new Disk version through
 *                               {@see File::uploadVersion()} (P5.T2): the version becomes a
 *                               regular Disk file in the shared `b_disk_version` chain, which
 *                               is exactly why no migration is needed on rollback (ADR §3).
 *  - `document.failover`      → refresh the stored vo-metadata of the session (Q-3); the
 *                               frontend helper restores itself over SSE.
 *  - `document.session-closed`→ deactivate the local sessions for the document key.
 *
 * The processor returns a {@see Result}; the caller responds 2xx only on success (for
 * `document.saved`, only after the download+save completed — otherwise the platform retries).
 *
 * This path never touches the OnlyOffice webhook handler; it is wholly additive.
 */
final class WebhookProcessor
{
	public const EVENT_SAVED = 'document.saved';
	public const EVENT_FAILOVER = 'document.failover';
	public const EVENT_SESSION_CLOSED = 'document.session-closed';

	/** terminal sources that finish editing → deactivate the local session. */
	private const TERMINAL_SOURCES = ['save', 'forgotten'];

	/** Named application lock prefix that serialises processing of one delivery. */
	private const LOCK_NAME_PREFIX = 'disk_vo_delivery_';

	/** Seconds to wait for the per-delivery lock before giving up (concurrent retry in flight). */
	private const LOCK_TIMEOUT = 15;

	/**
	 * Processes a single delivery atomically with respect to idempotency (B3).
	 *
	 * The whole claim→process→commit sequence for one `X-VO-Delivery` runs under a named
	 * application lock keyed by the delivery id, so a concurrent retry of the same delivery
	 * cannot observe a half-applied state:
	 *
	 *   1. acquire the per-delivery lock (a concurrent retry blocks here);
	 *   2. inside the lock, check whether the delivery row already exists — if so the delivery
	 *      was already fully applied → early 2xx, no re-work, no duplicate version;
	 *   3. otherwise run the handler (download + uploadVersion etc.); ONLY after it succeeds,
	 *      write the delivery row (the UNIQUE `DELIVERY_ID` is the final "done" marker and the
	 *      last line of defence against a race);
	 *   4. on a handler failure no row is written → the platform's retry re-processes the
	 *      delivery, and the caller returns a non-2xx (B2).
	 *
	 * @param int $deliveryId the `X-VO-Delivery` id (idempotency key)
	 * @param string $event the `X-VO-Event` value
	 * @param array $payload the decoded webhook body (parsed from the RAW, signed body — see
	 *        the controller: critical fields are read straight from the parsed body, not from
	 *        the proactively-filtered JsonPayload::getData()).
	 *
	 * @return Result success: the delivery was processed (or is a no-op duplicate);
	 *         failure: processing failed and the platform should retry (no 2xx).
	 */
	public function process(int $deliveryId, string $event, array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);

		$connection = Application::getConnection();
		$lockName = self::LOCK_NAME_PREFIX . $deliveryId;

		// Serialise concurrent retries of the same delivery. If the lock cannot be taken in
		// time, treat it as a transient failure (non-2xx) so the platform retries later — we
		// never apply the delivery twice.
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return $result->addError(new Error('Could not acquire the delivery lock.', 'lock_timeout'));
		}

		try
		{
			// Already applied? (the row is written only after a successful handler below).
			if ($this->isDeliveryProcessed($deliveryId))
			{
				return $result;
			}

			$processResult = match ($event)
			{
				self::EVENT_SAVED => $this->handleSaved($payload),
				self::EVENT_FAILOVER => $this->handleFailover($payload),
				self::EVENT_SESSION_CLOSED => $this->handleSessionClosed($payload),
				default => $this->handleUnsupportedEvent($event),
			};

			// Persist the "done" marker ONLY after the delivery was actually applied; on a
			// failure the row stays absent so the platform retry re-processes it (B2/B3).
			if ($processResult->isSuccess())
			{
				$this->markDeliveryProcessed($deliveryId, $event, $externalHash);
			}

			return $processResult;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * Whether the delivery has already been fully applied (its "done" row exists). Called
	 * inside the per-delivery lock, so this is a reliable existence check — no fragile
	 * parsing of the DB duplicate-key message (V3).
	 */
	private function isDeliveryProcessed(int $deliveryId): bool
	{
		$row = DeliveryTable::getRow([
			'select' => ['ID'],
			'filter' => ['=DELIVERY_ID' => $deliveryId],
		]);

		return $row !== null;
	}

	/**
	 * Writes the final "done" marker for a successfully applied delivery. The UNIQUE
	 * `DELIVERY_ID` constraint remains the last-resort guard against a race; under the
	 * per-delivery lock a duplicate here is not expected, so any error propagates to the
	 * caller's failure path (the platform then retries and the existence check short-circuits).
	 */
	private function markDeliveryProcessed(int $deliveryId, string $event, ?string $externalHash): void
	{
		DeliveryTable::add([
			'DELIVERY_ID' => $deliveryId,
			'EXTERNAL_HASH' => $externalHash,
			'EVENT' => $event,
			'PROCESSED_TIME' => new DateTime(),
		]);
	}

	/**
	 * P5.T2: `document.saved` → continuity of versions.
	 *
	 * Resolves the document by `host_doc_key` → `EXTERNAL_HASH`, downloads the presigned
	 * `file_url` and stores it as a new Disk version via {@see File::uploadVersion()}.
	 * The download+save must complete before the caller answers 2xx (otherwise the platform
	 * retries the delivery). Platform-origin auto-forcesave (`origin=platform`) is never
	 * delivered to the host, so every saved we see is a host-visible save/forcesave/forgotten.
	 *
	 * `source` is honoured exactly as OnlyOffice does (api-reference §6.3, mirroring
	 * {@see \Bitrix\Disk\Controller\OnlyOffice::handleOnlyOfficeAction()}):
	 *  - `save`/`forgotten` (terminal — editing finished, all editors out) → upload the version
	 *    AND deactivate the local sessions;
	 *  - `forcesave` (periodic/host-initiated forced save while editing continues) → upload the
	 *    version but DO NOT deactivate — the live session must survive every autosave/forcesave.
	 * Drain (P5.T3) issues a `forcesave` and waits for the resulting saved; it deactivates /
	 * deletes the session itself after the save is confirmed, so it does not rely on this path
	 * deactivating the session.
	 */
	private function handleSaved(array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.saved webhook.', 'validation'));
		}

		$fileUrl = (string)($payload['file_url'] ?? '');
		if ($fileUrl === '')
		{
			return $result->addError(new Error('Missing file_url in document.saved webhook.', 'validation'));
		}

		// SSRF guard (RF-S1): `file_url` is attacker-influenceable payload data, so never
		// dereference it blindly — accept only http(s) URLs pointing at the configured
		// platform host before it is downloaded and stored as a Disk version.
		if (!$this->isAllowedFileUrl($fileUrl))
		{
			return $result->addError(new Error('Refusing to download file_url outside the configured platform host.', 'ssrf_blocked'));
		}

		$sessions = $this->getSessionsByHash($externalHash);
		$session = $sessions[0] ?? null;
		if (!$session)
		{
			// PERMANENT not-found (IMP1): the local session was drained/deleted while a late
			// save still arrived. Retrying can never resolve it, so acknowledge (2xx + ledger
			// row) and log instead of a non-2xx that triggers an endless retry-storm — mirrors
			// self::handleUnsupportedEvent(). Transient failures (download) stay retryable below.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no local session for document.saved key '{$externalHash}', acknowledging."
			);
		}

		$file = $session->getFile();
		if (!($file instanceof File))
		{
			// PERMANENT not-found (IMP1): the session's file was removed. Acknowledge + log; a
			// retry would fail identically forever.
			return $this->acknowledgePermanent(
				"Vibeoffice webhook: no file for document.saved session (key '{$externalHash}'), acknowledging."
			);
		}

		$downloadResult = $this->downloadFromPlatform($fileUrl);
		if (!$downloadResult->isSuccess())
		{
			return $result->addErrors($downloadResult->getErrors());
		}

		$tmpFile = $downloadResult->getData()['file'];
		$tmpFileArray = \CFile::makeFileArray($tmpFile);
		if (!is_array($tmpFileArray) || empty($tmpFileArray['tmp_name']))
		{
			// RF-M2: makeFileArray() returns null/false for a missing or empty temp file;
			// bail out with a clear error (the platform retries) instead of feeding an
			// invalid array into uploadVersion and emitting a PHP warning.
			return $result->addError(new Error('Downloaded document is not a readable file.', 'download_failed'));
		}

		// Ingress size guard (M4): cap the platform-supplied file against getMaxFileSize() before
		// storing it as a Disk version. An oversized file is a PERMANENT condition (the same bytes
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
				. " ({$maxFileSize} bytes) for key '{$externalHash}'; skipping upload."
			);
		}

		if (in_array($tmpFileArray['type'] ?? null, ['application/encrypted', 'application/zip'], true))
		{
			$fileType = (string)($payload['filetype'] ?? $file->getExtension());
			$tmpFileArray['type'] = MimeType::getByFileExtension($fileType);
		}

		$userId = $this->resolveSavingUserId($payload, $sessions, $session);

		// commentAttachedObjects is run separately (on background) below, matching OnlyOffice.
		if (!$file->uploadVersion($tmpFileArray, $userId, ['commentAttachedObjects' => false]))
		{
			return $result->addError(new Error('Could not upload the new document version.', 'upload_failed'));
		}

		// Differentiate `source` (P5.T2 / B1): deactivate the local sessions ONLY on a
		// terminal save (`save`/`forgotten`) — exactly like OnlyOffice deactivates on
		// STATUS_IS_READY_FOR_SAVE but NOT on STATUS_FORCE_SAVE. A periodic `forcesave`
		// persists the version while the live session keeps editing.
		$source = mb_strtolower((string)($payload['source'] ?? 'save'));
		if (in_array($source, self::TERMINAL_SOURCES, true))
		{
			$this->deactivateSessions($externalHash);
		}

		// Finalize side effects on background, after the version is durably saved (the 2xx
		// the caller returns confirms the save).
		$this->commentAttachedObjectsOnBackground($file);
		$this->sendEventToParticipants($sessions, 'saved');

		return $result;
	}

	/**
	 * `document.failover` → refresh the vo-metadata stored in the session CONTEXT (Q-3).
	 *
	 * Additional idempotency by `editor_key`: a failover that does not change `editor_key`
	 * (relative to what is already stored) is a no-op. The frontend helper performs the
	 * actual SSE/refresh recovery; the host only keeps the latest `editor_key`/`rev` so it
	 * can re-issue the config and for drain.
	 */
	private function handleFailover(array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.failover webhook.', 'validation'));
		}

		$metadata = array_filter([
			'editor_key' => $payload['editor_key'] ?? null,
			'rev' => $payload['rev'] ?? null,
		], static fn($value) => $value !== null);

		if (empty($metadata))
		{
			// Nothing host-relevant to store; treat as a benign no-op.
			return $result;
		}

		$sessionManager = new DocumentSessionManager();
		foreach ($this->getSessionsByHash($externalHash) as $session)
		{
			$stored = DocumentSessionManager::extractVoMetadata($session);
			$newEditorKey = $metadata['editor_key'] ?? null;
			if ($newEditorKey !== null && ($stored['editor_key'] ?? null) === $newEditorKey)
			{
				// Same editor_key already recorded — idempotent failover, skip.
				continue;
			}

			$sessionManager->persistSessionMetadata($session, $metadata);
		}

		return $result;
	}

	/**
	 * `document.session-closed` → deactivate the local sessions for the document key
	 * (by analogy with {@see DocumentSessionTable::deactivateByHash()} used by OnlyOffice
	 * on close-without-changes / save).
	 */
	private function handleSessionClosed(array $payload): Result
	{
		$result = new Result();

		$externalHash = $this->extractExternalHash($payload);
		if ($externalHash === null)
		{
			return $result->addError(new Error('Missing host_doc_key in document.session-closed webhook.', 'validation'));
		}

		$this->deactivateSessions($externalHash);

		return $result;
	}

	/**
	 * Resolves the user the new version is attributed to (RF-S2).
	 *
	 * The saved-webhook `users[0]` is honoured ONLY when it belongs to a local session of
	 * this document (i.e. a real participant of the `EXTERNAL_HASH` sessions); otherwise the
	 * payload could spoof authorship in the version history. When it does not match, we fall
	 * back to the session owner, and finally to the system user.
	 *
	 * @param DocumentSession[] $sessions
	 */
	private function resolveSavingUserId(array $payload, array $sessions, DocumentSession $session): int
	{
		$users = $payload['users'] ?? null;
		if (is_array($users) && isset($users[0]))
		{
			$candidate = (int)$users[0];
			if ($candidate > 0 && in_array($candidate, $this->collectSessionUserIds($sessions), true))
			{
				return $candidate;
			}
		}

		$sessionUserId = $session->getUserId();

		return $sessionUserId > 0 ? $sessionUserId : SystemUser::SYSTEM_USER_ID;
	}

	/**
	 * User ids that participate in the given document sessions (the trusted set of possible
	 * version authors — see {@see self::resolveSavingUserId()}).
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
	 * SSRF allowlist for the platform-supplied `file_url` (RF-S1).
	 *
	 * Accepts only http(s) URLs whose host equals the configured platform base URL host
	 * ({@see Configuration::getServer()}). If the platform base URL is not configured we
	 * cannot vouch for the target host, so we fail closed rather than dereference an
	 * arbitrary address.
	 */
	private function isAllowedFileUrl(string $fileUrl): bool
	{
		$url = new Uri($fileUrl);

		$scheme = mb_strtolower($url->getScheme());
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return false;
		}

		$server = $this->getConfiguration()->getServer();
		if (!is_string($server) || $server === '')
		{
			return false;
		}

		$expectedUri = new Uri($server);
		$expectedHost = mb_strtolower($expectedUri->getHost());
		$actualHost = mb_strtolower($url->getHost());

		if ($expectedHost === '' || $actualHost !== $expectedHost)
		{
			return false;
		}

		// Defense-in-depth (M3): also pin the port, so a same-host URL on a different port (e.g.
		// an internal service co-located on the platform host) is rejected. Uri::getPort()
		// normalises the scheme default (80/443), so an explicit default port compares equal.
		// Private IPs are still deliberately NOT blocked (box/on-prem reachability) — see
		// self::downloadFromPlatform().
		return $url->getPort() === $expectedUri->getPort();
	}

	/**
	 * Resolves the shared vibeoffice {@see Configuration} through the registered singleton
	 * service (M2) instead of `new Configuration()`, so the php_interface override file is
	 * stat'ed/required once per request rather than on every hot-path call.
	 */
	private function getConfiguration(): Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Acknowledges a PERMANENTLY unprocessable saved-delivery (the target session/file no longer
	 * exists, or the downloaded document violates a hard limit): logs it and returns a successful
	 * Result so {@see self::process()} writes the ledger row and the caller answers 2xx. A
	 * permanent condition can never succeed on retry, so a non-2xx here would only cause an
	 * endless retry-storm + log spam (IMP1) — mirrors {@see self::handleUnsupportedEvent()}.
	 */
	private function acknowledgePermanent(string $message): Result
	{
		AddMessage2Log($message, 'disk');

		return new Result();
	}

	/**
	 * Downloads the presigned platform `file_url` into a temp file, hardened against SSRF (RF-S1).
	 *
	 * SSRF is contained by two mechanisms that do NOT block private IPs: {@see self::isAllowedFileUrl()}
	 * pins the target host to the configured platform host, and `setRedirect(false)` refuses to follow
	 * redirects — the presigned platform URL serves the bytes directly, so a 3xx here is treated as a
	 * failure and a redirect to another address cannot be used to pivot. Private-IP targets are
	 * deliberately NOT blocked (no `setPrivateIp(false)`): the vibeoffice platform is legitimately
	 * reachable at a private/internal address in box/on-prem/local-dev deployments (e.g. vo-platformd
	 * on 127.0.0.1), and blocking private IPs would break the normal download there. This is also why
	 * the fetch is NOT delegated to the shared {@see \Bitrix\Disk\Document\OnlyOffice\FileDownloader}
	 * (used by OnlyOffice — it must not change): the webhook path uses its own no-redirect client. A
	 * failure returns a non-2xx so the platform simply retries the delivery.
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
			return $result->addError(new Error('Could not download the document from the platform.', 'download_failed'));
		}

		$status = $httpClient->getStatus();
		if ($status !== 200)
		{
			return $result->addError(new Error("Could not download the document from the platform. Got {$status}.", 'download_failed'));
		}

		return $result->setData(['file' => $tmpFile]);
	}

	/**
	 * Unsupported/unknown webhook event (RF-M1).
	 *
	 * Failing here would make the caller answer non-2xx and the platform would retry the
	 * same delivery forever. Instead we acknowledge it: the successful Result makes
	 * {@see self::process()} write the idempotency ledger row (so the delivery is retained),
	 * and we additionally log the event for visibility.
	 */
	private function handleUnsupportedEvent(string $event): Result
	{
		AddMessage2Log("Vibeoffice webhook: acknowledging unsupported event '{$event}'.", 'disk');

		return new Result();
	}

	private function deactivateSessions(string $externalHash): void
	{
		DocumentSessionTable::deactivateByHash($externalHash);
	}

	private function commentAttachedObjectsOnBackground(File $file): void
	{
		Application::getInstance()->addBackgroundJob(static function () use ($file) {
			$lastVersion = $file->getLastVersion();
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
		return DocumentSession::getModelList([
			'filter' => [
				'=EXTERNAL_HASH' => $externalHash,
			],
		]);
	}

	private function extractExternalHash(array $payload): ?string
	{
		$hash = $payload['host_doc_key'] ?? null;

		return is_string($hash) && $hash !== '' ? $hash : null;
	}
}
