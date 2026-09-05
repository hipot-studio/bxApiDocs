<?php

use Bitrix\Disk;
use Bitrix\Disk\Document\LocalDocumentController;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\document\SharingControlType;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\User;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Document\Vibeoffice\Service\PresenceAccessService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

Loader::requireModule('disk');

/**
 * Server-rendered shell for the vibeoffice document editor (Web).
 *
 * Parallel to {@see CDiskFileEditorOnlyOfficeComponent}, but additive: the editor config
 * is built and signed by the vibeoffice platform, not by Bitrix. This component only
 * renders the page chrome and proxies the platform open-response (DTO-EDITORCONFIG)
 * inline to the browser as `arResult['OPEN_CONFIG']`; the helper (phase P2) feeds it
 * to `@vibeoffice/helper createEditor({ openConfig })` as-is.
 *
 * The promo/boost/upsell UI and the editor-engine split-button of the OnlyOffice shell
 * are intentionally NOT carried over (ADR §8). The double rights check (File +
 * AttachedObject) and the session resolution stay on the backend.
 */
class CDiskFileEditorVibeofficeComponent extends BaseComponent implements Controllerable
{
	// Shared with the OnlyOffice component on purpose: the concurrent-edit-session limit is
	// global per portal (one RestrictionManager / one restriction log, keyed by EXTERNAL_HASH,
	// no SERVICE split), so vibeoffice and OnlyOffice draw from the same pool.
	public const ERROR_CODE_EXCEEDED_LIMIT = 'exceeded_limit';
	public const ERROR_CODE_COULD_NOT_LOCK = 'could_not_lock';

	protected User $currentUser;
	protected OnlyOffice\RestrictionManager $restrictionManager;
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

		// The general (engine-agnostic) restriction manager of the disk module; reused as-is.
		$this->restrictionManager = new OnlyOffice\RestrictionManager();

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

		if (isset($this->arParams['TEMPLATE']) && $this->arParams['TEMPLATE'] === 'not-found')
		{
			$this->includeComponentTemplate('not-found');

			return;
		}

		if (!isset($this->arParams['SHOW_BUTTON_OPEN_NEW_WINDOW']))
		{
			$this->arParams['SHOW_BUTTON_OPEN_NEW_WINDOW'] = true;
		}

		/** @var Disk\Document\Models\DocumentSession $documentSession */
		$documentSession = $this->arParams['DOCUMENT_SESSION'];

		$this->arResult['EXTERNAL_LINK_MODE'] = (bool)($this->arParams['EXTERNAL_LINK_MODE'] ?? false);

		if ($documentSession->isNonActive())
		{
			$documentSession->setAsActive();
		}

		$allowEdit = $this->isEditAllowed($documentSession);

