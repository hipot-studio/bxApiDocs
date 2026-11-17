<?php

use Bitrix\Disk;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Component\DocumentHeaderPage;
use Bitrix\Disk\Internal\Service\Bitrix24SiteUrlResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\User;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Result;
use Bitrix\Main\Session\SessionInterface;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Pull\Config;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

Loader::requireModule('disk');

class CDiskFileEditorOnlyOfficeComponent extends BaseComponent implements Controllerable
{
	use DocumentHeaderPage;

	public const ERROR_CODE_EXCEEDED_LIMIT = 'exceeded_limit';
	public const ERROR_CODE_COULD_NOT_LOCK = 'could_not_lock';
	public const ERROR_CODE_DOCUMENT_SERVICE_REFUSED = 'document_service_refused';
	protected const SESSION_SHOW_IMMEDIATELY_KEY = 'disk.promoShowImmediately';
	protected const AUTO_FORCE_RELOAD_AFTER = 300_000; // 5 minutes in ms

	private const OPTION_CATEGORY_TO_CONTROL_BUTTON_WIDGET_DISPLAY = 'session-boost-in-editor';

	protected User $currentUser;
	protected OnlyOffice\Configuration $onlyOfficeConfiguration;
	protected OnlyOffice\RestrictionManager $restrictionManager;
	protected Disk\Integration\Baas\BaasSessionBoostService $sessionBoostService;
	protected Disk\Public\Provider\CustomServerAvailabilityProvider $customServerAvailabilityProvider;
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkUrl $unifiedLinkUrl;
	private bool $unifiedLinkAccessOnly = false;
	private bool $unifiedLinkMode = false;
	/** The short opening the editor is shown for, resolved again right before the config is built. */
	private ?ShortLinkOpening $shortLinkOpening = null;

	protected function processBeforeAction($actionName)
	{
		$this->onlyOfficeConfiguration = new OnlyOffice\Configuration();
		$this->restrictionManager = new OnlyOffice\RestrictionManager();

		if (!OnlyOffice\OnlyOfficeHandler::isEnabled())
		{
			return false;
		}

		$this->sessionBoostService = new Disk\Integration\Baas\BaasSessionBoostService();

		$serviceLocator = ServiceLocator::getInstance();
		$this->customServerAvailabilityProvider = $serviceLocator->get(
			Disk\Public\Provider\CustomServerAvailabilityProvider::class,
		);
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
		$this->unifiedLinkAccessOnly = (bool)($this->arParams['UNIFIED_LINK_ACCESS_ONLY'] ?? false);
	}

	public function configureActions()
	{
		return [];
	}

