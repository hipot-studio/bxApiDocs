<?php

use Bitrix\Disk;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Component\DocumentHeaderPage;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\OpeningNoticeCodeAware;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\User;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

Loader::requireModule('disk');

/**
 * Server-rendered shell for the Vibeoffice document editor.
 *
 * The platform-signed open config is passed to the browser without modification.
 */
class CDiskFileEditorVibeofficeComponent extends BaseComponent implements Controllerable
{
	use DocumentHeaderPage;

	// Shared with the OnlyOffice component on purpose: the concurrent-edit-session limit is
	// global per portal (one RestrictionManager / one restriction log, keyed by EXTERNAL_HASH,
	// no SERVICE split), so vibeoffice and OnlyOffice draw from the same pool.
	private const RESTRICTION_OUTCOMES = [
		Vibeoffice\EditSessionAdmission::OUTCOME_LIMIT_EXCEEDED,
		Vibeoffice\EditSessionAdmission::OUTCOME_LOCK_UNAVAILABLE,
	];
	private const OPENING_NOTICE_CODES = [
		OpeningNoticeCodeAware::OBJECT_LOCK_EDIT_VIEW,
	];
	// @see \Bitrix\Disk\Controller\Vibeoffice::mapOpenErrors()
	private const IN_PROGRESS_ERROR_CODE = 'ERR-IN-PROGRESS';
	private const SAVE_REFUSED_ERROR_CODE = 'ERR-SAVE-REFUSED';
	// A single leading slash, then no scheme, no second slash, no backslash and no control character:
	// what is left addresses this portal alone.
	private const SAME_PORTAL_PATH_PATTERN = '#^/(?!/)[^\\\\\x00-\x1F\x7F]*$#D';

	protected User $currentUser;
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkUrl $unifiedLinkUrl;
	private bool $unifiedLinkMode = false;

	protected function processBeforeAction($actionName)
	{
		if (!Vibeoffice\VibeofficeHandler::isEnabled())
		{
			// vibeoffice is switched off on the portal: render the "editor unavailable" surface
			// instead of returning a silent false (which left the user with a blank panel).
			// Reuse the existing cloud-error template — the generic "could not open the editor"
			// page shared with the platform-open-failure path.
			$this->includeComponentTemplate('cloud-error');

			return false;
		}

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkUrl = $serviceLocator->get(UnifiedLinkUrl::class);

		return parent::processBeforeAction($actionName);
	}

	public function prepareParams()
	{
		parent::prepareParams();

		if (!isset($this->arParams['EDITOR_MODE']))
		{
			$this->arParams['EDITOR_MODE'] = OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL;
		}

		if (CurrentUser::get()->getId())
		{
			$this->currentUser = User::getById(CurrentUser::get()->getId());
		}
		else
		{
			$this->currentUser = Disk\Document\Models\GuestUser::create();
		}

		$this->unifiedLinkMode = (bool)($this->arParams['UNIFIED_LINK_MODE'] ?? false);
	}

	public function configureActions()
	{
		return [];
	}

