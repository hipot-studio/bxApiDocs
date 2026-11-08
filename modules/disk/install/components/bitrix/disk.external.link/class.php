<?php

use Bitrix\Disk\Document\Flipchart\DocumentDownloadUrlService;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotLog;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\BoardsHandler;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\Document\FileData;
use Bitrix\Disk\Document\GoogleViewerHandler;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\SessionManager;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Document\Vibeoffice\PullInitializationSuppressor;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internal\Service\HtmlViewerPageService;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internal\Service\HtmlViewerService;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordProtectionService;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
use Bitrix\Disk\Internals\DiskComponent;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\TiffPreview\ErrorCode;
use Bitrix\Disk\Internal\Service\TiffPreview\PreviewStatus;
use Bitrix\Disk\Internal\Service\TiffPreviewService;
use Bitrix\Disk\Internal\Service\ExternalLink\PasswordProtectionResult;
use Bitrix\Disk\Internal\Service\ExternalLink\PasswordProtectionState;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Disk\Ui\Icon;
use Bitrix\Disk\Ui;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\ZipNginx;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;
use JetBrains\PhpStorm\NoReturn;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if(!\Bitrix\Main\Loader::includeModule('disk'))
{
	return false;
}

Loc::loadMessages(__FILE__);

class CDiskExternalLinkComponent extends DiskComponent
{
	protected const EXCEPTION_CODE_ACCESS_DENIED = 221880;

	public const PAGE_SIZE = 25;
	// An offset built from a larger page number overflows int and turns the LIMIT of the query into
	// a negative number, so the page number is capped before it reaches the offset.
	private const MAX_PAGE_NUMBER = 100000;
	// 44 hours: below it a change is shown as "yesterday at 13:48", above it as a full date.
	private const RELATIVE_UPDATE_TIME_THRESHOLD = 158400;
	public const MAX_SIZE_TO_PREVIEW = 15728640; //1024 * 1024 * 15 bytes

	private const ONLYOFFICE_FILE_VIEWER = 'onlyoffice';
	private const VIBEOFFICE_FILE_VIEWER = 'vibeoffice';
	private const BOARD_FILE_VIEWER = 'board';
	private const ACTION_DOWNLOAD_FILE_UNDER_FOLDER = 'downloadFileUnderFolder';
	private const ACTION_SHOW_FILE_UNDER_FOLDER = 'showFileUnderFolder';
	private const HTML_FILE_VIEWER = 'html';
	private const FILE_VIEWERS = [
		self::ONLYOFFICE_FILE_VIEWER,
		self::VIBEOFFICE_FILE_VIEWER,
		self::BOARD_FILE_VIEWER,
		self::HTML_FILE_VIEWER,
	];

	protected ExternalLinkProvider $externalLinkProvider;
	protected ExternalLinkPasswordService $externalLinkPasswordService;
	protected DownloadAccess $downloadAccess;
	protected ExternalLinkPasswordProtectionService $externalLinkPasswordProtectionService;
	protected ExternalLinkOpenPolicy $externalLinkOpenPolicy;
	/** @var ExternalLink */
	protected $externalLink;
	protected ?string $hash = null;
	protected bool $fromUnifiedLink = false;
	protected ?BaseObject $baseObject = null;
	protected ?AttachedObject $attachedObject = null;
	protected ?Version $version = null;
	protected ?string $unifiedLink = null;
	private ?array $unifiedLinkOptions = null;
	/** @var string */
	protected $downloadToken;
	/** @var DocumentHandler  */
	protected $defaultHandlerForView;
	protected $langId;
	private ?string $fullDateFormat = null;
	private bool $passwordValidationResultCached = false;
	private ?bool $passwordValidationResult = null;
	private ?PasswordProtectionResult $passwordProtectionResult = null;
	private ?PasswordProtectionState $passwordProtectionState = null;
	private ?string $passwordProtectionError = null;
	private bool $passwordProtectionRequestError = false;

	public function __construct($component = null)
	{
		parent::__construct($component);

		$serviceLocator = ServiceLocator::getInstance();
		$this->externalLinkProvider = $serviceLocator->get(ExternalLinkProvider::class);
		$this->externalLinkPasswordService = $serviceLocator->get(ExternalLinkPasswordService::class);
		$this->downloadAccess = $serviceLocator->get(DownloadAccess::class);
		$this->externalLinkPasswordProtectionService = new ExternalLinkPasswordProtectionService(
			$this->externalLinkPasswordService,
		);
		$this->externalLinkOpenPolicy = $serviceLocator->get(ExternalLinkOpenPolicy::class);
	}

	/**
	 * Common operations before run action.
	 * @param string $actionName Action name which will be run.
	 * @return bool If method will return false, then action will not execute.
	 */
	protected function processBeforeAction($actionName)
	{
		PullInitializationSuppressor::suppressForDocumentEditor(DocumentEditorUser::isCurrentUserDocumentEditor());

		$this->findLink();
		$this->maybeGenerateUnifiedLink($actionName);

		$this->defaultHandlerForView = $this->getHandlerForView();

		if (!$this->externalLinkOpenPolicy->canOpenByPortalPolicy($this->externalLink))
		{
			$this->maybeRefuseHtmlViewerContent();

			$this->arResult = [
				'ERROR_MESSAGE' => $this->getMessage('DISK_EXTERNAL_LINK_ERROR_DISABLED_MODE'),
			];
			$this->includeComponentTemplate('error');

			return false;
		}

		if (in_array($actionName, ['default', 'goToEdit'], true))
		{
			$this->downloadToken = Random::getString(12);
			$this->storeDownloadToken($this->downloadToken);

			if ($this->externalLink->allowEdit())
			{
				if ($this->isBoardsHandler() && $this->validatePassword() && $actionName !== 'goToEdit')
				{
					$this->redirectToAction('goToEdit');

					return true;
				}

				if (!$this->validatePassword() && $actionName !== 'default')
				{
					$this->redirectToAction('default');

					return false;
				}
			}
		}
		else
		{
			//so it means we work with action with file: download, show. We have to check download token.
			if (
				!$this->externalLink->isImage()
				&& !$this->externalLink->isAutomatic()
				&& !$this->checkDownloadToken($this->request->getQuery('token'))
			)
			{
				$this->maybeRefuseHtmlViewerContent();
				$this->redirectToAction('default', ['session' => 'expired']);
			}

			if ($this->validatePassword() !== true)
			{
				$this->maybeRefuseHtmlViewerContent();
				$this->showAccessDenied();

				return false;
			}
		}

		if ($this->externalLink->getObject()->isDeleted())
		{
			$this->showNotFoundPage();

			return false;
		}

		$this->arResult['FROM_UNIFIED_LINK'] = $this->fromUnifiedLink;
		$this->arResult['UNIFIED_LINK'] = $this->unifiedLink;

		return true;
	}

	#[NoReturn]
	private function redirectToAction(string $action, array $uriParams = []): void
	{
		$uriParams = [
			...$uriParams,
			'hash' => $this->externalLink->getHash(),
			'action' => $action,
		];
		$link = Driver::getInstance()->getUrlManager()->getUrlExternalLink($uriParams);

		$redirect = new Redirect($link);
		Application::getInstance()->end(0, $redirect);
	}

	private function isBoardsHandler(): bool
	{
		return $this->defaultHandlerForView instanceof BoardsHandler;
	}

	/**
	 * Keeps the html viewer speaking html, the same way the engine endpoints do
	 * ({@see \Bitrix\Disk\Infrastructure\Controller\HtmlViewerRefusalResponse}): the content is loaded
	 * into a plain iframe, so the redirect and the error pages the caller falls back to would render a
	 * portal page inside the frame instead of stating the reason.
	 *
	 * Guards every refusal of a content request: the token and password rubicons, and showNotFoundPage()
	 * for the rest — the trashed object, and the unresolvable link the exception handler lands on.
	 */
	private function maybeRefuseHtmlViewerContent(): void
	{
		if ($this->getAction() !== 'showHtml')
		{
			return;
		}

		$this->sendHtmlViewerResponse(
			ServiceLocator::getInstance()->get(HtmlViewerService::class)->unavailableResponse()
		);
	}

	#[NoReturn]
	private function sendHtmlViewerResponse(HttpResponse $response): void
	{
		$this->restartBuffer();
		Application::getInstance()->end(0, $response);
	}

	/**
	 * Maps the resolved office handler to the external-link file viewer name (ALG-EXTERNAL-VIEWER-DISPATCH).
	 *
	 * The engine decision is the single seam {@see \Bitrix\Disk\Document\DocumentHandlersManager::resolveEffectiveHandler()},
	 * NOT a local `instanceof` / {@see Vibeoffice\VibeofficeHandler::isEnabled()} check (principle 11.1):
	 * vibeoffice when the resolver picked it (flag ON), onlyoffice when it did not (flag OFF / vibeoffice not
	 * registered), and null for a non-office handler (board/google) so the caller behaves exactly as before.
	 */
	private function resolveDocumentViewer(?DocumentHandler $handler): ?string
	{
		if ($handler instanceof Vibeoffice\VibeofficeHandler)
		{
			return self::VIBEOFFICE_FILE_VIEWER;
		}

		if ($handler instanceof OnlyOffice\OnlyOfficeHandler)
		{
			return self::ONLYOFFICE_FILE_VIEWER;
		}

		return null;
	}

