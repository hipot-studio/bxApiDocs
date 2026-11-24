<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Document;
use Bitrix\Disk\Document\OnlyOffice\Filters\DocumentSessionCheck;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Integration\Collab\CollabDocumentCreationService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSupportService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;
use Bitrix\Main\Response as MainResponse;
use Bitrix\Main\Web\Uri;

final class DocumentService extends Engine\Controller
{
	protected UnifiedLinkUrl $unifiedLinkUrl;
	/** @var Vibeoffice[] */
	private array $forwardedVibeofficeControllers = [];

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$this->unifiedLinkUrl = ServiceLocator::getInstance()->get(UnifiedLinkUrl::class);
	}

	public function configureActions()
	{
		return [
			'viewDocument' => [
				'+prefilters' => [
					(new DocumentSessionCheck())
						->enableStrictCheckRight()
					,
					new HttpMethod([HttpMethod::METHOD_GET]),
				],
				'-prefilters' => [Csrf::class],
			],
			'restoreDocumentInteraction' => [
				'+prefilters' => [
					(new DocumentSessionCheck())
						->enableStrictCheckRight()
						->enableHashCheck(function(){
							return Context::getCurrent()->getRequest()->get('documentSessionHash');
						})
					,
					new HttpMethod([HttpMethod::METHOD_GET]),
				],
				'-prefilters' => [Csrf::class],
			],
			'downloadDocument' => [
				'+prefilters' => [
					(new DocumentSessionCheck())
						->enableStrictCheckRight()
						->enableVersionScopedUnifiedLink()
						->enableHashCheck(function(){
							return Context::getCurrent()->getRequest()->get('documentSessionHash');
						})
					,
					new HttpMethod([HttpMethod::METHOD_GET]),
					new CloseSession(),
				],
				'-prefilters' => [Csrf::class],
			],
			'goToEditOrPreview' => ['-prefilters' => [Csrf::class]],
			'goToPreview' => ['-prefilters' => [Csrf::class]],
			'goToEdit' => ['-prefilters' => [Csrf::class]],
			'love' => ['prefilters' => [
				new HttpMethod([HttpMethod::METHOD_GET]),
				new CloseSession(),
			]],
		];
	}

	public function forward($controller, string $actionName, ?array $parameters = null)
	{
		if ($controller === Vibeoffice::class)
		{
			$controller = new Vibeoffice();
		}
		if ($controller instanceof Vibeoffice)
		{
			$this->forwardedVibeofficeControllers[spl_object_id($controller)] = $controller;
		}

		return parent::forward($controller, $actionName, $parameters);
	}

	public function finalizeResponse(MainResponse $response)
	{
		parent::finalizeResponse($response);
		$controllers = $this->forwardedVibeofficeControllers;
		$this->forwardedVibeofficeControllers = [];
		foreach ($controllers as $controller)
		{
			$controller->finalizeResponse($response);
		}
	}

	public function restoreDocumentInteractionAction(Document\Models\DocumentSession $documentSession)
	{
		$currentUser = $this->getCurrentUser();
		if (!$currentUser)
		{
			$this->addError(new Error('Could not find current user.'));

			return null;
		}

		$sessionManager = $documentSession->getService() === Document\Models\DocumentService::Vibeoffice
			? new Document\Vibeoffice\DocumentSessionManager()
			: new Document\OnlyOffice\DocumentSessionManager();
		$file = $documentSession->getFile();
		$version = $documentSession->getVersion();
		$sessionManager
			->setUserId($currentUser->getId())
			->setSessionType($documentSession->getType())
			->setSessionContext($documentSession->getContext())
			->setFile($file)
			->setVersion($version)
			->setAttachedObject($documentSession->getContext()?->getAttachedObject())
		;
		$restoredSharedOpening = false;
		$restoredOpenToken = null;

		if ($sessionManager instanceof Document\Vibeoffice\DocumentSessionManager
			&& $sessionManager->requiresSharedAdmission())
		{
			$unifiedLinkService = ServiceLocator::getInstance()->get(
				\Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService::class,
			);
			if (!$sessionManager->configureFromPreviousSession($documentSession, $unifiedLinkService))
			{
				$this->addError(new Error('The editing source is unavailable.', 'vibeoffice_access_denied'));

				return null;
			}
			$admission = $sessionManager->admitSharedEdit(new Document\Vibeoffice\OpeningBudget(
				ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration')->getOpenBudgetSeconds() * 1000,
			));
			if (!$admission->isSuccess())
			{
				$this->addErrors($admission->getErrors());

				return null;
			}
			$forkedSession = $admission->getData()['session'];
			$restoredOpenToken = is_string($admission->getData()['openToken'] ?? null)
				? $admission->getData()['openToken']
				: null;
			$restoredSharedOpening = true;
		}
		else
		{
			if (!$sessionManager->lock())
			{
				$this->addError(new Error('Could not getting lock for the session.'));

				return null;
			}
			try
			{
				$forkedSession = $sessionManager->findOrCreateSession();
				if (!$forkedSession)
				{
					$this->addErrors($sessionManager->getErrors());

					return null;
				}
			}
			finally
			{
				$sessionManager->unlock();
			}
		}
		$restrictionOutcome = $sessionManager instanceof Document\Vibeoffice\DocumentSessionManager
			? $sessionManager->getRestrictionOutcome()
			: null
		;

		$response = new HttpResponse();

		if ($file && $file->supportsUnifiedLink())
		{
			$unifiedLinkOptions = [];

			if ($version)
			{
				$unifiedLinkOptions['versionId'] = (int)$version->getId();
			}
			if ($restoredSharedOpening)
			{
				$context = $forkedSession->getContext();
				$link = $context?->getExternalLink();
				if (!$context || ($context->getExternalLinkId() !== null
					&& (!($link instanceof Disk\ExternalLink)
						|| (int)$link->getId() !== (int)$context->getExternalLinkId())))
				{
					if ($restoredOpenToken !== null)
					{
						(new Document\Vibeoffice\SessionRegistry())->cancelDefinitiveOpen($restoredOpenToken);
					}
					$forkedSession->setAsNonActive();
					$this->addError(new Error('The editing source is unavailable.', 'vibeoffice_access_denied'));

					return null;
				}
				$shortLinkOpening = $sessionManager instanceof Document\Vibeoffice\DocumentSessionManager
					? $sessionManager->getShortLinkOpening()
					: null;
				if ($shortLinkOpening !== null)
				{
					$freshOpening = \Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening::resolve(
						(string)$link?->getShortCode(),
					);
					if ($freshOpening === null
						|| (int)$freshOpening->getExternalLink()->getId() !== (int)$context->getExternalLinkId())
					{
						if ($restoredOpenToken !== null)
						{
							(new Document\Vibeoffice\SessionRegistry())->cancelDefinitiveOpen($restoredOpenToken);
						}
						$forkedSession->setAsNonActive();
						$this->addError(new Error('The editing source is unavailable.', 'vibeoffice_access_denied'));

						return null;
					}
					$response->setStatus(302);
					$response->getHeaders()->set('Location', $freshOpening->getCanonicalUrl());

					return $response;
				}
				$attachedId = $context->getAttachedObjectId();
				if ($attachedId !== null)
				{
					$unifiedLinkOptions['attachedId'] = $attachedId;
				}
				if ($link instanceof Disk\ExternalLink)
				{
					$unifiedLinkOptions['additionalQueryParams'][ExternalLinkContext::getParameterName()]
						= ExternalLinkContext::create($link, $file, $attachedId, $forkedSession->getVersionId());
				}
			}

			$unifiedLink = Driver::getInstance()->getUrlManager()->getUnifiedLink($file, $unifiedLinkOptions);

			$response->setStatus(302);
			$response->getHeaders()->set('Location', $unifiedLink);
		}
		else
		{
			$handlersManager = Driver::getInstance()->getDocumentHandlersManager();

			$content = $GLOBALS['APPLICATION']->includeComponent(
				'bitrix:ui.sidepanel.wrapper',
				'',
				[
					'RETURN_CONTENT' => true,
					'POPUP_COMPONENT_NAME' => $handlersManager->resolveShellComponentName($documentSession->getService()),
					'POPUP_COMPONENT_TEMPLATE_NAME' => '',
					'POPUP_COMPONENT_PARAMS' => [
						'DOCUMENT_SESSION' => $forkedSession,
						'RESTRICTION_OUTCOME' => $restrictionOutcome,
						'SHOW_BUTTON_OPEN_NEW_WINDOW' => false,
					],
					'PLAIN_VIEW' => true,
					'IFRAME_MODE' => true,
					'PREVENT_LOADING_WITHOUT_IFRAME' => false,
					'USE_PADDING' => false,
				],
			);

			$response->setContent($content);
		}

		return $response;
	}

	public function viewDocumentAction(Document\Models\DocumentSession $documentSession): HttpResponse
	{
		$currentUser = $this->getCurrentUser();
		if (
			$documentSession->getService() === Document\Models\DocumentService::Vibeoffice
			&& !$documentSession->isVersion()
		)
		{
			try
			{
				$file = $documentSession->getFile();
				if ($file)
				{
					(new Document\Vibeoffice\SavedContentSynchronizer())->synchronize($file);
				}

				$reloadedSession = Document\Models\DocumentSession::loadById($documentSession->getId());
				if ($reloadedSession)
				{
					$documentSession = $reloadedSession;
				}
			}
			catch (\Throwable)
			{
				// A delayed platform save must not turn a readable document into an error page.
			}
		}

		if ($documentSession->isOutdatedByFileContent())
		{
			$forkedSession = $documentSession->getService() === Document\Models\DocumentService::Vibeoffice
				? (new Document\Vibeoffice\DocumentSessionManager())
					->cloneSessionWithCurrentContentVersion($documentSession, $currentUser->getId())
				: $documentSession->cloneWithNewHash($currentUser->getId());

			/** @see \Bitrix\Disk\Controller\DocumentService::viewDocumentAction() */
			$viewUri = $this->getActionUri('viewDocument', ['documentSessionId' => $forkedSession->getId()]);

			return $this->redirectTo($viewUri);
		}

		if (!$documentSession->belongsToUser($currentUser->getId()))
		{
			//we already checked rights in filter DocumentSessionCheck and acccess to the type of document session.
			$forkedSession = $documentSession->forkForUser($currentUser->getId(), $documentSession->getContext());
			if ($forkedSession)
			{
				/** @see \Bitrix\Disk\Controller\DocumentService::viewDocumentAction() */
				$viewUri = $this->getActionUri('viewDocument', ['documentSessionId' => $forkedSession->getId()]);

				return $this->redirectTo($viewUri);
			}

			$this->addErrors($documentSession->getErrors());
			$response = new HttpResponse();
			$response->setContent(implode("\n", $this->getErrors()));

			return $response;
		}

		$handlersManager = Driver::getInstance()->getDocumentHandlersManager();

		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => $handlersManager->resolveShellComponentName($documentSession->getService()),
				'POPUP_COMPONENT_TEMPLATE_NAME' => '',
				'POPUP_COMPONENT_PARAMS' => [
					'DOCUMENT_SESSION' => $documentSession,
					'SHOW_BUTTON_OPEN_NEW_WINDOW' => false,
				],
				'PLAIN_VIEW' => true,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => false,
			]
		);

		$response = new HttpResponse();
		$response->setContent($content);

		return $response;
	}

	public function downloadDocumentAction(Document\Models\DocumentSession $documentSession): HttpResponse
	{
		$file = $documentSession->getFile();
		$selectedContent = $this->getSelectedDownloadContent($documentSession);

		if (!$selectedContent || !$this->canDownloadDocument($documentSession))
		{
			return $this->createDownloadErrorResponse('403 Forbidden');
		}

		if (!$documentSession->isVersion())
		{
			$result = (new Document\Vibeoffice\SavedDownloadSynchronizer())->synchronize($file);
			if (!$result->isSuccess())
			{
				return $this->createDownloadErrorResponse('503 Service Unavailable');
			}
			if ($result->getData()['synchronized'])
			{
				$freshFile = Disk\File::loadById((int)$file->getId(), ['STORAGE']);
				if (!$freshFile || (int)$freshFile->getRealObjectId() !== (int)$file->getRealObjectId()
					|| ($freshFile instanceof Disk\FileLink && !$freshFile->getRealObject()))
				{
					return $this->createDownloadErrorResponse('403 Forbidden');
				}
				$documentSession->setReferenceValue(Document\Models\DocumentSession::REF_OBJECT, $freshFile);
				if (!$this->canDownloadDocument($documentSession))
				{
					return $this->createDownloadErrorResponse('403 Forbidden');
				}

				$selectedContent = $this->getSelectedDownloadContent($documentSession);
			}
		}

		$response = Response\BFile::createByFileId(
			$selectedContent['fileId'],
			$selectedContent['name'],
		);
		$response->setCacheTime(Disk\Configuration::DEFAULT_CACHE_TIME);

		return $response;
	}

	private function canDownloadDocument(Document\Models\DocumentSession $documentSession): bool
	{
		$securityContext = $documentSession->getFile()?->getStorage()?->getSecurityContext(
			user: $this->getCurrentUser(),
			shouldCheckUnifiedLink: $this->shouldCheckUnifiedLink($documentSession),
		);

		return $securityContext && $documentSession->canDownload($securityContext);
	}

	private function createDownloadErrorResponse(string $status): HttpResponse
	{
		$this->addError(new Error('Could not download document.'));

		return (new HttpResponse())->setStatus($status);
	}

	private function getSelectedDownloadContent(Document\Models\DocumentSession $documentSession): ?array
	{
		$file = $documentSession->getFile();
		if (!$file)
		{
			return null;
		}

		if (!$documentSession->isVersion())
		{
			return ['fileId' => $file->getFileId(), 'name' => $file->getName()];
		}

		$version = $documentSession->getVersion();
		if (!$version
			|| (int)$version->getId() !== (int)$documentSession->getVersionId()
			|| (int)$version->getObjectId() !== (int)$file->getRealObjectId())
		{
			return null;
		}

		return ['fileId' => $version->getFileId(), 'name' => $version->getName()];
	}

	private function shouldCheckUnifiedLink(Document\Models\DocumentSession $documentSession): bool
	{
		$file = $documentSession->getFile();

		if (!$file)
		{
			return false;
		}

		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->getRequest());

		if (!is_string($uls) || $uls === '')
		{
			return false;
		}

		/** @var UnifiedLinkAccessService $unifiedLinkAccessService */
		$unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);

		if ($documentSession->isVersion())
		{
			$version = $documentSession->getVersion();

			return $version
				&& (int)$version->getId() === (int)$documentSession->getVersionId()
				&& (int)$version->getObjectId() === (int)$file->getRealObjectId()
				&& $unifiedLinkAccessService->validateUlsForVersion(
					objectId: $file->getId(),
					versionId: $version->getId(),
					uls: $uls,
				);
		}

		return $unifiedLinkAccessService->validateUlsForObjectId($file->getId(), $uls);
	}

	public function goToEditOrPreviewAction($serviceCode, $attachedObjectId = null, $objectId = null, $versionId = null)
	{
		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			$serviceCode,
			Document\DocumentResolveContext::forObject(
				$objectId !== null ? (int)$objectId : null,
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));

			return null;
		}

		if (
			!($documentHandler instanceof VibeofficeHandler)
			&& !($documentHandler instanceof OnlyOfficeHandler)
		)
		{
			$this->addError(new Error('Unsupported document service.'));
		}

		if ($this->getErrors())
		{
			return null;
		}

		$canEdit = false;
		$canRead = false;

		if ($versionId)
		{
			$version = Disk\Version::getById($versionId);
			if ($version)
			{
				$objectId = $version->getObjectId();
			}
		}

		$objectIdForSessionCheck = 0;
		if ($objectId)
		{
			$file = Disk\File::getById($objectId);
			if ($file)
			{
				$securityContext = $file->getStorage()->getSecurityContext($this->getCurrentUser());
				$canEdit = $file->canUpdate($securityContext);
				$canRead = $file->canRead($securityContext);
				$objectIdForSessionCheck = (int)$objectId;
			}
		}

		if ($attachedObjectId)
		{
			$attachedObject = Disk\AttachedObject::getById($attachedObjectId);
			$file = $attachedObject?->getFile();
			if ($attachedObject)
			{
				// When both $objectId and $attachedObjectId are passed, $file becomes the
				// attached object's file. Keep the $objectId file's rights in the OR only
				// when it points to the same object as the attached one; otherwise a foreign
				// attached file used for the unified redirect would inherit access from an
				// unrelated $objectId file (see #250817). The $canRead gate on the redirect
				// still enforces read rights on the resulting file (see #248603).
				$sameObject = $objectId && (int)$objectId === (int)$attachedObject->getObjectId();
				$canEdit = ($sameObject && $canEdit) || $attachedObject->canUpdate((int)$this->getCurrentUser()?->getId());
				$canRead = ($sameObject && $canRead) || $attachedObject->canRead((int)$this->getCurrentUser()?->getId());
				$objectIdForSessionCheck = (int)$attachedObject->getObjectId();
			}
		}

		if (isset($file) && $canRead && $file->supportsUnifiedLink())
		{
			$unifiedLinkOptions = [];
			if ((int)$attachedObjectId > 0)
			{
				$unifiedLinkOptions['attachedId'] = (int)$attachedObjectId;
			}
			if ((int)$versionId > 0)
			{
				$unifiedLinkOptions['versionId'] = (int)$versionId;
			}

			$unifiedLink = Driver::getInstance()->getUrlManager()->getUnifiedLink($file, $unifiedLinkOptions);

			LocalRedirect($unifiedLink);
		}

		if ($canEdit)
		{
			if (Document\Vibeoffice\Feature::servesPortalEditing())
			{
				return $this->goToEditAction($serviceCode, $attachedObjectId, $objectId);
			}

			$isAllowedEdit = true;
			$availableSessionBoost = ServiceLocator::getInstance()->get(BaasSessionBoostService::class)->isAvailable();
			if ($availableSessionBoost)
			{
				$userId = (int)$this->getCurrentUser()?->getId();
				try
				{
					$isAllowedEdit = (new RestrictionManager())->isAllowedEditByObjectId($objectIdForSessionCheck, $userId);
				}
				catch (\RuntimeException $exception)
				{
					if (ServiceLocator::getInstance()->get(Disk\Internal\Service\Environment::class)->isCloudPortal())
					{
						throw $exception;
					}

					return $this->goToEditAction($serviceCode, $attachedObjectId, $objectId);
				}
			}

			if (!$availableSessionBoost || $isAllowedEdit)
			{
				return $this->goToEditAction($serviceCode, $attachedObjectId, $objectId);
			}
		}

		return $this->goToPreviewAction($serviceCode, $attachedObjectId, $objectId, $versionId);
	}

	public function goToPreviewAction($serviceCode, $attachedObjectId = null, $objectId = null, $versionId = null)
	{
		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			$serviceCode,
			Document\DocumentResolveContext::forObject(
				$objectId !== null ? (int)$objectId : null,
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));

			return null;
		}

		if ($documentHandler instanceof VibeofficeHandler)
		{
			return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'loadDocumentViewer', [
				'attachedObjectId' => $attachedObjectId,
				'objectId' => $objectId,
				'versionId' => $versionId,
			]);
		}

		if ($documentHandler instanceof OnlyOfficeHandler)
		{
			/** @see \Bitrix\Disk\Controller\OnlyOffice::loadDocumentViewerAction() */
			return $this->forward(OnlyOffice::class, 'loadDocumentViewer', [
				'attachedObjectId' => $attachedObjectId,
				'objectId' => $objectId,
				'versionId' => $versionId,
			]);
		}
	}

	public function goToEditAction($serviceCode, $attachedObjectId = null, $objectId = null, $documentSessionId = null, $documentSessionHash = null)
	{
		if ($documentSessionId)
		{
			// An already-existing session must continue in the engine it was opened with
			// ("one document — one engine"): route the view→edit transformation by the
			// session's own SERVICE, not by the currently effective handler. Otherwise, after
			// the vibeoffice flag is turned on, a live OnlyOffice-family session would be
			// forwarded into the Vibeoffice controller, whose auto-wire filters by
			// SERVICE=vibeoffice and fails to load the onlyoffice session.
			$documentSession = Document\Models\DocumentSession::loadById((int)$documentSessionId);
			if ($documentSession)
			{
				if ($documentSession->getService() === Document\Models\DocumentService::Vibeoffice)
				{
					/** @see \Bitrix\Disk\Controller\Vibeoffice::loadDocumentEditorByViewSessionAction() */
					return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'loadDocumentEditorByViewSession', [
						'documentSessionId' => $documentSessionId,
						'documentSessionHash' => $documentSessionHash,
					]);
				}

				if ($documentSession->getService() === Document\Models\DocumentService::OnlyOffice)
				{
					/** @see \Bitrix\Disk\Controller\OnlyOffice::loadDocumentEditorByViewSessionAction() */
					return $this->forward(OnlyOffice::class, 'loadDocumentEditorByViewSession', [
						'documentSessionId' => $documentSessionId,
						'documentSessionHash' => $documentSessionHash,
					]);
				}
			}
			// Session not found by id, or a non-office service (e.g. FlipChart): fall through
			// to the effective-handler routing below to preserve prior behavior.
		}

		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			$serviceCode,
			Document\DocumentResolveContext::forObject(
				$objectId !== null ? (int)$objectId : null,
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));

			return null;
		}

		if ($documentHandler instanceof VibeofficeHandler)
		{
			if ($documentSessionId)
			{
				return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'loadDocumentEditorByViewSession', [
					'documentSessionId' => $documentSessionId,
					'documentSessionHash' => $documentSessionHash,
				]);
			}

			return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'loadDocumentEditor', [
				'attachedObjectId' => $attachedObjectId,
				'objectId' => $objectId,
			]);
		}

		if ($documentHandler instanceof OnlyOfficeHandler)
		{
			if ($documentSessionId)
			{
				/** @see \Bitrix\Disk\Controller\OnlyOffice::loadDocumentEditorByViewSessionAction() */
				return $this->forward(OnlyOffice::class, 'loadDocumentEditorByViewSession', [
					'documentSessionId' => $documentSessionId,
					'documentSessionHash' => $documentSessionHash,
				]);
			}

			/** @see \Bitrix\Disk\Controller\OnlyOffice::loadDocumentEditorAction() */
			return $this->forward(OnlyOffice::class, 'loadDocumentEditor', [
				'attachedObjectId' => $attachedObjectId,
				'objectId' => $objectId,
			]);
		}

		$urlManager = $driver->getUrlManager();
		if ($attachedObjectId)
		{
			LocalRedirect(
				$this->addUnifiedLinkSignatureToUrl(
					$urlManager::getUrlToStartEditUfFileByService($attachedObjectId, $documentHandler::getCode())
				)
			);

		}
		else
		{
			LocalRedirect(
				$this->addUnifiedLinkSignatureToUrl(
					$urlManager::getUrlForStartEditFile($objectId, $documentHandler::getCode())
				)
			);
		}
	}

	protected function addUnifiedLinkSignatureToUrl(string $url): string
	{
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->getRequest());

		if (!is_string($uls) || $uls === '')
		{
			return $url;
		}

		$uri = new Uri($url);

		$this->unifiedLinkUrl->addUlsToUri($uri, $uls);

		return (string)$uri;
	}

	public function goToCreateAction(
		$serviceCode,
		$typeFile,
		$attachedObjectId = null,
		$targetFolderId = null,
		bool $createByUnifiedLink = false,
		array $analytics = [],
		?int $collabId = null,
	)
	{
		$driver = Driver::getInstance();
		$userId = (int)$this->getCurrentUser()?->getId();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			$serviceCode,
			Document\DocumentResolveContext::forCreate(
				$targetFolderId !== null ? (int)$targetFolderId : null,
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));

			return null;
		}

		$collabDocumentCreationService = ServiceLocator::getInstance()->get(CollabDocumentCreationService::class);
		$hasExplicitTargetFolderId = is_numeric($targetFolderId) && (int)$targetFolderId > 0;
		$targetFolder = $collabDocumentCreationService->getFolderByFolderId($targetFolderId);
		if ($hasExplicitTargetFolderId && $targetFolder === null)
		{
			$this->addError(new Error('Target folder was not found'));

			return null;
		}

		if ($targetFolder === null)
		{
			$targetFolderId = null;
		}

		// TODO: remove if (false) when frontend sends $collabId
		if (false)
		{
			$isCollaber = $collabDocumentCreationService->isCollaberUserById($userId);
			if ($isCollaber)
			{
				if ($targetFolder !== null && !$collabDocumentCreationService->isFolderInCollabStorage($targetFolder))
				{
					$this->addError(new Error('Wrong target folder for collab user'));
					return null;
				}

				if ($targetFolder === null && (int)$collabId < 1)
				{
					$this->addError(new Error('No collab ID for collab user'));
					return null;
				}
			}
		}

		if ((int)$collabId > 0 && (int)$targetFolderId < 1)
		{
			$collabFolder = $collabDocumentCreationService->getCreatedFilesFolderByCollabId($collabId, $userId);
			if ($collabFolder === null)
			{
				$this->addError(new Error('There is no such collab or user has no access to it'));
				return null;
			}
			$targetFolderId = $collabFolder->getId();
		}

		if ($documentHandler instanceof VibeofficeHandler)
		{
			$unifiedLinkSupportService = ServiceLocator::getInstance()->get(UnifiedLinkSupportService::class);

			$parameters = [
				'typeFile' => $typeFile,
				'targetFolderId' => $targetFolderId,
				'analytics' => $analytics,
			];

			$onlyOfficeHandler = $handlersManager->getHandlerByCode(OnlyOfficeHandler::getCode()) ?? $documentHandler;
			if ($createByUnifiedLink && $unifiedLinkSupportService->supportsDocumentHandler($onlyOfficeHandler))
			{
				return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'createDocument', $parameters);
			}

			return $this->forward(\Bitrix\Disk\Controller\Vibeoffice::class, 'loadCreateDocumentEditor', $parameters);
		}

		if ($documentHandler instanceof OnlyOfficeHandler)
		{
			$unifiedLinkSupportService = ServiceLocator::getInstance()->get(UnifiedLinkSupportService::class);

			$parameters = [
				'typeFile' => $typeFile,
				'targetFolderId' => $targetFolderId,
				'analytics' => $analytics,
			];

			if ($createByUnifiedLink && $unifiedLinkSupportService->supportsDocumentHandler($documentHandler))
			{
				/** @see OnlyOffice::createDocumentAction() */
				return $this->forward(OnlyOffice::class, 'createDocument', $parameters);
			}

			/** @see OnlyOffice::loadCreateDocumentEditorAction() */
			return $this->forward(OnlyOffice::class, 'loadCreateDocumentEditor', $parameters);
		}

		$externalHandlerParameters = [];
		$urlManager = $driver->getUrlManager();
		if ($targetFolderId > 0)
		{
			$externalHandlerParameters['targetFolderId'] = $targetFolderId;
		}

		if ($attachedObjectId)
		{
			LocalRedirect($urlManager::getUrlToStartCreateUfFileByService(
				$typeFile, $documentHandler::getCode(), $externalHandlerParameters)
			);
		}
		else
		{
			LocalRedirect($urlManager::getUrlForStartCreateFile(
				$typeFile, $documentHandler::getCode(), $externalHandlerParameters)
			);
		}
	}

	public function getAction($serviceCode)
	{
		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->getHandlerByCode($serviceCode);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));
		}

		$urlManager = $driver->getUrlManager();

		return [
			'documentService' => [
				'code' => $documentHandler::getCode(),
				'name' => $documentHandler::getName(),
				'links' => [
					'create' => $urlManager::getUrlForStartCreateFile('TYPE_FILE', $documentHandler::getCode()),
					'edit' => $urlManager::getUrlForStartEditFile('FILE_ID', $documentHandler::getCode()),
					'uf' => [
						'create' => $urlManager::getUrlToStartCreateUfFileByService('TYPE_FILE', $documentHandler::getCode()),
						'edit' => $urlManager::getUrlToStartEditUfFileByService('ATTACHED_ID', $documentHandler::getCode()),
					],
				],
				'messages' => [],
			],
		];
	}

	/**
	 * It's fake love action to show user blank page on current domain. It's necessary
	 * to work with postMessage on IE11. So we use this action while running edit of documents.
	 *
	 * @return HttpResponse
	 */
	public function loveAction()
	{
		return new HttpResponse();
	}

	public function setStatusWorkWithLocalDocumentAction($uidRequest, $status)
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		Driver::getInstance()->sendEvent($this->getCurrentUser()->getId(), 'bdisk', [
			'uidRequest' => $uidRequest,
			'status' => $status,
		]);
	}
}
