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
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSupportService;
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

final class DocumentService extends Engine\Controller
{
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
			'goToCreate' => ['-prefilters' => [Csrf::class]],
			'love' => ['prefilters' => [
				new HttpMethod([HttpMethod::METHOD_GET]),
				new CloseSession(),
			]],
		];
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
		;

		if (!$sessionManager->lock())
		{
			$this->addError(new Error('Could not getting lock for the session.'));

			return null;
		}

		$forkedSession = $sessionManager->findOrCreateSession();
		if (!$forkedSession)
		{
			$this->addErrors($forkedSession->getErrors());

			return null;
		}
		$sessionManager->unlock();

		$response = new HttpResponse();

		if ($file && $file->supportsUnifiedLink())
		{
			$unifiedLinkOptions = [];

			if ($version)
			{
				$unifiedLinkOptions['versionId'] = (int)$version->getId();
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
		$response = Response\BFile::createByFileId($file->getFileId(), $file->getName());
		$response->setCacheTime(Disk\Configuration::DEFAULT_CACHE_TIME);

		return $response;
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
			$isAllowedEdit = true;
			$availableSessionBoost = (new BaasSessionBoostService())->isAvailable();
			if ($availableSessionBoost)
			{
				$userId = (int)$this->getCurrentUser()?->getId();
				$isAllowedEdit = (new RestrictionManager())->isAllowedEditByObjectId($objectIdForSessionCheck, $userId);
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
			LocalRedirect($urlManager::getUrlToStartEditUfFileByService($attachedObjectId, $documentHandler::getCode()));

		}
		else
		{
			LocalRedirect($urlManager::getUrlForStartEditFile($objectId, $documentHandler::getCode()));
		}
	}

	public function goToCreateAction(
		$serviceCode,
		$typeFile,
		$attachedObjectId = null,
		$targetFolderId = null,
		bool $createByUnifiedLink = false,
		array $analytics = [],
	)
	{
		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			$serviceCode,
			Document\DocumentResolveContext::forObject(
				null,
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);
		if (!$documentHandler)
		{
			$this->addError(new Error('There is no document service by code'));
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

		$urlManager = $driver->getUrlManager();
		if ($attachedObjectId)
		{
			LocalRedirect($urlManager::getUrlToStartCreateUfFileByService($typeFile, $documentHandler::getCode()));

		}
		else
		{
			LocalRedirect($urlManager::getUrlForStartCreateFile($typeFile, $documentHandler::getCode()));
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