	protected function listActions()
	{
		return [
			'goToEdit',
			'download',
			'downloadFolderArchive',
			self::ACTION_DOWNLOAD_FILE_UNDER_FOLDER,
			self::ACTION_SHOW_FILE_UNDER_FOLDER,
			'showByGoogleViewer' => [
				'method' => ['GET', 'POST'],
			],
			'showByOnlyOfficeViewer' => [
				'method' => ['GET', 'POST'],
			],
			'showByBoardsViewer' => [
				'method' => ['GET', 'POST'],
			],
			'showViewHtml',
			'showHtml',
			'showFile',
			'showTiffPreview',
			'showPreview',
			'showView',
		];
	}

	protected function runProcessingExceptionComponent(Exception $e)
	{
		if (($e instanceof SystemException) && $e->getCode() === self::EXCEPTION_CODE_ACCESS_DENIED)
		{
			$this->showNotFoundPage();
		}
	}

	protected function prepareParams()
	{
		$hash = $this->request->get('hash');

		if (is_string($hash))
		{
			if (!ExternalLink::isValidValueForField('HASH', $hash, $this->errorCollection))
			{
				throw new SystemException('Hash contains invalid character', self::EXCEPTION_CODE_ACCESS_DENIED);
			}

			$this->hash = $hash;
		}

		$this->fromUnifiedLink = ($this->arParams['FROM_UNIFIED_LINK'] ?? false) === true;
		$this->baseObject = $this->arParams['BASE_OBJECT'] ?? $this->arParams['FILE'] ?? null;
		$this->attachedObject = $this->arParams['ATTACHED_OBJECT'] ?? null;
		$this->version = $this->arParams['VERSION'] ?? null;

		if (!is_string($this->hash) && !$this->baseObject instanceof BaseObject)
		{
			throw new SystemException('Neither hash nor object were provided', self::EXCEPTION_CODE_ACCESS_DENIED);
		}

		$this->langId = $this->request->get('langId') ?: LANGUAGE_ID;

		return $this;
	}

	private function isViewableDocument(string $ext): bool
	{
		return DocumentHandler::isEditable($ext) || (mb_strtolower($ext) === 'pdf');
	}

	/**
	 * The revision a version-pinned link serves — the very one processActionDownload() hands out, loaded
	 * within the file so an id belonging elsewhere resolves to nothing. Null on a link that is not pinned.
	 */
	private function resolvePinnedVersion(File $file): ?Version
	{
		return $this->externalLink->isSpecificVersion()
			? $file->getVersion($this->externalLink->getVersionId())
			: null
		;
	}

	/**
	 * Asks about the very content {@see buildHtmlViewerContentResponse()} serves, not about the file
	 * standing next to it: a version-pinned link serves that revision, and a version keeps the name it
	 * was saved under, so renaming the file cannot open the viewer page over a version it would refuse.
	 *
	 * The same split the content side makes, stated here as a verdict: HtmlViewerService answers a file
	 * by HtmlViewerPolicy::isViewable() and a version by its extension alone, a version carrying no
	 * stored type of its own.
	 */
	private function isHtmlViewerTarget(File $file, ?Version $pinnedVersion): bool
	{
		if (!$this->externalLink->isSpecificVersion())
		{
			return HtmlViewerPolicy::isViewable($file);
		}

		return Configuration::isEnabledHtmlViewer()
			&& HtmlViewerPolicy::isViewableExtension($pinnedVersion?->getExtension())
		;
	}

	private function storeDownloadToken($token)
	{
		$_SESSION['DISK_PUBLIC_VERIFICATION'][$this->externalLink->getObject()->getId()] = $token;
	}

	private function checkDownloadToken($token)
	{
		if($token === null)
		{
			return false;
		}

		return $_SESSION['DISK_PUBLIC_VERIFICATION'][$this->externalLink->getObject()->getId()] === $token;
	}

	protected function processActionGoToEdit()
	{
		// Html has no editor, so edit is the view page, exactly as on the authenticated path.
		$file = $this->externalLink->getFile();
		if ($file instanceof File && HtmlViewerPolicy::isViewable($file))
		{
			$this->redirectToAction('default');
		}

		if (!$this->externalLink->getFile() || !$this->externalLink->allowEdit())
		{
			$this->showNotFoundPage();

			return false;
		}

		$isDocument = $this->isViewableDocument($this->externalLink->getFile()->getExtension());
		$documentViewer = $this->resolveDocumentViewer($this->defaultHandlerForView);
		$isOfficeDocument = $documentViewer !== null && $isDocument;
		$isBoard = $this->isBoardsHandler();
		if ($isOfficeDocument || $isBoard)
		{
			$documentSession = $this->generateDocumentSession($this->externalLink->getFile());
			if (!$documentSession)
			{
				$this->showNotFoundPage();

				return false;
			}

			if ($documentSession->canTransformUserToEdit(CurrentUser::get()))
			{
				$fieldsToCreateUser = [
					'NAME' => GuestUser::create()->getName(),
				];

				if (DocumentEditorUser::login($fieldsToCreateUser))
				{
					$documentSession->setUserId(CurrentUser::get()->getId());
				}

				$createdSession = $this->getSessionServiceByFile($this->externalLink->getFile())
					=== DocumentService::Vibeoffice
						? $this->generateDocumentSession(
							$this->externalLink->getFile(),
							sessionType: DocumentSession::TYPE_EDIT,
						)
						: $documentSession->createEditSession()
				;
				if (!$createdSession)
				{
					$this->showNotFoundPage();

					return false;
				}

				if ($createdSession->getId() != $documentSession->getId())
				{
					$documentSession->delete();
				}

				$documentSession = $createdSession;
				$this->arResult['DOCUMENT_SESSION'] = $documentSession;
				$this->arResult['LINK_TO_DOWNLOAD'] = $this->getDownloadUrl();
				$this->arResult['CAN_DOWNLOAD'] = true;
			}

			if ($isBoard && !$this->setParamsForBoard($documentSession, $this->externalLink->getFile()))
			{
				$this->showNotFoundPage();

				return false;
			}

			$this->showFileViewer($isBoard ? self::BOARD_FILE_VIEWER : $documentViewer);
		}
		else
		{
			$this->showNotFoundPage();
		}
	}

