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
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Main\Application;
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

	private const OPENING_NOTICE_CODES = [
		OpeningNoticeCodeAware::OBJECT_LOCK_EDIT_VIEW,
	];
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
			$this->prepareOpenFailureScreen(null, 'cloud-error');
			$this->includeComponentTemplate('open-error');

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
		$this->arResult['SIGN_PLACEHOLDERS_ENABLED'] = $this->isSignPlaceholdersEnabled();

		if (in_array($this->arParams['TEMPLATE'] ?? null, ['not-found', 'cloud-error'], true))
		{
			$this->prepareOpenFailureScreen(null, $this->arParams['TEMPLATE']);
			$this->includeComponentTemplate('open-error');

			return;
		}

		/** @var Disk\Document\Models\DocumentSession $documentSession */
		$documentSession = $this->arParams['DOCUMENT_SESSION'];

		$this->arResult['EXTERNAL_LINK_MODE'] = (bool)($this->arParams['EXTERNAL_LINK_MODE'] ?? false);

		if ($documentSession->isNonActive())
		{
			if ($documentSession->isEdit())
			{
				$this->prepareOpenFailureScreen($documentSession, 'cloud-error');
				$this->includeComponentTemplate('open-error');

				return;
			}

			$documentSession->setAsActive();
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
		// Not wired: ajax.php passes OPENING_BUDGET as a parameter, this component never reads it, so
		// Controller\Vibeoffice::setOpeningBudget() is never called and the opening deadline stays
		// empty. Wiring it changes the timing of an open, so it waits for the owner of the restart
		// layer (ROL-102, @see Disk\Controller\Vibeoffice::$openingBudget).
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
			$template = $this->chooseOpenFailureTemplate($errors);
			foreach ($errors as $error)
			{
				if ($error->getCode() === Vibeoffice\OpenFailureCode::SaveRefused->value)
				{
					$this->arResult['OPEN_FAILURE_KIND'] = 'save_refused';
					$this->arResult['SAVE_REFUSAL_REASON'] = $this->findSavedRefusalReason($documentSession);
					break;
				}
				if ($error->getCode() === Vibeoffice\OpenFailureCode::CapacityFull->value)
				{
					$this->arResult['OPEN_RETRY_URL'] = Disk\Document\Vibeoffice\RestartTarget::build(
						$documentSession,
						$this->arParams['TRUSTED_OPENING'] ?? [],
					);
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

			if ($this->shouldReleaseAfterOpenFailure($template, $errors))
			{
				$this->releaseFailedEditSession($documentSession, $template === 'no-capacity');
			}
			if ($template !== 'saving')
			{
				$this->prepareOpenFailureScreen($documentSession, $template);
			}
			$this->includeComponentTemplate($template === 'saving' ? 'saving' : 'open-error');

			return;
		}

		$documentSession = $openedDocumentSession;
		if ($documentSession->isView())
		{
			$this->releaseFailedEditSession($this->arParams['DOCUMENT_SESSION']);
		}
		$this->arParams['DOCUMENT_SESSION'] = $documentSession;
		$this->arResult['VO_OPEN_REFUSAL'] = [
			'VIEW_ONLY_BY_FORMAT' => $this->isViewOnlyByFormat($documentSession),
		];
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
		$this->arResult['CAN_DOWNLOAD'] = $this->arResult['LINK_TO_DOWNLOAD'] !== '';
		// The engine draws the button of editing by the sole fact an address is there, so a document
		// the portal leaves to viewing - a restricted format, an exhausted personal quota - is given
		// none: the shared header names the reason instead (@see HeaderCompositionService).
		$this->arResult['LINK_TO_EDIT'] = $documentSession->findEditingRefusal() === null
			? $this->getLinkToEdit()
			: ''
		;

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
			// An in-portal visitor without a storage of their own has nothing to open the document
			// from, so the page answers with the "not found" surface instead of an empty editor.
			$proxyTypeUser = Driver::getInstance()->getStorageByUserId($this->currentUser->getId())?->getProxyType();
			if (!$proxyTypeUser)
			{
				$this->prepareOpenFailureScreen(null, 'not-found');
			$this->includeComponentTemplate('open-error');

				return;
			}
		}

		// The flat OpenConfig (doc_id/host_doc_key/joined/editor_key/rev/session_id/
		// session_expires_at/vo_sess/urls{}/config) is proxied straight from the platform
		// and inlined for the helper; the host never rebuilds or re-signs it.
		$this->arResult['OPEN_CONFIG'] = Json::encode($openConfig['openConfig'] ?? []);
		$this->arResult['API_JS_URL'] = $openConfig['openConfig']['urls']['api_js'] ?? '';
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
			$versionId = (int)$documentSession->getVersionId();
			if ($versionId > 0)
			{
				$unifiedLinkOptions['versionId'] = $versionId;
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

	protected function getLinkToDownload(Disk\Document\Models\DocumentSession $documentSession): string
	{
		$selection = Vibeoffice\OpenRoutePolicy::selectSession($documentSession);
		if ($selection === null || !$this->hasDownloadRight($documentSession))
		{
			return '';
		}

		$file = $selection['file'];
		$version = $selection['version'];
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			$url = $this->arParams['LINK_TO_DOWNLOAD'] ?? '';

			return is_string($url) && preg_match(self::SAME_PORTAL_PATH_PATTERN, $url) === 1 ? $url : '';
		}

		$downloadUrl = (new Disk\Controller\DocumentService())->getActionUri(
			actionName: 'downloadDocument',
			params: [
				'documentSessionId' => $documentSession->getId(),
				'documentSessionHash' => $documentSession->getExternalHash(),
			],
		);

		if ($this->unifiedLinkMode)
		{
			return $this->unifiedLinkUrl->addUlsToUrl(
				url: $downloadUrl,
				uls: $version
					? $this->unifiedLinkAccessService->getUlsForVersion($file->getId(), $version->getId())
					: $this->unifiedLinkAccessService->getUlsForObjectId($file->getId()),
			);
		}

		return $downloadUrl;
	}

	protected function canDownloadDocument(Disk\Document\Models\DocumentSession $documentSession): bool
	{
		return !empty($this->arResult['LINK_TO_DOWNLOAD']) && $this->hasDownloadRight($documentSession);
	}

	protected function hasDownloadRight(DocumentSession $documentSession): bool
	{
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			$file = $documentSession->getFile();
			$link = $this->getPublicLink($documentSession);
			if (!$file || !$link instanceof Disk\ExternalLink
				|| !$link->getObject()
				|| !$this->publicLinkMatchesSessionContent($documentSession, $link)
				|| $link->isExpired()
				|| !ServiceLocator::getInstance()->get(ExternalLinkOpenPolicy::class)->canOpenByPortalPolicy($link)
				|| ($link->hasPassword()
					&& !ServiceLocator::getInstance()->get(ExternalLinkPasswordService::class)->isConfirmed($link)))
			{
				return false;
			}
			return $link->allowEdit()
				|| ServiceLocator::getInstance()->get(DownloadAccess::class)->checkExternalLink($file, $link);
		}

		$file = $documentSession->getFile();

		$securityContext = $file
			?->getStorage()
			->getCurrentUserSecurityContext(
				$this->arResult['EXTERNAL_LINK_MODE']
					|| $this->unifiedLinkMode,
			)
		;

		return $securityContext instanceof Disk\Security\SecurityContext
			&& $documentSession->canDownload($securityContext);
	}

	protected function getPublicLink(DocumentSession $session): ?Disk\ExternalLink
	{
		return $session->getContext()?->getExternalLink();
	}

	private function publicLinkMatchesSessionContent(DocumentSession $session, Disk\ExternalLink $link): bool
	{
		$file = $session->getFile();
		if (!$file)
		{
			return false;
		}
		$fileId = (int)$file->getRealObjectId();
		$linkObjectId = (int)$link->getObjectId();
		if ($linkObjectId !== $fileId)
		{
			$linkObject = $link->getObject();
			if (!$linkObject instanceof Disk\Folder || !(bool)Disk\Internals\ObjectPathTable::query()
				->setSelect(['ID'])
				->where('OBJECT_ID', $fileId)
				->where('PARENT_ID', $linkObjectId)
				->setLimit(1)
				->fetch())
			{
				return false;
			}
		}

		$versionId = (int)$session->getVersionId();

		return $link->isSpecificVersion()
			? $versionId > 0 && (int)$link->getVersionId() === $versionId
			: $versionId === 0;
	}

	protected function getUserIdForOnline(): int
	{
		if ($this->currentUser instanceof Disk\Document\Models\GuestUser)
		{
			return $this->currentUser->getUniqueId();
		}

		return $this->currentUser->getId();
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
			if ($error->getCode() === Vibeoffice\OpenFailureCode::InProgress->value)
			{
				return 'saving';
			}
		}
		foreach ($errors as $error)
		{
			$template = Vibeoffice\OpenFailureCode::tryFrom((string)$error->getCode())?->template();
			if ($template !== null)
			{
				return $template;
			}
		}

		return 'cloud-error';
	}

	private function prepareOpenFailureScreen(?DocumentSession $session, string $kind): void
	{
		$screen = Vibeoffice\OpenFailureScreen::forKind(
			$kind,
			$this->arResult['OPEN_FAILURE_KIND'] ?? null,
			$session?->isEdit() ?? false,
		);
		$selection = $session ? Vibeoffice\OpenRoutePolicy::selectSession($session) : null;
		$source = $selection ? ($selection['version'] ?? $selection['file']) : null;
		if ($screen['fileName'] && $source)
		{
			$screen['name'] = (string)$source->getName();
		}
		if ($screen['fileSize'] && $source)
		{
			$screen['size'] = (string)CFile::FormatSize($source->getSize());
		}
		$screen['allowedActions'] = [];
		foreach ($screen['actions'] as $action)
		{
			$url = match ($action)
			{
				'download' => $session && $source ? $this->getLinkToDownload($session) : '',
				'view' => $session ? (string)$this->getLinkToView($session, exactlyView: true) : '',
				'return' => $this->getSafeReturnUrl(),
				'retry' => (string)($this->arResult['OPEN_RETRY_URL'] ?? ''),
				default => '',
			};
			if ($url !== '' && preg_match(self::SAME_PORTAL_PATH_PATTERN, $url) === 1)
			{
				$screen['allowedActions'][] = [
					'type' => $action,
					'label' => match ($action)
					{
						'download' => 'DISK_FILE_EDITOR_VIBEOFFICE_LARGE_FILE_DOWNLOAD',
						'view' => 'DISK_FILE_EDITOR_VIBEOFFICE_FILETYPE_VIEW',
						'return' => 'DISK_FILE_EDITOR_VIBEOFFICE_OPEN_OTHER_RETURN',
						'retry' => $screen['kind'] === 'no-capacity'
							? 'DISK_FILE_EDITOR_VIBEOFFICE_NO_CAPACITY_RETRY'
							: 'DISK_FILE_EDITOR_VIBEOFFICE_OPEN_EXPIRED_RETRY',
					},
					'url' => $url,
				];
			}
		}
		if ($screen['kind'] === 'cloud-error' && isset($screen['reasonLabel']))
		{
			$screen['reason'] = (string)($this->arResult['SAVE_REFUSAL_REASON'] ?? '');
		}
		if ($screen['kind'] === 'limit-exceeded')
		{
			$screen['limit'] = (int)($this->arResult['LIMIT']['VALUE'] ?? 0);
		}
		$this->arResult['OPEN_FAILURE_SCREEN'] = $screen;

		// Keep the existing templates functional until the shared renderer replaces them.
		$this->arResult['VO_OPEN_REFUSAL'] = ['VIEW_ONLY_BY_FORMAT' => false];
		$this->arResult['OBJECT'] = [
			'NAME' => $screen['name'] ?? '',
			'SIZE_READABLE' => $screen['size'] ?? '',
		];
		$this->arResult['OPEN_REFUSAL_VIEW_URL'] = '';
		$this->arResult['LINK_TO_RETURN'] = '';
		$this->arResult['LINK_TO_DOWNLOAD'] = '';
		foreach ($screen['allowedActions'] as $action)
		{
			match ($action['type'])
			{
				'download' => $this->arResult['LINK_TO_DOWNLOAD'] = $action['url'],
				'view' => $this->arResult['OPEN_REFUSAL_VIEW_URL'] = $action['url'],
				'return' => $this->arResult['LINK_TO_RETURN'] = $action['url'],
				default => null,
			};
		}
		$this->arResult['CAN_DOWNLOAD'] = $this->arResult['LINK_TO_DOWNLOAD'] !== '';
	}

	private function shouldReleaseAfterOpenFailure(string $template, array $errors): bool
	{
		if ($template === 'saving')
		{
			return false;
		}

		foreach ($errors as $error)
		{
			if (Vibeoffice\OpenFailureCode::tryFrom((string)$error->getCode())?->releasesEditSlot())
			{
				return true;
			}
		}

		return false;
	}

	private function getSafeReturnUrl(): string
	{
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			return '';
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return '';
		}
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		$root = $storage?->getRootObject();
		if (!$root || !$root->canRead($storage->getCurrentUserSecurityContext()))
		{
			return '';
		}
		$url = (string)Driver::getInstance()->getUrlManager()->getUrlForUserDisk($userId);

		return preg_match(self::SAME_PORTAL_PATH_PATTERN, $url) === 1 ? $url : '';
	}

	private function isViewOnlyByFormat(DocumentSession $session): bool
	{
		$selection = Vibeoffice\OpenRoutePolicy::selectSession($session);

		return $session->isView() && ($selection === null || !$selection['editable']);
	}

	private function releaseFailedEditSession(DocumentSession $session, bool $removeLocalSession = false): void
	{
		if ((!$session->isEdit() && !$removeLocalSession)
			|| $session->getService() !== Disk\Document\Models\DocumentService::Vibeoffice
			|| !$session->getId())
		{
			return;
		}
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			// Public sessions belong to the guest (-1) or to the temporary DocumentEditorUser.
			// The link id in the session context, rather than its user id, identifies this channel.
			// A revoked link may no longer load, but its refused edit slot still must be released.
			if (!$session->getContext()?->getExternalLinkId())
			{
				return;
			}
		}
		elseif ((int)$session->getUserId() !== (int)CurrentUser::get()->getId())
		{
			return;
		}
		(new Vibeoffice\TerminalSessionFinalizer())->releaseRefusedEditSession($session, $removeLocalSession);
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
			if ($error->getCode() !== Vibeoffice\OpenFailureCode::InProgress->value)
			{
				continue;
			}

			$data = $error->getCustomData();
			$retryAfter = is_array($data) ? ($data['retryAfter'] ?? null) : null;

			return is_int($retryAfter) && $retryAfter >= 1 && $retryAfter <= 5 ? $retryAfter : 2;
		}

		return 0;
	}

	private function isSignPlaceholdersEnabled(): bool
	{
		$request = Application::getInstance()->getContext()->getRequest();
		if ($request->get('signPlaceholders') !== 'Y')
		{
			return false;
		}

		return Loader::includeModule('sign');
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