	protected function processActionDefault()
	{
		$this->arResult['UNIFIED_LINK_MODE'] = $this->unifiedLinkMode;
		$this->arResult['UNIFIED_LINK_ACCESS_ONLY'] = $this->unifiedLinkAccessOnly;
		$this->arResult['FILE_UNIQUE_CODE'] = $this->arParams['FILE_UNIQUE_CODE'] ?? '';
		$this->arResult['SIGN_PLACEHOLDERS_ENABLED'] = $this->isSignPlaceholdersEnabled();

		if (isset($this->arParams['TEMPLATE']) && $this->arParams['TEMPLATE'] === 'not-found')
		{
			$this->includeComponentTemplate('not-found');

			return;
		}

		/** @var Disk\Document\Models\DocumentSession $documentSession */
		$documentSession = $this->arParams['DOCUMENT_SESSION'];

		if ($this->isCustomServerUnavailable())
		{
			$documentSession->setAsNonActive();
			$this->includeComponentTemplate('custom-server-unavailable');

			return;
		}

		// A short opening is checked before anything leaves it, the move to viewing on the limit included.
		$this->arResult['EXTERNAL_LINK_MODE'] = (bool)($this->arParams['EXTERNAL_LINK_MODE'] ?? false);
		if (!$this->guardShortLinkOpening($documentSession))
		{
			$this->includeComponentTemplate('not-found');

			return;
		}

		if ($documentSession->isEdit())
		{
			if (!$this->canUseEditByScenario())
			{
				$this->leaveForViewOnExceededLimit($documentSession);

				return;
			}
		}

		$documentInfo = $documentSession->getInfo();
		if (!$documentInfo)
		{
			$documentInfo = $documentSession->createInfo();
		}

		if ($documentSession->isEdit() && $documentInfo->isAbandoned())
		{
			DocumentSessionTable::deactivateByHash($documentSession->getExternalHash());
			$documentSession = $documentSession->cloneWithNewHash($documentSession->getUserId());
			$documentInfo = $documentSession->getInfo();
		}
		if ($documentInfo->isSaving())
		{
			$this->processSavingTemplate($documentSession);

			return;
		}

		if ($documentSession->isNonActive())
		{
			$documentSession->setAsActive();
		}

		$sessionGetParams = [
			'userId' => $documentSession->getUserId(),
			'sourceDocumentSessionId' => $documentSession->getId(),
			'documentSessionHash' => $documentSession->getExternalHash(),
		];
		$onlyOfficeController = new Disk\Controller\OnlyOffice();

		$allowRename = false;
		$allowEdit = $this->isEditAllowed($documentSession);
		if ($documentSession->isEdit() && !$documentSession->isVersion())
		{
			$allowRename = $documentSession->canUserRename(CurrentUser::get());
		}

		$configBuilder = new OnlyOffice\Editor\ConfigBuilder($documentSession);
		$configBuilder
			->allowEdit($allowEdit)
			->allowRename($allowRename)
			->setUser($this->currentUser)
			/** @see Disk\Controller\OnlyOffice::handleOnlyOfficeAction() */
			->setCallbackUrl($onlyOfficeController->getActionUri('handleOnlyOffice', $sessionGetParams, true))
			/** @see Disk\Controller\OnlyOffice::downloadAction() */
			->setDocumentUrl($onlyOfficeController->getActionUri('download', $sessionGetParams, true))
		;

		if ($this->arParams['EDITOR_MODE'] === OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_COMPACT)
		{
			$configBuilder
				->hideRightMenu()
				->hideRulers()
				->setCompactHeader()
				->setCompactToolbar()
			;
		}

		$this->arResult['EDITOR'] = [
			'MODE' => $configBuilder->getMode(),
			'ALLOW_EDIT' => $allowEdit,
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
		// A short opening stays on its own address: viewing is the address itself, and editing is the
		// transition posted to it.
		$this->arResult['LINK_OPEN_NEW_WINDOW'] = $this->shortLinkOpening?->getCanonicalUrl()
			?? $this->getLinkToView($documentSession)
		;
		$this->arResult['LINK_TO_EDIT'] = $this->shortLinkOpening?->getCanonicalUrl()
			?? $this->getLinkToEdit($documentSession)
		;
		// The entry moves only an employee by his own right; a guest of the link goes on in the mode of the link.
		$this->arResult['SHORT_LINK'] = $this->shortLinkOpening === null ? null : [
			'URL' => $this->shortLinkOpening->getCanonicalUrl(),
			'CODE' => $this->shortLinkOpening->getCode(),
			'EDIT_OPERATION' => $this->arResult['EXTERNAL_LINK_MODE']
				? Disk\Controller\ShortLink::OPERATION_CONTINUE
				: Disk\Controller\ShortLink::OPERATION_MODE_TRANSITION
			,
		];
		$this->arResult['LINK_TO_DOWNLOAD'] = $this->getLinkToDownload($documentSession);
		$this->arResult['CAN_DOWNLOAD'] = $this->canDownloadDocument($documentSession);

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

		if (!$this->arResult['CAN_DOWNLOAD'])
		{
			$configBuilder->allowDownload(false);
			$configBuilder->allowPrint(false);
		}
		else
		{
			//for BX.desktopUtils.runningCheck
			Loader::includeModule('im');
		}

		$siteUrlResolver = Bitrix24SiteUrlResolver::create();
		if ($this->arResult['EXTERNAL_LINK_MODE'] && $siteUrlResolver->isCloudPortal())
		{
			$this->arResult['HEADER_LOGO_LINK'] = $siteUrlResolver->getUrl();
			if (Loader::includeModule('bitrix24') && !\CBitrix24::isCustomDomain())
			{
				$host = parse_url(UrlManager::getInstance()->getHostUrl(), PHP_URL_HOST);
				$baseUrlToLogo = new Uri("https://bitrix24public.com/{$host}");

				$configBuilder->setBaseUrlToLogo($baseUrlToLogo);
			}
		}
		elseif ($this->arResult['EXTERNAL_LINK_MODE'] && !$siteUrlResolver->isCloudPortal())
		{
			$this->arResult['HEADER_LOGO_LINK'] = UrlManager::getInstance()->getHostUrl();
		}
		else
		{
			$proxyTypeUser = Driver::getInstance()->getStorageByUserId($this->currentUser->getId())?->getProxyType();
			if (!$proxyTypeUser)
			{
				$this->includeComponentTemplate('not-found');

				return;
			}
			if($proxyTypeUser instanceof Disk\ProxyType\User)
			{
				$this->arResult['HEADER_LOGO_LINK'] = $proxyTypeUser->getBaseUrlDocumentList();
			}
		}

		$this->arResult['SESSION_BOOST_OPTIONS'] = $this->getSessionBoostOptions($documentSession);
		$restrictionLocked = false;

		try
		{
			if ($allowEdit && $configBuilder->isEditMode() && $this->shouldUseRestriction())
			{
				if ($this->lockForRestriction())
				{
					$restrictionLocked = true;
					if (
						!$this->isAllowedEditByRestriction($documentSession->getExternalHash(), $this->currentUser->getId())
					)
					{
						$this->errorCollection[] = new Disk\Internals\Error\Error('Exceeded limit.', self::ERROR_CODE_EXCEEDED_LIMIT, [
							'limit' => $this->restrictionManager->getLimit(),
						]);

						AddEventToStatFile(
							'disk',
							'disk_oo_limit_edit',
							$documentSession->getExternalHash(),
							ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getServer(),
							'',
							$this->currentUser->getId()
						);
					}
					else
					{
						$this->registerRestrictionUsage($documentSession->getExternalHash(), $this->currentUser->getId());
					}
				}

				if ($this->getErrorByCode(self::ERROR_CODE_EXCEEDED_LIMIT) || $this->getErrorByCode(self::ERROR_CODE_COULD_NOT_LOCK))
				{
					$this->processRestrictionError($documentSession);

					return;
				}
			}
		}
		catch (\RuntimeException $exception)
		{
			if (ServiceLocator::getInstance()->get(Disk\Internal\Service\Environment::class)->isCloudPortal())
			{
				throw $exception;
			}

			if ($restrictionLocked)
			{
				$this->unlockForRestriction();
			}

			$error = new Disk\Internals\Error\Error(
				'Document service is unavailable.',
				self::ERROR_CODE_DOCUMENT_SERVICE_REFUSED,
			);
			$this->errorCollection[] = $error;
			$this->arResult['SESSION_BOOST_OPTIONS'] = null;
			$this->processCloudError((new Result())->addError($error), $documentSession);

			return;
		}

		$editorJsonConfigResult = $this->getEditorJsonConfig($configBuilder);
		if (!$editorJsonConfigResult->isSuccess())
		{
			$this->processCloudError($editorJsonConfigResult, $documentSession);

			return;
		}

		$editorConfigData = $editorJsonConfigResult->getData();
		$this->arResult['SERVER'] = $this->getServerByEditorConfig($editorConfigData);

		$this->arResult['EDITOR_JSON'] = Json::encode($editorConfigData);

		$this->arResult['PULL_CONFIG'] = null;
		$publicPullConfigurator = new Disk\Document\Online\PublicPullConfigurator();
		if ($publicPullConfigurator->getErrors())
		{
			$this->errorCollection->add($publicPullConfigurator->getErrors());
		}
		else
		{
			$realObjectId = $documentSession->getObject()->getRealObjectId();
			$this->arResult['PULL_CONFIG'] = $publicPullConfigurator->getConfig($realObjectId);

			$this->arResult['PULL_USER_CONFIG'] = Config::get([
				'JSON' => true,
			]);
		}

		// The header of the page and the presence on it belong to one and the same opening, so the
		// page describes it once and answers both from that description.
		$openingContext = $this->buildOpeningContext(
			$documentSession,
			$this->arResult['CAN_DOWNLOAD'] ? $this->arResult['LINK_TO_DOWNLOAD'] : '',
		);
		$this->arResult['PRESENCE_CONFIG'] = $this->buildPresenceConfig($documentSession, $openingContext);
		$this->arResult['HEADER_COMPOSITION'] = $this->buildHeaderComposition($openingContext);

		if (OnlyOffice\OnlyOfficeHandler::shouldRestrictedBySize($documentSession->getObject()->getSize()))
		{
			$documentSession->setAsNonActive();
			$this->includeComponentTemplate('large-file');
		}
		else
		{
			$this->processLimitSlider();

			if ($configBuilder->isEditMode())
			{
				$realtimeForceReloadTag = new Disk\Realtime\Tags\OnlyOfficeForceReloadTag();
				$this->arResult['REALTIME_FORCE_RELOAD_TAG'] = $realtimeForceReloadTag->getName();
				$this->arResult['REALTIME_FORCE_RELOAD_COMMAND'] = Disk\Realtime\Events\OnlyOfficeForceReloadEvent::COMMAND;
				$this->arResult['AUTO_FORCE_RELOAD_AFTER'] = self::AUTO_FORCE_RELOAD_AFTER;

				$realtimeForceReloadTag->subscribe();
			}

			$this->includeComponentTemplate();
		}

		if ($this->shouldUseRestriction())
		{
			$this->unlockForRestriction();
		}
	}

	protected function isCustomServerUnavailable(): bool
	{
		$customServerType = Disk\Configuration::getDefaultViewerCustomConfigType();

		return
			$customServerType instanceof Disk\Internal\Enum\CustomServerTypes &&
			!$this->customServerAvailabilityProvider->isAvailableForUse();
	}

 	/**
	 * Fill necessary params for show selected slider.
	 * @return void
	 */
	protected function processLimitSlider(): void
	{
		$session = $this->getSession();
		$this->arResult['PROMO_SHOW_IMMEDIATELY'] = (bool)$session->get(self::SESSION_SHOW_IMMEDIATELY_KEY);

		$session->remove(self::SESSION_SHOW_IMMEDIATELY_KEY);
	}

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
		$this->restrictionManager->registerUsage($documentKey, $userId, DocumentService::OnlyOffice);
	}