	protected function processActionDefault($path = '/')
	{
		$isFolder = $this->externalLink->getObject() instanceof Folder;
		$isFile = !$isFolder;
		$validPassword = $this->validatePassword();
			if ($this->passwordProtectionRequestError)
			{
				$this->showAccessDenied();

				return;
			}

			if ($this->hasUnsafePasswordProtectionState())
			{
				$this->showAccessDenied();

				return;
			}

		$server = Application::getInstance()->getContext()->getServer();

		$this->arResult = array_merge($this->arResult ?? [], [
			'HASH' => $this->externalLink->getHash(),
			'PROTECTED_BY_PASSWORD' => $this->externalLink->hasPassword(),
			'VALID_PASSWORD' => $validPassword,
			'PASSWORD_PROTECTION' => $this->getPasswordProtectionData(),
			'SESSION_EXPIRED' => $this->request->getQuery('session') === 'expired',
			'SITE_NAME' => Option::get('main', 'site_name', $server->getServerName()),
		]);

		if ($isFile)
		{
			$passwordPassed = !$this->arResult['PROTECTED_BY_PASSWORD'] || $this->arResult['VALID_PASSWORD'];
			$file = $this->externalLink->getFile();
			$viewerSource = $this->externalLink->isSpecificVersion()
				? $this->externalLink->getVersion()
				: $file;
			$version = $viewerSource instanceof Version ? $viewerSource : null;
			$isDocument = $viewerSource !== null && $this->isViewableDocument($viewerSource->getExtension());

			// Html is answered before the board and office branches, the order the unified link keeps in
			// HtmlRenderableHandlerFactory: the same decision is made twice, so it is made the same
			// way. Shown right here instead of the download card: the same viewer page a portal user
			// gets, minus the access popup. The card stays the fallback while the viewer is switched
			// off, which is what the policy answers.
			$file = $this->externalLink->getFile();
			if ($passwordPassed && $file instanceof File)
			{
				// Resolved once and handed on: the gate and the header name the same revision, and the
				// page costs a single version load.
				$pinnedVersion = $this->resolvePinnedVersion($file);

				if ($this->isHtmlViewerTarget($file, $pinnedVersion))
				{
					$pageService = ServiceLocator::getInstance()->get(HtmlViewerPageService::class);
					$this->arResult['HTML_VIEWER'] = $pageService->buildParamsByExternalLink(
						$file,
						$this->externalLink,
						$this->downloadToken,
						$pinnedVersion,
					);
					// The card this page replaces carried the link preview, so the page carries it too; its
					// head belongs to the wrapper, hence the values here and the meta in file-viewers/html.php.
					$this->arResult['OPEN_GRAPH'] = [
						'URL' => $this->getUrlManager()->getPublicExternalLink($file, $this->externalLink->getHash()),
						'TITLE' => $file->getName(),
					];

					$this->showFileViewer(self::HTML_FILE_VIEWER);

					return;
				}
			}

			if ($this->isBoardsHandler() && $passwordPassed && $isDocument)
			{
				$documentSession = $this->generateDocumentSession($this->externalLink->getFile(), $version);

				if (!$documentSession)
				{
					$this->includeComponentTemplate('error');

					return;
				}

				if (!$this->setParamsForBoard($documentSession, $this->externalLink->getFile()))
				{
					$this->includeComponentTemplate('error');

					return;
				}

				$this->showFileViewer(self::BOARD_FILE_VIEWER);

				return;
			}

			$documentViewer = $this->resolveDocumentViewer($this->defaultHandlerForView);
			if ($documentViewer !== null && $passwordPassed && $isDocument)
			{
				$documentSession = $this->generateDocumentSession(
					$this->externalLink->getFile(),
					$version,
				);
				if (!$documentSession)
				{
					$this->showNotFoundPage();

					return;
				}

				$this->arResult['DOCUMENT_SESSION'] = $documentSession;

				$this->arResult['LINK_TO_EDIT'] = $this->buildEditLink(
					$this->externalLink->getFile(),
					Driver::getInstance()->getUrlManager(),
				);

				$file = $this->externalLink->getObject()?->getRealObject();
				$canDownload = $file instanceof File
					&& $this->canDownloadObjectByExternalLink($file);
				$this->arResult['CAN_DOWNLOAD'] = $canDownload;

				if ($canDownload)
				{
					$this->arResult['LINK_TO_DOWNLOAD'] = $this->getDownloadUrl();
				}

				$this->showFileViewer($documentViewer);

				return;
			}

			$this->arResult['FILE'] = $this->getResultByFile();
		}

		if($isFolder)
		{
			$rootFolder = $this->externalLink->getFolder();

			if (!$rootFolder)
			{
				return;
			}

			[$targetFolder, $relativeItems] = $this->getTargetFolderData($rootFolder, $path);
			if(!$targetFolder)
			{
				throw new SystemException('Wrong path', self::EXCEPTION_CODE_ACCESS_DENIED);
			}

			$this->arResult['FOLDER'] = $this->getResultByFolder();
			$this->arResult['ENABLED_MOD_ZIP'] = \Bitrix\Disk\ZipNginx\Configuration::isEnabled();
			$this->arResult['VIEWER_CODE'] = Configuration::getDefaultViewerServiceCode();
			$this->arResult['DISABLE_DOCUMENT_VIEWER'] = !Configuration::canCreateFileByCloud();
			if ($this->arResult['VIEWER_CODE'] != GoogleViewerHandler::getCode())
			{
				$this->arResult['DISABLE_DOCUMENT_VIEWER'] = true;
			}

			$this->arResult['FOLDER_LIST'] = $this->getFolderListData($rootFolder, $targetFolder, $path);
			$this->arResult['FOLDER_META'] = $this->getFolderMeta(
				$rootFolder,
				$targetFolder,
				(int)$this->arResult['FOLDER_LIST']['TOTAL_COUNT'],
			);
			$this->arResult['SHARE_URL'] = $this->getShareUrl($relativeItems);
			$this->arResult['BREADCRUMBS'] = $this->getBreadcrumbs($path, $relativeItems);
			$this->arResult['BREADCRUMBS_ROOT'] = [
				'NAME' => $rootFolder->getName(),
				'LINK' => $this->getUrlManager()->getUrlExternalLink([
					'hash' => $this->externalLink->getHash(),
					'action' => 'default',
				], true),
				'ID' => $this->externalLink->getObjectId(),
			];

			$securityContext = $rootFolder->getStorage()?->getSecurityContext($this->externalLink->getCreatedBy());
			if ($securityContext !== null)
			{
				$this->arResult['FILE_LIMIT_EXCEEDED'] = ZipNginx\Archive::isFileLimitExceededByFolder($rootFolder, $securityContext);
			}
			else
			{
				$this->arResult['FILE_LIMIT_EXCEEDED'] = true;
			}
		}

		$this->includeComponentTemplate($isFile ? 'template' : 'folder');
	}

	protected function validatePassword(): ?bool
	{
		if ($this->passwordValidationResultCached)
		{
			return $this->passwordValidationResult;
		}

		$this->passwordValidationResultCached = true;
		$this->passwordValidationResult = $this->doValidatePassword();

		return $this->passwordValidationResult;
	}

	private function doValidatePassword(): ?bool
	{
		// Allow access if no password
		if (!$this->externalLink->hasPassword())
		{
			return true;
		}

		if ($this->externalLinkPasswordService->isConfirmed($this->externalLink))
		{
			return true;
		}

		$passwordFieldExists = $this->request->getPostList()->offsetExists('PASSWORD');
		$password = (string)$this->request->getPost('PASSWORD');

		if (!$passwordFieldExists)
		{
			$this->ensurePasswordProtectionState();

			return $this->request->isPost()
				? false // restrict access
				: null; // show form
		}

		if (!check_bitrix_sessid())
		{
			$this->passwordProtectionRequestError = true;
			$this->passwordProtectionError = 'request_error';

			return false;
		}

		$this->passwordProtectionResult = $this->externalLinkPasswordProtectionService->checkPassword(
			$this->externalLink,
			$password,
			$this->getSourceIp(),
			$this->request->getPost('CAPTCHA_SID'),
			$this->request->getPost('CAPTCHA_WORD'),
		);

		if ($this->isUnsafePasswordProtectionResult($this->passwordProtectionResult))
		{
			$this->passwordProtectionRequestError = true;
			$this->passwordProtectionResult = null;
			$this->passwordProtectionError = 'request_error';

			return false;
		}

		if ($this->passwordProtectionResult->status !== PasswordProtectionResult::STATUS_SUCCESS)
		{
			$this->passwordProtectionError = $this->passwordProtectionResult->status;

			return false;
		}

		return true;
	}

		private function isUnsafePasswordProtectionResult(PasswordProtectionResult $result): bool
		{
			$missingCaptchaSid = $result->captchaRequired && $result->captchaSid === null;
		$checkedPasswordBeforeCaptcha
			= $result->status === PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED
			&& $result->passwordChecked;

			return $missingCaptchaSid || $checkedPasswordBeforeCaptcha;
		}

		private function hasUnsafePasswordProtectionState(): bool
		{
			return (
				$this->passwordProtectionState instanceof PasswordProtectionState
				&& $this->passwordProtectionState->captchaRequired
				&& $this->passwordProtectionState->captchaSid === null
			);
		}

		private function getPasswordProtectionData(): array
		{
		$captchaRequired = false;
		$captchaSid = null;

		if ($this->passwordProtectionResult instanceof PasswordProtectionResult)
		{
			$captchaRequired = $this->passwordProtectionResult->captchaRequired;
			$captchaSid = $this->passwordProtectionResult->captchaSid;
		}
		elseif ($this->passwordProtectionState instanceof PasswordProtectionState)
		{
			$captchaRequired = $this->passwordProtectionState->captchaRequired;
			$captchaSid = $this->passwordProtectionState->captchaSid;
		}

		return [
			'STATUS' => $this->passwordProtectionResult?->status,
			'ERROR' => $this->passwordProtectionError,
			'CAPTCHA_REQUIRED' => $captchaRequired,
			'CAPTCHA_SID' => $captchaSid,
		];
	}

	private function ensurePasswordProtectionState(): void
	{
		if ($this->passwordProtectionState instanceof PasswordProtectionState)
		{
			return;
		}

		$this->passwordProtectionState = $this->externalLinkPasswordProtectionService->getState(
			$this->externalLink,
			$this->getSourceIp(),
		);
	}

	private function getSourceIp(): string
	{
		return trim((string)$this->request->getRemoteAddress());
	}

	private function generateDocumentSession(
		File $file,
		?Version $version = null,
		?int $sessionType = null,
	): ?DocumentSession
	{
		$documentSessionContext = new DocumentSessionContext($file->getId(), null, $this->externalLink->getId());
		$service = $this->getSessionServiceByFile($file);

		// Session manager per engine (ALG-EXTERNAL-VIEWER-DISPATCH): the vibeoffice manager writes
		// SERVICE='vibeoffice' when the resolver picked vibeoffice (flag ON); otherwise (flag OFF, and
		// flipchart, which keeps going through the OnlyOffice manager) the OnlyOffice manager is used —
		// so with the flag off this is byte-for-byte the pre-vibeoffice path (zero regression).
		$sessionManager = $service === DocumentService::Vibeoffice
			? new Vibeoffice\DocumentSessionManager()
			: new OnlyOffice\DocumentSessionManager();
		$sessionManager
			->setUserId($this->getUser()->getId() ?: GuestUser::GUEST_USER_ID)
			->setSessionType($sessionType ?? $this->getSessionType())
			->setSessionContext($documentSessionContext)
			->setService($service)
		;
		$this->setDocumentSource($sessionManager, $file, $version);

		if (!$sessionManager->lock())
		{
			return null;
		}

		try
		{
			$documentSession = $sessionManager->findOrCreateSession();
		}
		finally
		{
			$sessionManager->unlock();
		}

		if ($sessionManager instanceof Vibeoffice\DocumentSessionManager)
		{
			$this->arResult['RESTRICTION_OUTCOME'] = $sessionManager->getRestrictionOutcome();
		}

		return $documentSession;
	}

