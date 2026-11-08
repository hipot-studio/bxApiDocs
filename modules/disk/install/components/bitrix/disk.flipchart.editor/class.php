<?php

use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\DualMode\ConfigurationException;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotLog;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfile;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\Document\Flipchart\FramePolicy;
use Bitrix\Disk\Document\Flipchart\JwtService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\User;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Disk\Internals\DiskComponent;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Context;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die;

Loc::loadMessages(__FILE__);

class CDiskFlipchartViewerComponent extends DiskComponent
{

	const DISPLAY_VARIANTS = [
		'desktop',
		'mobile',
	];

	const DEFAULT_DISPLAY_VARIANT = 'desktop';

	const MOBILE_DISPLAY_VARIANT = 'mobile';

	private bool $isExternalLinkMode = false;
	private bool $isViewMode = false;
	private bool $isEditMode = false;
	private ?DocumentSession $session = null;

	private function convertDocumentId(int|string $documentId, $versionId = null): string
	{
		return BoardService::convertDocumentIdToExternal($documentId, $versionId);
	}

	private function generateToken(JwtService $jwt): ?string
	{
		$session = $this->session;
		$annotationsClaims = $this->getAnnotationsClaims();
		$commentsClaims = $this->getCommentsClaims();

		if ($this->isNewElement())
		{
			$jwt->addWebhookGetParam('newBoard', '1');
		}

		if ($_GET['c_element'] ?? null)
		{
			$jwt->addWebhookGetParam('c_element', $_GET['c_element']);
		}

		return $jwt->generateToken(
			$this->isViewMode,
			array_merge([
				'user_id' => $this->arParams['USER_ID'],
				'username' => $this->arParams['USERNAME'],
				'avatar_url' => $this->arParams['AVATAR_URL'] ?? null,
				'fileUrl' => $this->arParams['DOCUMENT_URL'],
				'download_link' => $this->arParams['DOCUMENT_URL'],
				'document_id' => $this->convertDocumentId($session->getObject()->getId(), $session->getVersionId()),
				'session_id' => $session->getExternalHash(),
				'file_name' => $this->arParams['ORIGINAL_FILE']?->getNameWithoutExtension() ?? $session->getObject()->getNameWithoutExtension(),
				'salt' => crc32($session->getCreateTime()),
				'analytics' => [
					'action' => 'open',
					'value1' => $this->isNewElement() ? 'new_element' : 'old_element',
				],
			], $annotationsClaims, $commentsClaims),
		);
	}

	/**
	 * @return array{annotations_access: string, annotations_api_url?: string}
	 */
	private function getAnnotationsClaims(): array
	{
		$apiUrl = Configuration::getAnnotationsApiUrl();
		if ($apiUrl === null)
		{
			return ['annotations_access' => 'none'];
		}

		$currentUser = CurrentUser::get();
		$isUnifiedLinkMode = (bool)($this->arParams['UNIFIED_LINK_MODE'] ?? false);
		$userId = (string)$this->arParams['USER_ID'];
		$requiresReadPermission = $this->isViewMode
			|| ($this->isEditMode && !self::isPersonalUserId($userId));
		$canRead = $requiresReadPermission
			&& (
				$this->isExternalLinkMode
				|| $isUnifiedLinkMode
				|| $this->session->canUserRead($currentUser)
			);
		$canEdit = $this->isEditMode
			&& ($isUnifiedLinkMode || $this->session->canUserEdit($currentUser));

		return self::buildAnnotationsClaims(
			$this->isViewMode,
			$this->isEditMode,
			$canRead,
			$canEdit,
			$userId,
			$apiUrl,
		);
	}

