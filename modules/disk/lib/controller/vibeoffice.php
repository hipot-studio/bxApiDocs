<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Document;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\EditorConfigResponse;
use Bitrix\Disk\Document\Vibeoffice\Filters\EgressUrlSignatureCheck;
use Bitrix\Disk\Document\Vibeoffice\Filters\WebhookSignatureCheck;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\User;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\ContentType;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Engine\Router;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Result;

/**
 * Controller for the vibeoffice document editing platform.
 *
 * Parallel to {@see OnlyOffice}, but the editor config is created and signed by the
 * platform: this controller performs the server-to-server `POST /v1/documents/{docKey}/open`
 * and proxies the signed config + `vo_sess` into the browser (DTO-EDITORCONFIG). The
 * `api-secret` used to sign protocol-client calls never leaves the backend (ADR §3).
 *
 * The double rights check (`File` + `AttachedObject`) is reused exactly as in OnlyOffice
 * without weakening the security model.
 *
 * The egress endpoint streams the current Disk file content to the platform over a
 * host-signed URL; sign-urls re-signs version-history URLs through the platform.
 */
final class Vibeoffice extends Engine\Controller
{
	/** TTL (seconds) of the host-signed egress URL the platform uses to pull the source. */
	private const EGRESS_URL_TTL = 600;