	protected function processActionDefault()
	{
		$this->arResult['UNIFIED_LINK_MODE'] = $this->unifiedLinkMode;
		$this->arResult['FILE_UNIQUE_CODE'] = $this->arParams['FILE_UNIQUE_CODE'] ?? '';
		$this->arResult['INITIAL_NOTICE'] = $this->getOpeningNoticeCode() !== null;

		if (isset($this->arParams['TEMPLATE']) && $this->arParams['TEMPLATE'] === 'not-found')
		{
			$this->includeComponentTemplate('not-found');

			return;
		}

		/** @var Disk\Document\Models\DocumentSession $documentSession */
		$documentSession = $this->arParams['DOCUMENT_SESSION'];

		$this->arResult['EXTERNAL_LINK_MODE'] = (bool)($this->arParams['EXTERNAL_LINK_MODE'] ?? false);

		if ($documentSession->isNonActive())
		{
			$documentSession->setAsActive();
		}

		$restrictionOutcome = $this->getRestrictionOutcome();
		if ($documentSession->isView() && $restrictionOutcome !== null)
		{
			$this->arResult['LIMIT'] = [
				'VALUE' => (int)(new OnlyOffice\RestrictionManager())->getLimit(),
			];
			$this->includeComponentTemplate('limit-exceeded');

			return;
		}

		// The platform builds & signs the config; the backend proxies the open-response
		// (DTO-EDITORCONFIG) verbatim. The component does NOT construct or sign it.
		//
		// The config channel is chosen by the access context of the visitor:
		//  - EXTERNAL_LINK_MODE: the visitor is anonymous (no portal session). Route to the
		//    external channel, which authorizes by the ExternalLink (validity/password/allowEdit)
		//    instead of a CurrentUser; otherwise the internal channel would return null for a
		//    guest (canAccessSession dereferences getCurrentUser()) and the editor would show a
		//    cloud-error.
		//  - unifiedLinkMode: the visitor is a logged-in portal user whose access to the file may
		//    come ONLY through the unified link (no direct canUserRead/canUserEdit). The internal
		//    channel (canAccessSession) would refuse them with a cloud-error, breaking parity with
		//    OnlyOffice; route to the unified-link channel, which authorizes (fail-closed) by the
		//    same UnifiedLinkAccessService the OnlyOffice shell and the upstream renderer use.
		//  - otherwise: the ordinary in-portal path with direct rights uses the internal channel
		//    as-is.
		/**
		 * @see \Bitrix\Disk\Controller\Vibeoffice::getEditorConfigAction
		 * @see \Bitrix\Disk\Controller\Vibeoffice::getEditorConfigForExternalLinkAction
		 * @see \Bitrix\Disk\Controller\Vibeoffice::getEditorConfigForUnifiedLinkAction
		 */
		$controller = new Disk\Controller\Vibeoffice();
		$controller->setCurrentUser(CurrentUser::get());
		$openingBudget = null;
		if ($this->arResult['EXTERNAL_LINK_MODE'])
		{
			$openConfig = $controller->getEditorConfigForExternalLinkAction($documentSession);
		}
		elseif ($this->unifiedLinkMode)
		{
			$openConfig = $controller->getEditorConfigForUnifiedLinkAction($documentSession);
		}
		else
		{
			$openConfig = $controller->getEditorConfigAction($documentSession);
		}
		$openedDocumentSession = $controller->getOpenedDocumentSession();
		if (!is_array($openConfig) || $openedDocumentSession === null)
		{
			// Server-side open failure: invalid/unsigned config or platform refusal. The user
			// sees a server-rendered screen instead of a silent refusal; the controller errors are
			// collapsed for the user but logged here so the diagnostics are not lost.
			$errors = $controller->getErrors();
			if ($errors)
			{
				$codes = array_map(static fn($error) => (string)$error->getCode(), $errors);
				AddMessage2Log(
					'disk.file.editor-vibeoffice: could not build editor config for document session '
						. $documentSession->getId() . ': codes=' . implode(',', $codes),
					'disk',
				);
			}
			$this->arResult['OPEN_RETRY_DELAY'] = $this->resolveOpenRetryDelay($errors);
			foreach ($errors as $error)
			{
				if ($error->getCode() === self::SAVE_REFUSED_ERROR_CODE)
				{
					$this->arResult['OPEN_FAILURE_KIND'] = 'save_refused';
					$this->arResult['SAVE_REFUSAL_REASON'] = $this->findSavedRefusalReason($documentSession);
					break;
				}
				if ($error->getCode() === 'open_operation_expired')
				{
					$this->arResult['OPEN_FAILURE_KIND'] = 'open_operation_expired';
					$requestUri = $_SERVER['REQUEST_URI'] ?? null;
					if (is_string($requestUri) && preg_match(self::SAME_PORTAL_PATH_PATTERN, $requestUri) === 1)
					{
						$this->arResult['OPEN_RETRY_URL'] = $requestUri;
					}
					break;
				}
			}

			$this->includeComponentTemplate($this->chooseOpenFailureTemplate($errors));

			return;
		}

		$documentSession = $openedDocumentSession;
		$this->arParams['DOCUMENT_SESSION'] = $documentSession;
		$this->arResult['DOCUMENT_SESSION'] = [
			'ID' => $documentSession->getId(),
			'HASH' => $documentSession->getExternalHash(),
		];
		$this->arResult['OBJECT'] = [
			'ID' => $documentSession->getObjectId(),
			'NAME' => $documentSession->getObject()->getName(),
			'SIZE' => $documentSession->getObject()->getSize(),
			'SIZE_READABLE' => CFile::FormatSize($documentSession->getObject()->getSize()),
			'DOC_TYPE' => Disk\Analytics\Enum\DocumentTypeEnum::getByExtension($documentSession->getObject()->getExtension())?->value,
		];
		$this->arResult['ATTACHED_OBJECT'] = [
			'ID' => $documentSession->getContext()->getAttachedObjectId(),
		];
		// The address a closed editor returns to names viewing itself, so the server leaves it as asked
		// instead of choosing the mode for it and sending the reader straight back into editing.
		$this->arResult['LINK_OPEN_NEW_WINDOW'] = $this->getLinkToView($documentSession, exactlyView: true);
		$this->arResult['LINK_TO_DOWNLOAD'] = $this->getLinkToDownload($documentSession);
		$this->arResult['CAN_DOWNLOAD'] = $this->canDownloadDocument($documentSession);
		$this->arResult['LINK_TO_EDIT'] = $this->getLinkToEdit();

		$infoToken = Disk\Document\Online\UserInfoToken::generateTimeLimitedToken(
			$this->getUserIdForOnline(),
			$documentSession->getObject()->getRealObjectId()
		);

		$this->arResult['CURRENT_USER_AS_GUEST'] = $this->currentUser instanceof Disk\Document\Models\GuestUser;
		$this->arResult['CURRENT_USER'] = Json::encode([
			'id' => $this->getUserIdForOnline(),
			'name' => $this->currentUser->getFormattedName(),
			'avatar' => $this->currentUser->getAvatarSrc(),
			'infoToken' => $infoToken,
		]);

		if (!$this->arResult['EXTERNAL_LINK_MODE'])
		{
			//for BX.desktopUtils.runningCheck
			Loader::includeModule('im');
		}

		if (!$this->arResult['EXTERNAL_LINK_MODE'])
		{
			// An in-portal visitor without a storage of their own has nothing to open the document
			// from, so the page answers with the "not found" surface instead of an empty editor.
			$proxyTypeUser = Driver::getInstance()->getStorageByUserId($this->currentUser->getId())?->getProxyType();
			if (!$proxyTypeUser)
			{
				$this->includeComponentTemplate('not-found');

				return;
			}
		}

		// The flat OpenConfig (doc_id/host_doc_key/joined/editor_key/rev/session_id/
		// session_expires_at/vo_sess/urls{}/config) is proxied straight from the platform
		// and inlined for the helper; the host never rebuilds or re-signs it.
		$this->arResult['OPEN_CONFIG'] = Json::encode($openConfig['openConfig'] ?? []);
		$this->arResult['RESTART_TARGET'] = Disk\Document\Vibeoffice\RestartTarget::build(
			$documentSession,
			$this->arParams['TRUSTED_OPENING'] ?? [],
		);
		$this->arResult['HEADER_COMPOSITION'] = $this->buildHeaderComposition(
			$this->buildOpeningContext(
				$documentSession,
				$this->arResult['CAN_DOWNLOAD'] ? $this->arResult['LINK_TO_DOWNLOAD'] : '',
			),
		);

		// Reuse the existing Disk object pull channel (object_{id}) so an already-open VIEW
		// gets the live "content updated" host notification, mirroring the OnlyOffice shell
		// (CDiskFileEditorOnlyOfficeComponent). The forced-reload realtime tag is NOT carried
		// over: vibeoffice has no booster servers. The backend already emits the
		// `contentUpdated` pull command from File::uploadVersion(); here we only subscribe.
		$this->arResult['PULL_CONFIG'] = null;
		$this->arResult['PUBLIC_CHANNEL'] = null;
		$publicPullConfigurator = new Disk\Document\Online\PublicPullConfigurator();
		if ($publicPullConfigurator->getErrors())
		{
			$this->errorCollection->add($publicPullConfigurator->getErrors());
		}
		else
		{
			$realObjectId = $documentSession->getObject()->getRealObjectId();
			$this->arResult['PULL_CONFIG'] = $publicPullConfigurator->getConfig($realObjectId);
			$this->arResult['PUBLIC_CHANNEL'] = $publicPullConfigurator->getChannel($realObjectId)->getSignedPublicId();
		}

		$this->arResult['OPENING_BUDGET'] = $openingBudget?->toArray();
		$this->includeComponentTemplate();
	}