	private function setDocumentSource(SessionManager $sessionManager, File $file, ?Version $version = null): void
	{
		$sessionManager
			->setFile($file)
			->setVersion($version)
		;
	}

	private function getSessionType(): int
	{
		if ($this->isBoardsHandler())
		{
			$file = $this->externalLink->getFile();

			$securityContext = $file?->getStorage()?->getCurrentUserSecurityContext();

			if ($file?->canUpdate($securityContext))
			{
				return DocumentSession::TYPE_EDIT;
			}
		}

		return DocumentSession::TYPE_VIEW;
	}

	private function getTargetFolderData(Folder $rootFolder, $path)
	{
		$data = Driver::getInstance()->getUrlManager()->resolvePathUnderRootObject($rootFolder->getRealObject(), $path);
		if (!$data)
		{
			return null;
		}

		return [Folder::loadById($data['OBJECT_ID']), $data['RELATIVE_ITEMS']];
	}

	protected function getBreadcrumbs($path, array $relativeItems)
	{
		$crumbs = [];

		$serverName = (Context::getCurrent()->getRequest()->isHttps() ? "https" : "http") . "://" . Context::getCurrent()->getServer()->getHttpHost();
		$uri = new Uri($serverName . $this->request->getRequestUri());
		$parts = explode('/', trim($path, '/'));

		foreach ($relativeItems as $i => $item)
		{
			if (empty($item))
			{
				continue;
			}

			$uri->deleteParams(
				array_merge(
					\Bitrix\Main\HttpRequest::getSystemParameters(),
					['path'],
				),
			);
			$uri->addParams(
				[
					'path' => implode('/', (array_slice($parts, 0, $i + 1))) ?: '',
				],
			);

			$crumbs[] = [
				'ID' => $item['ID'],
				'NAME' => $item['NAME'],
				'ENCODED_LINK' => $uri->getLocator(),
			];
		}
		unset($i, $item);

		return $crumbs;
	}

	/**
	 * Numbers of the folder the crumbs point at, shown above its file list. The count comes from the
	 * paged query, so it covers the whole folder and not the page on the screen.
	 */
	private function getFolderMeta(Folder $rootFolder, Folder $targetFolder, int $itemCount): array
	{
		// At the root the size is the one already counted for the archive button; a subfolder needs
		// its own count, which is a single query over the whole subtree.
		$size = $targetFolder->getRealObjectId() === $rootFolder->getRealObjectId()
			? (int)$this->arResult['FOLDER']['SIZE']
			: (int)$targetFolder->getRealObject()->countSizeOfFiles()
		;

		return [
			'ITEM_COUNT' => $itemCount,
			'SIZE' => $size,
			'FORMATTED_SIZE' => CFile::formatSize($size),
			'UPDATE_TIME' => $this->formatUpdateTime(
				$targetFolder->getUpdateTime()->toUserTime()->getTimestamp(),
				time() + CTimeZone::getOffset(),
			),
		];
	}

	/**
	 * Public address of the folder currently open, the way a crumb of it addresses it. Only the path of
	 * the request is kept and the query is rebuilt from the resolved folder names, so neither a foreign
	 * parameter nor the number of the page reaches the address that goes to the clipboard.
	 */
	private function getShareUrl(array $relativeItems): string
	{
		$context = Context::getCurrent();
		$scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
		$requestPath = (new Uri($this->request->getRequestUri()))->getPath();

		$uri = new Uri($scheme . '://' . $context->getServer()->getHttpHost() . $requestPath);

		$segments = [];
		foreach ($relativeItems as $item)
		{
			if (!empty($item['NAME']))
			{
				$segments[] = $item['NAME'];
			}
		}

		if ($segments)
		{
			$uri->addParams(['path' => implode('/', $segments)]);
		}

		return $uri->getLocator();
	}

	private function formatUpdateTime(int $timestampUpdate, int $nowTime): string
	{
		$this->fullDateFormat ??= preg_replace(
			'/:s$/',
			'',
			CDatabase::dateFormatToPHP(CSite::GetDateFormat('FULL')),
		);

		return ($nowTime - $timestampUpdate > self::RELATIVE_UPDATE_TIME_THRESHOLD)
			? formatDate($this->fullDateFormat, $timestampUpdate, $nowTime)
			: formatDate('x', $timestampUpdate, $nowTime)
		;
	}

