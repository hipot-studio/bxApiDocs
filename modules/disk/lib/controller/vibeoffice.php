<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Document;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Document\Vibeoffice\Clients\OpenFingerprint;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolCallContext;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClient;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\ConfiguredClientOriginProvider;
use Bitrix\Disk\Document\Vibeoffice\EditorConfigResponse;
use Bitrix\Disk\Document\Vibeoffice\EditorFailureReceiver;
use Bitrix\Disk\Document\Vibeoffice\EditorFailureReport;
use Bitrix\Disk\Document\Vibeoffice\Filters\EgressUrlSignatureCheck;
use Bitrix\Disk\Document\Vibeoffice\Filters\WebhookSignatureCheck;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\OpenFailureCode;
use Bitrix\Disk\Document\Vibeoffice\OpenRetryPolicy;
use Bitrix\Disk\Document\Vibeoffice\OpenAttemptState;
use Bitrix\Disk\Document\Vibeoffice\OpenRoutePolicy;
use Bitrix\Disk\Document\Vibeoffice\OpenSendRight;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizer;
use Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizerInterface;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\User;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
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
use Bitrix\Main\Response as MainResponse;
use Bitrix\Main\UuidGenerator;

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
	public const EVENT_HOST_PROBE = 'host.probe';

	/** TTL (seconds) of the host-signed egress URL the platform uses to pull the source. */
	private const EGRESS_URL_TTL = 600;
	private const SAVED_ACK_ACCEPT_HEADER = 'X-VO-Accept-Ack';
	private const SAVED_ACK_PROTOCOL = 'saved-apply-v1';


	/**
	 * Hard cap on version descriptors accepted per signVersionUrls call. The version-history
	 * panel deals in tens of versions; a larger array is bogus/abusive input. Bounds both the
	 * batch version load and the platform sign-urls payload.
	 */
	private const MAX_VERSIONS_PER_REQUEST = 100;

	/** Logger id of the open journal (ALG-RETRY); `.settings.php` configures its sink by this id. */
	private const OPEN_LOGGER_ID = 'disk.vibeoffice.open';

	/** A named-lock timeout of {@see Document\Vibeoffice\DocumentStateRepository}. */
	private const LOCK_TIMEOUT_ERROR_CODE = 'vibeoffice_lock_timeout';

	/**
	 * The temporary class of the host half of the open path, the counterpart of
	 * {@see OpenRetryPolicy::TEMPORARY_PLATFORM_CODES}: a timeout of the named lock of the document, a
	 * state transaction that did not go through, and a write of the metadata of the open that lost to a
	 * save of the same document. Every one of them means the document is busy right now, so the reader
	 * gets the screen of the branch of saving with a button of retry - the same answer the temporary
	 * refusals of the platform earn. A refusal of the registry that repeating cannot cure - a UUID bound
	 * to another session, a reservation that expired - keeps its own code and the generic screen.
	 * So does a transport failure without a verified answer: the open-error screen, its reservation
	 * stays pending for the next open to resume.
	 */
	private const TEMPORARY_HOST_CODES = [
		self::LOCK_TIMEOUT_ERROR_CODE,
		'vibeoffice_state_failed',
		SessionRegistry::ERROR_CODE_BUSY,
	];

	private const TERMINAL_OPEN_CODES = [
		'open_access_revoked',
		'open_session_expired',
		'open_session_closed',
		'open_identity_mismatch',
		'save_refused',
	];

	/** Value of `editor.ui.shell` of the open-body: the VibeOffice design of the DS image. */
	private const EDITOR_UI_SHELL = 'vibeoffice';

	private ?DocumentSession $openedDocumentSession = null;
	private ?\stdClass $openObservationScope = null;
	private bool $ownsEngineOpenObservationScope = false;
	private bool $observedLoadAction = false;
	private bool $openLoadActionStarted = false;
	private ?\stdClass $webhookObservationScope = null;
	private ?Document\Vibeoffice\Logging\WebhookRefusalLogLimiter $webhookRefusalLogLimiter = null;
	private ?Result $capacityFallbackOutcome = null;
	private ?Result $capacitySourceFailure = null;
	private ?Result $originalOpenFailure = null;
	private array $registeredOpenAttempts = [];
	private ?UnifiedLinkAccessLevel $unifiedLinkAccessLevel = null;
	/**
	 * The checked source of a short opening, handed in by the server render of the editor for one config of the
	 * unified link: never a parameter of an action, since that config is open to AJAX as well.
	 */
	private ?ShortLinkOpening $shortLinkOpening = null;

	private array $trustedOpening = [];

	/**
	 * Finalizations of open attempts made by this very controller, keyed by the Result of the open that
	 * produced them: the only proof a cancellation accepts. A Result built elsewhere is never in it.
	 */
	private ?\WeakMap $openFinalizations = null;

	// BX-29.1 keeps the overall opening budget parametric: only an explicit policy hands one in, and
	// the numeric total is owned by ROL-102. Without a budget the per-open limits of OpenRetryPolicy apply.
	private ?Document\Vibeoffice\OpeningBudget $openingBudget = null;

	public static function createOpenObservationScope(string $owner): \stdClass
	{
		$scope = new \stdClass();
		$scope->owner = $owner;
		$scope->startedAt = hrtime(true);
		$scope->attempts = 0;
		$scope->documentSession = null;
		$scope->platformSessionId = null;
		$scope->diagnosticContext = null;
		$scope->diagnosticContextInitialized = false;
		$scope->userId = null;
		$scope->result = null;
		$scope->code = null;
		$scope->emitted = false;
		$scope->configurationRequested = false;
		$scope->routingSucceeded = false;
		$scope->protocolContext = new ProtocolCallContext();
		$scope->protocolContext->beginOperation('open');

		return $scope;
	}

	public function setOpenObservationScope(\stdClass $scope): void
	{
		$this->openObservationScope = $scope;
		$this->ownsEngineOpenObservationScope = false;
	}

	public static function markOpenObservationFailure(
		?\stdClass $scope,
		string $code,
		bool $exception = false,
	): void
	{
		if ($scope === null || (($scope->result ?? null) === 'error' && !$exception))
		{
			return;
		}
		$scope->result = $exception ? 'error' : 'refused';
		$scope->code = $code;
	}

	public static function finishOpenObservationScope(\stdClass $scope): void
	{
		if ($scope->emitted)
		{
			return;
		}
		$scope->emitted = true;
		$scope->protocolContext->endOperation();
		if (($scope->routingSucceeded ?? false) && !($scope->configurationRequested ?? false)
			&& $scope->result === null)
		{
			return;
		}
		$result = $scope->result ?? 'error';
		$level = match ($result)
		{
			'ok' => 'info',
			'refused' => 'warning',
			default => 'error',
		};
		$session = $scope->documentSession;
		LoggerProvider::get(self::OPEN_LOGGER_ID)->log($level, 'vibeoffice open', [
			'result' => $result,
			'code' => $result === 'ok' ? null : ($scope->code ?? 'open_failed'),
			'attempts' => $scope->attempts,
			'document_session_id' => $session instanceof DocumentSession ? (int)$session->getId() : null,
			'session_id' => $scope->platformSessionId,
			'report_attempt_id' => $scope->diagnosticContext['report_attempt_id'] ?? null,
			'attempt_id_source' => $scope->diagnosticContext['attempt_id_source'] ?? null,
			'load_attempt_id' => $scope->diagnosticContext['load_attempt_id'] ?? null,
			'trace_id' => null,
			'user_id' => $scope->userId,
			'duration_ms' => max(0, (int)((hrtime(true) - $scope->startedAt) / 1_000_000)),
		]);
	}

	private function beginObservedConfigAction(DocumentSession $session, callable $action): ?array
	{
		$direct = $this->openObservationScope === null;
		if ($direct)
		{
			$this->openObservationScope = self::createOpenObservationScope('direct');
		}
		$scope = $this->openObservationScope;
		$scope->configurationRequested = true;
		$scope->documentSession = $session;
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		$scope->userId ??= $userId > 0 ? $userId : null;
		try
		{
			$result = $action();
			if (is_array($result) && !$this->getErrors())
			{
				if (!$scope->diagnosticContextInitialized)
				{
					$openConfig = is_array($result['openConfig'] ?? null) ? $result['openConfig'] : [];
					$scope->diagnosticContext = self::createEditorDiagnosticContext($openConfig);
					$scope->diagnosticContextInitialized = true;
				}
				$result['diagnosticContext'] = $scope->diagnosticContext;
				$scope->result = 'ok';
				$scope->code = null;
			}
			elseif ($scope->result !== 'error')
			{
				self::markOpenObservationFailure($scope, $this->safeOpenErrorCode($this->getErrors()));
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			self::markOpenObservationFailure($scope, 'open_exception', true);
			throw $exception;
		}
		finally
		{
			if ($direct)
			{
				self::finishOpenObservationScope($scope);
				$this->openObservationScope = null;
			}
		}
	}

	private static function createEditorDiagnosticContext(array $openConfig): ?array
	{
		$loadAttemptId = $openConfig['load_attempt_id'] ?? null;
		if (is_string($loadAttemptId)
			&& preg_match('/\A[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/D', $loadAttemptId) === 1)
		{
			$loadAttemptId = strtolower($loadAttemptId);

			return [
				'report_attempt_id' => $loadAttemptId,
				'attempt_id_source' => 'platform',
				'load_attempt_id' => $loadAttemptId,
			];
		}

		try
		{
			$reportAttemptId = UuidGenerator::generateV4();
		}
		catch (\Throwable)
		{
			return null;
		}

		return [
			'report_attempt_id' => $reportAttemptId,
			'attempt_id_source' => 'portal',
			'load_attempt_id' => null,
		];
	}

	private function safeOpenErrorCode(array $errors): string
	{
		foreach ($errors as $error)
		{
			if (!$error instanceof Error)
			{
				continue;
			}
			$code = (string)$error->getCode();
			foreach (OpenFailureCode::cases() as $failure)
			{
				if ($code === $failure->value)
				{
					return $code;
				}
			}
			if (in_array($code, [
				'vibeoffice_open_deadline', 'open_access_revoked', 'open_identity_mismatch',
				'vibeoffice_session_closed',
			], true))
			{
				return $code;
			}
		}

		return 'open_failed';
	}

	private function isObservedConfigAction(Action $action): bool
	{
		return in_array($action->getName(), [
			'getEditorConfig', 'getEditorConfigForUnifiedLink', 'getEditorConfigForExternalLink',
		], true);
	}

	private function isObservedLoadAction(Action $action): bool
	{
		return in_array($action->getName(), [
			'loadCreateDocumentEditor', 'loadDocumentEditor', 'loadDocumentViewer',
			'loadDocumentEditorByViewSession',
		], true);
	}

	private function observedOpenErrorCode(string $fallback): string
	{
		$code = $this->safeOpenErrorCode($this->getErrors());

		return $code === 'open_failed' ? $fallback : $code;
	}

	protected function processBeforeAction(Action $action)
	{
		$this->observedLoadAction = $this->isObservedLoadAction($action);
		$this->openLoadActionStarted = false;
		if ($this->isObservedConfigAction($action) || $this->observedLoadAction)
		{
			if ($this->openObservationScope === null)
			{
				$this->openObservationScope = self::createOpenObservationScope('engine');
				$this->ownsEngineOpenObservationScope = true;
			}
			if ($this->isObservedConfigAction($action))
			{
				$this->openObservationScope->configurationRequested = true;
			}
			$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
			$this->openObservationScope->userId ??= $userId > 0 ? $userId : null;
		}
		elseif ($action->getName() === 'webhook')
		{
			$this->webhookObservationScope = $this->createWebhookObservationScope();
		}

		return parent::processBeforeAction($action);
	}

	protected function runProcessingThrowable(\Throwable $throwable)
	{
		if ($this->openObservationScope !== null)
		{
			self::markOpenObservationFailure($this->openObservationScope, 'open_exception', true);
		}
		if ($this->webhookObservationScope !== null)
		{
			$this->webhookObservationScope->result = 'error';
			$this->webhookObservationScope->code = 'webhook_exception';
		}
		parent::runProcessingThrowable($throwable);
	}

	protected function processAfterAction(Action $action, $result)
	{
		$result = parent::processAfterAction($action, $result);
		$scope = $this->openObservationScope;
		if ($scope !== null && $scope->result !== 'error')
		{
			if ($this->isObservedConfigAction($action))
			{
				if (is_array($result) && !$this->getErrors())
				{
					$scope->result = 'ok';
					$scope->code = null;
				}
				else
				{
					self::markOpenObservationFailure($scope, $this->observedOpenErrorCode(
						$scope->result === null ? 'open_prefilter_refused' : 'open_failed',
					));
				}
			}
			elseif ($this->isObservedLoadAction($action))
			{
				if (!$result instanceof HttpResponse || $this->getErrors() || $result->getStatus() >= 400)
				{
					self::markOpenObservationFailure($scope, $this->observedOpenErrorCode(
						$this->openLoadActionStarted ? 'open_load_refused' : 'open_prefilter_refused',
					));
				}
				elseif ($scope->result === null && !($scope->configurationRequested ?? false))
				{
					$scope->routingSucceeded = true;
				}
			}
		}

		return $result;
	}

	public function finalizeResponse(MainResponse $response)
	{
		if ($this->ownsEngineOpenObservationScope && $this->openObservationScope !== null)
		{
			$scope = $this->openObservationScope;
			if ($scope->result !== 'error' && ($response->getStatus() >= 400 || $this->getErrors()))
			{
				$fallback = $this->observedLoadAction && $this->openLoadActionStarted
					? 'open_load_refused' : ($scope->code ?? 'open_prefilter_refused');
				self::markOpenObservationFailure($scope, $this->observedOpenErrorCode($fallback));
			}
			self::finishOpenObservationScope($scope);
			$this->openObservationScope = null;
			$this->ownsEngineOpenObservationScope = false;
		}
		if ($this->webhookObservationScope !== null)
		{
			$this->finishWebhookObservationScope($response);
		}
	}

	private function createWebhookObservationScope(): \stdClass
	{
		$request = Context::getCurrent()->getRequest();
		$deliveryId = self::normalizeWebhookDeliveryId($request->getHeader(WebhookSignatureCheck::HEADER_DELIVERY));
		$event = (string)$request->getHeader(WebhookSignatureCheck::HEADER_EVENT);

		return (object)[
			'startedAt' => hrtime(true),
			'deliveryId' => $deliveryId,
			'event' => $event === '' ? null : $event,
			'result' => null,
			'code' => null,
			'deliveryOutcome' => null,
			'authenticated' => false,
			'emitted' => false,
		];
	}

	private static function normalizeWebhookDeliveryId(mixed $raw): ?int
	{
		if (!is_string($raw) || preg_match('/^[0-9]+$/D', $raw) !== 1)
		{
			return null;
		}
		$id = (int)$raw;
		if ($id <= 0 || (string)$id !== ltrim($raw, '0'))
		{
			return null;
		}

		return $id;
	}

	private function safeWebhookErrorCode(): string
	{
		foreach ($this->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			if (in_array($code, ['invalid_http_method', 'invalid_content_type'], true))
			{
				return $code;
			}
		}

		return 'webhook_failed';
	}

	private function finishWebhookObservationScope(MainResponse $response): void
	{
		$scope = $this->webhookObservationScope;
		if ($scope->emitted)
		{
			return;
		}
		$scope->emitted = true;
		$status = $response->getStatus();
		if ($status === 401)
		{
			$scope->result = 'bad_signature';
			$scope->code = 'bad_signature';
		}
		elseif ($status >= 500)
		{
			$scope->result = 'error';
			$scope->code ??= 'webhook_failed';
		}
		elseif ($status >= 400)
		{
			$scope->result = 'refused';
			$scope->code ??= match ($status)
			{
				405 => 'invalid_http_method',
				415 => 'invalid_content_type',
				default => 'webhook_failed',
			};
		}
		elseif ($scope->result === null)
		{
			$scope->result = $this->getErrors() ? 'refused' : 'applied';
			$scope->code ??= $scope->result === 'applied' ? null : $this->safeWebhookErrorCode();
		}
		if (
			$status >= 400
			&& !($scope->authenticated ?? false)
			&& !($this->webhookRefusalLogLimiter ??= new Document\Vibeoffice\Logging\WebhookRefusalLogLimiter())->allow()
		)
		{
			$this->webhookObservationScope = null;

			return;
		}
		$level = match ($scope->result)
		{
			'applied', 'duplicate', 'ignored' => 'info',
			'error' => 'error',
			default => 'warning',
		};
		LoggerProvider::get('disk.vibeoffice.webhook')->log($level, 'vibeoffice webhook received', [
			'delivery_id' => $scope->deliveryId,
			'event' => $scope->event,
			'result' => $scope->result,
			'code' => $scope->code,
			'delivery_outcome' => $scope->deliveryOutcome,
			'httpStatus' => $status,
			'duration_ms' => max(0, (int)((hrtime(true) - $scope->startedAt) / 1_000_000)),
		]);
		$this->webhookObservationScope = null;
	}

	public function setOpeningBudget(Document\Vibeoffice\OpeningBudget $budget): void
	{
		$this->openingBudget ??= $budget;
	}

	/**
	 * The source of a short opening for the next config of the unified link: its access is then the effective
	 * right of the employee {@see ShortLinkOpening::getEffectiveAccessLevel()}. The config drops it once built.
	 */
	public function setShortLinkOpening(?ShortLinkOpening $shortLinkOpening): void
	{
		$this->shortLinkOpening = $shortLinkOpening;
	}

	public function getOpenedDocumentSession(): ?DocumentSession
	{
		return $this->openedDocumentSession;
	}

	/** Internal request outcome; never part of an action response or error custom data. */
	public function getCapacityFallbackOutcome(): ?Result
	{
		return $this->capacityFallbackOutcome;
	}

	private function resetCapacityFallback(): void
	{
		$this->capacityFallbackOutcome = null;
		$this->capacitySourceFailure = null;
		$this->originalOpenFailure = null;
		$this->registeredOpenAttempts = [];
	}

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
				},
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
				// turn it into an edit session (the manager attributes the edit session to the
				// current user). Same owner+hash guard as OnlyOffice.
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
			'reportEditorFailure' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'getSavedRefusalReason' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'getSavedRefusalReasonForExternalLink' => [
				'prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new Csrf(false),
					new Document\Vibeoffice\Filters\VibeofficeEnabled(),
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
						(string)$this->getConfiguration()->getApiSecret(),
						$this->getConfiguration()->getPreviousApiSecret(),
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
						(string)$this->getConfiguration()->getWebhookSecret(),
						$this->getConfiguration()->getPreviousWebhookSecret(),
					),
				],
			],
		];
	}

	public function reportEditorFailureAction(JsonPayload $payload): ?array
	{
		$request = Context::getCurrent()->getRequest();
		$contentType = $request->getHeader('Content-Type');
		$raw = $payload->getRaw();
		if (
			!is_string($contentType)
			|| preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*[A-Za-z0-9_-]+)?\s*\z/iD', $contentType) !== 1
			|| !is_string($raw)
			|| $raw === ''
			|| strlen($raw) > 4096
		)
		{
			$this->addError(new Error('Invalid editor failure report.', 'editor_failure_invalid_payload'));

			return null;
		}

		try
		{
			$decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
			if (!is_object($decoded))
			{
				throw new \InvalidArgumentException();
			}
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			$report = EditorFailureReport::fromArray($data);
		}
		catch (\JsonException | \InvalidArgumentException)
		{
			$this->addError(new Error('Invalid editor failure report.', 'editor_failure_invalid_payload'));

			return null;
		}

		$userId = (int)User::resolveUserId($this->getCurrentUser());
		$accepted = (new EditorFailureReceiver())->receive($report, $userId);
		if ($accepted === null)
		{
			$this->addError(new Error('Document session unavailable.', 'editor_failure_session_unavailable'));

			return null;
		}

		return ['accepted' => $accepted];
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
	 * We answer 2xx only AFTER the processing succeeds — for `document.saved` this means
	 * after the durable delivery receipt proves the working copy was persisted as a new Disk
	 * version. A caller that sends `X-VO-Accept-Ack: saved-apply-v1` receives that receipt;
	 * callers without the negotiation header retain the bare `{}` response.
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
		$direct = $this->webhookObservationScope === null;
		if ($direct)
		{
			$this->webhookObservationScope = $this->createWebhookObservationScope();
		}
		try
		{
			$this->webhookObservationScope->authenticated = true;

			return $this->processWebhookAction($payload);
		}
		finally
		{
			if ($direct)
			{
				$this->finishWebhookObservationScope(Context::getCurrent()->getResponse());
			}
		}
	}

	private function processWebhookAction(JsonPayload $payload): ?Response\Json
	{
		$request = Context::getCurrent()->getRequest();
		$response = Context::getCurrent()->getResponse();

		$deliveryId = self::normalizeWebhookDeliveryId(
			$request->getHeader(WebhookSignatureCheck::HEADER_DELIVERY),
		);
		$event = (string)$request->getHeader(WebhookSignatureCheck::HEADER_EVENT);
		if ($this->webhookObservationScope !== null)
		{
			$this->webhookObservationScope->deliveryId = $deliveryId;
			$this->webhookObservationScope->event = $event === '' ? null : $event;
		}

		if ($deliveryId === null || $event === '')
		{
			$response->setStatus('400 Bad Request');
			$this->webhookObservationScope->result = 'refused';
			$this->webhookObservationScope->code = 'invalid_delivery';
			$this->addError(new Error('Missing or invalid webhook delivery headers.', 'validation'));

			return null;
		}

		$payloadData = $this->decodeWebhookBody($payload);
		if ($payloadData === null)
		{
			$response->setStatus('400 Bad Request');
			$this->webhookObservationScope->result = 'refused';
			$this->webhookObservationScope->code = 'invalid_payload';
			$this->addError(new Error('Malformed webhook body.', 'validation'));

			return null;
		}

		if ($event === self::EVENT_HOST_PROBE)
		{
			return new Response\Json(new \stdClass());
		}

		try
		{
			$processor = new WebhookProcessor();
			$result = $processor->process($deliveryId, $event, $payloadData);
		}
		catch (\Throwable $e)
		{
			// A thrown exception (deadlock, DB failure, etc.) would otherwise be caught by the
			// Engine and returned as an error envelope with HTTP 200 — which the platform reads
			// as accepted and never retries, silently losing the delivery. Force a non-2xx so
			// the (idempotent) delivery is retried; platform retry is bound to the HTTP status.
			$response->setStatus('500 Internal Server Error');
			$this->webhookObservationScope->result = 'error';
			$this->webhookObservationScope->code = 'webhook_exception';
			$this->addError(new Error($e->getMessage(), 'processing'));

			return null;
		}

		if ($this->webhookObservationScope !== null)
		{
			$observation = $processor->getDeliveryObservation();
			$this->webhookObservationScope->result = $observation['result'];
			$this->webhookObservationScope->deliveryOutcome = $observation['outcome'];
		}
		if (!$result->isSuccess())
		{
			// Do NOT acknowledge: a bare error envelope keeps HTTP 200, which the platform
			// reads as accepted. Force a non-2xx so the (idempotent) delivery is retried.
			$response->setStatus('500 Internal Server Error');
			$this->webhookObservationScope->result = 'error';
			$this->webhookObservationScope->code = 'webhook_failed';
			$this->addErrors($result->getErrors());

			return null;
		}
		if ($event === WebhookProcessor::EVENT_SAVED)
		{
			$ack = $result->getData()['ack'] ?? null;
			if (!is_array($ack))
			{
				$response->setStatus('500 Internal Server Error');
				$this->webhookObservationScope->result = 'error';
				$this->webhookObservationScope->code = 'vibeoffice_saved_not_applied';
				$this->addError(new Error('Saved content is not committed.', 'vibeoffice_saved_not_applied'));

				return null;
			}
			if ((string)$request->getHeader(self::SAVED_ACK_ACCEPT_HEADER) === self::SAVED_ACK_PROTOCOL)
			{
				return new Response\Json(['ack' => $ack]);
			}
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
		$this->openLoadActionStarted = true;
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
		?Disk\File $object = null,
		?Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
	): ?HttpResponse
	{
		$this->openLoadActionStarted = true;
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

		$file = $object ?? $attachedObject?->getFile();
		$selection = $file ? OpenRoutePolicy::select($file, null, $attachedObject) : null;
		if ($selection === null)
		{
			return $this->showNotFoundPageAction();
		}
		$type = $selection['editable']
			? DocumentSession::TYPE_EDIT
			: DocumentSession::TYPE_VIEW;

		return $this->loadDocumentEditor($object, $selection['version'], $attachedObject, $type, $editorMode);
	}

	public function loadDocumentViewerAction(
		?Disk\File $object = null,
		?Disk\Version $version = null,
		?Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
	): ?HttpResponse
	{
		$this->openLoadActionStarted = true;
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
		elseif ($attachedObject && $this->isSelectedContentCoveredByAttachedObject($attachedObject, $version))
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

		$file = $object ?? $attachedObject?->getFile();
		$selection = $file ? OpenRoutePolicy::select($file, $version, $attachedObject) : null;
		if ($selection === null)
		{
			return $this->showNotFoundPageAction();
		}

		return $this->loadDocumentEditor(
			$object,
			$selection['version'],
			$attachedObject,
			DocumentSession::TYPE_VIEW,
			$editorMode,
		);
	}

	private function isSelectedContentCoveredByAttachedObject(
		Disk\AttachedObject $attachedObject,
		?Disk\Version $version,
	): bool
	{
		if ($attachedObject->isSpecificVersion())
		{
			return $version === null
				|| (int)$attachedObject->getVersionId() === (int)$version->getId();
		}

		return $version === null;
	}

	public function loadDocumentEditorByViewSessionAction(Models\DocumentSession $documentSession): ?HttpResponse
	{
		$this->openLoadActionStarted = true;
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

		$selection = OpenRoutePolicy::selectSession($documentSession);
		if ($selection === null || !$selection['editable'])
		{
			return $this->showNotFoundPageAction();
		}

		$sessionManager = $this->getSessionManager();
		$sessionManager
			->setUserId((int)$this->getCurrentUser()->getId())
			->setSessionType(DocumentSession::TYPE_EDIT)
			->setSessionContext($documentSession->getContext())
			->setFile($documentSession->getFile())
			->setVersion($selection['version'])
			->setAttachedObject($documentSession->getContext()?->getAttachedObject())
		;
		if (!$sessionManager->lock())
		{
			$this->addError(new Error('Could not getting lock for the session.'));

			return null;
		}

		try
		{
			$createdSession = $sessionManager->findOrCreateSession();
			if (!$createdSession)
			{
				$this->addErrors($sessionManager->getErrors());

				return null;
			}
		}
		finally
		{
			$sessionManager->unlock();
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
		return $this->redirectToShell($createdSession, $sessionManager->getRestrictionOutcome());
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

		if (!$file instanceof Disk\File)
		{
			$this->addError(new Error('Could not find the file (content).'));

			return null;
		}

		$selection = OpenRoutePolicy::select($file, $version, $attachedObject);
		if ($selection === null)
		{
			return $this->showNotFoundPageAction();
		}
		if ($type === DocumentSession::TYPE_EDIT && !$selection['editable'])
		{
			$type = DocumentSession::TYPE_VIEW;
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
			->setUserId((int)$this->getCurrentUser()->getId())
			->setSessionType($type)
			->setSessionContext($documentSessionContext)
			->setFile($object)
			->setVersion($selection['version'])
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

		$restrictionOutcome = $sessionManager->getRestrictionOutcome();

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

		// Register after the response, in the same request that successfully created the session.
		Application::getInstance()->addBackgroundJob([Document\Vibeoffice\DrainService::class, 'register']);

		// Redirect into the editor shell-component carrying only the session id/hash (as
		// OnlyOffice does). The platform `open`/join and DTO-EDITORCONFIG delivery are deferred
		// to `getEditorConfig` (below), called lazily by the shell once it loads: the `open` runs
		// once and is idempotent for a live session (platform returns `joined:true`, no re-pull).
		$this->trustedOpening = [
			'channel' => 'internal',
			'objectId' => (int)$file->getId(),
			'attachedObjectId' => (int)$attachedObject?->getId(),
			'versionId' => (int)$documentSession->getVersionId(),
			'type' => $documentSession->getType(),
			'editorMode' => $editorMode,
			'openingBudget' => $this->openingBudget?->toArray(),
			'budgetIssuedAt' => microtime(true),
		];

		return $this->redirectToShell($documentSession, $restrictionOutcome);
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
		return $this->beginObservedConfigAction(
			$documentSession,
			fn(): ?array => $this->getEditorConfigInternal($documentSession),
		);
	}

	private function getEditorConfigInternal(Models\DocumentSession $documentSession): ?array
	{
		$this->resetCapacityFallback();
		$this->openedDocumentSession = null;

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

		return $this->buildEditorConfig($documentSession, $this->canDownloadByCurrentUser($documentSession));
	}

	public function getSavedRefusalReasonAction(int $documentSessionId, string $documentSessionHash): ?array
	{
		$documentSession = $this->loadExactRefusalSession($documentSessionId, $documentSessionHash);
		$currentUserId = User::resolveUserId($this->getCurrentUser());
		if (!$documentSession || !$documentSession->isEdit() || !$currentUserId
			|| !$documentSession->belongsToUser($currentUserId)
			|| $documentSession->getContext()?->getExternalLink() instanceof ExternalLink
			|| !$documentSession->getFile()
			|| (!$this->canAccessSession($documentSession)
				&& !$this->resolveUnifiedLinkAccessLevel($documentSession)->canEdit()))
		{
			return $this->showNotFoundConfig();
		}

		return ['reason' => DeliveryTable::findLatestSafeRefusalReason(HostDocumentKey::resolve($documentSession))];
	}

	public function getSavedRefusalReasonForExternalLinkAction(
		int $documentSessionId,
		string $documentSessionHash,
	): ?array
	{
		$documentSession = $this->loadExactRefusalSession($documentSessionId, $documentSessionHash);
		$link = $documentSession?->getContext()?->getExternalLink();
		if (!$documentSession?->isEdit() || !($link instanceof ExternalLink)
			|| !$link->allowEdit() || !$this->canAccessExternalSession($documentSession, $link))
		{
			return $this->showNotFoundConfig();
		}

		return ['reason' => DeliveryTable::findLatestSafeRefusalReason(HostDocumentKey::resolve($documentSession))];
	}

	private function loadExactRefusalSession(int $documentSessionId, string $documentSessionHash): ?DocumentSession
	{
		if ($documentSessionId <= 0 || $documentSessionHash === '')
		{
			return null;
		}
		$session = DocumentSession::loadById($documentSessionId);
		if (!$session || $session->getService() !== Models\DocumentService::Vibeoffice
			|| !hash_equals($session->getExternalHash(), $documentSessionHash))
		{
			return null;
		}

		return $session;
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
		return $this->beginObservedConfigAction(
			$documentSession,
			fn(): ?array => $this->getEditorConfigForExternalLinkInternal($documentSession),
		);
	}

	private function getEditorConfigForExternalLinkInternal(Models\DocumentSession $documentSession): ?array
	{
		$this->resetCapacityFallback();
		$this->openedDocumentSession = null;

		if (!$documentSession->getId())
		{
			return $this->showNotFoundConfig();
		}

		$link = $documentSession->getContext()?->getExternalLink();
		if (!($link instanceof ExternalLink) || !$this->canAccessExternalSession($documentSession, $link))
		{
			return $this->showNotFoundConfig();
		}

		// External-link sessions bypass loadDocumentEditor, so queue registration here in the same request.
		Application::getInstance()->addBackgroundJob([Document\Vibeoffice\DrainService::class, 'register']);

		// An edit session is only admissible while the link still allows editing; otherwise it is
		// degraded to a view session (mirrors DocumentSession::canEdit and shouldDowngradeToView),
		// so the anonymous visitor never gets an edit config wider than the link.
		if ($documentSession->isEdit() && !$link->allowEdit())
		{
			$documentSession = $this->findViewSession($documentSession);
			if (!$documentSession)
			{
				return null;
			}
		}

		return $this->buildEditorConfig(
			documentSession: $documentSession,
			canDownload: $this->canDownloadByExternalLink($documentSession, $link),
			authorizedExternalLinkId: (int)$link->getId(),
		);
	}

	/**
	 * The unified-link config channel for an internal (portal) office document opened through a
	 * unified link. This is the vibeoffice analog of the OnlyOffice unified-link shell, which builds
	 * its config inline while relying on the same {@see UnifiedLinkAccessService} for the edit/view
	 * decision ({@see \CDiskFileEditorOnlyOfficeComponent::isEditAllowed()}) and on the upstream
	 * {@see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer} gate.
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
		try
		{
			return $this->beginObservedConfigAction(
				$documentSession,
				fn(): ?array => $this->getEditorConfigForUnifiedLinkInternal($documentSession),
			);
		}
		finally
		{
			$this->shortLinkOpening = null;
		}
	}

	private function getEditorConfigForUnifiedLinkInternal(Models\DocumentSession $documentSession): ?array
	{
		$this->resetCapacityFallback();
		$this->openedDocumentSession = null;

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

		$accessLevel = $this->resolveConfigAccessLevel($documentSession);
		if (!$accessLevel->canRead())
		{
			return $this->showNotFoundConfig();
		}

		$attachedObject = $documentSession->getContext()?->getAttachedObject();
		$attachedFile = $attachedObject?->getFile();
		$sessionFile = $documentSession->getFile();
		if ($attachedObject
			&& (!$attachedFile || !$sessionFile
				|| (int)$attachedFile->getRealObjectId() !== (int)$sessionFile->getRealObjectId()
				|| !$this->isSelectedContentCoveredByAttachedObject($attachedObject, $documentSession->getVersion()))
			&& !$this->canReadFileByCurrentUser($documentSession)
		)
		{
			return $this->showNotFoundConfig();
		}

		// An edit session is only admissible while the unified link still grants editing; otherwise it
		// is degraded to a view session (mirrors getEditorConfigForExternalLinkAction), so the user
		// never gets an edit config wider than the unified link.
		if ($documentSession->isEdit() && !$accessLevel->canEdit())
		{
			$documentSession = $this->findViewSession($documentSession);
			if (!$documentSession)
			{
				return null;
			}
		}

		$this->unifiedLinkAccessLevel = $accessLevel;
		try
		{
			return $this->buildEditorConfig(
				documentSession: $documentSession,
				canDownload: $this->canDownloadByUnifiedLink($documentSession, $accessLevel),
			);
		}
		finally
		{
			$this->unifiedLinkAccessLevel = null;
		}
	}

	/**
	 * Resolves the unified-link access level of the current user on the session's file, delegating to
	 * the shared {@see UnifiedLinkAccessService} (the same service the OnlyOffice/vibeoffice shells and
	 * the {@see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer} use). The
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
		$version = $documentSession->getVersion();
		if ($documentSession->isVersion() && $version === null)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$accessLevel = $this->getUnifiedLinkAccessService()->check($object, $attachedObject, version: $version);

		return $version !== null && $accessLevel === UnifiedLinkAccessLevel::Edit
			? UnifiedLinkAccessLevel::Read
			: $accessLevel;
	}

	/**
	 * The access a config of the unified link and the check before the open read: the effective right of the
	 * employee by the source of a short opening when one is handed in, the chain of the file otherwise. A source
	 * that fails its check, or one of another file or revision, is a refusal.
	 */
	private function resolveConfigAccessLevel(DocumentSession $documentSession): UnifiedLinkAccessLevel
	{
		if ($this->shortLinkOpening === null)
		{
			return $this->resolveUnifiedLinkAccessLevel($documentSession);
		}

		$object = $documentSession->getObject();
		if (
			!$object
			|| (int)$object->getRealObjectId() !== (int)$this->shortLinkOpening->getFile()->getRealObjectId()
			|| (int)$documentSession->getVersionId() !== (int)$this->shortLinkOpening->getVersion()?->getId()
		)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return $this->shortLinkOpening->getEffectiveAccessLevel((int)$this->getCurrentUser()?->getId())
			?? UnifiedLinkAccessLevel::Denied
		;
	}

	private function getUnifiedLinkAccessService(): UnifiedLinkAccessService
	{
		return ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	/**
	 * Shared open flow behind both config channels ({@see self::getEditorConfigAction()} and
	 * {@see self::getEditorConfigForExternalLinkAction()}): the file guard, the fresh-open vs.
	 * version-stream decision, the open retry cycle with the downgrade after it and the restriction
	 * payload. It runs on an already-authorized session, so authorization (a `CurrentUser` re-check
	 * vs. an ExternalLink check) and the pre-open edit->view downgrade stay in the callers; the
	 * open-body itself is built from whichever identity the session carries
	 * ({@see self::buildOpenBody()} branches on the session's external link, never forcing
	 * a `CurrentUser`).
	 */
	private function buildEditorConfig(
		DocumentSession $documentSession,
		bool $canDownload,
		?int $authorizedExternalLinkId = null,
	): ?array
	{
		$this->resetCapacityFallback();
		$this->openedDocumentSession = null;

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
		$selection = OpenRoutePolicy::selectSession($documentSession);
		if ($selection === null)
		{
			return $this->showNotFoundConfig();
		}

		if ($documentSession->isEdit() && !$selection['editable'])
		{
			$documentSession = $this->findViewSession($documentSession);
			if (!$documentSession)
			{
				return null;
			}
		}

		if ($this->openObservationScope !== null)
		{
			$this->openObservationScope->documentSession = $documentSession;
		}

		if ($this->isSelectedContentTooLarge($documentSession, $file))
		{
			$this->addError(new Error(
				'The document exceeds the file size limit.',
				OpenFailureCode::FileTooLarge->value,
				['responseVerified' => false, 'requestSent' => false, 'preflight' => true],
			));

			return null;
		}

		// The document source (`document.url`/`document.filetype`) is always sent: for a
		// version-bound session it points at that version's content, and on a join of a
		// live session the platform ignores `document.url` anyway, so the current-file
		// behaviour is unchanged.
		$withDocumentSource = true;

		// An HTTP hit opens once and never sleeps: a temporary refusal becomes the "saving" screen, whose
		// client repeats the open after Retry-After.
		$openResult = $this->openDocumentSession(
			documentSession: $documentSession,
			file: $file,
			withDocumentSource: $withDocumentSource,
			canDownload: $canDownload,
			policy: OpenRetryPolicy::singleAttempt(),
		);
		if (!$openResult->isSuccess())
		{
			if ($documentSession->isEdit() && $this->isVerifiedCapacityFailure($this->originalOpenFailure))
			{
				return $this->buildCapacityFallbackConfig(
					$documentSession, $file, $canDownload, $authorizedExternalLinkId,
				);
			}
			if ($documentSession->isEdit() && $this->shouldDowngradeToView($openResult))
			{
				$documentSession = $this->findViewSession($documentSession);
				if (!$documentSession)
				{
					return null;
				}

				// A separate VIEW opens once as well.
				$openResult = $this->openDocumentSession(
					documentSession: $documentSession,
					file: $file,
					withDocumentSource: $withDocumentSource,
					canDownload: $canDownload,
					policy: OpenRetryPolicy::singleAttempt(),
				);
			}

			if (!$openResult->isSuccess())
			{
				$this->addOpenErrors($openResult->getErrors());

				return null;
			}
		}

		$editorConfig = $openResult->getData()['editorConfig'] ?? null;
		if (!$editorConfig instanceof EditorConfigResponse)
		{
			return null;
		}

		$this->openedDocumentSession = $documentSession;

		return $editorConfig->toArray() + [
			'openingBudget' => $this->openingBudget?->toArray(),
		];
	}

	private function isVerifiedCapacityFailure(?Result $result): bool
	{
		if ($result === null || $result->isSuccess())
		{
			return false;
		}
		foreach ($result->getErrors() as $error)
		{
			$data = $error->getCustomData();
			if ($this->normalizeHttpStatus($error->getCode()) === 503 && is_array($data)
				&& ($data['responseVerified'] ?? null) === true
				&& $this->normalizeHttpStatus($data['httpStatus'] ?? null) === 503
				&& ($data['platformCode'] ?? null) === 'no_capacity')
			{
				return true;
			}
		}

		return false;
	}

	private function buildCapacityFallbackConfig(
		DocumentSession $source,
		Disk\File $file,
		bool $canDownload,
		?int $authorizedExternalLinkId = null,
	): ?array
	{
		$this->capacitySourceFailure = $this->originalOpenFailure;
		$sourceEdit = Document\Vibeoffice\DocumentSessionManager::snapshotSession($source);
		$outcome = $this->capacityFallbackOutcome = (new Result())->setData([
			'reason' => 'capacity', 'sourceEdit' => $sourceEdit, 'attemptedType' => DocumentSession::TYPE_VIEW,
			'view' => null, 'selection' => 'none', 'ownedRows' => [], 'ownedOpenResources' => [],
			'cleanup' => new Result(),
		]);
		$viewPrepared = false;
		try
		{
			$view = $this->findViewSession($source, true);
			if ($view === null)
			{
				$data = $outcome->getData();
				$outcome->setData(array_replace($data, [
					'view' => null,
					'selection' => $data['selection'] === 'new' ? 'partial' : $data['selection'],
				]));
				if ($outcome->isSuccess())
				{
					$outcome->addError(new Error('Could not prepare the Vibeoffice view.', 'vibeoffice_view_failed'));
				}

				return null;
			}
			$outcome->setData(array_replace($outcome->getData(), ['view' => $view]));
			if ($this->openObservationScope !== null)
			{
				$this->openObservationScope->documentSession = $view;
			}
			$viewFile = $view->getFile();
			if (!$viewFile || !$view->isView() || OpenRoutePolicy::selectSession($view) === null)
			{
				$outcome->addError(new Error('Could not select the Vibeoffice view.', 'not_found'));

				return null;
			}
			$viewPrepared = true;
			$open = $this->openDocumentSession(
				$view, $viewFile, true, $canDownload, OpenRetryPolicy::singleAttempt(), restartClosedOnce: false,
			);
			if (!$open->isSuccess())
			{
				$outcome->addErrors($open->getErrors());

				return null;
			}
			$config = $open->getData()['editorConfig'] ?? null;
			if (!$config instanceof EditorConfigResponse)
			{
				$outcome->addError(new Error('Could not read the Vibeoffice view configuration.', 'vibeoffice_view_failed'));

				return null;
			}
			$this->openedDocumentSession = $view;

			return $config->toArray() + ['openingBudget' => $this->openingBudget?->toArray()];
		}
		catch (\Throwable)
		{
			if (!$viewPrepared)
			{
				$data = $outcome->getData();
				$outcome->setData(array_replace($data, [
					'view' => null, 'selection' => $data['selection'] === 'new' ? 'partial' : $data['selection'],
				]));
			}
			$outcome->addError(new Error('Could not prepare the Vibeoffice view.', 'vibeoffice_view_failed'));

			return null;
		}
		finally
		{
			$outcome->setData(array_replace($outcome->getData(), [
				'ownedOpenResources' => $this->registeredOpenAttempts,
				'cleanup' => $this->releaseCapacityFallbackAttempts($sourceEdit, $outcome, $authorizedExternalLinkId),
			]));
			if (!$outcome->isSuccess())
			{
				$this->addOpenErrors($outcome->getErrors());
			}
		}
	}

	private function releaseCapacityFallbackAttempts(
		array $sourceEdit,
		Result $outcome,
		?int $authorizedExternalLinkId = null,
	): Result
	{
		$currentUserId = (int)User::resolveUserId($this->getCurrentUser());
		$canReleaseSource = $authorizedExternalLinkId !== null
			? $authorizedExternalLinkId > 0 && $sourceEdit['externalLinkId'] === $authorizedExternalLinkId
			: $currentUserId > 0 && $sourceEdit['userId'] === $currentUserId;
		$candidates = [[$sourceEdit, 'EDIT']];
		if (!$outcome->isSuccess())
		{
			foreach ($outcome->getData()['ownedRows'] as $row)
			{
				$candidates[] = [$row, 'VIEW'];
			}
		}
		$cleanup = new Result();
		$statuses = [];
		$finalizer = new TerminalSessionFinalizer();
		foreach ($candidates as [$snapshot, $kind])
		{
			$tokens = [];
			foreach ($this->registeredOpenAttempts as $resource)
			{
				if ($resource['sessionId'] === $snapshot['sessionId'])
				{
					$tokens[] = $resource['openToken'];
				}
			}
			foreach ($tokens ?: [null] as $token)
			{
				$result = $finalizer->releaseRefusedCapacityAttempt($snapshot + [
					'kind' => $kind, 'selection' => $kind === 'VIEW' ? 'new' : 'reused',
					'refused' => true, 'openToken' => $token,
					'preserveLocalSession' => $kind === 'EDIT' && !$canReleaseSource,
				]);
				$statuses[] = $result->getData();
				$cleanup->addErrors($result->getErrors());
			}
		}

		return $cleanup->setData(['attempts' => $statuses]);
	}

	private function isSelectedContentTooLarge(DocumentSession $documentSession, Disk\File $file): bool
	{
		$limit = $this->getConfiguration()->getMaxFileSize();
		if ($limit <= 0)
		{
			return false;
		}

		$version = $documentSession->getVersionId() > 0 ? $documentSession->getVersion() : null;
		$selectedSize = $version ? (int)$version->getSize() : (int)$file->getSize();

		return $selectedSize > $limit;
	}

	private function findViewSession(DocumentSession $documentSession, bool $capacityFallback = false): ?DocumentSession
	{
		if ($documentSession->getService() !== Models\DocumentService::Vibeoffice)
		{
			$this->showNotFoundConfig();

			return null;
		}

		$context = $documentSession->getContext();
		$file = $documentSession->getFile();
		if (!$context || !$file)
		{
			$this->showNotFoundConfig();

			return null;
		}

		$manager = $this->getSessionManager();
		$manager
			->setSessionType(DocumentSession::TYPE_VIEW)
			->setUserId($context->getExternalLink() instanceof ExternalLink
				? $documentSession->getUserId()
				: (int)$this->getCurrentUser()->getId(),
			)
			->setFile($file)
			->setVersion($documentSession->getVersion())
			->setAttachedObject($context->getAttachedObject())
			->setSessionContext($context)
		;
		if ($capacityFallback)
		{
			$selection = $manager->findOrCreateSessionWithOwnership(true);
			$data = $selection->getData();
			$viewSession = $data['session'];
			if ($viewSession !== null && $data['selection'] === 'reused'
				&& !$this->hasCompatibleCapacityViewContext($documentSession, $viewSession))
			{
				// A lookup may find a live VIEW opened through another attachment. Create our
				// requested context without changing that row; addSession pins its INSERT atomically.
				$viewSession = $manager->addSession();
				$data = [
					'session' => $viewSession,
					'selection' => $viewSession === null ? 'none' : 'new',
					'ownedRows' => $viewSession === null
						? [] : [Document\Vibeoffice\DocumentSessionManager::snapshotSession($viewSession)],
				];
				if ($viewSession === null)
				{
					$this->capacityFallbackOutcome->addErrors($manager->getErrors());
				}
			}
			// Keep the receipt before metadata persistence or a reload can lose the object.
			$this->capacityFallbackOutcome->setData(array_replace($this->capacityFallbackOutcome->getData(), [
				'view' => $viewSession,
				'selection' => $data['selection'],
				'ownedRows' => $data['ownedRows'],
			]));
			if (!$selection->isSuccess())
			{
				$this->capacityFallbackOutcome->addErrors($selection->getErrors());
			}
		}
		else
		{
			$viewSession = $manager->findOrCreateSession(true);
		}
		if (!$viewSession)
		{
			if (!$capacityFallback)
			{
				$this->addErrors($manager->getErrors());
			}

			return null;
		}

		$editorModeKey = Document\Vibeoffice\DocumentSessionManager::CONTEXT_VO_EDITOR_MODE_KEY;
		$editorMode = Document\Vibeoffice\DocumentSessionManager::extractVoMetadata($documentSession)[$editorModeKey] ?? null;
		if ($editorMode !== null)
		{
			if (!$manager->persistSessionMetadata($viewSession, [$editorModeKey => $editorMode]))
			{
				$error = new Error('Could not persist the Vibeoffice view mode.', SessionRegistry::ERROR_CODE_BUSY);
				if ($capacityFallback)
				{
					$this->capacityFallbackOutcome->addError($error);
				}
				else
				{
					$this->addOpenErrors([$error]);
				}

				return null;
			}

			$viewSession = DocumentSession::loadById($viewSession->getId());
		}

		return $viewSession;
	}

	private function hasCompatibleCapacityViewContext(DocumentSession $source, DocumentSession $view): bool
	{
		$sourceContext = $source->getContext();
		$viewContext = $view->getContext();
		$modeKey = Document\Vibeoffice\DocumentSessionManager::CONTEXT_VO_EDITOR_MODE_KEY;
		$usual = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL;

		return $sourceContext !== null && $viewContext !== null
			&& $sourceContext->getObjectId() === $viewContext->getObjectId()
			&& $sourceContext->getAttachedObjectId() === $viewContext->getAttachedObjectId()
			&& $sourceContext->getExternalLinkId() === $viewContext->getExternalLinkId()
			&& (Document\Vibeoffice\DocumentSessionManager::extractVoMetadata($source)[$modeKey] ?? $usual)
				=== (Document\Vibeoffice\DocumentSessionManager::extractVoMetadata($view)[$modeKey] ?? $usual);
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
		$file = $documentSession->getFile();
		$externalLinkObject = $link->getObject();
		if (!$file || $file->isDeleted() || !$externalLinkObject || $externalLinkObject->isDeleted())
		{
			return false;
		}

		if (!$this->isFileCoveredByExternalLink($file, $externalLinkObject))
		{
			return false;
		}

		if (!$this->canOpenExternalLinkByPortalPolicy($link))
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

	private function isFileCoveredByExternalLink(Disk\File $file, Disk\BaseObject $externalLinkObject): bool
	{
		$fileId = (int)$file->getRealObjectId();
		$externalLinkObjectId = (int)$externalLinkObject->getRealObjectId();
		if ($fileId === $externalLinkObjectId)
		{
			return true;
		}

		if (!$externalLinkObject instanceof Folder)
		{
			return false;
		}

		return (bool)ObjectPathTable::query()
			->setSelect(['ID'])
			->where('OBJECT_ID', $fileId)
			->where('PARENT_ID', $externalLinkObjectId)
			->setLimit(1)
			->fetch()
		;
	}

	private function canDownloadByCurrentUser(DocumentSession $documentSession): bool
	{
		return $this->canDownloadBySecurityContext($documentSession);
	}

	private function canReadFileByCurrentUser(DocumentSession $documentSession): bool
	{
		$file = $documentSession->getFile();
		$storage = $file?->getStorage();
		if (!$file || !$storage)
		{
			return false;
		}

		return $file->canRead($storage->getCurrentUserSecurityContext());
	}

	private function canDownloadByExternalLink(
		DocumentSession $documentSession,
		ExternalLink $externalLink,
	): bool
	{
		if ($externalLink->allowEdit())
		{
			return true;
		}

		$file = $documentSession->getFile();
		if (!$file)
		{
			return false;
		}

		return ServiceLocator::getInstance()
			->get(DownloadAccess::class)
			->checkExternalLink($file, $externalLink)
		;
	}

	private function canDownloadByUnifiedLink(
		DocumentSession $documentSession,
		UnifiedLinkAccessLevel $accessLevel,
	): bool
	{
		if ($accessLevel->canEdit())
		{
			return true;
		}

		return $this->canDownloadBySecurityContext($documentSession, true);
	}

	private function canDownloadBySecurityContext(
		DocumentSession $documentSession,
		bool $useExternalContext = false,
	): bool
	{
		$file = $documentSession->getFile();
		$storage = $file?->getStorage();
		if (!$file || !$storage)
		{
			return false;
		}

		$securityContext = $storage->getCurrentUserSecurityContext($useExternalContext);

		return $file->canDownload($securityContext);
	}

	/**
	 * The portal policy of opening the link (ALG-02): the same decision the public external-link
	 * page makes, so both entry points answer identically for every kind of link.
	 */
	private function canOpenExternalLinkByPortalPolicy(ExternalLink $link): bool
	{
		return ServiceLocator::getInstance()
			->get(ExternalLinkOpenPolicy::class)
			->canOpenByPortalPolicy($link)
		;
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
	 * `POST /v1/documents/{docKey}/open` and registers `session_id`/`editor_key`/`rev`. The signed
	 * `config` is returned verbatim to the browser via {@see EditorConfigResponse} and
	 * is NOT rebuilt by the host.
	 *
	 * @param bool $withDocumentSource whether to send `document.url`/`document.filetype`.
	 *        On a join of a live session (joined:true) the platform ignores them.
	 * @param OpenRetryPolicy|null $policy the retry policy of the cycle; null resolves the one of the
	 *        portal configuration. {@see OpenRetryPolicy::singleAttempt()} for an open that must not
	 *        be repeated.
	 */
	protected function openDocumentSession(
		DocumentSession $documentSession,
		Disk\File $file,
		bool $withDocumentSource,
		bool $canDownload = true,
		?OpenRetryPolicy $policy = null,
		bool $restartClosedOnce = true,
	): Result
	{
		$this->originalOpenFailure = null;
		try
		{
			$client = $this->getProtocolClient();
			// buildOpenBody signs the egress URL; with an empty api-secret signing
			// throws (defense-in-depth, alongside EgressUrlSignatureCheck denial).
			// The body is assembled once and reused by every attempt of the cycle: the signed egress
			// URL outlives the budget, and an open without a document source is refused by the
			// platform with `409 session_closing`.
			$body = $this->buildOpenBody($documentSession, $file, $withDocumentSource, $canDownload);
		}
		catch (\Throwable $e)
		{
			return (new Result())->addError(new Error($e->getMessage()));
		}

		return $this->openRegisteredDocumentSession(
			$documentSession, $client, $body, $policy, validateFresh: true, restartClosedOnce: $restartClosedOnce,
		);
	}

	/**
	 * The clock and the delay are parameters for the same reason the cycle of `open` has them: a case
	 * states the time it means instead of waiting for it.
	 */
	protected function openRegisteredDocumentSession(
		DocumentSession $documentSession,
		ProtocolClientInterface $client,
		array $body,
		?OpenRetryPolicy $policy = null,
		?SessionRegistry $registry = null,
		?callable $clock = null,
		?callable $lockSleeper = null,
		bool $validateFresh = false,
		bool $restartClosedOnce = true,
		?string $reopenOwner = null,
	): Result
	{
		$registry ??= new SessionRegistry(
			clientOriginProvider: new ConfiguredClientOriginProvider($this->getConfiguration()),
		);
		$policy ??= OpenRetryPolicy::createFromConfiguration($this->getConfiguration());
		$clock ??= static fn(): float => hrtime(true) / 1e9;
		$lockSleeper ??= static function (int $microseconds): void
		{
			usleep($microseconds);
		};

		$deadline = $clock() + $policy->getBudgetSeconds();
		if ($this->openingBudget !== null)
		{
			if ($this->openingBudget->remainingMs() === 0)
			{
				return (new Result())->addError(new Error('Opening deadline exceeded.', 'vibeoffice_open_deadline'));
			}
			$deadline = min($deadline, $this->openingBudget->deadlineForOpen());
		}
		$hostDocKey = HostDocumentKey::resolve($documentSession);
		$beginResult = $this->beginOpenWithRetries(
			$registry,
			$documentSession,
			$hostDocKey,
			$policy,
			$clock,
			$lockSleeper,
			$deadline,
			$reopenOwner,
			$this->resolveOpenParticipant($body),
		);
		if (!$beginResult->isSuccess())
		{
			return $beginResult;
		}

		$openToken = $beginResult->getData()['openToken'];
		$this->registeredOpenAttempts[] = Document\Vibeoffice\DocumentSessionManager::snapshotSession($documentSession)
			+ ['openToken' => $openToken];
		$body['host_open_id'] = $openToken;
		// The origin is the snapshot of the reservation; a body reused by a reopen must not keep the old one.
		unset($body['client']['origin']);
		if (($body['client'] ?? null) === [])
		{
			unset($body['client']);
		}
		$clientOrigin = $beginResult->getData()['clientOrigin'] ?? null;
		if (is_string($clientOrigin))
		{
			$body['client']['origin'] = $clientOrigin;
		}
		$fingerprint = $this->fingerprintOpen($client, $documentSession, $hostDocKey, $body);
		if ($fingerprint === null)
		{
			return (new Result())->addError(new Error(
				'Vibeoffice open request cannot be bound to its token unambiguously.',
				SessionRegistry::ERROR_CODE_OPEN_UNRESOLVED,
			));
		}
		$trustedTransport = !$client instanceof ProtocolClient || $client->isTrustedTransport();
		$right = null;
		$finalization = null;
		$admit = static function () use ($registry, $openToken, $fingerprint, &$right): Result {
			$admission = $registry->admitOpenSend($openToken, $fingerprint);
			$right = $admission->isSuccess() ? $admission->getData()['right'] : null;

			return $admission;
		};
		$settle = function (Result $sendResult) use (
			$registry,
			$hostDocKey,
			$trustedTransport,
			&$right,
			&$finalization,
		): void {
			$sendRight = $right;
			$right = null;
			$finalization = $this->settleFailedSend($registry, $hostDocKey, $sendRight, $sendResult, $trustedTransport)
				?? $finalization
			;
		};
		try
		{
			$openResult = $this->openDocumentSessionWithRetries(
				$documentSession,
				$client,
				$body,
				$policy,
				$clock,
				deadline: $deadline,
				validateFresh: $validateFresh,
				admitSend: $admit,
				settleSend: $settle,
			);
			if ($openResult->isSuccess())
			{
				$sendRight = $right;
				$right = null;
				$openResult = $this->completeOpenedSession(
					$documentSession,
					$registry,
					$openToken,
					$openResult,
					sendRight: $sendRight,
					trustedTransport: $trustedTransport,
				);
				$finalization = $openResult->getData()['finalization'] ?? $finalization;
				if ($openResult->isSuccess() || ($openResult->getData()['terminal'] ?? false))
				{
					return $openResult;
				}
			}
		}
		catch (\Throwable)
		{
			$openResult = (new Result())->addError(new Error(
				'Vibeoffice transport failed before a verified response.',
				'vibeoffice_transport_unknown',
				['responseVerified' => false],
			));
		}
		if ($finalization !== null)
		{
			$this->openFinalizations ??= new \WeakMap();
			$this->openFinalizations[$openResult] = $finalization;
		}
		if ($restartClosedOnce && $this->hasDefinitiveOpenOutcome($openResult, 409, 'open_session_closed'))
		{
			$cancelled = $this->cancelRegisteredOpen($registry, $openToken, $hostDocKey, $openResult, $documentSession);
			if (($cancelled->getData()['hostDocKey'] ?? null) !== $hostDocKey)
			{
				return $cancelled;
			}
			$fresh = $this->validateFreshOpenSession($documentSession);
			if (!$fresh->isSuccess())
			{
				return $fresh;
			}

			return $this->openRegisteredDocumentSession(
				$documentSession,
				$client,
				$body,
				$policy,
				$registry,
				$clock,
				$lockSleeper,
				validateFresh: true,
				restartClosedOnce: false,
				reopenOwner: $finalization['owner'],
			);
		}

		return $finalization !== null || $this->isTerminalOpenFailure($openResult)
			? $this->cancelRegisteredOpen($registry, $openToken, $hostDocKey, $openResult, $documentSession)
			: $openResult
		;
	}

	/**
	 * An allow pair ends the attempt only through the finalization this controller made for the Result:
	 * a trusted, correlated answer of its own send, a stop, and no send of the token left outstanding.
	 * The pair itself, or a flag of whoever built the Result, proves nothing.
	 */
	private function hasDefinitiveOpenOutcome(Result $result, int $httpStatus, string $code): bool
	{
		$finalization = $this->findOpenFinalization($result);
		$candidate = match ([$httpStatus, $code])
		{
			[409, 'open_session_closed'] => OpenAttemptState::CANDIDATE_CLOSED,
			[410, 'open_session_expired'] => OpenAttemptState::CANDIDATE_EXPIRED,
			default => null,
		};

		return $finalization !== null
			&& $candidate !== null
			&& $finalization['candidate'] === $candidate
			&& ($candidate !== OpenAttemptState::CANDIDATE_CLOSED || is_string($finalization['owner']))
		;
	}

	private function findOpenFinalization(Result $result): ?array
	{
		$finalization = $this->openFinalizations !== null && isset($this->openFinalizations[$result])
			? $this->openFinalizations[$result]
			: null;

		return is_array($finalization) && ($finalization['finalized'] ?? false) === true ? $finalization : null;
	}

	/**
	 * Settles a send that came back without a config. Only an answer of this very send over a trusted
	 * transport, or the proof that it never left the host, writes its debt off; only an exact allow pair of
	 * such an answer stops the attempt. Returns the finalization when this settlement made it.
	 */
	private function settleFailedSend(
		SessionRegistry $registry,
		string $hostDocKey,
		?OpenSendRight $right,
		Result $sendResult,
		bool $trustedTransport,
	): ?array
	{
		if ($right === null)
		{
			return null;
		}
		$error = $this->firstOpenError($sendResult->getErrors());
		$data = $error?->getCustomData();
		$data = is_array($data) ? $data : [];
		$notSent = ($data['requestSent'] ?? null) === false;
		$answered = $trustedTransport && ($data['responseVerified'] ?? null) === true;
		$candidate = null;
		if ($answered && $error !== null)
		{
			$candidate = match ($this->splitErrorCode($error))
			{
				[409, 'open_session_closed'] => OpenAttemptState::CANDIDATE_CLOSED,
				[410, 'open_session_expired'] => OpenAttemptState::CANDIDATE_EXPIRED,
				default => null,
			};
		}

		try
		{
			$finalizer = new TerminalSessionFinalizer();
			$settled = $finalizer->withDocumentTransaction(
				$hostDocKey,
				static function () use ($registry, $right, $answered, $notSent, $candidate, $finalizer): Result {
					$settle = $registry->settleOpenSend($right, $answered || $notSent, $candidate);
					if (!$settle->isSuccess() || !$settle->getData()['finalized'])
					{
						return $settle;
					}
					$finalize = $finalizer->finalizeSessions(
						$registry->getTerminatedSessionIds($settle->getData()['sessionIds']),
					);

					return $finalize->isSuccess() ? $settle : $finalize;
				},
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		return $settled->isSuccess() && $settled->getData()['finalized'] ? $settled->getData() : null;
	}

	/**
	 * Fingerprint of the request the token is sent with; the protocol client adds its endpoint, key and
	 * capabilities. Null when the body cannot be bound unambiguously.
	 */
	private function fingerprintOpen(
		ProtocolClientInterface $client,
		DocumentSession $documentSession,
		string $hostDocKey,
		array $body,
	): ?string
	{
		$versionId = $documentSession->getVersionId();
		$sourceBinding = OpenFingerprint::digest([
			(string)$documentSession->getId(),
			(string)$documentSession->getExternalHash(),
			(string)$documentSession->getObjectId(),
			$versionId === null ? 'current-file' : (string)$versionId,
			(string)self::EGRESS_URL_TTL,
		]);
		if ($client instanceof ProtocolClient)
		{
			return $client->fingerprintOpen($hostDocKey, $body, $sourceBinding);
		}

		$rawBody = OpenFingerprint::encodeBody($body);
		$maskedBody = $rawBody === null ? null : OpenFingerprint::maskRawBody($rawBody, $body);

		return $maskedBody === null
			? null
			: OpenFingerprint::digest(['client', $hostDocKey, $sourceBinding, $maskedBody])
		;
	}

	/**
	 * The reservation of the open, asked for again while the named lock of the document is the only
	 * thing in the way: a save running in a neighbouring tab holds that lock for as long as it writes
	 * the version, and letting it fail the open here is exactly the trouble the retry cycle was added
	 * to cure. Every other refusal of the registry is handed back untouched - repeating a UUID already
	 * bound elsewhere buys nothing.
	 *
	 * The repeats live inside the budget of the whole open and cost no request to the platform, so the
	 * cap of ALG-RETRY on the requests of an open stands as it is.
	 */
	private function beginOpenWithRetries(
		SessionRegistry $registry,
		DocumentSession $documentSession,
		string $hostDocKey,
		OpenRetryPolicy $policy,
		callable $clock,
		callable $lockSleeper,
		float $deadline,
		?string $reopenOwner = null,
		?string $participant = null,
	): Result
	{
		$attempt = 0;
		while (true)
		{
			$attempt++;
			$beginResult = $reopenOwner === null
				? $registry->beginOrResumeOpen($documentSession, $hostDocKey, $participant)
				: $registry->beginReopen($documentSession, $hostDocKey, $reopenOwner, $participant)
			;
			if ($beginResult->isSuccess() || !$this->isDocumentLockTimeout($beginResult))
			{
				return $beginResult;
			}

			if (
				!$policy->hasBeginOpenAttemptsLeft($attempt)
				|| !$policy->fitsBeginOpenIntoBudget($clock(), $deadline)
			)
			{
				return $beginResult;
			}

			$lockSleeper(OpenRetryPolicy::BEGIN_OPEN_PAUSE_MICROSECONDS);
		}
	}

	/**
	 * Who the open speaks for: visitors of one public link share the local session, but each of them opens
	 * with a user of its own, and the attempt of one must be neither resumed by nor block another.
	 */
	private function resolveOpenParticipant(array $body): ?string
	{
		$userId = $body['user']['id'] ?? null;

		return is_string($userId) && $userId !== '' ? OpenFingerprint::digest(['open-participant', $userId]) : null;
	}

	private function isDocumentLockTimeout(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === self::LOCK_TIMEOUT_ERROR_CODE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Releases the pending attempt only through the finalization this controller made for the Result (see
	 * {@see self::hasDefinitiveOpenOutcome()}); any other refusal keeps the token with its snapshot, and the
	 * local session stays bound to it.
	 */
	private function cancelRegisteredOpen(
		SessionRegistry $registry,
		string $openToken,
		string $hostDocKey,
		Result $openResult,
		DocumentSession $documentSession,
	): Result
	{
		$finalization = $this->findOpenFinalization($openResult);
		if ($finalization !== null)
		{
			return $openResult->setData(['sessionIds' => $finalization['sessionIds'], 'hostDocKey' => $hostDocKey]);
		}

		$cancel = $registry->cancelOpen($openToken);
		if (!$cancel->isSuccess())
		{
			if ($this->capacityFallbackOutcome !== null
				|| ($documentSession->isEdit() && $this->isVerifiedCapacityFailure($this->originalOpenFailure)))
			{
				// Cleanup failure is separate from the verified cause and the actual VIEW refusal.
				return $openResult->setData($openResult->getData() + [
					'cleanup' => $cancel, 'hostDocKey' => $hostDocKey, 'openToken' => $openToken,
				]);
			}

			return $cancel->setData(['openErrors' => $openResult->getErrorMessages()]);
		}

		return $openResult->setData($cancel->getData() + ['hostDocKey' => $hostDocKey]);
	}

	/**
	 * ALG-RETRY: repeats `open` while the platform answers with a temporary refusal carrying a
	 * `Retry-After`, within one wall-clock budget of the whole cycle. The deadline limits the start
	 * of a new attempt, never the completion of one already running: an interrupted read leaves a
	 * live session on the platform and an error for the user, and no repeat cures that.
	 *
	 * The session type is untouched here - the decision on the outcome ({@see shouldDowngradeToView()})
	 * is taken by the caller once the cycle is over.
	 *
	 * The clock and the delay are parameters so that a test can drive the cycle without real pauses;
	 * the protocol client comes in already resolved for the same reason. The deadline is a parameter
	 * too: the caller has been spending the very same budget since it reserved the open, and a fresh
	 * one here would hand the cycle time the open no longer has.
	 */
	protected function openDocumentSessionWithRetries(
		DocumentSession $documentSession,
		ProtocolClientInterface $client,
		array $body,
		?OpenRetryPolicy $policy = null,
		?callable $clock = null,
		?callable $sleeper = null,
		?float $deadline = null,
		bool $validateFresh = false,
		?callable $admitSend = null,
		?callable $settleSend = null,
	): Result
	{
		$policy ??= OpenRetryPolicy::createFromConfiguration($this->getConfiguration());
		$clock ??= static fn(): float => hrtime(true) / 1e9;
		$sleeper ??= static function (int $microseconds): void
		{
			usleep($microseconds);
		};

		$docKey = HostDocumentKey::resolve($documentSession);
		$deadline ??= $clock() + $policy->getBudgetSeconds();
		if ($this->openingBudget !== null)
		{
			$deadline = min($deadline, $this->openingBudget->deadlineForOpen());
		}
		$attempt = 0;
		if ($this->openObservationScope !== null)
		{
			$this->openObservationScope->documentSession = $documentSession;
		}

		while (true)
		{
			$attempt++;
			$startedAt = $clock();
			$validation = $validateFresh ? $this->validateFreshOpenSession($documentSession) : null;
			if ($validation !== null && !$validation->isSuccess())
			{
				return $validation;
			}

			if ($this->openingBudget !== null && $this->openingBudget->remainingMs() === 0)
			{
				return (new Result())->addError(new Error('Opening deadline exceeded.', 'vibeoffice_open_deadline'));
			}

			$admission = $admitSend === null ? null : $admitSend();
			if ($admission !== null && !$admission->isSuccess())
			{
				return $admission;
			}

			if ($this->openObservationScope !== null)
			{
				$this->openObservationScope->attempts++;
				$this->openObservationScope->protocolContext->beginOpenAttempt();
			}
			$openResult = $client->open($docKey, $body, $this->openingBudget?->deadlineForOpen());
			// The legacy mapper defaults missing verification to true: capacity must use the raw result.
			$this->originalOpenFailure = $openResult->isSuccess() ? null : $openResult;
			if ($openResult->isSuccess())
			{
				return $openResult;
			}
			if ($settleSend !== null)
			{
				$settleSend($openResult);
			}

			$errors = $this->mapOpenErrors($openResult->getErrors());
			$pause = $this->resolveRetryPause($errors, $policy, $attempt, $clock() - $startedAt);
			$pauseMicroseconds = $pause !== null ? $policy->scatterPauseMicroseconds($pause) : null;
			if (
				$pauseMicroseconds !== null
				&& $policy->fitsIntoBudget($clock(), $deadline, $pauseMicroseconds / 1_000_000)
			)
			{
				$sleeper($pauseMicroseconds);
				if ($policy->fitsIntoBudget($clock(), $deadline, 0.0))
				{
					continue;
				}
			}

			return (new Result())->addErrors($errors);
		}
	}

	private function validateFreshOpenSession(DocumentSession $documentSession): Result
	{
		$fresh = DocumentSession::loadById($documentSession->getId());
		$originalFile = $documentSession->getFile();
		$freshFile = $fresh?->getFile();
		if (
			!$fresh
			|| !$originalFile
			|| !$freshFile
			|| !$fresh->isActive()
			|| $fresh->getService() !== Models\DocumentService::Vibeoffice
			|| $fresh->getExternalHash() !== $documentSession->getExternalHash()
			|| $fresh->getObjectId() !== $documentSession->getObjectId()
			|| $fresh->getVersionId() !== $documentSession->getVersionId()
			|| $fresh->getType() !== $documentSession->getType()
			|| $fresh->getUserId() !== $documentSession->getUserId()
			|| HostDocumentKey::resolve($fresh) !== HostDocumentKey::resolve($documentSession)
			|| $freshFile->getName() !== $originalFile->getName()
			|| $freshFile->getExtension() !== $originalFile->getExtension()
		)
		{
			return (new Result())->addError(new Error(
				'Vibeoffice open identity changed.',
				'open_identity_mismatch',
			));
		}

		$link = $fresh->getContext()?->getExternalLink();
		if ($link instanceof ExternalLink)
		{
			$allowed = $this->canAccessExternalSession($fresh, $link)
				&& (!$fresh->isEdit() || $link->allowEdit());
		}
		elseif ($this->unifiedLinkAccessLevel !== null)
		{
			$accessLevel = $this->resolveConfigAccessLevel($fresh);
			$allowed = $accessLevel->canRead() && (!$fresh->isEdit() || $accessLevel->canEdit());
		}
		else
		{
			$allowed = $this->canAccessSession($fresh);
		}
		if (!$allowed)
		{
			return (new Result())->addError(new Error('Vibeoffice access was revoked.', 'open_access_revoked'));
		}

		return new Result();
	}

	private function isTerminalOpenFailure(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if (!$error instanceof Error)
			{
				continue;
			}

			[, $platformCode] = $this->splitErrorCode($error);
			if (in_array($platformCode ?? $error->getCode(), self::TERMINAL_OPEN_CODES, true))
			{
				return true;
			}
			if ($platformCode !== null && !OpenRetryPolicy::isTemporaryPlatformCode($platformCode))
			{
				return true;
			}

			$data = $error->getCustomData();
			if (is_array($data) && ($data['requestSent'] ?? null) === false)
			{
				return true;
			}
			if (is_array($data) && ($data['responseVerified'] ?? null) === true)
			{
				if (OpenRetryPolicy::isTemporaryPlatformCode($platformCode))
				{
					return false;
				}

				$httpStatus = $this->normalizeHttpStatus($data['httpStatus'] ?? null);

				return !in_array($httpStatus, [409, 429, 502, 503, 504], true);
			}
		}

		return false;
	}

	/**
	 * The pause before repeating the refusal, or null when the cycle must stop: the attempt cap is
	 * reached, the platform code is not a temporary one, the platform asked for no delay, or it had
	 * already spent its own waiting on this attempt.
	 *
	 * @param Error[] $errors errors of the failed attempt, already mapped by {@see mapOpenErrors()}
	 */
	private function resolveRetryPause(
		array $errors,
		OpenRetryPolicy $policy,
		int $madeAttempts,
		float $attemptDurationSec,
	): ?int
	{
		if (!$policy->hasAttemptsLeft($madeAttempts))
		{
			return null;
		}

		$error = $this->firstOpenError($errors);
		if ($error === null)
		{
			return null;
		}

		if (!$policy->isRetryablePlatformCode($this->splitErrorCode($error)[1]))
		{
			return null;
		}

		$retryAfter = $this->extractRetryAfter($error);
		if ($retryAfter === null)
		{
			return null;
		}

		if ($policy->isLongAttempt($attemptDurationSec))
		{
			return null;
		}

		return $policy->resolvePauseSeconds($retryAfter);
	}

	/**
	 * @param Error[] $errors
	 */
	private function firstOpenError(array $errors): ?Error
	{
		$error = $errors[0] ?? null;

		return $error instanceof Error ? $error : null;
	}

	/**
	 * Extracts `session_id`/`editor_key`/`rev` of the attempt that succeeded and packs the signed
	 * config for the browser. Metadata of a failed attempt is never persisted.
	 */
	private function completeOpenedSession(
		DocumentSession $documentSession,
		SessionRegistry $registry,
		string $openToken,
		Result $openResult,
		?TerminalSessionFinalizerInterface $finalizer = null,
		?OpenSendRight $sendRight = null,
		bool $trustedTransport = false,
	): Result
	{
		$openConfig = $openResult->getData();
		if ($this->openObservationScope !== null)
		{
			$sessionId = $openConfig['session_id'] ?? null;
			$this->openObservationScope->platformSessionId = is_string($sessionId)
				&& preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $sessionId) === 1
					? strtolower($sessionId)
					: null;
		}
		$finalizer ??= new TerminalSessionFinalizer();
		$completeResult = $finalizer->withDocumentTransaction(
			HostDocumentKey::resolve($documentSession),
			function () use ($registry, $openToken, $openConfig, $finalizer, $sendRight, $trustedTransport): Result {
				if ($sendRight !== null)
				{
					// The linear point of the success: a stop committed before it admits no config.
					$settle = $registry->settleOpenSend(
						$sendRight,
						$trustedTransport,
						untrustedSuccess: !$trustedTransport,
					);
					if (!$settle->isSuccess())
					{
						return $settle;
					}
					$settled = $settle->getData();
					if (!$settled['configAllowed'])
					{
						$finalize = $settled['finalized']
							? $finalizer->finalizeSessions($registry->getTerminatedSessionIds($settled['sessionIds']))
							: new Result();

						$stopped = ['terminal' => false, 'stopped' => true, 'settlement' => $settled];

						return $finalize->isSuccess() ? (new Result())->setData($stopped) : $finalize;
					}
				}
				$complete = $registry->completeOpen($openToken, $this->extractSessionMetadata($openConfig));
				if (!$complete->isSuccess() || !$complete->getData()['terminal'])
				{
					return $complete;
				}
				$finalize = $finalizer->finalizeSessions($complete->getData()['sessionIds']);

				return $finalize->isSuccess() ? $complete : $finalize;
			},
		);
		if (!$completeResult->isSuccess())
		{
			self::markOpenObservationFailure($this->openObservationScope, 'open_failed', true);

			return $completeResult;
		}
		if ($completeResult->getData()['stopped'] ?? false)
		{
			$settled = $completeResult->getData()['settlement'];

			return (new Result())
				->addError(new Error(
					'The Vibeoffice open attempt was stopped before its answer.',
					SessionRegistry::ERROR_CODE_OPEN_UNRESOLVED,
				))
				->setData(['terminal' => false, 'finalization' => $settled['finalized'] ? $settled : null])
			;
		}
		if ($completeResult->getData()['terminal'])
		{
			return $completeResult->addError(new Error(
				'The Vibeoffice session was closed while opening.',
				'vibeoffice_session_closed',
			));
		}

		if ($this->openObservationScope !== null)
		{
			$this->openObservationScope->result = 'ok';
			$this->openObservationScope->code = null;
		}
		return (new Result())->setData([
			'editorConfig' => new EditorConfigResponse($openConfig, $documentSession->getExternalHash()),
		]);
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
		bool $canDownload = true,
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
				'grant' => [
					'mode' => $this->unifiedLinkAccessLevel === null
						? (new Document\Vibeoffice\GrantMapper())->resolveForSession($documentSession, $currentUser)
						: (new Document\Vibeoffice\GrantMapper())->mapFromRights(
							$documentSession->isEdit() && $this->unifiedLinkAccessLevel->canEdit(),
						),
				],
			];
		}

		$body['document'] = [
			'title' => $file->getName(),
		];
		if (!$canDownload)
		{
			$body['document']['permissions'] = [
				'download' => false,
				'print' => false,
			];
		}
		$body['editor'] = [
			'lang' => (string)(Context::getCurrent()?->getLanguage() ?? 'en'),
		];

		// `editor.ui.shell` names the design: the platform passes it into the signed `editorConfig.ui`
		// (API-OPEN), and the DS image starts the VibeOffice shell on it. Every open through VibeOffice
		// carries it, whatever the format or the channel.
		$body['editor']['ui'] = [
			'shell' => self::EDITOR_UI_SHELL,
		];

		// Point #4 (messenger-call, compact editor): when the session was opened with a compact
		// request (persisted into CONTEXT.vo by loadDocumentEditor), send the optional `editor.ui.mode`
		// so the platform injects the compact-chrome flags into the signed `editorConfig.customization`
		// before it returns the verbatim config (API-OPEN). Only "compact" is recognised; for a full
		// layout (the default, e.g. every open outside a call) the mode is omitted (fail-safe).
		if ($this->resolveEditorMode($documentSession) === Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_COMPACT)
		{
			$body['editor']['ui']['mode'] = 'compact';
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
		if (!$this->canAccessSession($documentSession) || !$this->canReadFileByCurrentUser($documentSession))
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

		$signResult = $client->signVersionUrls(HostDocumentKey::resolve($documentSession), ['items' => $items]);
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
		if ($this->observedLoadAction)
		{
			self::markOpenObservationFailure($this->openObservationScope, 'open_load_refused');
		}
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
	protected function redirectToShell(DocumentSession $documentSession, ?string $restrictionOutcome = null): HttpResponse
	{
		/** @see \DiskFileEditorVibeofficeController::getSliderContentAction */
		$link = UrlManager::getInstance()->create('getSliderContent', [
			'c' => 'bitrix:disk.file.editor-vibeoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
			'documentSessionId' => $documentSession->getId(),
			'documentSessionHash' => $documentSession->getExternalHash(),
			'restrictionOutcome' => $restrictionOutcome,
			'openingContext' => $this->trustedOpening === [] ? null : Document\Vibeoffice\RestartTarget::sign(
				$this->trustedOpening,
				(int)$this->getCurrentUser()->getId(),
			),
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
		return ProtocolClientFactory::create($this->openObservationScope?->protocolContext);
	}

	protected function getConfiguration(): Document\Vibeoffice\Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}

	/**
	 * Whether an open-conflict for an edit session should fall back to a view session.
	 *
	 * The verdict is taken by the platform code, not by the HTTP status: `doc_full` is the only
	 * refusal a view session is a meaningful answer to. A temporary refusal never downgrades, not
	 * even once the retries are spent: a view opens through the very same `open` call and would hit
	 * the same refusal, while the session type would already be changed irreversibly. An unknown
	 * `409` (a body-less answer among them) counts as temporary for the same reason. A `403 no_grant`
	 * is NOT downgraded either: it surfaces as ERR-NO-GRANT so the frontend can decide.
	 *
	 * Called once the retry cycle is over; the errors are already mapped by {@see mapOpenErrors()},
	 * so the platform code lives in the error custom data.
	 */
	private function shouldDowngradeToView(Result $openResult): bool
	{
		foreach ($openResult->getErrors() as $error)
		{
			if (!$error instanceof Error)
			{
				continue;
			}

			if ($this->splitErrorCode($error)[1] === 'doc_full')
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
	 * The protocol-client reports the status, the platform code and the retry delay
	 * in the error custom data (DTO-OPENERR); we re-key by HTTP status and carry all
	 * three along, so the retry policy works with the already mapped error.
	 *
	 * The temporary class comes from {@see OpenRetryPolicy}: a code the cycle repeats is a code the
	 * "still saving" screen is chosen by, whatever HTTP status the platform sends it with. The
	 * temporary refusals of the host half of the path ({@see self::TEMPORARY_HOST_CODES}) are read the
	 * same way: they carry no HTTP status, and the document they name is busy just as literally.
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
			if (!$error instanceof Error)
			{
				$mapped[] = $error;

				continue;
			}

			[$httpStatus, $platformCode] = $this->splitErrorCode($error);
			if ($httpStatus === null)
			{
				if ($error->getCode() === 'vibeoffice_transport_unknown')
				{
					self::markOpenObservationFailure(
						$this->openObservationScope,
						OpenFailureCode::InProgress->value,
						true,
					);
				}
				$hostData = ['hostCode' => $error->getCode()];
				$errorData = $error->getCustomData();
				if (is_array($errorData) && is_bool($errorData['responseVerified'] ?? null))
				{
					$hostData['responseVerified'] = $errorData['responseVerified'];
				}
				// A refusal of the host itself (no HTTP status): temporary ones join the platform ones on
				// the screen of the branch of saving, the rest are kept as they are.
				$mapped[] = in_array($error->getCode(), self::TEMPORARY_HOST_CODES, true)
					? new Error($error->getMessage(), OpenFailureCode::InProgress->value, $hostData)
					: $error;

				continue;
			}

			$errorData = $error->getCustomData();
			$responseVerified = !is_array($errorData) || ($errorData['responseVerified'] ?? true) !== false;
			$errCode = OpenFailureCode::fromPlatform($httpStatus, $platformCode, $responseVerified)->value;

			$mapped[] = new Error(
				$error->getMessage(),
				$errCode,
				[
					'responseVerified' => $responseVerified,
					'httpStatus' => $httpStatus,
					'platformCode' => $platformCode,
					'retryAfter' => $this->extractRetryAfter($error),
				],
			);
		}

		return $mapped;
	}

	/**
	 * The errors of the open as the shell of the component reads them: mapped onto the stable `ERR-*`
	 * codes it picks the screen by, whichever half of the path - the platform or the host - refused.
	 *
	 * @param Error[] $errors
	 */
	private function addOpenErrors(array $errors): void
	{
		$this->addErrors($this->mapOpenErrors($errors));
	}

	/**
	 * Splits a protocol-client open-error into [httpStatus, platformCode].
	 *
	 * The structured DTO-OPENERR custom data is the source of truth. Producers that
	 * do not fill it in yet fall back to the legacy pair: the HTTP status from
	 * Error::getCode() and the platform code parsed out of the trailing
	 * `Code: <code>.` suffix of the message.
	 *
	 * @return array{0: int|null, 1: string|null}
	 */
	private function splitErrorCode(Error $error): array
	{
		$customData = $error->getCustomData();
		if (is_array($customData) && isset($customData['httpStatus']))
		{
			$platformCode = $customData['platformCode'] ?? null;

			return [
				$this->normalizeHttpStatus($customData['httpStatus']),
				is_string($platformCode) && $platformCode !== '' ? $platformCode : null,
			];
		}

		$platformCode = null;
		if (preg_match('/Code:\s*([a-z0-9_]+)\.?\s*$/i', $error->getMessage(), $matches))
		{
			$platformCode = $matches[1];
		}

		return [$this->normalizeHttpStatus($error->getCode()), $platformCode];
	}

	private function normalizeHttpStatus(mixed $rawStatus): ?int
	{
		if (!is_numeric($rawStatus))
		{
			return null;
		}

		$status = (int)$rawStatus;

		return $status >= 100 && $status <= 599 ? $status : null;
	}

	/**
	 * Retry delay the platform asked for, as reported by the protocol client in the
	 * DTO-OPENERR custom data.
	 */
	private function extractRetryAfter(Error $error): ?int
	{
		$customData = $error->getCustomData();
		$retryAfter = is_array($customData) ? ($customData['retryAfter'] ?? null) : null;

		return is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : null;
	}
}