	/**
	 * Generate link for view document.
	 *
	 * @param Disk\Document\Models\DocumentSession $documentSession
	 * @param bool $exactlyView open document exactly in view mode instead of any side effects (for e.g. redirect)
	 * @return Uri|string
	 */
	protected function getLinkToView(
		Disk\Document\Models\DocumentSession $documentSession,
		bool $exactlyView = false,
	): string|Uri
	{
		if ($this->unifiedLinkMode)
		{
			$unifiedLinkOptions = [];
			$attachedObjectId = (int)$documentSession->getContext()?->getAttachedObjectId();
			if ($attachedObjectId > 0)
			{
				$unifiedLinkOptions['attachedId'] = $attachedObjectId;
			}

			if ($exactlyView)
			{
				$unifiedLinkOptions['noRedirect'] = true;
				$unifiedLinkOptions['exactMode'] = true;
			}

			return $this->getUrlManager()->getUnifiedLink($documentSession->getFile(), $unifiedLinkOptions);
		}

		// In EXTERNAL_LINK_MODE the visitor is anonymous (no portal session): the internal
		// viewDocument action below is gated by a portal login (prefilter strictRight +
		// getCurrentUser()), so an anonymous "downgrade/reload to view" navigation (JS sets
		// document.location = linkToView on session close) would land on the login/403 page
		// instead of the public document. Route to the public external-link page (authorized by
		// the ExternalLink hash, not by a CurrentUser) so the guest stays on the public surface.
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			$externalLink = $documentSession->getContext()?->getExternalLink();
			if ($externalLink instanceof Disk\ExternalLink)
			{
				return $this->getUrlManager()->getUrlExternalLink([
					'hash' => $externalLink->getHash(),
					'action' => 'default',
				]);
			}
		}