	/**
	 * Hard cap on version descriptors accepted per signVersionUrls call. The version-history
	 * panel deals in tens of versions; a larger array is bogus/abusive input. Bounds both the
	 * batch version load and the platform sign-urls payload.
	 */
	private const MAX_VERSIONS_PER_REQUEST = 100;

	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				DocumentSession::class,
				'documentSession',
				static function($className, $documentSessionHash) {
					// Only vibeoffice sessions may enter this controller: an OnlyOffice/Flipchart
					// session hash must not be openable/transformable through the vibeoffice flow
					// (the "one document — one engine" invariant).
					return DocumentSession::load([
						'=EXTERNAL_HASH' => $documentSessionHash,
						'=SERVICE' => Models\DocumentService::Vibeoffice->value,
					]);
				}
			),
		];
	}

	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new Document\Vibeoffice\Filters\VibeofficeEnabled();

		return $defaultPreFilters;
	}

	public function configureActions()
	{
		return [
			// Browser-initiated open/create flows: dropped CSRF as in OnlyOffice
			// because they are reached through forward() from DocumentService.
			'loadDocumentEditor' => [
				'-prefilters' => [Csrf::class],
			],
			'loadDocumentEditorByViewSession' => [
				'-prefilters' => [Csrf::class],
				// The hash is shared by every participant of the document, so it alone must not
				// authorize the view→edit transformation: only the owner of the view session may
				// turn it into an edit session (createEditSession attributes the edit session to
				// the session's user). Same owner+hash guard as OnlyOffice.
				'+prefilters' => [
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return Context::getCurrent()->getRequest()->get('documentSessionHash');
						})
					,
				],
			],
			'loadDocumentViewer' => [
				'-prefilters' => [Csrf::class],
			],
			'loadCreateDocumentEditor' => [
				'-prefilters' => [Csrf::class],
			],
			'createDocument' => [
				'-prefilters' => [Csrf::class],
			],
			// Browser-initiated: the shell-component fetches the proxied DTO-EDITORCONFIG
			// for an already-created session over a regular AJAX call (CSRF stays on).
			'getEditorConfig' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'waitForSaved' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
				],
			],
			// Unified-link config channel: keeps the default prefilters (CSRF + user auth) — the
			// visitor is a logged-in portal user — and, like getEditorConfig above, only pins the
			// method to POST. Authorization is fail-closed inside the action via UnifiedLinkAccessService.
			'getEditorConfigForUnifiedLink' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			// External/anonymous config channel for public (disk.external.link) office documents
			// (P8.T2, ALG-EXTERNAL-GRANT). An anonymous visitor has no portal session, so — like
			// `egress`/`webhook` — we fully replace prefilters to drop CSRF and the default user
			// authentication. The only authorization here is the ExternalLink (validity/password/
			// allowEdit) carried by the session context plus the auto-wired session hash; the
			// internal `getEditorConfig` above keeps CSRF + user-auth untouched.
			'getEditorConfigForExternalLink' => [
				'prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new Csrf(false),
					new Document\Vibeoffice\Filters\VibeofficeEnabled(),
				],
			],
			// Server-to-server: the platform (egress) pulls the source over a
			// host-signed URL. We fully replace prefilters (as OnlyOffice does for its
			// `download` action) to drop CSRF and the default user authentication — the
			// only authorization here is the host-side URL signature. The method is
			// pinned to GET (the platform pulls the source with a plain GET) to reduce
			// the attack surface. Deliberately NOT gated by VibeofficeEnabled: during the
			// rollback drain (ALG-DRAIN) the portal flag is already off while the platform
			// still finishes the in-flight sessions; EgressUrlSignatureCheck itself denies
			// the call when the api-secret is not configured.
			'egressDocument' => [
				'prefilters' => [
					new HttpMethod([HttpMethod::METHOD_GET]),
					new EgressUrlSignatureCheck(
						(string)$this->getConfiguration()->getApiSecret()
					),
				],
			],
			'signVersionUrls' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
				],
			],
			// Server-to-server: the platform delivers webhooks here. Fully isolated
			// prefilters (its own VO1-style HMAC over the RAW body), not shared with
			// the OnlyOffice webhook (JWT Bearer). CSRF/default user auth are dropped —
			// the only authorization is the webhook HMAC signature.
			// Deliberately NOT gated by VibeofficeEnabled: the rollback drain (ALG-DRAIN)
			// turns the portal flag off FIRST and then waits for the final `document.saved`
			// deliveries — rejecting them here (inside an HTTP-200 Engine envelope the
			// platform treats as accepted, so it never retries) would lose the unsaved
			// edits and break the ADR rollback invariant. WebhookSignatureCheck itself
			// denies the call when the webhook secret is not configured.
			'webhook' => [
				'prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					// S2S webhook: the only authorization is the HMAC signature (WebhookSignatureCheck);
					// suppress the engine's auto-injected CSRF for a POST route in the AJAX scope.
					new Csrf(false),
					new ContentType([ContentType::JSON]),
					new Document\Vibeoffice\Filters\WebhookSignatureCheck(
						(string)$this->getConfiguration()->getWebhookSecret()
					),
				],
			],
		];
	}

	/**
	 * P5.T1 (API-WEBHOOK): receives platform webhooks (`document.saved`/`document.failover`/
	 * `document.session-closed`).
	 *
	 * The HMAC `{ts}.{rawBody}` signature, the ±300s timestamp window and the JSON
	 * content-type are already enforced by the prefilters
	 * ({@see Document\Vibeoffice\Filters\WebhookSignatureCheck}). Here we dispatch by
	 * `X-VO-Event` and de-duplicate by `X-VO-Delivery` through {@see WebhookProcessor}.
	 *
	 * We answer 2xx (a bare `{}` JSON) only AFTER the processing succeeds — for
	 * `document.saved` this means after the working copy was downloaded and persisted as a
	 * new Disk version, so the platform does not retry a delivery we have already applied.
	 * A duplicate `X-VO-Delivery` is acknowledged with 2xx without re-processing.
	 *
	 * On a malformed delivery id or body we return 400; the signature failure paths return
	 * 401 from the prefilter; on a processing failure we MUST return a non-2xx (500) so the
	 * platform retries — the Engine error envelope alone keeps HTTP 200, which the platform
	 * would treat as accepted (event loss). The body is parsed from the RAW (already
	 * signature-verified) request, so critical webhook fields (`file_url`, `host_doc_key`,
	 * `source`, `rev`, `editor_key`) are read as sent, not through the proactive XSS filter
	 * of JsonPayload::getData().
	 */
	public function webhookAction(JsonPayload $payload): ?Response\Json
	{
		$request = Context::getCurrent()->getRequest();
		$response = Context::getCurrent()->getResponse();

		$deliveryId = (int)$request->getHeader(WebhookSignatureCheck::HEADER_DELIVERY);
		$event = (string)$request->getHeader(WebhookSignatureCheck::HEADER_EVENT);

		if ($deliveryId <= 0 || $event === '')
		{
			$response->setStatus('400 Bad Request');
			$this->addError(new Error('Missing or invalid webhook delivery headers.', 'validation'));

			return null;
		}

		$payloadData = $this->decodeWebhookBody($payload);
		if ($payloadData === null)
		{
			$response->setStatus('400 Bad Request');
			$this->addError(new Error('Malformed webhook body.', 'validation'));

			return null;
		}

		try
		{
			$result = (new WebhookProcessor())->process($deliveryId, $event, $payloadData);
		}
		catch (\Throwable $e)
		{
			// A thrown exception (deadlock, DB failure, etc.) would otherwise be caught by the
			// Engine and returned as an error envelope with HTTP 200 — which the platform reads
			// as accepted and never retries, silently losing the delivery. Force a non-2xx so
			// the (idempotent) delivery is retried; platform retry is bound to the HTTP status.
			$response->setStatus('500 Internal Server Error');
			$this->addError(new Error($e->getMessage(), 'processing'));

			return null;
		}

		if (!$result->isSuccess())
		{
			// Do NOT acknowledge: a bare error envelope keeps HTTP 200, which the platform
			// reads as accepted. Force a non-2xx so the (idempotent) delivery is retried.
			$response->setStatus('500 Internal Server Error');
			$this->addErrors($result->getErrors());

			return null;
		}

		return new Response\Json([]);
	}

	/**
	 * Reports whether the version produced by the finished editor session is already in Disk.
	 *
	 * The platform sends `document.saved` asynchronously. The editor may close before that
	 * webhook has finished downloading and storing the new version, so the client must not open
	 * the view URL until the edit session is inactive and its newer content is in Disk.
	 */
	public function waitForSavedAction(DocumentSession $documentSession): array
	{
		$currentUserId = User::resolveUserId($this->getCurrentUser());
		if (!$documentSession->isEdit() || !$documentSession->belongsToUser($currentUserId))
		{
			$this->addError(new Error('Could not operate by this user document session.'));

			return [];
		}

		$file = $documentSession->getFile();
		$currentContentVersion = $file ? (int)$file->getGlobalContentVersion() : null;
		$sessionContentVersion = $documentSession->getContentVersionSnapshot();

		return [
			'ready' => $currentContentVersion !== null
				&& $sessionContentVersion !== null
				&& $currentContentVersion > $sessionContentVersion
				&& $documentSession->isNonActive(),
			'contentVersion' => $currentContentVersion,
			'snapshot' => $sessionContentVersion,
		];
	}

	/**
	 * Decodes the RAW (already signature-verified) webhook body so the processor reads
	 * critical fields exactly as sent, bypassing the proactive XSS filter that
	 * {@see JsonPayload::getData()} applies (V4). Returns null on a malformed body.
	 */
	private function decodeWebhookBody(JsonPayload $payload): ?array
	{
		$raw = $payload->getRaw();
		if (!is_string($raw) || $raw === '')
		{
			return null;
		}

		try
		{
			$decoded = \Bitrix\Main\Web\Json::decode($raw);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	public function createDocumentAction(
		string $typeFile,
		?Disk\Folder $targetFolder = null,
		array $analytics = [],
	): ?array
	{
		$newFile = $this->createDocument($typeFile, $targetFolder, $analytics);
		if (!$newFile)
		{
			return null;
		}

		return [
			'id' => $newFile->getId(),
			'name' => $newFile->getName(),
			'size' => $newFile->getSize(),
			'openUrl' => Driver::getInstance()->getUrlManager()->getUnifiedEditLink($newFile, [
				'additionalQueryParams' => [
					'analytics' => $analytics,
				],
			]),
		];
	}

	public function loadCreateDocumentEditorAction(
		string $typeFile,
		?Disk\Folder $targetFolder = null,
		array $analytics = [],
	): ?HttpResponse
	{
		$newFile = $this->createDocument($typeFile, $targetFolder, $analytics);
		if (!$newFile)
		{
			return null;
		}

		return $this->loadDocumentEditorAction($newFile);
	}

	private function createDocument(
		string $typeFile,
		?Disk\Folder $targetFolder = null,
		array $analytics = [],
	): ?Disk\File
	{
		$createBlankDocumentScenario = new Document\OnlyOffice\CreateBlankDocumentScenario(
			(int)($this->getCurrentUser()?->getId() ?? 0),
			Context::getCurrent()?->getLanguage(),
		);

		if ($targetFolder)
		{
			$result = $createBlankDocumentScenario->createBlank($typeFile, $targetFolder, $analytics);
		}
		else
		{
			$result = $createBlankDocumentScenario->createBlankInDefaultFolder($typeFile, $analytics);
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData()['file'];
	}

	public function loadDocumentEditorAction(
		Disk\File $object = null,
		Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
	): ?HttpResponse
	{
		// Double rights check (File + AttachedObject), identical to OnlyOffice.
		$canEdit = false;
		if ($attachedObject && !$attachedObject->canUpdate(User::resolveUserId($this->getCurrentUser())))
		{
			$attachedObject = null;
		}
		elseif ($attachedObject)
		{
			$canEdit = true;
		}

		if ($object && $attachedObject && ($object->getId() !== $attachedObject->getObjectId()))
		{
			$object = null;
		}

		if ($object && !$canEdit)
		{
			$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
			if ($object->canUpdate($securityContext))
			{
				$canEdit = true;
			}
		}

		if (!$canEdit)
		{
			return $this->showNotFoundPageAction();
		}

		return $this->loadDocumentEditor($object, null, $attachedObject, DocumentSession::TYPE_EDIT, $editorMode);
	}

	public function loadDocumentViewerAction(
		Disk\File $object = null,
		Disk\Version $version = null,
		Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
	): ?HttpResponse
	{
		if ($object && $version && $object->getId() !== $version->getObjectId())
		{
			$object = null;
		}

		if ($object === null && $version)
		{
			$object = $version->getObject();
		}

		// Double rights check (File + AttachedObject), identical to OnlyOffice.
		$canRead = false;
		if ($attachedObject && !$attachedObject->canRead(User::resolveUserId($this->getCurrentUser())))
		{
			$attachedObject = null;
		}
		elseif ($attachedObject)
		{
			$canRead = true;
		}

		if ($object && $attachedObject && ($object->getId() !== $attachedObject->getObjectId()))
		{
			$object = null;
		}

		if ($object && !$canRead)
		{
			$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
			if ($object->canRead($securityContext))
			{
				$canRead = true;
			}
		}

		if (!$canRead)
		{
			return $this->showNotFoundPageAction();
		}

		if ($version === null)
		{
			$file = $object ?: $attachedObject?->getFile();
			if ($file instanceof Disk\File)
			{
				try
				{
					(new Document\Vibeoffice\SavedContentSynchronizer())->synchronize($file);

					// The synchronizer may wait for an asynchronous webhook to upload a new
					// version. Do not pass the pre-wait model instance to the session manager:
					// it can still contain the old GLOBAL_CONTENT_VERSION.
					$freshFile = Disk\File::getById($file->getId());
					if ($freshFile && (!$attachedObject || !$attachedObject->isSpecificVersion()))
					{
						$object = $freshFile;
					}
				}
				catch (\Throwable)
				{
					// A delayed platform save must not turn a readable document into an error page.
				}
			}
		}

		return $this->loadDocumentEditor($object, $version, $attachedObject, DocumentSession::TYPE_VIEW, $editorMode);
	}

	public function loadDocumentEditorByViewSessionAction(Models\DocumentSession $documentSession): ?HttpResponse
	{
		if (!$documentSession->isView())
		{
			$this->addError(new Error('Could not work with session with {edit} type.'));

			return null;
		}

		if (!$documentSession->getObject())
		{
			$this->addError(new Error("Could not find file {{$documentSession->getObjectId()}}."));
			$documentSession->delete();

			return null;
		}

		if (!$documentSession->canTransformUserToEdit($this->getCurrentUser()))
		{
			$this->addError(new Error('Could not transform document session to edit mode because lack of rights.'));

			return null;
		}

		$createdSession = $documentSession->createEditSession();
		if (!$createdSession)
		{
			$this->addErrors($documentSession->getErrors());

			return null;
		}

		if ($createdSession->getId() !== $documentSession->getId())
		{
			$documentSession->delete();
		}

		$file = $createdSession->getFile();
		if (!$file)
		{
			$this->addError(new Error('Could not find the file (content).'));

			return null;
		}

		// The platform `open`/join and the DTO-EDITORCONFIG delivery happen in
		// `getEditorConfig` once the shell-component resolves; see loadDocumentEditor().
		return $this->redirectToShell($createdSession);
	}

	protected function loadDocumentEditor(
		?Disk\File $object,
		?Disk\Version $version,
		?Disk\AttachedObject $attachedObject,
		int $type,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
	): ?HttpResponse
	{
		if (!$object && !$attachedObject && !$version)
		{
			$this->addError(new Error('There is no file to preview.'));

			return null;
		}

		$file = $object;
		if ($version)
		{
			$file = $version->getObject();
		}
		elseif ($attachedObject)
		{
			$file = $attachedObject->getFile();
		}

		if (!($file instanceof Disk\File))
		{
			$this->addError(new Error('Could not find the file (content).'));

			return null;
		}

		$trackedObjectManager = Driver::getInstance()->getTrackedObjectManager();
		if ($attachedObject)
		{
			$trackedObjectManager->pushAttachedObject($this->getCurrentUser()->getId(), $attachedObject, true);
		}
		else
		{
			$trackedObjectManager->pushFile($this->getCurrentUser()->getId(), $file, true);
		}

		$documentSessionContext = Models\DocumentSessionContext::tryBuildByAttachedObject($attachedObject, $file);

		$sessionManager = $this->getSessionManager();
		$sessionManager
			->setUserId($this->getCurrentUser()->getId())
			->setSessionType($type)
			->setSessionContext($documentSessionContext)
			->setFile($object)
			->setVersion($version)
			->setAttachedObject($attachedObject)
		;

		if (!$sessionManager->lock())
		{
			$this->addError(new Error('Could not getting lock for the session.'));

			return null;
		}

		try
		{
			$documentSession = $sessionManager->findOrCreateSession();
			if (!$documentSession)
			{
				$this->addErrors($sessionManager->getErrors());

				return null;
			}
		}
		finally
		{
			$sessionManager->unlock();
		}

		// Point #4 (messenger-call, compact editor): the config channel (getEditorConfigAction)
		// resolves the session by hash alone, so the editorMode of this load* action cannot reach
		// buildOpenBody directly — persist it into the session CONTEXT (under the reserved `vo` key)
		// so buildOpenBody can read it back and inject `editor.ui.mode="compact"`. Only a compact
		// request is stored (fail-safe: absence means the full editor); a live-session join keeps the
		// mode of the FIRST open, which matches the platform persisting `ui_mode` from that first open.
		// Best-effort: a failed persist must not fail the open (the session is already usable).
		if ($editorMode === Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_COMPACT)
		{
			$sessionManager->persistSessionMetadata($documentSession, [
				Document\Vibeoffice\DocumentSessionManager::CONTEXT_VO_EDITOR_MODE_KEY => $editorMode,
			]);
		}

		// Lazily (re)install the rollback drain agent (A′): we only reach this point when a
		// vibeoffice session was really opened (the flag is on — the dispatcher routed here), so
		// the drain reconciler is installed only on portals that actually used vibeoffice.
		// Idempotent (AddAgent de-dups by NAME); it also revives the agent after an idle
		// self-unregister ({@see Document\Vibeoffice\DrainService::drainAgent()}).
		Document\Vibeoffice\DrainService::register();

		// Redirect into the editor shell-component carrying only the session id/hash (as
		// OnlyOffice does). The platform `open`/join and DTO-EDITORCONFIG delivery are deferred
		// to `getEditorConfig` (below), called lazily by the shell once it loads: the `open` runs
		// once and is idempotent for a live session (platform returns `joined:true`, no re-pull).
		return $this->redirectToShell($documentSession);
	}

	/**
	 * P3.T2 deliverable: resolves and returns the proxied DTO-EDITORCONFIG (the
	 * platform open-response: `vo_sess`, `urls{}`, verbatim `config`) for a
	 * DocumentSession already created by the load* actions.
	 *
	 * This is the backend channel the editor shell-component (frontend phase) uses
	 * to obtain the config for `@vibeoffice/helper createEditor({ openConfig })`.
	 * The platform `config` is proxied verbatim (never rebuilt) and `api-secret`
	 * never reaches the browser — only `vo_sess` + `config` do (ADR §3).
	 *
	 * Idempotency / join: calling this again on a live session re-issues the config
	 * via a platform join (`joined:true`) without re-pulling the working copy, so a
	 * repeated resolve is safe. The transient `vo_sess`/`config` are returned straight
	 * from the platform response and are NOT persisted (Q-3).
	 *
	 * @return array|null the DTO-EDITORCONFIG ({@see EditorConfigResponse::toArray()})
	 *         on success, null (with errors) otherwise. The array form is returned (not
	 *         the Jsonable object) because the Engine JSON layer serialises with raw
	 *         json_encode and would not invoke Jsonable::toArray() on a nested object.
	 */
	public function getEditorConfigAction(Models\DocumentSession $documentSession): ?array
	{
		if (!$documentSession->getId())
		{
			return $this->showNotFoundConfig();
		}

		// Internal channel must not serve a session carried by an ExternalLink context: that channel
		// is getEditorConfigForExternalLinkAction, which validates the link's expiry/password/allowEdit.
		// canAccessSession→canEdit() would otherwise grant an edit config to any authenticated user with
		// the session hash, bypassing those checks (SERVICE=vibeoffice rows are auto-wired by hash).
		if ($documentSession->getContext()?->getExternalLink() instanceof ExternalLink)
		{
			return $this->showNotFoundConfig();
		}

		// Re-check rights on the resolved session, do not trust the hash alone.
		if (!$this->canAccessSession($documentSession))
		{
			return $this->showNotFoundConfig();
		}

		return $this->buildEditorConfig($documentSession);
	}

	/**
	 * P8.T2 (ALG-EXTERNAL-GRANT): the external/anonymous config channel for public office
	 * documents opened through {@see \Bitrix\Disk\ExternalLink}.
	 *
	 * Unlike {@see self::getEditorConfigAction()}, this path never dereferences a `CurrentUser`
	 * (an anonymous visitor has none). Authorization is delegated to the ExternalLink carried by
	 * the session context — its validity/expiry, its (already validated) password and its
	 * `allowEdit()` — plus the auto-wired session hash (a second factor). The access is therefore
	 * never wider than the ExternalLink. The prefilters already dropped CSRF and the default user
	 * authentication; see {@see self::configureActions()}.
	 *
	 * Reachable only for a vibeoffice session (SERVICE='vibeoffice'), which the external-link
	 * component creates only when the resolver picked vibeoffice — i.e. only when the portal flag
	 * is on. With the flag off no such session exists, so this channel is never engaged (zero
	 * regression).
	 */
	public function getEditorConfigForExternalLinkAction(Models\DocumentSession $documentSession): ?array
	{
		if (!$documentSession->getId())
		{
			return $this->showNotFoundConfig();
		}

		$link = $documentSession->getContext()?->getExternalLink();
		if (!($link instanceof ExternalLink) || !$this->canAccessExternalSession($documentSession, $link))
		{
			return $this->showNotFoundConfig();
		}

		// Lazily (re)install the rollback drain agent (A′): the external-link vibeoffice session is
		// created on a SEPARATE route (disk.external.link component's generateDocumentSession), not
		// through loadDocumentEditor, so it would otherwise never register the drain reconciler.
		// Reaching an authorized vibeoffice external session confirms real usage (the resolver only
		// picks vibeoffice while the flag is on). Idempotent (AddAgent de-dups by NAME).
		Document\Vibeoffice\DrainService::register();

		// An edit session is only admissible while the link still allows editing; otherwise it is
		// degraded to a view session (mirrors DocumentSession::canEdit and shouldDowngradeToView),
		// so the anonymous visitor never gets an edit config wider than the link.
		if ($documentSession->isEdit() && !$link->allowEdit())
		{
			$documentSession->transformToView();
		}

		return $this->buildEditorConfig($documentSession);
	}

	/**
	 * The unified-link config channel for an internal (portal) office document opened through a
	 * unified link. This is the vibeoffice analog of the OnlyOffice unified-link shell, which builds
	 * its config inline while relying on the same {@see UnifiedLinkAccessService} for the edit/view
	 * decision ({@see \CDiskFileEditorOnlyOfficeComponent::isEditAllowed()}) and on the upstream
	 * {@see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer} gate.
	 *
	 * Unlike {@see self::getEditorConfigAction()}, authorization here is NOT the direct
	 * `canUserRead/canUserEdit` on the object: a user whose access to the file comes ONLY through the
	 * unified link (no direct Disk rights) legitimately reaches this shell, and the internal channel
	 * would refuse them with a cloud-error (OnlyOffice supports this scenario — parity gap).
	 *
	 * Fail-closed: access is (re-)validated server-side against the unified-link ACL of the CURRENT
	 * user on this file via {@see UnifiedLinkAccessService::check()} — never by the session hash
	 * alone (SERVICE='vibeoffice' rows are auto-wired by hash). A Denied level yields a not-found
	 * config, so a bare session hash cannot obtain a config. The access is never wider than the
	 * unified link grants: an edit session is degraded to view when the link grants only read
	 * (mirrors {@see self::getEditorConfigForExternalLinkAction()}).
	 */
	public function getEditorConfigForUnifiedLinkAction(Models\DocumentSession $documentSession): ?array
	{
		if (!$documentSession->getId())
		{
			return $this->showNotFoundConfig();
		}

		// An ExternalLink-carried session belongs to the external channel
		// (getEditorConfigForExternalLinkAction, which validates the link's expiry/password/allowEdit);
		// the unified-link channel serves internal sessions authorized by the portal unified-link ACL.
		if ($documentSession->getContext()?->getExternalLink() instanceof ExternalLink)
		{
			return $this->showNotFoundConfig();
		}

		$accessLevel = $this->resolveUnifiedLinkAccessLevel($documentSession);
		if (!$accessLevel->canRead())
		{
			return $this->showNotFoundConfig();
		}

		// An edit session is only admissible while the unified link still grants editing; otherwise it
		// is degraded to a view session (mirrors getEditorConfigForExternalLinkAction), so the user
		// never gets an edit config wider than the unified link.
		if ($documentSession->isEdit() && !$accessLevel->canEdit())
		{
			$documentSession->transformToView();
		}

		return $this->buildEditorConfig($documentSession);
	}

	/**
	 * Resolves the unified-link access level of the current user on the session's file, delegating to
	 * the shared {@see UnifiedLinkAccessService} (the same service the OnlyOffice/vibeoffice shells and
	 * the {@see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer} use). The
	 * check honours the double File + AttachedObject context. A session without a resolvable object is
	 * treated as Denied (fail-closed).
	 */
	private function resolveUnifiedLinkAccessLevel(DocumentSession $documentSession): UnifiedLinkAccessLevel
	{
		$object = $documentSession->getObject();
		if (!$object)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$attachedObject = $documentSession->getContext()?->getAttachedObject();

		return $this->getUnifiedLinkAccessService()->check($object, $attachedObject);
	}

	private function getUnifiedLinkAccessService(): UnifiedLinkAccessService
	{
		return ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	/**
	 * Shared open flow behind both config channels ({@see self::getEditorConfigAction()} and
	 * {@see self::getEditorConfigForExternalLinkAction()}): the file guard, the fresh-open vs.
	 * version-stream decision, the 409-only downgrade retry and the restriction payload. It runs on
	 * an already-authorized session, so authorization (a `CurrentUser` re-check vs. an ExternalLink
	 * check) and the pre-open edit→view downgrade stay in the callers; the open-body itself is built
	 * from whichever identity the session carries ({@see self::buildOpenBody()} branches on the
	 * session's external link, never forcing a `CurrentUser`).
	 */
	private function buildEditorConfig(DocumentSession $documentSession): ?array
	{
		// Engine isolation: this shared choke-point may be reached without the SERVICE auto-wire
		// filter (the component path resolves a DocumentSession by id and calls the action directly).
		// Refuse any non-vibeoffice session so the owner of an OnlyOffice session cannot open their
		// own file on vibeoffice ("one document — one engine"), which would leak vo-metadata into a
		// foreign context and spawn phantom versions.
		if ($documentSession->getService() !== Models\DocumentService::Vibeoffice)
		{
			return $this->showNotFoundConfig();
		}

		$file = $documentSession->getFile();
		if (!$file)
		{
			$this->addError(new Error('Could not find the file (content).'));

			return null;
		}

		// The document source (`document.url`/`document.filetype`) is always sent: for a
		// version-bound session it points at that version's content, and on a join of a
		// live session the platform ignores `document.url` anyway, so the current-file
		// behaviour is unchanged.
		$withDocumentSource = true;

		$openResult = $this->openDocumentSession($documentSession, $file, $withDocumentSource);
		if (!$openResult->isSuccess())
		{
			if ($documentSession->isEdit() && $this->shouldDowngradeToView($openResult))
			{
				$documentSession->transformToView();

				$openResult = $this->openDocumentSession($documentSession, $file, $withDocumentSource);
			}

			if (!$openResult->isSuccess())
			{
				$this->addErrors($openResult->getErrors());

				return null;
			}
		}

		$editorConfig = $openResult->getData()['editorConfig'] ?? null;
		if (!($editorConfig instanceof EditorConfigResponse))
		{
			return null;
		}

		// P4.T2 (API-RESTRICT): hand the frontend the data it needs to repeat the
		// OnlyOffice-style limit cycle in the vibeoffice editor component. The global
		// counter is shared with OnlyOffice (no SERVICE split); no promo/boost upsell.
		return $editorConfig->toArray() + [
			'restriction' => $this->buildRestrictionData(),
		];
	}

	/**
	 * ALG-EXTERNAL-GRANT authorization: an external session may be accessed only while the
	 * ExternalLink is valid — not expired, and (if password-protected) with the password already
	 * validated within the external-link session.
	 * The session hash stays the second factor via the auto-wire. Rights of any current user are
	 * deliberately ignored here — the source of truth is the link (access is never wider than it).
	 */
	private function canAccessExternalSession(DocumentSession $documentSession, ExternalLink $link): bool
	{
		$object = $link->getObject();
		if (!$object || $object->isDeleted())
		{
			return false;
		}

		if ((int)$documentSession->getObjectId() !== (int)$object->getRealObjectId())
		{
			return false;
		}

		if (
			($link->isAutomatic() && !Disk\Configuration::isEnabledAutoExternalLink())
			|| (!$link->isAutomatic() && !Disk\Configuration::isEnabledManualExternalLink())
		)
		{
			return false;
		}

		if ($link->isExpired())
		{
			return false;
		}

		if ($link->hasPassword() && !$this->isExternalLinkPasswordValidated($link))
		{
			return false;
		}

		return true;
	}

	/**
	 * Whether the ExternalLink password was already validated within the current external-link session.
	 */
	private function isExternalLinkPasswordValidated(ExternalLink $link): bool
	{
		return ServiceLocator::getInstance()
			->get(ExternalLinkPasswordService::class)
			->isConfirmed($link)
		;
	}

	/**
	 * P4.T2 (API-RESTRICT): builds the limit data the frontend needs to repeat the cycle
	 * `shouldUseRestriction → lock → isAllowedEdit → registerUsage → unlock` and to post
	 * the encountered limit into the existing `disk.api.limitEncounter.documentEditSession`.
	 *
	 * vibeoffice deliberately reuses the existing OnlyOffice {@see RestrictionManager}
	 * AS IS, without splitting by SERVICE: its sessions count against the same global
	 * `b_disk_oo_restriction_log` and the same portal limit as OnlyOffice. No promo-gate
	 * and no OnlyOffice→vibeoffice boost are applied — on `EXCEEDED_LIMIT` the frontend
	 * performs a hard refusal / downgrade to view, with no upsell slider.
	 *
	 * Q-1: on a box without OnlyOffice cloud-registration the limit resolves to UNLIMITED
	 * (vibeoffice on box is effectively unlimited); this is the documented, expected
	 * behaviour — no new branch is added to RestrictionManager.
	 *
	 * @return array{shouldUseRestriction: bool, availableDocumentSessionCount: int}
	 */
	protected function buildRestrictionData(): array
	{
		$restrictionManager = new RestrictionManager();

		return [
			'shouldUseRestriction' => $restrictionManager->shouldUseRestriction(),
			'availableDocumentSessionCount' => $restrictionManager->getAvailableDocumentSessionCount(),
		];
	}

	/**
	 * Re-validates that the current user may access the session's file before
	 * re-issuing the editor config. Relies on the session model's own double rights
	 * check (File + AttachedObject through the session context), identical in spirit
	 * to the load* actions.
	 */
	private function canAccessSession(Models\DocumentSession $documentSession): bool
	{
		$currentUser = $this->getCurrentUser();
		if (!$currentUser)
		{
			return false;
		}

		return $documentSession->isEdit()
			? $documentSession->canUserEdit($currentUser)
			: $documentSession->canUserRead($currentUser);
	}

	private function showNotFoundConfig(): ?array
	{
		$this->addError(new Error('Could not find the document session.', 'not_found'));

		return null;
	}

	/**
	 * P3.T2: builds the open body, performs the server-to-server
	 * `POST /v1/documents/{docKey}/open` and extracts `session_id`/`editor_key`/`rev`
	 * (persisting them into the session CONTEXT is the P4.T1 / Q-3 seam). The signed
	 * `config` is returned verbatim to the browser via {@see EditorConfigResponse} and
	 * is NOT rebuilt by the host.
	 *
	 * @param bool $withDocumentSource whether to send `document.url`/`document.filetype`.
	 *        On a join of a live session (joined:true) the platform ignores them.
	 */
	protected function openDocumentSession(
		DocumentSession $documentSession,
		Disk\File $file,
		bool $withDocumentSource,
	): Result
	{
		$result = new Result();

		try
		{
			$client = $this->getProtocolClient();
			$docKey = $documentSession->getExternalHash();
			// buildOpenBody signs the egress URL; with an empty api-secret signing
			// throws (defense-in-depth, alongside EgressUrlSignatureCheck denial).
			$body = $this->buildOpenBody($documentSession, $file, $withDocumentSource);
		}
		catch (\Throwable $e)
		{
			return $result->addError(new Error($e->getMessage()));
		}

		$openResult = $client->open($docKey, $body);
		if (!$openResult->isSuccess())
		{
			return $result->addErrors($this->mapOpenErrors($openResult->getErrors()));
		}

		$openConfig = $openResult->getData();
		if (!is_array($openConfig))
		{
			$openConfig = [];
		}

		// P4.T1 (Q-3): persist the minimal metadata subset (session_id/editor_key/rev)
		// into the session CONTEXT (under the reserved `vo` key) so it survives for
		// re-issuing the config and for drain. Best-effort: a failed persist must not
		// fail the open (the config is already valid and returned below).
		$metadata = $this->extractSessionMetadata($openConfig);
		if ($metadata)
		{
			$this->getSessionManager()->persistSessionMetadata($documentSession, $metadata);
		}

		$result->setData([
			'editorConfig' => new EditorConfigResponse($openConfig, $docKey),
		]);

		return $result;
	}

	/**
	 * Assembles the open-body for `POST /v1/documents/{docKey}/open` (API-OPEN).
	 *
	 * `document.url` is the absolute, host-signed egress URL the platform itself pulls
	 * to fetch the current source ([P3.T3]); it must be reachable from the DS fleet,
	 * not from the browser. On a join of a live session the platform ignores
	 * `document.url`/`document.filetype`, so they are omitted there.
	 */
	protected function buildOpenBody(
		DocumentSession $documentSession,
		Disk\File $file,
		bool $withDocumentSource,
	): array
	{
		// P8.T2 (ALG-EXTERNAL-GRANT): a session reached through a public external link is
		// authorized by the link, not by a CurrentUser (which is absent for an anonymous visitor).
		// The user identity and grant of the open-body then come from the link, not from the user.
		$link = $documentSession->getContext()?->getExternalLink();
		if ($link instanceof ExternalLink)
		{
			$body = $this->buildExternalUserAndGrant($documentSession, $link);
		}
		else
		{
			$currentUser = $this->getCurrentUser();
			$body = [
				'user' => [
					'id' => (string)$currentUser->getId(),
					'name' => $currentUser->getFormattedName(),
				],
				// P4.T3 (GRANT-MAP): the grant role is resolved by the GrantMapper from the
				// Disk rights (double File + AttachedObject check via the session model).
				'grant' => [
					'mode' => (new Document\Vibeoffice\GrantMapper())->resolveForSession($documentSession, $currentUser),
				],
			];
		}

		$body['document'] = [
			'title' => $file->getName(),
		];
		$body['editor'] = [
			'lang' => (string)(Context::getCurrent()?->getLanguage() ?? 'en'),
		];

		// Point #4 (messenger-call, compact editor): when the session was opened with a compact
		// request (persisted into CONTEXT.vo by loadDocumentEditor), send the optional `editor.ui.mode`
		// so the platform injects the compact-chrome flags into the signed `editorConfig.customization`
		// before it returns the verbatim config (API-OPEN). Only "compact" is recognised; for a full
		// editor (the default, e.g. every open outside a call) the `ui` section is omitted (fail-safe).
		if ($this->resolveEditorMode($documentSession) === Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_COMPACT)
		{
			$body['editor']['ui'] = [
				'mode' => 'compact',
			];
		}

		if ($withDocumentSource)
		{
			// A version-bound session (versionId !== null) streams that version's content and
			// its filetype; a current-file session (versionId === null) builds the egress URL
			// without a versionId and takes the filetype from the file — byte-for-byte as before.
			$versionId = $documentSession->getVersionId();
			$body['document']['url'] = $this->buildSignedEgressUrl($documentSession->getExternalHash(), $versionId);
			$body['document']['filetype'] = $documentSession->isVersion()
				? ((string)($documentSession->getVersion()?->getExtension() ?? $file->getExtension()))
				: $file->getExtension();
		}

		return $body;
	}

	/**
	 * Resolves the editor visual mode persisted for the session by {@see self::loadDocumentEditor()}
	 * (under CONTEXT.vo.editorMode). The config channel resolves the session by hash alone, so this
	 * is the only carrier of the compact request into {@see self::buildOpenBody()}. Absent metadata
	 * (every open outside a call, and every external/unified-link channel that never persists it)
	 * yields the full-editor default ({@see Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL}).
	 */
	protected function resolveEditorMode(DocumentSession $documentSession): int
	{
		$voMetadata = Document\Vibeoffice\DocumentSessionManager::extractVoMetadata($documentSession);
		$editorMode = $voMetadata[Document\Vibeoffice\DocumentSessionManager::CONTEXT_VO_EDITOR_MODE_KEY] ?? null;

		return $editorMode !== null
			? (int)$editorMode
			: Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL;
	}

	/**
	 * Builds the `user`/`grant` part of the open-body for an external-link session (Q-4 / ALG-EXTERNAL-GRANT).
	 *
	 * - grant: resolved from the ExternalLink ({@see Document\Vibeoffice\GrantMapper::resolveForExternalLink()}),
	 *   never from a CurrentUser — `edit` only if the (edit) session and the link both allow it.
	 * - user.id: for an edit session the external-link component has already converted the guest into a
	 *   real `document_editor` user before the open (as OnlyOffice does in `processActionGoToEdit`), so the
	 *   session already carries that real uid; for a view session there is no real user, so we synthesize a
	 *   stable per-session guest id (`'~'+random`, mirroring the board anonymous-id precedent). The platform
	 *   protocol accepts an arbitrary string `user.id`.
	 * - user.name: the generic guest display name.
	 */
	protected function buildExternalUserAndGrant(DocumentSession $documentSession, ExternalLink $link): array
	{
		$grantMode = (new Document\Vibeoffice\GrantMapper())->resolveForExternalLink($documentSession, $link);

		if ($documentSession->isEdit() && !GuestUser::isGuestUserId($documentSession->getUserId()))
		{
			// Edit: the real document_editor uid the external-link edit flow already logged in.
			$userId = (string)$documentSession->getUserId();
		}
		else
		{
			$userId = $this->getStableGuestUserId();
		}

		return [
			'user' => [
				'id' => $userId,
				'name' => GuestUser::create()->getName(),
			],
			'grant' => [
				'mode' => $grantMode,
			],
		];
	}

	/**
	 * Stable per-session synthetic guest id (`'~'+random`) for an anonymous view open, mirroring the
	 * board anonymous-id precedent ({@see \CDiskExternalLinkComponent::getOrCreateAnonymousUserIdForBoard()}).
	 * Deterministic within a session so repeated opens of the same public view keep one `user.id`.
	 */
	private function getStableGuestUserId(): string
	{
		$session = \Bitrix\Main\Application::getInstance()->getSession();
		$sessionKey = 'VIBEOFFICE_ANONYMOUS_USER_ID';

		if ($session->get($sessionKey) === null)
		{
			$session->set($sessionKey, '~' . \Bitrix\Main\Security\Random::getString(12));
		}

		return (string)$session->get($sessionKey);
	}

	/**
	 * Extracts only the metadata host needs to re-issue the config / drain (Q-3):
	 * `session_id`, last `editor_key`, `rev`. Failover/refresh itself is driven by the
	 * frontend helper over SSE. The extraction lives here so the open-logic stays
	 * self-contained; the CONTEXT write is done by DocumentSessionManager.
	 *
	 * @return array<string, mixed> the metadata subset to persist.
	 */
	protected function extractSessionMetadata(array $openConfig): array
	{
		return array_filter([
			'session_id' => $openConfig['session_id'] ?? null,
			'editor_key' => $openConfig['editor_key'] ?? null,
			'rev' => $openConfig['rev'] ?? null,
		], static fn($value) => $value !== null);
	}

	public function egressDocumentAction(Models\DocumentSession $documentSession, ?int $versionId = null): HttpResponse
	{
		// P3.T3: stream the current Disk file content (or a specific version, for the
		// version-history panel) to the platform. The signature has already been
		// verified by EgressUrlSignatureCheck; we do NOT re-open the session here.
		//
		// The consumer is the DS fleet (server-to-server), not a browser, so on a
		// missing file/version we return a bare 404 (empty body) instead of the Engine
		// JSON error envelope — mirroring how OnlyOffice `download` serves a raw stream.
		$file = $documentSession->getFile();
		if (!$file)
		{
			return $this->bareNotFound();
		}

		if ($versionId)
		{
			$version = Disk\Version::loadById($versionId);
			if (!$version || (int)$version->getObjectId() !== $documentSession->getObjectId())
			{
				return $this->bareNotFound();
			}

			return Response\BFile::createByFileId($version->getFileId(), $version->getName());
		}

		return Response\BFile::createByFileId($file->getFileId(), $file->getName());
	}

	/**
	 * Returns a bare 404 (empty body, no Engine JSON envelope) for the egress
	 * endpoint, which is consumed server-to-server by the platform.
	 */
	private function bareNotFound(): HttpResponse
	{
		$response = new HttpResponse();
		$response->setStatus('404 Not Found');

		return $response;
	}

	/**
	 * P3.T4: signs version-history URLs through the platform (API-SIGNURLS).
	 *
	 * Builds `items[]` from the file version history (`b_disk_version`), each carrying a
	 * host-signed egress URL of that version's content, asks the platform to re-sign them
	 * and returns the ready `setHistoryData` objects for the api.js history panel.
	 *
	 * @param array $versions version descriptors from the frontend: list of `{ versionId, key, previousVersionId? }`.
	 */
	public function signVersionUrlsAction(Models\DocumentSession $documentSession, array $versions): ?array
	{
		// Internal channel must not serve a session carried by an ExternalLink context: that channel
		// is getEditorConfigForExternalLinkAction, which validates the link's expiry/password/allowEdit.
		// canAccessSession→canEdit()→ExternalLink::allowEdit() would otherwise sign the full version
		// history for any authenticated user holding the session hash, regardless of file rights and
		// wider than the external link itself grants (the history is intentionally not exposed by link).
		if ($documentSession->getContext()?->getExternalLink() instanceof ExternalLink)
		{
			return $this->showNotFoundConfig();
		}

		// Re-check rights on the resolved session, do not trust the hash alone (same rule as
		// getEditorConfig): the signed egress URLs are pulled later WITHOUT authentication,
		// so revoked file rights must cut the issuance here.
		if (!$this->canAccessSession($documentSession))
		{
			return $this->showNotFoundConfig();
		}

		if (count($versions) > self::MAX_VERSIONS_PER_REQUEST)
		{
			$this->addError(new Error('Too many version descriptors requested.', 'validation'));

			return null;
		}

		if (!$documentSession->isActive())
		{
			$this->addError(new Error('Document is not active.', 'not_active'));

			return null;
		}

		$file = $documentSession->getFile();
		if (!$file)
		{
			$this->addError(new Error('Could not find file for this document session.'));

			return null;
		}

		try
		{
			$client = $this->getProtocolClient();
			// buildVersionItems signs egress URLs; an empty api-secret makes signing
			// throw (kept in lockstep with the egress denial).
			$items = $this->buildVersionItems($documentSession, $versions);
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage()));

			return null;
		}

		if (!$items)
		{
			$this->addError(new Error('No resolvable versions.', 'validation'));

			return null;
		}

		$signResult = $client->signVersionUrls($documentSession->getExternalHash(), ['items' => $items]);
		if (!$signResult->isSuccess())
		{
			$this->addErrors($signResult->getErrors());

			return null;
		}

		$data = $signResult->getData();

		return is_array($data['items'] ?? null) ? $data['items'] : [];
	}

	/**
	 * Resolves the frontend version descriptors into API-SIGNURLS `items[]`, each with a
	 * host-signed egress URL of the version content. Versions of another file are skipped.
	 */
	protected function buildVersionItems(Models\DocumentSession $documentSession, array $versions): array
	{
		$objectId = $documentSession->getObjectId();
		$documentSessionHash = $documentSession->getExternalHash();

		// Batch-load every referenced version (own + previous) in one query, keyed by id and
		// restricted to this file's object — avoids the up-to-2N point selects of loadById.
		$versionsById = $this->loadVersionsForItems($versions, $objectId);

		$items = [];
		foreach ($versions as $descriptor)
		{
			if (!is_array($descriptor))
			{
				continue;
			}

			$versionId = (int)($descriptor['versionId'] ?? 0);
			$version = $versionsById[$versionId] ?? null;
			if (!$version)
			{
				continue;
			}

			$item = [
				'version' => $version->getGlobalContentVersion(),
				'key' => (string)($descriptor['key'] ?? ''),
				'fileType' => $version->getExtension(),
				'url' => $this->buildSignedEgressUrl($documentSessionHash, $versionId),
			];

			$previousVersionId = (int)($descriptor['previousVersionId'] ?? 0);
			if ($previousVersionId > 0)
			{
				$previousVersion = $versionsById[$previousVersionId] ?? null;
				if ($previousVersion)
				{
					$item['previous'] = [
						'key' => (string)($descriptor['previousKey'] ?? ''),
						'fileType' => $previousVersion->getExtension(),
						'url' => $this->buildSignedEgressUrl($documentSessionHash, $previousVersionId),
					];
				}
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Loads all versions referenced by the descriptors (both `versionId` and
	 * `previousVersionId`) in a single query, keyed by id and restricted to $objectId. Replaces
	 * the per-descriptor {@see Disk\Version::loadById()} (up to 2N point selects per call).
	 *
	 * @param array $versions version descriptors from the frontend.
	 *
	 * @return array<int, Disk\Version> version model keyed by its id.
	 */
	private function loadVersionsForItems(array $versions, ?int $objectId): array
	{
		$ids = [];
		foreach ($versions as $descriptor)
		{
			if (!is_array($descriptor))
			{
				continue;
			}

			$versionId = (int)($descriptor['versionId'] ?? 0);
			if ($versionId > 0)
			{
				$ids[$versionId] = true;
			}

			$previousVersionId = (int)($descriptor['previousVersionId'] ?? 0);
			if ($previousVersionId > 0)
			{
				$ids[$previousVersionId] = true;
			}
		}

		if (!$ids)
		{
			return [];
		}

		$versionsById = [];
		foreach (Disk\Version::loadBatchById(array_keys($ids)) as $version)
		{
			if ((int)$version->getObjectId() === (int)$objectId)
			{
				$versionsById[(int)$version->getId()] = $version;
			}
		}

		return $versionsById;
	}

	public function showNotFoundPageAction(): HttpResponse
	{
		/** @see \DiskFileEditorVibeofficeController::showNotFoundAction() */
		$link = UrlManager::getInstance()->create('showNotFound', [
			'c' => 'bitrix:disk.file.editor-vibeoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
		]);

		return $this->redirectTo($link);
	}

	/**
	 * Redirects into the vibeoffice editor shell-component (frontend), carrying the
	 * session id/hash exactly as OnlyOffice redirects to its own shell.
	 */
	protected function redirectToShell(DocumentSession $documentSession): HttpResponse
	{
		/** @see \DiskFileEditorVibeofficeController::getSliderContentAction */
		$link = UrlManager::getInstance()->create('getSliderContent', [
			'c' => 'bitrix:disk.file.editor-vibeoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
			'documentSessionId' => $documentSession->getId(),
			'documentSessionHash' => $documentSession->getExternalHash(),
		]);

		return $this->redirectTo($link);
	}

	/**
	 * Builds an absolute, host-signed egress URL for the source/version content. The
	 * signature/TTL ensure only the platform (which receives it via `document.url`)
	 * can pull it; see {@see EgressUrlSignatureCheck}.
	 */
	protected function buildSignedEgressUrl(string $documentSessionHash, ?int $versionId = null): string
	{
		$params = [
			'documentSessionHash' => $documentSessionHash,
		];
		if ($versionId !== null)
		{
			$params['versionId'] = $versionId;
		}

		$absoluteUrl = (string)UrlManager::getInstance()->createByController(
			$this,
			'egressDocument',
			$params,
			UrlManager::ABSOLUTE_URL,
		);

		return EgressUrlSignatureCheck::buildSignedUrl(
			$absoluteUrl,
			(string)$this->getConfiguration()->getApiSecret(),
			$documentSessionHash,
			self::EGRESS_URL_TTL,
			$versionId,
		);
	}

	/**
	 * Resolves the session manager for vibeoffice sessions (P4.T1).
	 *
	 * Sessions are written/looked up in the shared `b_disk_document_session` table with
	 * SERVICE='vibeoffice' through the dedicated subclass.
	 */
	protected function getSessionManager(): Document\Vibeoffice\DocumentSessionManager
	{
		return new Document\Vibeoffice\DocumentSessionManager();
	}

	protected function getProtocolClient(): ProtocolClientInterface
	{
		return ProtocolClientFactory::create();
	}

	protected function getConfiguration(): Document\Vibeoffice\Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Whether an open-conflict for an edit session should fall back to a view session.
	 *
	 * Only HTTP 409 conflicts (`*_in_progress` / `doc_full`) warrant a downgrade
	 * (SDD P3.md: «409 *_in_progress / doc_full»). A `403 no_grant` is NOT downgraded
	 * here — it surfaces as ERR-NO-GRANT so the frontend can decide; re-opening a
	 * view session would in many cases just fail again without a read grant.
	 *
	 * The errors are already mapped by {@see mapOpenErrors()} at this point, so the
	 * original HTTP status lives in the error custom data.
	 */
	private function shouldDowngradeToView(Result $openResult): bool
	{
		foreach ($openResult->getErrors() as $error)
		{
			if (!($error instanceof Error))
			{
				continue;
			}

			$customData = $error->getCustomData();
			if (is_array($customData) && (int)($customData['httpStatus'] ?? 0) === 409)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Centralised mapping of platform `open` conflicts into the stable BRIEF
	 * ERR-001..009 codes the frontend relies on, while preserving the concrete
	 * platform code (`doc_full`/`no_grant`/`pull_failed`/…) and the HTTP status in
	 * the error custom data so nothing is lost for diagnostics/logging.
	 *
	 * The protocol-client (P2) puts the HTTP status into Error::getCode() and the
	 * platform code into the message; we re-key by HTTP status (reliable) and carry
	 * the parsed platform code along.
	 *
	 * @param Error[] $errors
	 *
	 * @return Error[]
	 */
	private function mapOpenErrors(array $errors): array
	{
		$mapped = [];
		foreach ($errors as $error)
		{
			if (!($error instanceof Error))
			{
				$mapped[] = $error;

				continue;
			}

			[$httpStatus, $platformCode] = $this->splitErrorCode($error);
			if ($httpStatus === null)
			{
				// Transport/local error (no HTTP status) — keep as is.
				$mapped[] = $error;

				continue;
			}

			$errCode = match (true)
			{
				$platformCode === 'doc_full' => 'ERR-DOC-FULL',
				$platformCode === 'open_in_progress',
				$platformCode === 'saving_in_progress',
				$platformCode === 'failover_in_progress' => 'ERR-IN-PROGRESS',
				$httpStatus === 409 => 'ERR-IN-PROGRESS',
				$httpStatus === 403 => 'ERR-NO-GRANT',
				$httpStatus === 413 => 'ERR-FILE-TOO-LARGE',
				$httpStatus === 422 => 'ERR-UNSUPPORTED-FILETYPE',
				$httpStatus === 502 => 'ERR-PULL-FAILED',
				$httpStatus === 503 => 'ERR-NO-CAPACITY',
				$httpStatus === 401 => 'ERR-AUTH',
				default => 'ERR-OPEN-FAILED',
			};

			$mapped[] = new Error(
				$error->getMessage(),
				$errCode,
				[
					'httpStatus' => $httpStatus,
					'platformCode' => $platformCode,
				],
			);
		}

		return $mapped;
	}

	/**
	 * Splits a protocol-client open-error into [httpStatus, platformCode].
	 *
	 * httpStatus comes from Error::getCode() (the client stores the HTTP status
	 * there); platformCode is parsed from the trailing `Code: <code>.` the client
	 * appends to the message, or null when absent.
	 *
	 * @return array{0: int|null, 1: string|null}
	 */
	private function splitErrorCode(Error $error): array
	{
		$rawCode = $error->getCode();
		$httpStatus = is_numeric($rawCode) ? (int)$rawCode : null;
		if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599))
		{
			$httpStatus = null;
		}

		$platformCode = null;
		if (preg_match('/Code:\s*([a-z0-9_]+)\.?\s*$/i', $error->getMessage(), $matches))
		{
			$platformCode = $matches[1];
		}

		return [$httpStatus, $platformCode];
	}
}