	/**
	 * @return array{annotations_access: string, annotations_api_url?: string}
	 */
	private static function buildAnnotationsClaims(
		bool $isViewMode,
		bool $isEditMode,
		bool $canRead,
		bool $canEdit,
		string $userId,
		?string $apiUrl,
	): array
	{
		if ($apiUrl === null)
		{
			return ['annotations_access' => 'none'];
		}

		$access = 'none';
		if ($isViewMode && $canRead)
		{
			$access = 'read';
		}
		elseif ($isEditMode && $canEdit)
		{
			$access = self::isPersonalUserId($userId) ? 'write' : ($canRead ? 'read' : 'none');
		}

		$claims = ['annotations_access' => $access];
		if ($access !== 'none')
		{
			$claims['annotations_api_url'] = $apiUrl;
		}

		return $claims;
	}

	/**
	 * @return array{comments_api_url?: string, can_comment?: bool}
	 */
	private function getCommentsClaims(): array
	{
		$isUnifiedLinkMode = (bool)($this->arParams['UNIFIED_LINK_MODE'] ?? false);
		$canEdit = $this->isEditMode
			&& ($isUnifiedLinkMode || $this->session->canUserEdit(CurrentUser::get()));

		return self::buildCommentsClaims(
			Configuration::getCommentsApiUrl(),
			$this->session->getVersionId() !== null,
			$canEdit,
			(string)$this->arParams['USER_ID'],
		);
	}

	/**
	 * Comments belong to the live board only, so a historical version session gets no address.
	 * Flip lets a session create comments only with `can_comment`, so the flag repeats the write
	 * rule of the comments API: an editing registered user writes, a reader and a guest only read.
	 * The API stays the final authority and checks the current rights on every request.
	 *
	 * @return array{comments_api_url?: string, can_comment?: bool}
	 */
	private static function buildCommentsClaims(
		?string $apiUrl,
		bool $isHistoricalVersion,
		bool $canEdit,
		string $userId,
	): array
	{
		if ($apiUrl === null || $isHistoricalVersion)
		{
			return [];
		}

		return [
			'comments_api_url' => $apiUrl,
			'can_comment' => $canEdit && self::isRegisteredUserId($userId),
		];
	}

	private static function isRegisteredUserId(string $userId): bool
	{
		return preg_match('/^[1-9][0-9]{0,18}$/D', $userId) === 1;
	}

	private static function isPersonalUserId(string $userId): bool
	{
		return preg_match('/^[1-9][0-9]{0,18}$/D', $userId) === 1
			|| preg_match('/^~[a-zA-Z0-9_-]+$/D', $userId) === 1;
	}

	private function getDisplayVariant()
	{
		$variant = $this->arParams['DISPLAY_VARIANT'] ?? $_GET['variant'] ?? null;
		if (!in_array($variant, self::DISPLAY_VARIANTS))
		{
			$variant = self::DEFAULT_DISPLAY_VARIANT;
		}

		return $variant;
	}