	private function lockForRestriction(): bool
	{
		if (!$this->restrictionManager->lock())
		{
			$this->errorCollection[] = new Disk\Internals\Error\Error('Could not get exclusive lock', self::ERROR_CODE_COULD_NOT_LOCK);

			return false;
		}

		return true;
	}

	private function unlockForRestriction(): void
	{
		$this->restrictionManager->unlock();
	}

	/** @see CDiskFileEditorVibeofficeComponent::guardShortLinkOpening() */
	private function guardShortLinkOpening(Disk\Document\Models\DocumentSession $documentSession): bool
	{
		$opening = $this->arParams['SHORT_LINK_OPENING'] ?? null;
		if (!$opening instanceof ShortLinkOpening)
		{
			return true;
		}

		$this->shortLinkOpening = $opening->revalidateForEditor(
			$documentSession,
			$this->arParams['SHORT_LINK_MODE'] ?? null,
			(bool)$this->arResult['EXTERNAL_LINK_MODE'],
		);

		return $this->shortLinkOpening !== null;
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

		// A guest of a public link has no authentication for viewDocument, so the link page itself is reopened.
		$externalLink = ($this->arParams['EXTERNAL_LINK_MODE'] ?? false)
			? $documentSession->getContext()?->getExternalLink()
			: null;
		if ($externalLink && (int)$externalLink->getObjectId() === (int)$documentSession->getObjectId())
		{
			return $this->getUrlManager()->getUrlExternalLink([
				'hash' => $externalLink->getHash(),
				'action' => 'default',
			]);
		}

		/** @see \Bitrix\Disk\Controller\DocumentService::viewDocumentAction */
		return (new Disk\Controller\DocumentService())->getActionUri(
			'viewDocument',
			['documentSessionId' => $documentSession->getId()]
		);
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
				'serviceCode' => OnlyOffice\OnlyOfficeHandler::getCode(),
			]
		);
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

		if ($file && ($this->unifiedLinkMode || $this->unifiedLinkAccessOnly))
		{
			return $this->unifiedLinkUrl->addUlsToUrl(
				url: $downloadUrl,
				uls: $documentSession->isVersion()
					? $this->unifiedLinkAccessService->getUlsForVersion($file->getId(), $documentSession->getVersionId())
					: $this->unifiedLinkAccessService->getUlsForObjectId($file->getId()),
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
					|| $this->unifiedLinkMode
					|| $this->unifiedLinkAccessOnly,
			)
		;

		return $securityContext
			&& $documentSession->canDownload($securityContext);
	}

	protected function processSavingTemplate(Disk\Document\Models\DocumentSession $documentSession): void
	{
		$this->arResult['DOCUMENT_SESSION'] = [
			'ID' => $documentSession->getId(),
			'HASH' => $documentSession->getExternalHash(),
		];
		$this->arResult['OBJECT'] = [
			'ID' => $documentSession->getObjectId(),
			'NAME' => $documentSession->getObject()->getName(),
			'SIZE' => $documentSession->getObject()->getSize(),
		];
		// The editing a short opening was allowed is chosen again by its address, never inherited.
		$this->arResult['SHORT_LINK_CONTINUATION'] = $this->getShortLinkContinuation(
			$documentSession->isEdit() ? Disk\Controller\ShortLink::MODE_EDIT : Disk\Controller\ShortLink::MODE_VIEW,
		);

		$this->includeComponentTemplate('saving');
	}

	protected function getUserIdForOnline(): int
	{
		if ($this->currentUser instanceof Disk\Document\Models\GuestUser)
		{
			return $this->currentUser->getUniqueId();
		}

		return $this->currentUser->getId();
	}

	protected function getEditorJsonConfig(OnlyOffice\Editor\ConfigBuilder $configBuilder): Result
	{
		$cloudRegistrationData = (new OnlyOffice\Configuration())->getCloudRegistrationData();
		if ($cloudRegistrationData)
		{
			$configSigner = new OnlyOffice\Cloud\SingDocumentConfig(
				$cloudRegistrationData['serverHost']
			);

			return $configSigner->sign($configBuilder->build());
		}

		return (new Result())->setData($configBuilder->build());
	}

	protected function processRestrictionError(Disk\Document\Models\DocumentSession $documentSession): void
	{
		$cloudErrorResult = [];
		$error = $this->getErrorByCode(self::ERROR_CODE_EXCEEDED_LIMIT);

		if ($error)
		{
			$cloudErrorResult['LIMIT'] = [
				'RESTRICTION' => true,
				'LIMIT_VALUE' => $this->getErrorByCode(self::ERROR_CODE_EXCEEDED_LIMIT)->getCustomData()['limit'],
			];

			if (!Disk\Internal\Service\OnlyOffice\Promo\PromoBlocker::shouldBlockPromoForUser())
			{
				$this->leaveForViewOnExceededLimit($documentSession);

				return;
			}
		}

		$cloudErrorResult['ERRORS'] = $this->getErrors();
		$this->arResult['CLOUD_ERROR'] = $cloudErrorResult;

		$this->includeComponentTemplate('cloud-error');
	}

	protected function canUseEditByScenario(): bool
	{
		return (new OnlyOffice\Bitrix24Scenario())->canUseEdit();
	}

	/**
	 * Leaves the editing refused by the limit for viewing. A short opening stays on its address: viewing there is
	 * a continuation posted to it, since a GET would choose the mode of the source again, the very edit refused.
	 */
	protected function leaveForViewOnExceededLimit(Disk\Document\Models\DocumentSession $documentSession): void
	{
		$linkToView = $this->processExceededLimit($documentSession);
		if ($this->shortLinkOpening === null)
		{
			$this->redirectTo($linkToView);

			return;
		}

		$this->arResult['SHORT_LINK_CONTINUATION'] = $this->getShortLinkContinuation(
			Disk\Controller\ShortLink::MODE_VIEW,
		);
		$this->includeComponentTemplate('short-link-continuation');
	}

	protected function redirectTo(string|Uri $url): void
	{
		LocalRedirect($url);
	}

	/**
	 * The continuation of a short opening posted to its address in the given mode: the entry resolves the link
	 * again and lowers the mode of the source to the one asked, never raises it. Null without a short opening.
	 *
	 * @return array{url: string, fields: array<string, string>}|null
	 */
	private function getShortLinkContinuation(string $requestedMode): ?array
	{
		if ($this->shortLinkOpening === null)
		{
			return null;
		}

		return [
			'url' => $this->shortLinkOpening->getCanonicalUrl(),
			'fields' => [
				Disk\Controller\ShortLink::PARAM_CODE => $this->shortLinkOpening->getCode(),
				Disk\Controller\ShortLink::PARAM_OPERATION => Disk\Controller\ShortLink::OPERATION_CONTINUE,
				Disk\Controller\ShortLink::PARAM_REQUESTED_MODE => $requestedMode,
			],
		];
	}

	/**
	 * Generate URL for redirection and setting slider type for show in UI.
	 *
	 * @param Disk\Document\Models\DocumentSession $documentSession
	 * @return string url for redirection
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 */
	protected function processExceededLimit(Disk\Document\Models\DocumentSession $documentSession): string
	{
		$this->getSession()->set(self::SESSION_SHOW_IMMEDIATELY_KEY, true);
		$documentSession->transformToView();

		Application::getInstance()->addBackgroundJob(function () use ($documentSession) {
			$file = $documentSession->getFile();

			$analyticsEvent = (new AnalyticsEvent(
				event: 'oo_limit_edit',
				tool: 'docs',
				category: 'docs',
			))
				->setSubSection('old_element')
				->setP4("fileId_{$file->getId()}")
			;

			$cElement = $this->arParams['ANALYTICS']['c_element'] ?? null;

			if (is_string($cElement))
			{
				$analyticsEvent->setElement($cElement);
			}

			$docType = Disk\Analytics\Enum\DocumentTypeEnum::getByExtension($file->getExtension());

			if ($docType instanceof Disk\Analytics\Enum\DocumentTypeEnum)
			{
				$analyticsEvent->setP3($docType->value);
			}

			$analyticsEvent->send();
		});

		return $this->getLinkToView($documentSession, true);
	}

	protected function processCloudError(Result $result, Disk\Document\Models\DocumentSession $documentSession): void
	{
		$cloudErrorResult = [];

		$errorCollection = $result->getErrorCollection();
		/** @see \Bitrix\DocumentProxy\Controller\SignDocumentConfiguration::ERROR_CODE_EXCEEDED_LIMIT */
		if ($errorCollection->getErrorByCode('exceeded_limit') && !Disk\Internal\Service\OnlyOffice\Promo\PromoBlocker::shouldBlockPromoForUser())
		{
			$this->leaveForViewOnExceededLimit($documentSession);

			return;
		}
		/** @see \Bitrix\DocumentProxy\Engine\Filter\DemoRestriction::ERROR_DEMO_RESTRICTION */
		if ($errorCollection->getErrorByCode('demo_restriction'))
		{
			$cloudErrorResult['DEMO'] = [
				'END' => true,
			];
		}
		$cloudErrorResult['ERRORS'] = $errorCollection->toArray();

		$this->arResult['CLOUD_ERROR'] = $cloudErrorResult;

		$this->includeComponentTemplate('cloud-error');
	}

	private function getServerByEditorConfig(array $editorConfigData): string
	{
		if (!empty($editorConfigData['_server']))
		{
			return $editorConfigData['_server'];
		}

		return rtrim(ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getServer(), '/');
	}

	protected function isEditAllowed(Disk\Document\Models\DocumentSession $documentSession): bool
	{
		if ($documentSession->findEditingRefusal() !== null)
		{
			return false;
		}

		$allowEdit = false;

		if (
			!$documentSession->isVersion()
			&& OnlyOffice\OnlyOfficeHandler::isEditable($documentSession->getObject()->getExtension())
		)
		{
			// An employee of a short opening edits by his effective right: no other public link of the file counts.
			if ($this->unifiedLinkMode && $this->shortLinkOpening !== null)
			{
				return $this->shortLinkOpening->getEffectiveAccessLevel((int)CurrentUser::get()->getId())
					=== UnifiedLinkAccessLevel::Edit
				;
			}

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
	 * Get instance of session.
	 *
	 * @return SessionInterface session
	 */
	protected function getSession(): SessionInterface
	{
		return Application::getInstance()->getSession();
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

	private function getSessionBoostOptions(Disk\Document\Models\DocumentSession $documentSession): ?array
	{
		if ($documentSession->isEdit() && $this->sessionBoostService->isAvailable())
		{
			$extension = (string)$documentSession->getObject()?->getExtension();
			$completedDateTimestamp = (int)CUserOptions::GetOption(self::OPTION_CATEGORY_TO_CONTROL_BUTTON_WIDGET_DISPLAY, $extension, 0, $this->currentUser->getId());

			return [
				'shouldShowButtonWidgetInstantly' => $completedDateTimestamp === 0,
				'optionParamsToControlButtonWidgetDisplay' => [
					'category' => self::OPTION_CATEGORY_TO_CONTROL_BUTTON_WIDGET_DISPLAY,
					'name' => $extension,
				],
			];
		}

		return null;
	}
}