	private function getFolderListData(Folder $rootFolder, Folder $targetFolder, string $path): array
	{
		$driver = Driver::getInstance();
		$storage = $rootFolder->getStorage();
		$securityContext = $storage->getSecurityContext($this->externalLink->getCreatedBy());
		$parameters = [
			'filter' => [
				'PARENT_ID' => $targetFolder->getRealObjectId(),
				'DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
		];

		$parameters = $driver->getRightsManager()->addRightsCheck($securityContext, $parameters, ['ID', 'CREATED_BY']);

		$pageSize = self::PAGE_SIZE;
		$pageNumber = min(max((int)$this->request->getQuery('pageNumber'), 1), self::MAX_PAGE_NUMBER);
		$parameters['count_total'] = true;
		$parameters['offset'] = $pageSize * ($pageNumber - 1);

		$relativePath = trim($path, '/');

		$page = $this->getFolderListPage($rootFolder, $parameters, $relativePath, $pageSize);
		// A page number over the last page returns nothing, which is indistinguishable from an empty
		// folder: a count over a page that fetched less rows than the limit is the offset itself, not
		// the total. The query of the first page is what tells the two apart and gives the true total.
		if (empty($page['ITEMS']) && $pageNumber > 1)
		{
			$pageNumber = 1;
			$parameters['offset'] = 0;
			$page = $this->getFolderListPage($rootFolder, $parameters, $relativePath, $pageSize);
		}

		return [
			'ITEMS' => $page['ITEMS'],
			'TOTAL_COUNT' => $page['TOTAL_COUNT'],
			'CURRENT_PAGE' => $pageNumber,
			'HAS_NEXT_PAGE' => $page['HAS_NEXT_PAGE'],
		];
	}

	private function getFolderListPage(Folder $rootFolder, array $parameters, string $relativePath, int $pageSize): array
	{
		// +1 because the row over the page is what tells about the existence of the next page
		$parameters['limit'] = $pageSize + 1;

		$nowTime = time() + CTimeZone::getOffset();

		$urlManager = Driver::getInstance()->getUrlManager();
		$objectsOnPage = [];
		$countObjectsOnPage = 0;
		$hasNextPage = false;
		$cursor = $rootFolder->getList($parameters);
		foreach ($cursor as $row)
		{
			$countObjectsOnPage++;
			if ($countObjectsOnPage > $pageSize)
			{
				$hasNextPage = true;
				break;
			}

			$objectsOnPage[] = BaseObject::buildFromArray($row);
		}

		$downloadSecurityContext = $rootFolder->getStorage()?->getCurrentUserSecurityContext(true);
		if ($downloadSecurityContext instanceof SecurityContext)
		{
			$this->preloadDownloadAccessForExternalLinkObjects($objectsOnPage, $downloadSecurityContext);
		}

		$items = [];
		foreach ($objectsOnPage as $object)
		{
			$name = $object->getName();
			$isFolder = $object instanceof Folder;
			$timestampUpdate = $object->getUpdateTime()->toUserTime()->getTimestamp();

			// A folder shortcut carries its own icon in the set, as in the file list of the portal.
			$iconName = $isFolder && $object->isLink()
				? 'folder-shared'
				: Ui\Icon::getIconSetNameByObject($object)
			;

			$item = [
				'NAME' => $name,
				'IS_FOLDER' => $isFolder,
				'ICON_NAME' => $iconName,
				'FORMATTED_SIZE' => $isFolder ? '' : CFile::formatSize($object->getSize()),
				'UPDATE_TIME' => $this->formatUpdateTime($timestampUpdate, $nowTime),
				'VIEWER_ATTRIBUTES' => '',
				'CAN_DOWNLOAD' => false,
			];

			if ($isFolder)
			{
				$uri = new Uri($this->request->getRequestUri());
				$uri->deleteParams(array_merge(
					\Bitrix\Main\HttpRequest::getSystemParameters(),
					['path', 'pageNumber'],
				));
				$uri->addParams([
					'path' => $relativePath . '/' . $name . '/',
				]);

				$item['URL'] = $uri->getPathQuery();
			}
			else
			{
				$downloadUrl = $this->getDownloadUrlForFileUnderFolder($relativePath, $object->getId());
				$viewerSourceUrl = $this->getViewerSourceUrlForFileUnderFolder($relativePath, $object->getId());
				$viewUrl = $urlManager->getUrlExternalLink([
					'hash' => $this->externalLink->getHash(),
					'action' => $this->getViewActionNameForJs($object),
					'token' => $this->downloadToken,
					'path' => $relativePath ?: '/',
					'fileId' => $object->getId(),
				]);
				$canDownload = $this->canDownloadObjectByExternalLink($object);

				$attributes = Ui\ExternalLinkAttributes::tryBuildByFileId($object->getFileId(), new Uri($viewerSourceUrl))
					->setTitle($name)
					->setDocumentViewUrl($viewUrl)
					->setGroupBy($this->componentId)
					->setDownloadDisabled(!$canDownload)
				;

				if ($this->getHandlerForViewByFile($object) instanceof BoardsHandler)
				{
					$attributes->addAction([
						'type' => 'edit',
						'buttonIconClass' => ' ',
						'action' => 'BX.Disk.Viewer.Actions.openInNewTab',
						'params' => [
							'url' => $viewUrl,
						],
					]);
				}

				$item['URL'] = $canDownload ? $downloadUrl : 'javascript:void(0)';
				$item['VIEWER_ATTRIBUTES'] = (string)$attributes;
				$item['CAN_DOWNLOAD'] = $canDownload;
			}

			$items[] = $item;
		}

		return [
			'ITEMS' => $items,
			'TOTAL_COUNT' => $cursor->getCount(),
			'HAS_NEXT_PAGE' => $hasNextPage,
		];
	}

	private function getViewActionNameForJs(BaseObject $object): string
	{
		if ($object instanceof File)
		{
			$documentHandler = $this->getHandlerForViewByFile($object);

			if ($documentHandler instanceof BoardsHandler)
			{
				return 'showByBoardsViewer';
			}
		}

		return 'showByOnlyOfficeViewer';
	}

	private function getResultByFolder()
	{
		$rootFolder = $this->externalLink->getFolder();
		if (!$rootFolder)
		{
			return null;
		}

		$canDownload = $this->canDownloadObjectByExternalLink($rootFolder);

		return [
			'ID' => $rootFolder->getId(),
			'STORAGE_ID' => $rootFolder->getStorageId(),
			'NAME' => $rootFolder->getName(),
			'CREATED_BY' => $this->externalLink->getCreatedBy(),
			'UPDATE_TIME' => $rootFolder->getUpdateTime(),
			'SIZE' => $rootFolder->getRealObject()->countSizeOfFiles(),
			'DOWNLOAD_URL' => $canDownload
				? $this->getUrlManager()->getUrlExternalLink([
					'fileId' => $this->externalLink->getId(),
					'folderId' => $this->externalLink->getId(),
					'hash' => $this->externalLink->getHash(),
					'action' => 'downloadFolderArchive',
					'token' => $this->downloadToken,
					'path' => '/',
				])
				: null,
			'VIEW_URL' => $this->getUrlManager()->getPublicExternalLink($rootFolder, $this->externalLink->getHash()),
			'CAN_DOWNLOAD' => $canDownload,
		];
	}

	private function getDownloadUrl()
	{
		$file = $this->externalLink->getFile();
		if (!$file)
		{
			return null;
		}

		return $this->getUrlManager()->getUrlExternalLink([
			'hash' => $this->externalLink->getHash(),
			'action' => 'download',
			'token' => $this->downloadToken,
		]);
	}

	private function getResultByFile()
	{
		$file = $this->externalLink->getFile();
		if (!$file)
		{
			return null;
		}

		$canDownload = $this->canDownloadObjectByExternalLink($file);

		$version = null;
		if ($this->externalLink->isSpecificVersion())
		{
			$version = $this->externalLink->getVersion();
			if ($version === null)
			{
				return null;
			}
		}

		$viewerSource = $version ?? $file;
		$viewerSourceName = $viewerSource->getName();
		$viewerSourceTime = $version?->getCreateTime() ?? $file->getUpdateTime();

		$result = [
			'ID' => $file->getId(),
			'IS_IMAGE' => TypeFile::isImage($version === null ? $file : $viewerSourceName),
			'IS_DOCUMENT' => TypeFile::isDocument($viewerSourceName),
			'ICON_CLASS' => Icon::getIconClassByObject($file),
			'ICON_NAME' => Icon::getIconSetNameByFile($file),
			'UPDATE_TIME' => $file->getUpdateTime(),
			// The head of the page tells about the file the way the head of the folder page does.
			'FORMATTED_UPDATE_TIME' => $this->formatUpdateTime(
				$file->getUpdateTime()->toUserTime()->getTimestamp(),
				time() + CTimeZone::getOffset(),
			),
			'NAME' => $file->getName(),
			'SIZE' => $file->getSize(),
			'FORMATTED_SIZE' => CFile::formatSize($file->getSize()),
			'DOWNLOAD_URL' => $this->getDownloadUrl(),
			'ABSOLUTE_SHOW_FILE_URL' => $this->getUrlManager()->getUrlExternalLink([
				'hash' => $this->externalLink->getHash(),
				'action' => 'showFile',
				'token' => $this->downloadToken,
			]),
			'SHOW_PREVIEW_URL' => $this->getUrlManager()->getUrlExternalLink([
				'hash' => $this->externalLink->getHash(),
				'action' => 'showPreview',
				'token' => $this->downloadToken,
			]),
			'SHOW_FILE_URL' => $this->getUrlManager()->getUrlExternalLink([
				'hash' => $this->externalLink->getHash(),
				'action' => 'showFile',
				'token' => $this->downloadToken,
			]),
			'VIEW_URL' => $this->getUrlManager()->getPublicExternalLink($file, $this->externalLink->getHash()),
			'VIEW_FULL_URL' => $this->getUrlManager()->getUrlExternalLink([
				'hash' => $this->externalLink->getHash(),
				'action' => 'default',
			], true),
			'CAN_DOWNLOAD' => $canDownload,
		];

		if ($result['IS_IMAGE'])
		{
			$fileData = $viewerSource->getFile();
			if ($fileData)
			{
				$result['IMAGE_DIMENSIONS'] = [
					'WIDTH' => $fileData['WIDTH'],
					'HEIGHT' => $fileData['HEIGHT'],
				];
			}
		}
		elseif ($this->isTiffFile($viewerSource) || $viewerSource->getView()->getData())
		{
			CJSCore::Init('disk');
			$viewUrl = [
				$this->getUrlManager()->getUrlExternalLink([
					'hash' => $this->externalLink->getHash(),
					'action' => 'showView',
					'token' => $this->downloadToken,
					'ts' => $viewerSourceTime->getTimestamp(),
					'ncc' => 1,
				]),
				$this->getUrlManager()->getUrlExternalLink([
					'hash' => $this->externalLink->getHash(),
					'action' => 'showFile',
					'token' => $this->downloadToken,
				]),
			];

			$height = 520;
			$width = 720;
			if (
				$viewerSource->getView() instanceof \Bitrix\Disk\View\Video
				&& !$viewerSource->getView()->getPreviewData()
			)
			{
				$height = 400;
				$width = 600;
			}
			$sourceUri = $this->getUrlManager()->getUrlExternalLink([
				'hash' => $this->externalLink->getHash(),
				'action' => 'showFile',
				'token' => $this->downloadToken,
			]);

			if (
				$this->isTiffFile($viewerSource)
				|| $viewerSource->getView() instanceof \Bitrix\Disk\View\Document
			)
			{
				$attributes = FileAttributes::tryBuildByFileId(
					$viewerSource->getFileId(),
					$sourceUri,
					$version === null ? $file : null,
				);
				if ($version !== null)
				{
					$attributes
						->setObjectId($file->getId())
						->setVersionId($version->getId())
						->setTitle($viewerSourceName)
					;
				}

				if ($this->isTiffFile($viewerSource))
				{
					$attributes->setTiffPreviewUrlOverride(
						$this->getUrlManager()->getUrlExternalLink([
							'hash' => $this->externalLink->getHash(),
							'action' => 'showTiffPreview',
							'token' => $this->downloadToken,
						]),
					);
				}
				elseif ($file->supportsUnifiedLink())
				{
					$attributes->setTiffPreviewUrlParams([
						'_uls' => (new Signer())->getSignature((string)$file->getId()),
					]);
				}
				$attributes
					->unsetAttribute('data-viewer')
					->setAttribute('data-inline-viewer')
					->setAttribute('data-disable-annotation-layer')
					->setDownloadDisabled(!$canDownload)
				;


				$result['VIEWER'] = "<div id=\"test-content\" class=\"disk-external-link-wrapper\" {$attributes}></div>";
			}
			else
			{
				$result['VIEWER'] = $viewerSource->getView()->render([
					'PATH' => $viewUrl,
					'HEIGHT' => $height,
					'WIDTH' => $width,
					'SIZE_TYPE' => 'absolute',
				]);
			}

		}
		elseif ($result['IS_DOCUMENT'] && $this->canMakePreview($viewerSource))
		{
			$result['PREVIEW'] = [
				'VIEW_URL' => $this->getDocumentPreviewUrl($file, $version),
			];
		}

		return $result;
	}

	private function isTiffFile(File|Version $file): bool
	{
		$extension = mb_strtolower(getFileExtension($file->getName()));

		return in_array($extension, ['tif', 'tiff'], true)
			&& Configuration::isEnabledFileViewerFormats();
	}

	private function getDocumentPreviewData(File $file, ?Version $version = null)
	{
		$fileName = $version?->getName() ?? $file->getName();
		$fileData = new \Bitrix\Disk\Document\FileData();
		$fileData
			->setFile($file)
			->setVersion($version)
			->setName($fileName)
			->setMimeType(TypeFile::getMimeTypeByFilename($fileName))
		;

		$dataForViewFile = $this->defaultHandlerForView->getDataForViewFile($fileData);
		if(!$dataForViewFile)
		{
			return null;
		}

		return $dataForViewFile;
	}

	private function getDocumentPreviewUrl(File $file, ?Version $version = null)
	{
		$documentPreviewData = $this->getDocumentPreviewData($file, $version);
		if (!$documentPreviewData)
		{
			return null;
		}

		return $documentPreviewData['viewUrl'];
	}

	private function canMakePreview(File|Version $file)
	{
		if ($file->getSize() > self::MAX_SIZE_TO_PREVIEW)
		{
			return false;
		}

		return !$this->externalLink->hasPassword() && $this->defaultHandlerForView instanceof \Bitrix\Disk\Document\GoogleViewerHandler;
	}

	protected function processActionShowViewHtml($path, $fileId, $pathToView, $mode = '', $print = '', $preview = '', $sizeType = '', $printUrl = '')
	{
		$file = $this->getTargetFile($path, $fileId);
		if (!$file)
		{
			$this->includeComponentTemplate('error');

			return false;
		}

		$printParam = $iframe = 'N';
		if($mode === 'iframe')
		{
			$iframe = 'Y';
			if($print === 'Y')
			{
				$printParam = 'Y';
			}
		}

		$elementId = 'bx_ajaxelement_' . $file->getId() . '_' . randString(4);
		$view = $file->getView();

		$html = $view->render([
			'PATH' => $pathToView,
			'IFRAME' => $iframe,
			'ID' => $elementId,
			'PRINT' => $printParam,
			'PREVIEW' => $preview,
			'SIZE_TYPE' => $sizeType,
			'PRINT_URL' => $printUrl,
		]);

		if($iframe == 'Y')
		{
			echo $html;
		}
		else
		{
			$result = ['html' => $html, 'innerElementId' => $elementId];
			$result = array_merge($result, $view->getJsViewerAdditionalJsonParams());
			$this->sendJsonResponse($result);
		}

		$this->end();
	}

	protected function processActionShowByBoardsViewer(string $path, int $fileId, string $token): void
	{
		$file = $this->getTargetFile($path, $fileId);
		if (!$file)
		{
			$this->sendJsonErrorResponse();
		}

		$passwordPassed = !$this->arResult['PROTECTED_BY_PASSWORD'] || $this->arResult['VALID_PASSWORD'];
		$isDocument = $this->isViewableDocument($file->getExtension());
		$documentHandler = $this->getHandlerForViewByFile($file);
		if (!$passwordPassed || !$isDocument || !($documentHandler instanceof BoardsHandler))
		{
			$this->sendJsonErrorResponse();
		}

		$documentSession = $this->generateDocumentSession($file);
		if (!$documentSession)
		{
			$this->sendJsonErrorResponse();
		}

		if (!$this->setParamsForBoard($documentSession, $file))
		{
			$this->sendJsonErrorResponse();
		}

		$this->showFileViewer(self::BOARD_FILE_VIEWER);
	}

	protected function processActionShowByOnlyOfficeViewer(string $path, int $fileId, string $token): void
	{
		$file = $this->getTargetFile($path, $fileId);
		if (!$file)
		{
			$this->sendJsonErrorResponse();
		}

		$passwordPassed = !$this->arResult['PROTECTED_BY_PASSWORD'] || $this->arResult['VALID_PASSWORD'];
		$isDocument = $this->isViewableDocument($file->getExtension());
		$documentViewer = $this->resolveDocumentViewer($this->defaultHandlerForView);
		if (!$passwordPassed || !$isDocument || $documentViewer === null)
		{
			$this->sendJsonErrorResponse();
		}

		$documentSession = $this->generateDocumentSession($file);
		if (!$documentSession)
		{
			$this->sendJsonErrorResponse();
		}

		$this->arResult['DOCUMENT_SESSION'] = $documentSession;
		$this->arResult['LINK_TO_EDIT'] = '';

		$canDownload = $this->canDownloadObjectByExternalLink($file);
		$this->arResult['CAN_DOWNLOAD'] = $canDownload;

		if ($canDownload)
		{
			$downloadUrl = Driver::getInstance()->getUrlManager()->getUrlExternalLink(
				[
					'hash' => $this->externalLink->getHash(),
					'action' => 'downloadFileUnderFolder',
					'token' => $token,
					'path' => $path,
					'fileId' => $fileId,
				],
			);

			$this->arResult['LINK_TO_DOWNLOAD'] = $downloadUrl;
		}

		$this->showFileViewer($documentViewer);
	}

	protected function processActionShowByGoogleViewer($path, $fileId)
	{
		$file = $this->getTargetFile($path, $fileId);
		if (!$file)
		{
			$this->sendJsonErrorResponse();
		}

		if (!$this->canMakePreview($file))
		{
			$this->sendJsonAccessDeniedResponse('Could not make preview by module settings');
		}

		if ($this->request->get('document_action') === 'checkView')
		{
			$fileData = new FileData();
			$fileData->setId($this->request->get('id'));
			$result = $this->defaultHandlerForView->checkViewFile($fileData);
			if ($result === null)
			{
				$this->sendJsonErrorResponse();
			}

			$this->sendJsonSuccessResponse(['viewed' => $result]);
		}

		$documentPreviewData = $this->getDocumentPreviewData($file);
		if (!$documentPreviewData)
		{
			$this->sendJsonErrorResponse();
		}

		$this->sendJsonSuccessResponse($documentPreviewData);
	}

	protected function processActionDownload($showFile = false, $runResize = false): void
	{
		$file = $this->externalLink->getFile();
		if (!$file)
		{
			$this->showNotFoundPage();

			return;
		}

		if (!$showFile && !$this->canDownloadObjectByExternalLink($file))
		{
			$this->showNotFoundPage();

			return;
		}

		$this->externalLink->incrementDownloadCount();
		if ($this->externalLink->isSpecificVersion())
		{
			$version = $file->getVersion($this->externalLink->getVersionId());
			if (!$version)
			{
				$this->showNotFoundPage();

				return;
			}
			$fileData = $version->getFile();
		}
		else
		{
			$fileData = $file->getFile();
		}

		if (!$fileData)
		{
			$this->showNotFoundPage();

			return;
		}

		if ($runResize && TypeFile::isImage($fileData['ORIGINAL_NAME']) && !TypeFile::shouldTreatImageAsFile($fileData))
		{
			$tmpFile = \CFile::resizeImageGet($fileData, ['width' => 1920, 'height' => 1080], BX_RESIZE_IMAGE_PROPORTIONAL, true, false, true);
			$fileData['FILE_SIZE'] = $tmpFile['size'];
			$fileData['SRC'] = $tmpFile['src'];
		}

		CFile::viewByUser($fileData, ['force_download' => !$showFile, 'attachment_name' => $file->getName()]);
	}

	protected function getTargetFile($path, $fileId)
	{
		[$targetFolder] = $this->getTargetFolderData($this->externalLink->getFolder(), $path);
		if (!$targetFolder)
		{
			return null;
		}

		$targetFile = File::load([
			'ID' => (int)$fileId,
			'PARENT_ID' => (int)$targetFolder->getRealObjectId(),
		]);

		if (!$targetFile || !$targetFile->getFile())
		{
			return null;
		}

		return $targetFile;
	}

	protected function processActionDownloadFileUnderFolder($path, $fileId, $showFile = false, $runResize = false)
	{
		$targetFile = $this->getTargetFile($path, $fileId);
		if(!$targetFile)
		{
			$this->showDownloadFileUnderFolderError();

			return false;
		}

		if (!$showFile && !$this->canDownloadObjectByExternalLink($targetFile))
		{
			$this->showDownloadFileUnderFolderError();

			return false;
		}

		CFile::viewByUser(
			arFile: $targetFile->getFile(),
			options: [
				'force_download' => !$showFile,
				'attachment_name' => $targetFile->getName(),
			],
		);
	}

	protected function processActionShowFileUnderFolder($path, $fileId): void
	{
		$this->processActionDownloadFileUnderFolder($path, $fileId, true);
	}

	protected function showDownloadFileUnderFolderError(): void
	{
		$this->includeComponentTemplate('error');
	}

	/**
	 * Feeds the iframe of the html viewer page: the document itself, served as an isolated response.
	 * The service answers a file outside the policy with the same stub it uses everywhere else, so a
	 * hand-made url tells nothing about the file behind the link.
	 */
	protected function processActionShowHtml(): void
	{
		// The password and the download token are both settled in processBeforeAction(), and nothing below
		// writes to the session: the lock is released before the content is read — a read that fetches the
		// blob first on cloud storage — so it does not stall the parallel requests of the same reader. The
		// engine says the same with the CloseSession filter, which a component of this base cannot declare.
		session_write_close();

		$this->sendHtmlViewerResponse($this->buildHtmlViewerContentResponse());
	}

	/**
	 * Routes to what the link publishes and leaves the policy to the service, which asks it of that very
	 * thing: the file by HtmlViewerPolicy::isViewable(), the version by its extension. Asking about the
	 * file here as well would refuse the page {@see isHtmlViewerTarget()} has already opened — a link
	 * pinned to an html revision of a file since renamed to .txt is exactly that.
	 */
	private function buildHtmlViewerContentResponse(): HttpResponse
	{
		$file = $this->externalLink->getFile();
		$service = ServiceLocator::getInstance()->get(HtmlViewerService::class);

		if (!($file instanceof File))
		{
			return $service->unavailableResponse();
		}

		if (!$this->externalLink->isSpecificVersion())
		{
			return $service->showByFile($file);
		}

		// A version-pinned link serves that revision, the very one processActionDownload() hands out:
		// otherwise the page would show the current content next to a download of the pinned version.
		$version = $this->resolvePinnedVersion($file);

		return $version ? $service->showByVersion($version) : $service->unavailableResponse();
	}

	protected function processActionShowFile()
	{
		$this->processActionDownload(true);
	}

	protected function processActionShowPreview()
	{
		$this->processActionDownload(true, true);
	}

	protected function processActionShowTiffPreview(): void
	{
		$file = $this->externalLink->getFile();
		if (!$file)
		{
			$this->showNotFoundPage();

			return;
		}

		$previewSource = $this->externalLink->isSpecificVersion()
			? $this->externalLink->getVersion()
			: $file;
		if ($previewSource === null || !$this->isTiffFile($previewSource))
		{
			$this->showNotFoundPage();

			return;
		}

		$this->downloadToken = (string)$this->request->getQuery('token');

		$urlManager = $this->getUrlManager();
		$previewActionUrl = $urlManager->getUrlExternalLink([
			'hash' => $this->externalLink->getHash(),
			'action' => 'showTiffPreview',
			'token' => $this->downloadToken,
		]);
		$showFileUrl = $urlManager->getUrlExternalLink([
			'hash' => $this->externalLink->getHash(),
			'action' => 'showFile',
			'token' => $this->downloadToken,
		]);

		$result = (new TiffPreviewService())->getByExternalLink(
			$previewSource,
			(string)$this->getDownloadUrl(),
			$previewActionUrl,
			$showFileUrl,
			$this->getTiffPreviewToken(),
		);

		if (!$result->isSuccess())
		{
			$error = $result->getError();
			$this->sendTiffPreviewResponse([
				'status' => PreviewStatus::ERROR,
				'previewUrl' => null,
				'downloadUrl' => $this->getDownloadUrl(),
				'errorCode' => $error?->getCode() ?: ErrorCode::CONVERSION_FAILED,
				'message' => $error?->getMessage(),
			]);
		}

		$data = $result->getData();
		$response = $data['response'] ?? null;
		if ($response instanceof \Bitrix\Main\Response)
		{
			Application::getInstance()->end(0, $response);
		}

		$this->sendTiffPreviewResponse($data);
	}

	private function sendTiffPreviewResponse(array $data): void
	{
		$this->sendJsonResponse([
			'status' => self::STATUS_SUCCESS,
			'data' => $data,
			'errors' => [],
		]);
	}

	private function getTiffPreviewToken(): ?string
	{
		$previewToken = $this->request->getQuery('previewToken');

		return is_string($previewToken) && $previewToken !== '' ? $previewToken : null;
	}

	protected function processActionShowView($path = '/', $fileId = null)
	{
		if ($fileId && $path)
		{
			$file = $this->getTargetFile($path, $fileId);
		}
		else
		{
			$file = $this->externalLink->getFile();
		}

		if(!$file)
		{
			$this->showNotFoundPage();

			return false;
		}

		if(!$file->getView()->isHtmlAvailable() || !$this->checkDownloadToken($this->request->getQuery('token')))
		{
			$this->showNotFoundPage();

			return false;
		}

		if($this->externalLink->isSpecificVersion())
		{
			$version = $file->getVersion($this->externalLink->getVersionId());
			if(!$version)
			{
				$this->showNotFoundPage();

				return false;
			}
			$fileData = $version->getView()->getData();
		}
		else
		{
			$fileData = $file->getView()->getData();
		}

		if(!$fileData)
		{
			$this->showNotFoundPage();

			return false;
		}

		CFile::viewByUser($fileData, ['force_download' => false, 'attachment_name' => $file->getView()->getName(), 'cache_time' => 0]);
	}

	protected function findLink()
	{
		if ($this->fromUnifiedLink)
		{
			// An injected link is already loaded and validated by ExternalLinkContext::resolve() in the
			// same request, so reloading it would only repeat the link and object queries.
			$externalLink = $this->arParams['EXTERNAL_LINK'] ?? null;
			$this->externalLink = $externalLink instanceof ExternalLink
				? $externalLink
				: null;
		}
		elseif (is_string($this->hash))
		{
			$this->externalLink = $this->externalLinkProvider->getForComponentByHash($this->hash);
		}
		elseif ($this->baseObject instanceof BaseObject)
		{
			$this->externalLink = $this->externalLinkProvider->getForComponent($this->baseObject->getRealObjectId());
		}

		if(
			!$this->externalLink
			|| $this->externalLink->isExpired()
			|| !$this->externalLink->getObject()
			|| ($this->fromUnifiedLink && !$this->isExternalLinkContextValid())
		)
		{
			throw new SystemException('Invalid external link', self::EXCEPTION_CODE_ACCESS_DENIED);
		}

		return $this;
	}

	private function isExternalLinkContextValid(): bool
	{
		if (!$this->baseObject instanceof BaseObject)
		{
			return false;
		}

		$linkObject = $this->externalLink->getObject();
		if (
			!$linkObject instanceof BaseObject
			|| (int)$linkObject->getRealObjectId() !== (int)$this->baseObject->getRealObjectId()
		)
		{
			return false;
		}

		if (!$this->baseObject instanceof File)
		{
			return (
				$this->attachedObject === null
				&& $this->version === null
				&& (int)$this->externalLink->getVersionId() === 0
			);
		}

		if ($this->attachedObject instanceof AttachedObject)
		{
			$attachedFile = $this->attachedObject->getFile();
			if (
				!$attachedFile instanceof File
				|| (int)$attachedFile->getRealObjectId() !== (int)$this->baseObject->getRealObjectId()
			)
			{
				return false;
			}
		}

		if ($this->version instanceof Version)
		{
			$versionFile = $this->version->getObject();
			if (
				!$versionFile instanceof File
				|| (int)$versionFile->getRealObjectId() !== (int)$this->baseObject->getRealObjectId()
			)
			{
				return false;
			}
		}

		return (int)$this->externalLink->getVersionId() === (int)($this->version?->getId() ?? 0);
	}

	protected function showNotFoundPage()
	{
		$this->maybeRefuseHtmlViewerContent();

		\CHTTP::SetStatus('404 Not Found');

		$this->includeComponentTemplate('error');
	}

	protected function processActionDownloadFolderArchive()
	{
		$createdBy = $this->externalLink->getCreatedBy();
		if(!$createdBy)
		{
			$this->sendJsonAccessDeniedResponse();
		}

		$folder = $this->externalLink->getFolder();
		if(!$folder)
		{
			$this->errorCollection[] = new Error("It's not folder.");
			$this->sendJsonErrorResponse();
		}

		if(!ZipNginx\Configuration::isEnabled())
		{
			$this->errorCollection[] = new Error('Work with mod_zip is disabled in module settings.');
			$this->sendJsonErrorResponse();
		}

		$storage = $folder->getStorage();
		if(!$storage)
		{
			$this->errorCollection[] = new Error("Could not find storage for folder.");
			$this->sendJsonErrorResponse();
		}

		$securityContext = $storage->getSecurityContext($createdBy);

		if (!$this->canDownloadFolderArchiveByExternalLink($folder))
		{
			$this->sendJsonAccessDeniedResponse();
		}

		$zipArchive = ZipNginx\Archive::createFromFolder($folder, $securityContext);
		$this->restartBuffer();
		$zipArchive->send();
		$this->end();
	}

	public function getLangId()
	{
		return $this->langId;
	}

	private function canDownloadObjectByExternalLink(BaseObject $object): bool
	{
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext(true);

		if (!$securityContext instanceof SecurityContext)
		{
			return false;
		}

		if ($object->canUpdate($securityContext))
		{
			return true;
		}

		return $this->downloadAccess->checkExternalLink($object, $this->externalLink);
	}

	private function preloadDownloadAccessForExternalLinkObjects(
		array $objects,
		SecurityContext $securityContext,
	): void
	{
		if ($securityContext instanceof DiskSecurityContext)
		{
			$securityContext->preloadOperationsForObjects($objects);
		}

		$this->downloadAccess->preload($objects, $this->externalLink);
	}

	private function getDownloadUrlForFileUnderFolder(string $relativePath, int $fileId): string
	{
		return $this->getUrlForFileUnderFolder(
			self::ACTION_DOWNLOAD_FILE_UNDER_FOLDER,
			$relativePath,
			$fileId,
		);
	}

	private function getViewerSourceUrlForFileUnderFolder(string $relativePath, int $fileId): string
	{
		return $this->getUrlForFileUnderFolder(
			self::ACTION_SHOW_FILE_UNDER_FOLDER,
			$relativePath,
			$fileId,
		);
	}

	private function getUrlForFileUnderFolder(string $action, string $relativePath, int $fileId): string
	{
		return $this->getUrlManager()->getUrlExternalLink(
			[
				'hash' => $this->externalLink->getHash(),
				'action' => $action,
				'token' => $this->downloadToken,
				'path' => $relativePath ?: '/',
				'fileId' => $fileId,
			],
		);
	}

	private function canDownloadFolderArchiveByExternalLink(Folder $folder): bool
	{
		return $this->canDownloadObjectByExternalLink($folder);
	}

	public function getMessage($id, $replace = null)
	{
		return Loc::getMessage($id, $replace, $this->langId);
	}

	public function getMessagePlural(string $id, int $value, ?array $replace = null): string
	{
		return (string)Loc::getMessagePlural($id, $value, $replace, $this->langId);
	}

	private function showFileViewer(string $fileViewerType): void
	{
		$this->arResult['FILE_VIEWER'] = $fileViewerType;
		$this->arResult['FILE_VIEWERS'] = self::FILE_VIEWERS;

		$this->includeComponentTemplate('file_viewer_wrapper');
	}

	private function getHandlerForView(): ?DocumentHandler
	{
		return $this->getHandlerForViewByFile($this->externalLink->getFile());
	}

	private function getHandlerForViewByFile(?File $file): ?DocumentHandler
	{
		$typeFile = (int)$file?->getTypeFile();

		$documentHandlersManager = Driver::getInstance()->getDocumentHandlersManager();

		if ($typeFile === TypeFile::FLIPCHART)
		{
			return $documentHandlersManager->getHandlerByCode(BoardsHandler::getCode());
		}

		// Office branch: take the current default view handler, then run it through the single
		// engine-selection seam (ALG-EXTERNAL-VIEWER-DISPATCH). Passing the handler's own code keeps
		// a non-office default (google-viewer/bitrix) untouched — the resolver only translates an
		// OnlyOffice(-derived) handler — so the office viewer becomes VibeofficeHandler when the
		// portal flag is on and stays OnlyOfficeHandler when it is off (zero regression), while the
		// google-viewer preview path is preserved. The external-link identity is carried in the
		// context (no-op today).
		$defaultHandler = $documentHandlersManager->getDefaultHandlerForView();
		if ($defaultHandler === null)
		{
			return null;
		}

		return $documentHandlersManager->resolveEffectiveHandler(
			$defaultHandler::getCode(),
			DocumentResolveContext::forExternalLink($this->externalLink->getId(), $file?->getId()),
		);
	}

	private function getSessionServiceByFile(File $file): DocumentService
	{
		$typeFile = (int)$file->getTypeFile();

		if ($typeFile === TypeFile::FLIPCHART)
		{
			return DocumentService::FlipChart;
		}

		// The office session SERVICE follows the resolved engine (ALG-EXTERNAL-VIEWER-DISPATCH):
		// vibeoffice when the resolver picked it (flag ON), OnlyOffice otherwise (flag OFF).
		return $this->defaultHandlerForView instanceof Vibeoffice\VibeofficeHandler
			? DocumentService::Vibeoffice
			: DocumentService::OnlyOffice;
	}

	/**
	 * Answers false when no board service instance may be contacted with this object: the caller must
	 * then refuse the page the way it refuses any other failure. The editor component decides the same
	 * way and throws, but executeComponent() swallows that on an ordinary request, so a guest of the
	 * link would be left with a blank page instead of an answer.
	 */
	private function setParamsForBoard(DocumentSession $documentSession, File $file): bool
	{
		if (ServiceProfileResolver::createFromOptions()->resolveForEditor($documentSession->getObject()) === null)
		{
			PilotLog::error(
				'Board service profile is not resolved: {entryPoint}, object {objectId}',
				[
					'entryPoint' => 'externalLink',
					'objectId' => (int)$documentSession->getObjectId(),
				],
			);

			return false;
		}

		$this->arResult['DOCUMENT_SESSION'] = $documentSession;
		$this->arResult['LINK_TO_DOWNLOAD'] = $this->getBoardDocumentUri($documentSession);
		$this->arResult['STORAGE_ID'] = $file->getStorageId();
		$this->arResult['USER_ID'] = $this->getUserIdForBoard($documentSession);
		$this->arResult['USER_NAME'] = (string)$documentSession->getUser()->getName();
		$this->arResult['USER_AVATAR'] = (string)(new Uri($documentSession->getUser()->getAvatarSrc()))->toAbsolute();
		$this->arResult['DOWNLOAD_TOKEN'] = $this->downloadToken;
		$this->arResult['ORIGINAL_FILE'] = $file;

		return true;
	}

	private function getUserIdForBoard(DocumentSession $documentSession): string
	{
		$user = $documentSession->getUser();
		$userId = $user->getId();

		if (GuestUser::isGuestUserId($userId))
		{
			return $this->getOrCreateAnonymousUserIdForBoard();
		}

		return (string)$userId;
	}

	private function getBoardDocumentUri(DocumentSession $documentSession): Uri
	{
		// Public links have always been served over the portal scheme; downgrading them would expose
		// the session and the grant on the wire.
		return (new DocumentDownloadUrlService())->build($documentSession, applyLegacyHttpDowngrade: false);
	}

	private function getOrCreateAnonymousUserIdForBoard(): string
	{
		$session = Application::getInstance()->getSession();
		$sessionKey = 'ANONYMOUS_USER_ID_FOR_BOARD';

		if ($session->get($sessionKey) === null)
		{
			$session->set($sessionKey, '~' . Random::getString(12));
		}

		return $session->get($sessionKey);
	}

	private function maybeGenerateUnifiedLink(string $actionName): void
	{
		if (!in_array($actionName, ['default', 'goToEdit'], true))
		{
			return;
		}

		$baseObject = $this->externalLink->getObject();

		if (!$baseObject instanceof BaseObject || !$baseObject->supportsUnifiedLink())
		{
			return;
		}

		$urlManager = Driver::getInstance()->getUrlManager();
		$options = $baseObject instanceof File ? $this->getUnifiedLinkOptions($baseObject) : [];
		$this->unifiedLink = $urlManager->getPublicUnifiedLink(
			$baseObject,
			$actionName === 'goToEdit',
			$options,
		);
	}

	private function buildEditLink(?File $file, UrlManager $urlManager): string
	{
		if ($this->fromUnifiedLink && $file?->supportsUnifiedLink())
		{
			return $urlManager->getUnifiedEditLink($file, $this->getUnifiedLinkOptions($file));
		}

		return $urlManager->getUrlExternalLink([
			'hash' => $this->externalLink->getHash(),
			'action' => 'goToEdit',
		]);
	}

	public function getVibeofficeTrustedOpening(): array
	{
		$session = $this->arResult['DOCUMENT_SESSION'] ?? null;
		$file = $this->baseObject ?? $this->externalLink?->getFile();
		if (!$session instanceof \Bitrix\Disk\Document\Models\DocumentSession || !$file instanceof File)
		{
			return [];
		}

		return [
			'channel' => $this->fromUnifiedLink ? 'unified' : 'public',
			'file' => $file,
			'externalLink' => $this->externalLink,
			'unifiedOptions' => $this->fromUnifiedLink ? $this->getUnifiedLinkOptions($file) : [],
			'objectId' => (int)$file->getId(),
			'attachedObjectId' => (int)$this->attachedObject?->getId(),
			'versionId' => (int)$session->getVersionId(),
			'type' => $session->getType(),
			'editorMode' => \Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
		];
	}

	private function getUnifiedLinkOptions(File $file): array
	{
		if ($this->unifiedLinkOptions !== null)
		{
			return $this->unifiedLinkOptions;
		}

		$attachedId = $this->attachedObject?->getId();
		$versionId = $this->version?->getId() ?? (
			$this->externalLink->isSpecificVersion()
				? $this->externalLink->getVersionId()
				: null
		);
		$additionalQueryParams = [
			ExternalLinkContext::getParameterName() => ExternalLinkContext::create(
				$this->externalLink,
				$file,
				$attachedId,
				$versionId,
			),
		];
		$this->unifiedLinkOptions = [
			'attachedId' => $attachedId,
			'versionId' => $versionId,
			'additionalQueryParams' => $additionalQueryParams,
		];

		return $this->unifiedLinkOptions;
	}

}