	private function prepareSdkParams(): void
	{
		$session = $this->session;
		$jwt = new JwtService($session->getUser());

		$profile = ServiceProfileResolver::createFromOptions()->resolveForEditor($session->getObject());
		if ($profile === null)
		{
			// null is a full outcome of the resolver, not an error: no instance may be contacted with this
			// object's id. Checking before the token is minted avoids a proxy round trip
			// (JwtService::generateToken() signs data on the proxy) for an object no instance may serve.
			PilotLog::error(
				'Board service profile is not resolved: {entryPoint}, object {objectId}',
				[
					'entryPoint' => 'prepareSdkParams',
					'objectId' => (int)$session->getObjectId(),
				],
			);

			throw new SystemException('Board service profile is not resolved.');
		}

		$token = $this->generateToken($jwt);

		if ($profile === ServiceProfile::New)
		{
			// The new instance is unknown to the cloud proxy, so this profile never goes through it.
			try
			{
				$appUrl = Configuration::getAppUrl(ServiceProfile::New);
			}
			catch (ConfigurationException $exception)
			{
				// executeComponent() swallows this on an ordinary request with debug off, so without an
				// unconditional record the user gets an empty slider and the portal logs hold nothing.
				PilotLog::error(
					'Board service app url is not configured: {entryPoint}, object {objectId}, {reason}',
					[
						'entryPoint' => 'prepareSdkParams',
						'objectId' => (int)$session->getObjectId(),
						'reason' => $exception->getMessage(),
					],
				);

				throw $exception;
			}
		}
		elseif (Configuration::isUsingDocumentProxy())
		{
			$appUrl = $jwt->getAppUrlFromProxy();
		}
		else
		{
			$appUrl = Configuration::getAppUrl();
		}

		if (!Configuration::isValidAppUrl($appUrl))
		{
			throw new SystemException('Flipchart app url is not a valid absolute url');
		}

		$this->arResult['DOCUMENT_SESSION'] = $session;
		$this->arResult['DOCUMENT_URL'] = $this->arParams['DOCUMENT_URL'];
		$this->arResult['DOCUMENT_ID'] = $this->convertDocumentId($session->getObject()->getId(), $session->getVersionId());
		$this->arResult['ORIGINAL_DOCUMENT_ID'] = $this->arParams['ORIGINAL_FILE']?->getId() ?? $session->getObject()->getId();
		$this->arResult['DOCUMENT_NAME'] = $this->arParams['ORIGINAL_FILE']?->getName() ?? $session->getObject()->getName();
		$this->arResult['DOCUMENT_NAME_WITHOUT_EXTENSION'] = $this->arParams['ORIGINAL_FILE']?->getNameWithoutExtension() ?? $this->arParams['DOCUMENT_NAME_WITHOUT_EXTENSION'] ?? $session->getObject()->getNameWithoutExtension();
		$this->arResult['SESSION_ID'] = $session->getExternalHash();
		$this->arResult['APP_URL'] = $appUrl;
		$this->arResult['TOKEN'] = $token;
		$this->arResult['ACCESS_LEVEL'] = $this->isViewMode ? 'readonly' : 'editable';
		$this->arResult['EDIT_BOARD'] = $this->isEditMode;
		$this->arResult['SHOW_TEMPLATES_MODAL'] = (bool)($this->arParams['SHOW_TEMPLATES_MODAL'] ?? false);
		$this->arResult['LANGUAGE'] = $this->getLanguage();
		$this->arResult['HEADER_LOGO_URL'] = $this->getHeaderLogoUrl();

		// Task wiring is only for boards served by the new service - the vendor app has no task
		// protocol, and emptying shareElementInBitrix there would strip its own create-task item.
		$this->arResult['TASKS_ENABLED'] = Configuration::isTasksEnabled() && $profile === ServiceProfile::New;

		$this->arResult['ANNOTATIONS_ENABLED'] = Configuration::isAnnotationsEnabled();
		$this->arResult['TAGS_ENABLED'] = Configuration::isTagsEnabled();
		$this->arResult['COMMENTS_ENABLED'] = Configuration::isCommentsEnabled();
	}

	private function prepareOtherParams(): void
	{
		$displayVariant = $this->getDisplayVariant();

		$this->arResult['DISPLAY_VARIANT'] = $displayVariant;
		$this->arResult['HEADER_COMPOSITION'] = $displayVariant === self::MOBILE_DISPLAY_VARIANT
			? null
			: $this->buildHeaderComposition()
		;
	}

	/**
	 * The header is drawn from the description the page carries, so the page it belongs to computes it.
	 * An absent description is not an error of the page: the header then asks the controller for it.
	 *
	 * The mobile variant of the page renders no container for the header at all, so it is not described
	 * either: nothing there would read the answer (@see templates/.default/template.php).
	 */
	private function buildHeaderComposition(): ?array
	{
		$locator = ServiceLocator::getInstance();
		$userId = (int)CurrentUser::get()->getId();

		return $locator
			->get(HeaderCompositionService::class)
			->composeForPage(
				$locator->get(OpeningContextFactory::class)->fromDocumentSession(
					$this->session,
					$userId,
					$this->hasUnifiedLinkProof(),
				),
				$userId,
			)
		;
	}

