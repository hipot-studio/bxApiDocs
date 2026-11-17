<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Integration;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\DocumentDownloadUrlService;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotLog;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\Document\Flipchart\MentionCandidateProvider;
use Bitrix\Disk\Document\Flipchart\SessionManager;
use Bitrix\Disk\Document\Flipchart\WebhookEventType;
use Bitrix\Disk\Document\Flipchart\WriteNodeRefusal;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Integration\Collab\CollabDocumentCreationService;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Public\Service\Restrictions\WriteErrorTransportMap;
use Bitrix\Disk\Type\JwtHolder;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\OldBoardUrlToUnifiedRedirect;
use Bitrix\Disk\User;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Disk\Controller\Integration\Filter\JwtFilter;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\UserTable;

final class Flipchart extends Controller implements JwtHolder
{
	private ?object $jwtData;

	public function setJwtData(?object $data): void
	{
		$this->jwtData = $data;
	}

	public function getJwtData(): ?object
	{
		return $this->jwtData;
	}

	public function configureActions(): array
	{
		$oldBoardUrlToUnifiedRedirect = new OldBoardUrlToUnifiedRedirect();

		return [
			'listMentionCandidates' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'webhook' => [
				'+prefilters' => [
					new JwtFilter(
						Configuration::getJwtSecret(),
						$this,
					),
				],
				'-prefilters' => [
					Csrf::class,
					Authentication::class,
				],
			],
			'getDocument' => [
				'-prefilters' => [
					Csrf::class,
					Authentication::class,
				],
			],
			'openDocument' => [
				'+prefilters' => [
					$oldBoardUrlToUnifiedRedirect,
				],
				'-prefilters' => [
					Csrf::class,
				],
				'+postfilters' => [
					$oldBoardUrlToUnifiedRedirect,
				],
			],
			'openAttachedDocument' => [
				'+prefilters' => [
					$oldBoardUrlToUnifiedRedirect,
				],
				'-prefilters' => [
					Csrf::class,
				],
				'+postfilters' => [
					$oldBoardUrlToUnifiedRedirect,
				],
			],
			'viewDocument' => [
				'-prefilters' => [
					Csrf::class,
				],
			],
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new Parameter(
				DocumentSession::class,
				function ($className, $sessionId): ?DocumentSession {
					return (new SessionManager())
						->setExternalHash($sessionId)
						->setUserId((int)$this->getCurrentUser()?->getId())
						->setSessionType(DocumentSession::TYPE_EDIT)
						->findSession()
					;
				},
			),
		];
	}

	public function listMentionCandidatesAction(string $sessionHash, CurrentUser $currentUser): ?array
	{
		$userId = (int)$currentUser->getId();
		$session = $userId > 0 ? DocumentSession::load([
			'=USER_ID' => $userId,
			'=EXTERNAL_HASH' => $sessionHash,
			'=SERVICE' => DocumentService::FlipChart->value,
			'=STATUS' => DocumentSession::STATUS_ACTIVE,
			'=VERSION_ID' => null,
		]) : null;
		if (!$session)
		{
			$this->addError(new Error('Document Not Found', 404));

			return null;
		}

		$file = $session->getObject();
		if (!$file)
		{
			$this->addError(new Error('File Not Found', 404));

			return null;
		}

		$locator = ServiceLocator::getInstance();
		$uls = $locator->get(UnifiedLinkUrl::class)->getUlsFromRequest($this->getRequest());
		$hasUnifiedLinkProof = $uls !== null
			&& $locator->get(UnifiedLinkAccessService::class)->validateUlsForObjectId($file->getRealObjectId(), $uls)
		;
		$openingContext = $locator->get(OpeningContextFactory::class)->forPresence(
			$session,
			$userId,
			$hasUnifiedLinkProof,
		);
		if (!$openingContext || !$openingContext->canRead())
		{
			$this->addError(new Error('Access denied', 403));

			return null;
		}

		return ['items' => (new MentionCandidateProvider())->getCandidateItems($file, $userId)];
	}