		// True only while a real advisory lock is held; guarantees exactly one real unlock on every
		// exit path (success, early-return, exception) and never an unlock on a lock we did not take.
		$restrictionLocked = false;
		try
		{
			// ALG-RESTRICT: enforce the global concurrent-edit-session limit before the platform
			// builds an edit config. This mirrors the OnlyOffice component (shared RestrictionManager,
			// same restriction log), but on exceed the reaction is a HARD downgrade to view —
			// no promo/boost/upsell slider (ADR §8): there is no commercial cap to lift here.
			if ($documentSession->isEdit() && $allowEdit && $this->shouldUseRestriction())
			{
				if ($this->lockForRestriction())
				{
					$restrictionLocked = true;

					if (!$this->isAllowedEditByRestriction($documentSession->getExternalHash(), $this->currentUser->getId()))
					{
						$this->errorCollection[] = new Disk\Internals\Error\Error(
							'Exceeded limit.',
							self::ERROR_CODE_EXCEEDED_LIMIT,
							['limit' => $this->restrictionManager->getLimit()],
						);
					}
					else
					{
						$this->registerRestrictionUsage($documentSession->getExternalHash(), $this->currentUser->getId());
					}

					// Critical section is exactly the check-then-register on the shared session
					// counter (isAllowedEdit → registerUsage); once that decision is committed the
					// counter is consistent, so release the portal-global exclusive lock right here.
					// The synchronous platform `open` (getEditorConfig*) and the template render below
					// MUST run OUTSIDE this lock: holding it across a network call serialized every
					// concurrent edit-open of the portal on platform latency and drove them into
					// COULD_NOT_LOCK on degradation. The finally block stays as a safety net for an
					// exception thrown between lock acquisition and this point.
					$this->unlockForRestriction();
					$restrictionLocked = false;
				}

				if (
					$this->getErrorByCode(self::ERROR_CODE_EXCEEDED_LIMIT)
					|| $this->getErrorByCode(self::ERROR_CODE_COULD_NOT_LOCK)
				)
				{
					// The lock is already released above (or was never taken on COULD_NOT_LOCK), so
					// the finally block is a no-op on this early-return path.
					$this->processRestrictionError($documentSession);

					return;
				}
			}

			// The platform builds & signs the config; the backend proxies the open-response
			// (DTO-EDITORCONFIG) verbatim. The component does NOT construct or sign it.
			//
			// The config channel is chosen by the access context of the visitor:
			//  - EXTERNAL_LINK_MODE: the visitor is anonymous (no portal session). Route to the
			//    external channel, which authorizes by the ExternalLink (validity/password/allowEdit)
			//    instead of a CurrentUser — otherwise the internal channel would return null for a
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
			if (!is_array($openConfig))
			{
				// Server-side open failure: invalid/unsigned config or platform refusal. The user
				// sees a clear server-rendered cloud-error page (analog of the OnlyOffice cloud-error
				// surface) instead of a silent refusal; the controller errors are collapsed for the
				// user but logged here so the diagnostics are not lost.
				$errors = $controller->getErrors();
				if ($errors)
				{
					$messages = array_map(static fn($error) => $error->getMessage(), $errors);
					AddMessage2Log(
						'disk.file.editor-vibeoffice: could not build editor config for document session '
							. $documentSession->getId() . ': ' . implode('; ', $messages),
						'disk',
					);
				}

				// The restriction lock (if it was ever taken) has already been released in the
				// ALG-RESTRICT block above, before this network open; nothing to unlock here.
				$this->includeComponentTemplate('cloud-error');

				return;
			}

			$editMode = $documentSession->isEdit();
			$this->arResult['EDITOR'] = [
				'MODE' => $editMode
					? OnlyOffice\Editor\ConfigBuilder::MODE_EDIT
					: OnlyOffice\Editor\ConfigBuilder::MODE_VIEW,
				'ALLOW_EDIT' => $allowEdit,
			];

			// "Open in..." split-menu handlers, parity with the OnlyOffice shell
			// ({@see CDiskFileEditorOnlyOfficeComponent::getDocumentHandlersForEditingFile()}).
			// Only meaningful for the in-portal editor: in EXTERNAL_LINK_MODE the header renders a
			// plain edit button with no menu, so the list is left empty there.
			$this->arResult['DOCUMENT_HANDLERS'] = $this->arResult['EXTERNAL_LINK_MODE']
				? []
				: $this->getDocumentHandlersForEditingFile();
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
			$this->arResult['LINK_OPEN_NEW_WINDOW'] = $this->getLinkToView($documentSession);
			$this->arResult['LINK_TO_EDIT'] = $this->getLinkToEdit($documentSession);
			$this->arResult['LINK_TO_DOWNLOAD'] = $this->getLinkToDownload($documentSession);

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

			$this->arResult['SHOULD_DISABLE_SHARING_BUTTON'] = $this->shouldDisableSharingButton();
			$this->arResult['SHARING_CONTROL_TYPE'] = $this->getSharingControlType($documentSession)?->value;

			if (!$this->arResult['EXTERNAL_LINK_MODE'])
			{
				//for BX.desktopUtils.runningCheck
				Loader::includeModule('im');
			}

			if ($this->arResult['EXTERNAL_LINK_MODE'] && ModuleManager::isModuleInstalled('bitrix24'))
			{
				$this->arResult['HEADER_LOGO_LINK'] = 'https://bitrix24.com';
			}
			elseif ($this->arResult['EXTERNAL_LINK_MODE'] && !ModuleManager::isModuleInstalled('bitrix24'))
			{
				$this->arResult['HEADER_LOGO_LINK'] = UrlManager::getInstance()->getHostUrl();
			}
			else
			{
				$proxyTypeUser = Driver::getInstance()->getStorageByUserId($this->currentUser->getId())?->getProxyType();
				if (!$proxyTypeUser)
				{
					// The restriction lock (if it was ever taken) has already been released in the
					// ALG-RESTRICT block above; nothing to unlock here.
					$this->includeComponentTemplate('not-found');

					return;
				}
				if ($proxyTypeUser instanceof Disk\ProxyType\User)
				{
					$this->arResult['HEADER_LOGO_LINK'] = $proxyTypeUser->getBaseUrlDocumentList();
				}
			}

			// The flat OpenConfig (doc_id/host_doc_key/joined/editor_key/rev/session_id/
			// session_expires_at/vo_sess/urls{}/config) is proxied straight from the platform
			// and inlined for the helper; the host never rebuilds or re-signs it (ADR §3).
			$this->arResult['OPEN_CONFIG'] = Json::encode($openConfig['openConfig'] ?? []);
			$this->arResult['PRESENCE_CONFIG'] = $this->buildPresenceConfig($documentSession);

			// Reuse the existing Disk object pull channel (object_{id}) so an already-open VIEW
			// gets the live "content updated" host notification, mirroring the OnlyOffice shell
			// (CDiskFileEditorOnlyOfficeComponent). Boost / realtimeForceReloadTag are NOT carried
			// over: vibeoffice has no booster servers (ADR §3). The backend already emits the
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

			$this->includeComponentTemplate();
		}
		finally
		{
			// Safety net for the narrow critical section: on the normal path the lock is already
			// released inline (right after the check-then-register on the counter), so this is a
			// no-op. It only fires if an exception is thrown between lock acquisition and that
			// inline release. Gated by $restrictionLocked so exactly one unlock happens and never
			// on a lock that was never taken.
			if ($restrictionLocked)
			{
				$this->unlockForRestriction();
				$restrictionLocked = false;
			}
		}
	}

	/**
	 * Presence is never initialized for a public ExternalLink, even if its session context is malformed.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildPresenceConfig(DocumentSession $documentSession): array
	{
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			return ['enabled' => false];
		}

		return (new PresenceAccessService())->createConfig(
			$documentSession,
			(int)CurrentUser::get()->getId(),
			$this->unifiedLinkMode
				? PresenceAccessService::ACCESS_TYPE_UNIFIED
				: PresenceAccessService::ACCESS_TYPE_INTERNAL,
		);
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

	protected function getLinkToEdit(Disk\Document\Models\DocumentSession $documentSession)
	{
		if ($this->unifiedLinkMode)
		{
			$unifiedLinkOptions = [
				'additionalQueryParams' => [
					'analytics' => $this->arParams['ANALYTICS'] ?? null,
				],
			];

			$attachedObjectId = (int)$documentSession->getContext()?->getAttachedObjectId();
			if ($attachedObjectId > 0)
			{
				$unifiedLinkOptions['attachedId'] = $attachedObjectId;
			}

			return $this->getUrlManager()->getUnifiedEditLink($documentSession->getFile(), $unifiedLinkOptions);
		}

		if (isset($this->arParams['LINK_TO_EDIT']))
		{
			return $this->arParams['LINK_TO_EDIT'];
		}

		return (new Disk\Controller\DocumentService())->getActionUri(
			'goToEdit',
			[
				'documentSessionId' => $documentSession->getId(),
				'documentSessionHash' => $documentSession->getExternalHash(),
				'serviceCode' => Vibeoffice\VibeofficeHandler::getCode(),
			]
		);
	}

	protected function getLinkToDownload(Disk\Document\Models\DocumentSession $documentSession)
	{
		if (isset($this->arParams['LINK_TO_DOWNLOAD']))
		{
			return $this->arParams['LINK_TO_DOWNLOAD'];
		}

		/** @see \Bitrix\Disk\Controller\DocumentService::downloadDocumentAction */
		return (new Disk\Controller\DocumentService())->getActionUri(
			'downloadDocument',
			[
				'documentSessionId' => $documentSession->getId(),
				'documentSessionHash' => $documentSession->getExternalHash(),
			]
		);
	}

	protected function getSharingControlType(Disk\Document\Models\DocumentSession $documentSession): ?SharingControlType
	{
		if ($this->arResult['EXTERNAL_LINK_MODE'] || !$documentSession->getObject())
		{
			return null;
		}

		if (
			!Bitrix24Manager::isFeatureEnabled('disk_file_sharing')
			&& !Bitrix24Manager::isFeatureEnabled('disk_onlyoffice_edit')
		)
		{
			return SharingControlType::BlockedByFeature;
		}

		$currentUser = CurrentUser::get();
		if (!$documentSession->canUserChangeRights($currentUser) && !$documentSession->canUserShare($currentUser))
		{
			return SharingControlType::WithoutEdit;
		}
		if ($documentSession->canUserChangeRights($currentUser))
		{
			return SharingControlType::WithChangeRights;
		}
		if ($documentSession->canUserShare($currentUser))
		{
			return SharingControlType::WithSharing;
		}

		return SharingControlType::WithoutEdit;
	}

	protected function getUserIdForOnline(): int
	{
		if ($this->currentUser instanceof Disk\Document\Models\GuestUser)
		{
			return $this->currentUser->getUniqueId();
		}

		return $this->currentUser->getId();
	}

	protected function isEditAllowed(Disk\Document\Models\DocumentSession $documentSession): bool
	{
		$allowEdit = false;

		if (
			!$documentSession->isVersion()
			&& Vibeoffice\VibeofficeHandler::isEditable($documentSession->getObject()->getExtension())
		)
		{
			if ($this->unifiedLinkMode)
			{
				$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);

				$attachedObject = $documentSession->getContext()?->getAttachedObject();
				$accessLevel = $unifiedLinkAccessService->check($documentSession->getObject(), $attachedObject);

				return $accessLevel->canEdit();
			}

			$allowEdit = $documentSession->canTransformUserToEdit(CurrentUser::get());
			if ($allowEdit && $this->arResult['EXTERNAL_LINK_MODE'])
			{
				$externalLink = $documentSession->getContext()->getExternalLink();
				if ($externalLink && !$externalLink->allowEdit())
				{
					$allowEdit = false;
				}
			}
		}

		return $allowEdit;
	}

	/**
	 * @return bool
	 */
	protected function shouldDisableSharingButton(): bool
	{
		return !$this->currentUser->isIntranetUser() && !$this->currentUser->isCollaber();
	}

	/**
	 * Builds the "Open in..." menu item list for the header edit split-button, in the exact
	 * shape and order of the OnlyOffice shell
	 * ({@see CDiskFileEditorOnlyOfficeComponent::getDocumentHandlersForEditingFile()}):
	 * cloud FileCreatable handlers first, then the "Local applications" pseudo-handler.
	 *
	 * Parity note on the "own" engine item ("Битрикс24.Docs", badge "new", edit-here):
	 * that item is {@see OnlyOfficeHandler} (code 'onlyoffice', name "Битрикс24.Docs"), NOT the
	 * VibeofficeHandler. When vibeoffice is enabled, DocumentHandlersManager::getHandlers() still
	 * exposes the OnlyOfficeHandler (buildDocumentHandlerList keeps it whenever the onlyoffice
	 * config/custom server is present), and DocumentHandlersManager::resolveEffectiveHandler()
	 * routes the 'onlyoffice' code to the VibeofficeHandler — so clicking "Битрикс24.Docs" opens
	 * the current (vibeoffice) editor. This keeps the menu 1:1 with the OnlyOffice screenshot.
	 *
	 * The VibeofficeHandler is deliberately kept OUT of the list so it never shows up as a separate,
	 * second visible engine item ("Редактор VibeOffice"): the vibeoffice engine is additive and is
	 * reached through the same 'onlyoffice' code. This exclusion is now handled centrally via
	 * {@see Disk\Document\DocumentHandler::isSelectableForCreation()}, applied by
	 * DocumentHandlersManager::getHandlersForCreatingFile().
	 */
	private function getDocumentHandlersForEditingFile(): array
	{
		$handlers = [];
		foreach ($this->listCloudHandlersForCreatingFile() as $handler)
		{
			$handlers[] = [
				'code' => $handler::getCode(),
				'name' => $handler::getName(),
			];
		}

		return array_merge($handlers, [
			[
				'code' => LocalDocumentController::getCode(),
				'name' => LocalDocumentController::getName(),
			],
		]);
	}

	/**
	 * @return Disk\Document\DocumentHandler[]
	 */
	private function listCloudHandlersForCreatingFile(): array
	{
		if (!\Bitrix\Disk\Configuration::canCreateFileByCloud())
		{
			return [];
		}

		return array_values(Driver::getInstance()->getDocumentHandlersManager()->getHandlersForCreatingFile());
	}

	// region ALG-RESTRICT — global concurrent-edit-session limit (shared RestrictionManager) ----

	private function shouldUseRestriction(): bool
	{
		return $this->restrictionManager->shouldUseRestriction();
	}

	private function isAllowedEditByRestriction(string $documentKey, int $userId): bool
	{
		return $this->restrictionManager->isAllowedEdit($documentKey, $userId);
	}

	private function registerRestrictionUsage(string $documentKey, int $userId): void
	{
		$this->restrictionManager->registerUsage($documentKey, $userId);
	}

	private function lockForRestriction(): bool
	{
		if (!$this->restrictionManager->lock())
		{
			$this->errorCollection[] = new Disk\Internals\Error\Error(
				'Could not get exclusive lock',
				self::ERROR_CODE_COULD_NOT_LOCK,
			);

			return false;
		}

		return true;
	}

	private function unlockForRestriction(): void
	{
		$this->restrictionManager->unlock();
	}

	/**
	 * Hard downgrade to view on a hit limit: unlike OnlyOffice, there is NO promo/boost/upsell
	 * here (ADR §8). The edit session is collapsed to a view session and a plain limit-exceeded
	 * page is rendered.
	 */
	protected function processRestrictionError(Disk\Document\Models\DocumentSession $documentSession): void
	{
		$documentSession->transformToView();

		$limitError = $this->getErrorByCode(self::ERROR_CODE_EXCEEDED_LIMIT);
		$this->arResult['LIMIT'] = [
			'VALUE' => $limitError ? (int)($limitError->getCustomData()['limit'] ?? 0) : 0,
		];

		$this->includeComponentTemplate('limit-exceeded');
	}

	// endregion
}