		// An edit session must not be passed to DocumentService::viewDocumentAction():
		// its stale-session rotation clones the original TYPE, which can keep the request
		// in edit mode. Start a fresh VIEW session through Vibeoffice so the session manager
		// applies the current content-version snapshot.
		$viewParams = [
			'objectId' => $documentSession->getObjectId(),
		];

		$attachedObjectId = (int)$documentSession->getContext()?->getAttachedObjectId();
		if ($attachedObjectId > 0)
		{
			$viewParams['attachedObjectId'] = $attachedObjectId;
		}

		$versionId = (int)$documentSession->getVersionId();
		if ($versionId > 0)
		{
			$viewParams['versionId'] = $versionId;
		}

		return (new Disk\Controller\Vibeoffice())->getActionUri('loadDocumentViewer', $viewParams);
	}

	protected function getLinkToDownload(Disk\Document\Models\DocumentSession $documentSession)
	{
		if (isset($this->arParams['LINK_TO_DOWNLOAD']))
		{
			return (string)$this->arParams['LINK_TO_DOWNLOAD'];
		}

		$file = $documentSession->getFile();

		$downloadUrl = (new Disk\Controller\DocumentService())->getActionUri(
			actionName: 'downloadDocument',
			params: [
				'documentSessionId' => $documentSession->getId(),
				'documentSessionHash' => $documentSession->getExternalHash(),
			],
		);

		if ($file && $this->unifiedLinkMode)
		{
			return $this->unifiedLinkUrl->addUlsToUrl(
				url: $downloadUrl,
				uls: $this->unifiedLinkAccessService->getUlsForObjectId($file->getId()),
			);
		}

		return $downloadUrl;
	}

	protected function canDownloadDocument(Disk\Document\Models\DocumentSession $documentSession): bool
	{
		if (empty($this->arResult['LINK_TO_DOWNLOAD']))
		{
			return false;
		}

		if ($this->arResult['EXTERNAL_LINK_MODE'])
		{
			return ($this->arParams['DOWNLOAD_ALLOWED_BY_EXTERNAL_LINK'] ?? false) === true;
		}

		$file = $documentSession->getFile();

		$securityContext = $file
			?->getStorage()
			->getCurrentUserSecurityContext(
				$this->arResult['EXTERNAL_LINK_MODE']
					|| $this->unifiedLinkMode,
			)
		;

		return $securityContext
			&& $documentSession->canDownload($securityContext);
	}

	protected function getUserIdForOnline(): int
	{
		if ($this->currentUser instanceof Disk\Document\Models\GuestUser)
		{
			return $this->currentUser->getUniqueId();
		}

		return $this->currentUser->getId();
	}

	private function getRestrictionOutcome(): ?string
	{
		$outcome = $this->arParams['RESTRICTION_OUTCOME'] ?? null;

		return in_array($outcome, self::RESTRICTION_OUTCOMES, true) ? $outcome : null;
	}

	/**
	 * The platform is still busy with the document - closing, saving or failing over - and the
	 * controller has already spent its retry budget on it. Such a refusal passes, so the reader gets
	 * the screen of the branch of saving with a button of retry instead of the generic failure.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function chooseOpenFailureTemplate(array $errors): string
	{
		foreach ($errors as $error)
		{
			if ($error->getCode() === self::IN_PROGRESS_ERROR_CODE)
			{
				return 'saving';
			}
		}

		return 'cloud-error';
	}

	private function findSavedRefusalReason(DocumentSession $session): ?string
	{
		return Vibeoffice\Webhook\DeliveryTable::findLatestSafeRefusalReason(
			Vibeoffice\HostDocumentKey::resolve($session),
		);
	}

	/** @param \Bitrix\Main\Error[] $errors */
	private function resolveOpenRetryDelay(array $errors): int
	{
		foreach ($errors as $error)
		{
			if ($error->getCode() !== self::IN_PROGRESS_ERROR_CODE)
			{
				continue;
			}

			$data = $error->getCustomData();
			$retryAfter = is_array($data) ? ($data['retryAfter'] ?? null) : null;

			return is_int($retryAfter) && $retryAfter >= 1 && $retryAfter <= 5 ? $retryAfter : 2;
		}

		return 0;
	}

	private function getOpeningNoticeCode(): ?string
	{
		$code = $this->arParams['OPENING_NOTICE_CODE'] ?? null;

		return in_array($code, self::OPENING_NOTICE_CODES, true) ? $code : null;
	}

	/**
	 * The address the reader moves to editing by. Only a path of this very portal is kept: an address
	 * naming a host of its own would turn the button of the editor into an open redirect.
	 */
	private function getLinkToEdit(): string
	{
		$link = $this->arParams['LINK_TO_EDIT'] ?? null;
		if (!is_string($link) || preg_match(self::SAME_PORTAL_PATH_PATTERN, $link) !== 1)
		{
			return '';
		}

		return $link;
	}
}