	public function isSaveByChanceNeeded(DocumentSession $session): bool
	{
		$timeToSave = Configuration::getSaveDeltaTime();
		$chanceToSave = Configuration::getSaveProbabilityCoef();

		$savedSecondAgo = time() - $session->getFile()->getUpdateTime()->getTimestamp();

		return ((random_int(0,1000000) / 1000000) < $chanceToSave)
			|| ($savedSecondAgo > $timeToSave);
	}

	public function webhookAction(): ?HttpResponse
	{
		$type = $this->getJwtData()?->type;
		$sessionId = $this->getJwtData()?->sessionId;
		$documentIdLong = $this->getJwtData()?->documentId;
		$userId = $this->getJwtData()?->user_id ?: null;

		$documentId = BoardService::getDocumentIdFromExternal($documentIdLong);
		$siteId = BoardService::getSiteIdFromExternal($documentIdLong);

		if (!$sessionId || !$documentId || !$type)
		{
			return null;
		}
		$manager = new SessionManager();
		$manager->setExternalHash($sessionId);
		if (!is_null($userId))
		{
			$manager->setUserId((int)$userId);
		}

		$session = $manager->findSession();

		if (!$session)
		{
			return null;
		}

		$boardService = new BoardService($session);
		// The session found may belong to a guest while a registered user edits under the same hash.
		$findAuthorId = static fn(): ?int => $manager->findEditingParticipantId((int)$userId);

		/*
		 * - WAS_MODIFIED
		 * - LAST_USER_LEFT_THE_FLIP
		 * - FLIP_DELETED
		 * - FLIP_RENAMED
		 * - USER_ENTRY
		 * - USER_LEFT
		 */

		$editAllowed = $session->getType() === DocumentSession::TYPE_EDIT;

		// ANALYTICS
		$isNewBoard = $_GET['newBoard'] ?? null;
		$cElement = $_GET['c_element'] ?? null;

		$saveRefusal = null;
		$boardWasLeftToViewing = false;

		switch ($type)
		{
			case WebhookEventType::UserEntry->value:
				if ($session->getUserId() > 0)
				{
					Application::getInstance()->addBackgroundJob(function () use ($session, $editAllowed) {
						$event = new AnalyticsEvent('session_start', 'boards', 'boards');
						$event->setType($editAllowed ? 'edit' : 'view');
						$event->setUserId((int)$session->getUserId());
						$event->setP4('fileId_' . $session->getObjectId());
						$event->setP5('sessionId_' . sha1($session->getExternalHash()));
						$event->send();
					});
				}

				break;

			case WebhookEventType::WasModified->value:
				if (
					$editAllowed
					&& $this->isSaveByChanceNeeded($session)
				)
				{
					$saveRefusal = $this->saveBoard($boardService, $isNewBoard, $cElement, $findAuthorId());
				}

				break;

			case WebhookEventType::UserLeft->value:
				$boardWasLeftToViewing = !$editAllowed;
				$saveRefusal = $editAllowed
					? $this->saveBoard($boardService, $isNewBoard, $cElement, $findAuthorId())
					: $this->refusalOfABoardLeftToViewing($session)
				;

				if (!$this->invitesRedelivery($saveRefusal))
				{
					$boardService->closeSession();
				}

				if ($session->getUserId() > 0)
				{
					Application::getInstance()->addBackgroundJob(function () use ($session, $editAllowed) {
						$event = new AnalyticsEvent('session_end', 'boards', 'boards');
						$event->setType($editAllowed ? 'edit' : 'view');
						$event->setUserId((int)$session->getUserId());
						$event->setP4('fileId_' . $session->getObjectId());
						$event->setP5('sessionId_' . sha1($session->getExternalHash()));
						$event->send();
					});
				}

				break;

			case WebhookEventType::LastUserLeftTheFlip->value:
				if ($session->getStatus() === DocumentSession::STATUS_ACTIVE)
				{
					$boardWasLeftToViewing = !$editAllowed;
					$saveRefusal = $editAllowed
						? $this->saveBoard($boardService, $isNewBoard, $cElement, $findAuthorId())
						: $this->refusalOfABoardLeftToViewing($session)
					;

					if (!$this->invitesRedelivery($saveRefusal))
					{
						$boardService->closeSession();
					}
				}

				break;
		}

		if ($saveRefusal !== null)
		{
			// The registry answers for a write the node actually saw. A board left to viewing was
			// never handed over: the delivery that follows meets the very same view session, so its
			// departure is acknowledged rather than asked for again.
			return $this->makeRefusedSaveResponse(
				$saveRefusal,
				$boardWasLeftToViewing || $this->isRefusalFinal($saveRefusal),
			);
		}

		return null;
	}