	/**
	 * The description is told whether the visitor holds the unified link of the board, and the page knows
	 * it two ways: the unified link drew the page itself - the handler reaches this component only after
	 * the chain answered for the uniqueCode it was given - or the request carries the signature of the
	 * object, which is how the transition from the viewer arrives. The shells of the office editors
	 * decide it the same way {@see \Bitrix\Disk\Internal\Component\DocumentHeaderPage}.
	 */
	private function hasUnifiedLinkProof(): bool
	{
		if ($this->arParams['UNIFIED_LINK_MODE'] ?? false)
		{
			return true;
		}

		$objectId = (int)$this->session?->getObjectId();
		if ($objectId <= 0)
		{
			return false;
		}

		$locator = ServiceLocator::getInstance();
		$uls = $locator->get(UnifiedLinkUrl::class)->getUlsFromRequest(Context::getCurrent()->getRequest());

		return $uls !== null
			&& $locator->get(UnifiedLinkAccessService::class)->validateUlsForObjectId($objectId, $uls)
		;
	}

	protected function prepareParams(): void
	{
		parent::prepareParams();

		if ($this->arParams['DOCUMENT_SESSION'] instanceof DocumentSession)
		{
			$this->session = $this->arParams['DOCUMENT_SESSION'];
			$this->isExternalLinkMode = (bool)($this->arParams['EXTERNAL_LINK_MODE'] ?? false);
			$this->isViewMode = $this->session->getType() === DocumentSession::TYPE_VIEW;
			$this->isEditMode = $this->session->getType() === DocumentSession::TYPE_EDIT;
		}
		else
		{
			throw new ArgumentException('DOCUMENT_SESSION is a required parameter!');
		}
	}

	protected function processActionDefault(): void
	{
		if (!FramePolicy::emit())
		{
			throw new SystemException('Frame policy cannot be applied: headers are already sent');
		}

		$this->prepareSdkParams();
		$this->prepareOtherParams();
		$this->includeComponentTemplate();
		$this->sendAnalytics();
	}

	protected function isNewElement(): bool
	{
		/** @var ?File $originalFile */
		$originalFile = $this->arParams['ORIGINAL_FILE'];

		return $originalFile && time() - $originalFile->getCreateTime()->getTimestamp() < 30;
	}

	protected function sendAnalytics(): void
	{
		$isNewElement = $this->isNewElement();

		Application::getInstance()->addBackgroundJob(function () use ($isNewElement) {
			$event = new AnalyticsEvent('open', 'boards', 'boards');
			if ($_GET['c_element'] ?? null)
			{
				$event->setElement($_GET['c_element']);
			}
			$event
				->setSubSection($isNewElement ? 'new_element' : 'old_element')
				->send()
			;
		});
	}

	private function getLanguage(): string
	{
		return in_array(Context::getCurrent()?->getLanguage(), Configuration::getAllowedLanguages())
			? Context::getCurrent()?->getLanguage()
			: Configuration::getDefaultLanguage();
	}

	private function getHeaderLogoUrl(): string
	{
		if ($this->isExternalLinkMode && ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 'https://bitrix24.com';
		}

		if ($this->isExternalLinkMode && !ModuleManager::isModuleInstalled('bitrix24'))
		{
			return UrlManager::getInstance()->getHostUrl();
		}

		$userId = $this->getCurrentUser()->getId();
		$proxyTypeUser = Driver::getInstance()->getStorageByUserId($userId)?->getProxyType();
		if ($proxyTypeUser instanceof \Bitrix\Disk\ProxyType\User)
		{
			return $proxyTypeUser->getBaseUrlBoardsList();
		}

		return '';
	}

	private function getCurrentUser(): User
	{
		if (CurrentUser::get()->getId())
		{
			return User::getById(CurrentUser::get()->getId());
		}

		return GuestUser::create();
	}

}