	/**
	 * Saves the board and answers with the refusal of a save that stored nothing, or null when the
	 * save was not refused. A board the backend is still preparing is no refusal: that save is
	 * deferred by {@see BoardService::saveDocument()} to its own retry and keeps the flow it had,
	 * analytics included. A refusal ends the analytics of a save that never happened.
	 */
	private function saveBoard(
		BoardService $boardService,
		mixed $isNewBoard,
		mixed $cElement,
		?int $authorId = null,
	): ?Main\Error
	{
		$saveResult = $boardService->saveDocument($isNewBoard, authorId: $authorId);
		if ($saveResult instanceof Main\Error)
		{
			return $saveResult;
		}

		$this->sendSaveChangesEvent($isNewBoard, $cElement);

		return null;
	}

	/**
	 * A board left to viewing stores nothing, so its departure is answered with the very refusal that
	 * closed the editing - the restricted format or the exhausted personal quota - under the domain
	 * code of that reason; every other view session keeps the answer it always had. Deliveries
	 * reporting a change stay silent on purpose: they arrive while the board is open, and the throttle
	 * spacing out the writes counts from the last stored version, which such a board never moves.
	 */
	private function refusalOfABoardLeftToViewing(DocumentSession $session): ?Main\Error
	{
		return $session->findEditingRefusal();
	}

	private function sendSaveChangesEvent(mixed $isNewBoard, mixed $cElement): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($isNewBoard, $cElement) {
			$event = new AnalyticsEvent('save_changes', 'boards', 'boards');
			$event->setSubSection($isNewBoard ? 'new_element' : 'old_element');
			if ($cElement)
			{
				$event->setElement($cElement);
			}
			$event->send();
		});
	}

	/**
	 * The answer the board backend reads when the portal stored no version of the board: the
	 * envelope the webhook already speaks, in its error form. Nothing in it reports a save, and a
	 * refusal the write node named by a code of the registry travels in the body under that code.
	 *
	 * The status line tells the two refusals apart, and whether this one is final is decided by the
	 * caller: the registry answers only for a write the node actually saw. A refusal nothing calls
	 * final is answered with an error of the server: an error envelope alone leaves HTTP 200, which a
	 * caller reading no body takes for accepted, while nothing was stored. A version the write node
	 * refused by the content itself would be refused identically at every later delivery, so that
	 * delivery is accepted instead of being asked for forever - the refusal stays in the body and no
	 * `save_changes` event is sent for it.
	 *
	 * Both refusals are written to the journal of the portal, and each says which of the two it is.
	 * Only the status tells them apart, so a save that stored nothing is never left with the portal
	 * having nothing to show for it.
	 *
	 * Whether a delivery that follows can still reach a save is a question of its own, answered by
	 * {@see self::invitesRedelivery()} through the editing session, not by this status.
	 *
	 * The same shape as {@see \Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor::acknowledgeRefusedWrite()},
	 * where a delivery of a refused write is acknowledged and recorded as refused all the same.
	 */
	private function makeRefusedSaveResponse(Main\Error $error, bool $isFinal): HttpResponse
	{
		$response = AjaxJson::createError(new Main\ErrorCollection([$error]));

		AddMessage2Log($this->describeRefusedSave($error, $isFinal), 'disk');

		return $response->setStatus($isFinal ? '200 OK' : '500 Internal Server Error');
	}

	/**
	 * The line a refused save leaves in the log of the portal: which of the two refusals it was,
	 * what the model said and under which code it said it. The answer to the board backend carries
	 * the status and the text alone, so this line is where the portal keeps the refusal itself.
	 */
	private function describeRefusedSave(Main\Error $error, bool $isFinal): string
	{
		return sprintf(
			'Flipchart webhook: the new version of the board was refused %s (%s), code: %s.',
			$isFinal ? 'for good' : 'for now',
			$error->getMessage(),
			(string)$error->getCode(),
		);
	}

	/**
	 * Whether the write node refused the board for good. The verdict is not spelled out here:
	 * {@see WriteErrorTransportMap} owns the answer to "would the very same content be refused
	 * again", and a code it says nothing about leaves the save retryable.
	 */
	private function isRefusalFinal(Main\Error $saveRefusal): bool
	{
		return WriteErrorTransportMap::isRetryPointless((string)$saveRefusal->getCode());
	}

	/**
	 * Whether the answer asks the board backend to deliver the webhook once more. Only a board the
	 * write node held in its hands and refused for now is worth asking for: the delivery that
	 * follows meets the very same board and may store it. A save that stopped before the write node
	 * - the backend that prepares the board answered with a failure, the board was not handed over,
	 * the file of the session is gone - meets that same obstacle at every later delivery, so it is
	 * no more retryable than a refusal the registry calls final.
	 *
	 * The distinction is not "an error with no code of its own": the refusals from before the write
	 * node carry no code either, and keeping a session open for them costs the portal a session
	 * nobody closes afterwards - the boards termination service goes by rights alone and no agent
	 * collects them.
	 *
	 * A retry reaches the save through the session: {@see \Bitrix\Disk\Document\SessionManager::findSession()}
	 * looks the board up among active sessions alone. So a session outlives the departure of its
	 * user for exactly as long as a retry of the write is invited, and is closed as before in every
	 * other case.
	 */
	private function invitesRedelivery(?Main\Error $saveRefusal): bool
	{
		return $saveRefusal instanceof WriteNodeRefusal && !$this->isRefusalFinal($saveRefusal);
	}

	public function getDocumentAction(): ?BFile
	{
		$sessionId = $this->request->getQuery('sessionId');
		$data = DocumentSessionTable::getList(
			[
				'select' => [
					'OBJECT_ID',
					'VERSION_ID',
				],
				'filter' => [
					'=EXTERNAL_HASH' => $sessionId,
					'=SERVICE' => DocumentService::FlipChart->value,
					'=STATUS' => DocumentSession::STATUS_ACTIVE,
				],
			],
		);
		$document = $data->fetch();
		if (!$document)
		{
			$this->addError(new Error('Document Not Found', 404));

			return null;
		}

		$object = File::getById($document['OBJECT_ID']);
		if (!$object)
		{
			$this->addError(new Error('File Not Found', 404));

			return null;
		}

		$file = null;
		if ($document['VERSION_ID'])
		{
			$version = $object->getVersion($document['VERSION_ID']);
			if ($version)
			{
				$file = $version->getFile();
			}
		}
		else
		{
			$file = $object->getFile();
		}

		if (!$file)
		{
			$this->addError(new Error('File Not Found', 404));

			return null;
		}

		return new BFile($file);
	}

	/**
	 * @param int $fileId AttachedObject ID
	 * @param CurrentUser $currentUser
	 * @return HttpResponse|null
	 */
	public function openAttachedDocumentAction(?AttachedObject $attachedObject, CurrentUser $currentUser): ?HttpResponse
	{
		if (!$attachedObject)
		{
			return $this->getErrorPageResponse();
		}

		$attachedCanUpdate = $attachedObject->canUpdate((int)$currentUser->getId());
		$attachedCanRead = $attachedCanUpdate || $attachedObject->canRead((int)$currentUser->getId());

		if (!$attachedCanRead)
		{
			return $this->getErrorPageResponse();
		}

		$sessionType = $attachedCanUpdate ? DocumentSession::TYPE_EDIT : DocumentSession::TYPE_VIEW;

		$manager = new SessionManager();
		$manager->setFile($attachedObject->getFile());
		$manager->setUserId((int)$currentUser->getId());
		$manager->setSessionType($sessionType);
		$manager->setSessionContext(
			new DocumentSessionContext(
				(int)$attachedObject->getFileId(),
				(int)$attachedObject->getId(),
				null,
			),
		);

		$session = $manager->findOrCreateSession();

		if (!$session)
		{
			return $this->getErrorPageResponse();
		}

		return $this->viewDocumentAction($session, $currentUser, $attachedObject->getFile());
	}

	public function openDocumentAction(?File $file, CurrentUser $currentUser): ?HttpResponse
	{
		if (!$file)
		{
			return $this->getErrorPageResponse();
		}

		$versionId = $this->request->getQuery('versionId');

		if ($versionId)
		{
			return $this->openDocumentVersion($file, $currentUser, (int)$versionId);
		}

		$manager = new SessionManager();
		$manager->setFile($file);
		$manager->setUserId((int)$currentUser->getId());
		$manager->setSessionContext(
			new DocumentSessionContext(
				(int)$file->getId(),
				null,
				null,
			),
		);
		$session = $manager->findSession(true);

		if ($session)
		{
			return $this->viewDocumentAction($session, $currentUser, $file);
		}

		$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
		$fileCanUpdate = $file->canUpdate($securityContext);
		$fileCanRead = $fileCanUpdate || $file->canRead($securityContext);

		if (!$fileCanRead && !$fileCanUpdate)
		{
			return $this->getErrorPageResponse();
		}

		$sessionType = $fileCanUpdate ? DocumentSession::TYPE_EDIT : DocumentSession::TYPE_VIEW;

		$manager = new SessionManager();
		$manager->setFile($file);
		$manager->setUserId((int)$currentUser->getId());
		$manager->setSessionType($sessionType);
		$manager->setSessionContext(
			new DocumentSessionContext(
				(int)$file->getId(),
				null,
				null,
			),
		);

		$session = $manager->findOrCreateSession();

		if (!$session)
		{
			return $this->getErrorPageResponse();
		}

		return $this->viewDocumentAction($session, $currentUser, $file);
	}

	public function openDocumentVersion(?File $file, CurrentUser $currentUser, int $versionId): ?HttpResponse
	{
		if (!$file)
		{
			return $this->getErrorPageResponse();
		}

		$version = $file->getVersion($versionId);
		if (!$version)
		{
			return $this->getErrorPageResponse();
		}

		$manager = new SessionManager();
		$manager->setFile($file);
		$manager->setVersion($version);
		$manager->setUserId((int)$currentUser->getId());
		$manager->setSessionContext(
			new DocumentSessionContext(
				(int)$file->getId(),
				null,
				null,
			),
		);
		$session = $manager->findSession(true);

		if ($session)
		{
			return $this->viewDocumentAction($session, $currentUser, $file);
		}

		$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
		$fileCanRead = $file->canRead($securityContext);

		if (!$fileCanRead)
		{
			return $this->getErrorPageResponse();
		}

		$sessionType = DocumentSession::TYPE_VIEW;

		$manager = new SessionManager();
		$manager->setFile($file);
		$manager->setVersion($version);
		$manager->setUserId((int)$currentUser->getId());
		$manager->setSessionType($sessionType);
		$manager->setSessionContext(
			new DocumentSessionContext(
				(int)$file->getId(),
				null,
				null,
			),
		);

		$session = $manager->findOrCreateSession();

		if (!$session)
		{
			return $this->getErrorPageResponse();
		}

		return $this->viewDocumentAction($session, $currentUser, $file);
	}

	public function createDocumentAction(CurrentUser $currentUser, ?int $collabId = null): ?array
	{
		$userId = (int)$currentUser->getId();
		$user = User::loadById($userId);

		// TODO: remove if (false) when frontend sends $collabId
		if (false)
		{
			$isCollaber = $user?->isCollaber() ?? false;
			if ($isCollaber && (int)$collabId < 1)
			{
				$this->addError(new Error('No collab ID for collab user'));
				return null;
			}
		}

		if ((int)$collabId > 0)
		{
			$collabDocumentCreationService = ServiceLocator::getInstance()->get(CollabDocumentCreationService::class);

			$folder = $collabDocumentCreationService->getCreatedFilesFolderByCollabId($collabId, $userId);
			if ($folder === null)
			{
				$this->addError(new Error('There is no such collab, or the user is not a collab member'));
				return null;
			}

			$storage = $folder->getStorage();
			if ($storage === null || !$folder->canAdd($storage->getSecurityContext($userId)))
			{
				$this->addError(new Error('Bad rights. Could not add file to the folder.'));
				return null;
			}
		}
		else
		{
			$userStorage = Driver::getInstance()->getStorageByUserId($userId);
			$folder = $userStorage->getFolderForCreatedFiles();
		}

		$res = BoardService::createNewDocument($user, $folder);

		$st = $this->request->getQuery('st');
		$cElement = null;
		if ($st && $st['c_element'] ?? null)
		{
			$cElement = $st['c_element'];
		}

		if (!$res->isSuccess())
		{
			// The code naming a refused creation is not always carried by the first error.
			$this->addErrors($res->getErrors());

			return null;
		}

		$urlManager = Driver::getInstance()->getUrlManager();

		$openUrl = $urlManager->getUrlForViewBoard($res->getData()['file'], false, $cElement);

		$res->setData(
			[
				'viewUrl' => $openUrl,
			]
			+ $res->getData(),
		);

		return $res->getData();
	}

	public function viewDocumentAction(DocumentSession $session, CurrentUser $currentUser, ?File $originalFile = null): HttpResponse
	{
		$userRow = UserTable::getById((int)$currentUser->getId())->fetch();
		/** @var User $userModel */
		$userModel = User::buildFromRow($userRow);

		$documentUrl = (new DocumentDownloadUrlService())->build($session, applyLegacyHttpDowngrade: true);

		if (
			($session->isEdit() && !$session->canUserEdit($currentUser))
			|| ($session->isView() && !$session->canUserRead($currentUser))
		)
		{
			return $this->getErrorPageResponse();
		}

		// The same decision the editor component makes: without it the component throws instead, and
		// executeComponent() swallows that on an ordinary request, leaving an empty slider in place of
		// this error page.
		if (ServiceProfileResolver::createFromOptions()->resolveForEditor($session->getObject()) === null)
		{
			PilotLog::error(
				'Board service profile is not resolved: {entryPoint}, object {objectId}',
				[
					'entryPoint' => 'viewDocumentAction',
					'objectId' => (int)$session->getObjectId(),
				],
			);

			return $this->getErrorPageResponse();
		}

		$avatarUrl = $userModel->getAvatarSrc();
		if (strpos($avatarUrl, 'http') !== 0)
		{
			$urlManager = UrlManager::getInstance();
			$avatarUrl = $urlManager->getHostUrl() . $avatarUrl;
		}

		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.flipchart.editor',
				'POPUP_COMPONENT_PARAMS' => [
					'DOCUMENT_SESSION' => $session,
					'DOCUMENT_URL' => $documentUrl,
					'STORAGE_MODULE_ID' => 'disk',
					'STORAGE_ENTITY_TYPE' => 'Bitrix\Disk\ProxyType\User',
					'STORAGE_ENTITY_ID' => $currentUser->getId(),
					'USER_ID' => $currentUser->getId(),
					'USERNAME' => $currentUser->getFormattedName(),
					'AVATAR_URL' => $avatarUrl,
					'SHOW_TEMPLATES_MODAL' => $this->shouldShowTemplatesModal($session),
					'ORIGINAL_FILE' => $originalFile,
				],
				'PLAIN_VIEW' => true,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => false,
			],
		);

		$response = new HttpResponse();
		$response->setContent($content);

		return $response;
	}

	/**
	 * Inserting a template writes into the board, so a session that only gives a view is offered none.
	 */
	private function shouldShowTemplatesModal(DocumentSession $session): bool
	{
		if (!$session->isEdit())
		{
			return false;
		}

		$file = $session->getFile();

		return $file !== null && BoardService::shouldShowTemplateModal($file);
	}

	private function getErrorPageResponse(): HttpResponse
	{
		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.error.page',
				'POPUP_COMPONENT_PARAMS' => [],
				'PLAIN_VIEW' => false,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => true,
			],
		);

		$response = new HttpResponse();
		$response->setContent($content);

		return $response;
	}
}
