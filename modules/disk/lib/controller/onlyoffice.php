<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Document;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\DocumentChannelReporter;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\DocumentSaveService;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\KeptDocumentRecovery;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SaveFailureCategory;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SaveTimingConfig;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SavingMarker;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SessionReportBuilder;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internal\Service\DocumentHeader\RenameService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\User;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\ContentType;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Engine\Router;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Ui;
use Psr\Log\LoggerInterface;

final class OnlyOffice extends Engine\Controller
{
	/**
	 * Document is being edited.
	 * @var int
	 */
	protected const STATUS_IS_BEING_EDITED = 1;
	/**
	 * Document is ready for saving.
	 * @var int
	 */
	protected const STATUS_IS_READY_FOR_SAVE = 2;
	/**
	 * Document saving error has occurred.
	 * @var int
	 */
	protected const STATUS_ERROR_WHILE_SAVING = 3;
	/**
	 * Document is closed with no changes.
	 * @var int
	 */
	protected const STATUS_CLOSE_WITHOUT_CHANGES = 4;
	/**
	 * Document is being edited, but the current document state is saved.
	 * @var int
	 */
	protected const STATUS_FORCE_SAVE = 6;
	/**
	 * Error has occurred while force saving the document.
	 * @var int
	 */
	protected const STATUS_ERROR_WHILE_FORCE_SAVING = 7;

	/** The editor answered with an error, or warned about changes it could not confirm. */
	private const REASON_EDITOR_FAILURE = 'editor_failure';
	/** The page closed on a save the editor never confirmed. */
	private const REASON_SAVE_UNCONFIRMED = 'save_unconfirmed';
	private const EDITOR_FAILURE_REASONS = [self::REASON_EDITOR_FAILURE, self::REASON_SAVE_UNCONFIRMED];
	private const ERROR_INVALID_REASON = 'INVALID_REASON';
	private const EDITOR_MESSAGE_LIMIT = 500;

	protected const ACTION_TYPE_DISCONNECT = 0;
	protected const ACTION_TYPE_CONNECT = 1;
	protected const ACTION_TYPE_FORCE_SAVE = 2;

	/**
	 * Answer of the callback that closes the request. The document server hands the document over
	 * once more for every other answer, so this one is also the only way to stop it asking.
	 * @var int
	 */
	protected const CALLBACK_ERROR_NONE = 0;
	/**
	 * Answer of the callback for a save that stored nothing. The document server keeps the
	 * document on its side instead of dropping it as saved, and hands it over again.
	 * @var int
	 */
	protected const CALLBACK_ERROR_SAVE_FAILED = 1;

	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkUrl $unifiedLinkUrl;
	/** @var array */
	protected $documentSessions;
	private $addExtendedErrorInfo = false;
	/**
	 * A save the callback asked for left no new version behind: the write node refused it, or the
	 * document could not be downloaded. Neither the answer nor the stored state may report such a
	 * save as done.
	 */
	private bool $saveFailed = false;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkUrl = $serviceLocator->get(UnifiedLinkUrl::class);
	}

	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				DocumentSession::class,
				'documentSession',
				function($className, $documentSessionHash) {
					return DocumentSession::load([
						'=EXTERNAL_HASH' => $documentSessionHash,
					]);
				}
			),
		];
	}

	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new Document\OnlyOffice\Filters\OnlyOfficeEnabled();

		return $defaultPreFilters;
	}

	public function configureActions()
	{
		return [
			'handleEndOfTrialFeature' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'loadDocumentEditor' => [
				'-prefilters' => [Csrf::class],
			],
			'loadCreateDocumentEditor' => [
				'-prefilters' => [Csrf::class],
			],
			'createDocument' => [
				'-prefilters' => [Csrf::class],
			],
			'loadDocumentViewer' => [
				'-prefilters' => [Csrf::class],
			],
			'loadDocumentEditorByViewSession' => [
				'-prefilters' => [Csrf::class],
				'+prefilters' => [
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return Context::getCurrent()->getRequest()->get('documentSessionHash');
						})
					,
				],
			],
			'renameDocument' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return (new JsonPayload())->getData()['documentSessionHash'];
						})
					,
				],
			],
			'endSession' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return (new JsonPayload())->getData()['documentSessionHash'];
						})
					,
				],
			],
			'reportEditorFailure' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return (new JsonPayload())->getData()['documentSessionHash'];
						})
					,
				],
			],
			'continueWithNewSession' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return (new JsonPayload())->getData()['documentSessionHash'];
						})
					,
				],
			],
			'recoverSessionWithBrokenFile' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new ContentType([ContentType::JSON]),
					(new Document\OnlyOffice\Filters\DocumentSessionCheck())
						->enableOwnerCheck()
						->enableHashCheck(function(){
							return (new JsonPayload())->getData()['documentSessionHash'];
						})
					,
				],
			],
			'handleOnlyOffice' => [
				'prefilters' => [
					new Document\OnlyOffice\Filters\OnlyOfficeEnabled(),
					new ContentType([ContentType::JSON]),
					new Document\OnlyOffice\Filters\Authorization(
						ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getSecretKey()
					),
				],
			],
			'download' => [
				'prefilters' => [
					new Document\OnlyOffice\Filters\OnlyOfficeEnabled(),
					new Document\OnlyOffice\Filters\Authorization(
						ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getSecretKey(),
						[
							'documentSessionHash',
						]
					),
				],
			],
		];
	}

	public function handleEndOfTrialFeatureAction(): void
	{
		Disk\UserConfiguration::resetDocumentServiceCode();

		$bitrix24Scenario = new Document\OnlyOffice\Bitrix24Scenario();
		if ($bitrix24Scenario->isTrialEnded() && !$bitrix24Scenario->canUseView())
		{
			$documentHandlersManager = Driver::getInstance()->getDocumentHandlersManager();
			$defaultHandlerForView = $documentHandlersManager->getDefaultHandlerForView();
			if ($defaultHandlerForView instanceof Document\OnlyOffice\OnlyOfficeHandler)
			{
				Disk\Configuration::setDefaultViewerService(Document\BitrixHandler::getCode());
			}
		}
	}

	public function handleTrialFeatureActivationAction(): void
	{
		$trialFeatureInfo = Disk\Integration\Bitrix24Manager::getTrialFeatureInfo('disk_onlyoffice_edit');
		if (!$trialFeatureInfo)
		{
			return;
		}

		['startDate' => $startDate, 'tillDate' => $tillDate] = $trialFeatureInfo;
		$startDateTime = new DateTime($startDate, \DateTime::ISO8601);
		$tillDate = new Date($tillDate, \DateTime::ISO8601);
		$now = new DateTime();
		$secondsAfterActivation = $now->getTimestamp() - $startDateTime->getTimestamp();
		if ($secondsAfterActivation > 12*3600)
		{
			return;
		}

		$value = Option::get(Driver::INTERNAL_MODULE_ID, 'posted_message_after_onlyoffice_feature_trial', 'N');
		if ($value === 'Y')
		{
			return;
		}

		$imManager = new Disk\Integration\ImManager();
		$fromUserId = $this->getCurrentUser()->getId();

		$imManager->sendMessageToGeneralChat(
			$fromUserId,
			[
				'MESSAGE' => $this->generateMessageToChat($fromUserId, $tillDate),
				'SYSTEM' => 'Y',
			]
		);

		Option::set(Driver::INTERNAL_MODULE_ID, 'posted_message_after_onlyoffice_feature_trial', 'Y');
	}

	private function generateMessageToChat(int $fromUserId, Date $tillDate): string
	{
		$userModel = User::getById($fromUserId);
		if ($userModel && $userModel->getPersonalGender() === 'F')
		{
			return Loc::getMessage('DISK_ONLYOFFICE_MSG_TO_GENERAL_CHAT_AFTER_DEMO_FEATURE_F', [
				'#USER#' => "[USER={$fromUserId}][/USER]",
				'#TRIAL_PERIOD_END_DATE#' => $tillDate->toString(),
				'#HELPDESK_LINK#' => Ui\Util::getArticleUrlByCode(13663816),
			]);
		}

		return Loc::getMessage('DISK_ONLYOFFICE_MSG_TO_GENERAL_CHAT_AFTER_DEMO_FEATURE_M', [
			'#USER#' => "[USER={$fromUserId}][/USER]",
			'#TRIAL_PERIOD_END_DATE#' => $tillDate->toString(),
			'#HELPDESK_LINK#' => Ui\Util::getArticleUrlByCode(13663816),
		]);
	}

	/**
	 * The rename requested by the editor interface and the one requested by the header are the same
	 * operation, so both go through {@see RenameService} - including its rule that the right on the
	 * object decides, not the kind of the session.
	 */
	public function renameDocumentAction(
		Models\DocumentSession $documentSession,
		CurrentUser $currentUser,
		RenameService $renameService,
		string $newName,
	): ?array
	{
		$result = $renameService->rename($documentSession, $newName, (int)$currentUser->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'file' => [
				'name' => $result->getData()['name'],
			],
		];
	}

	public function recoverSessionWithBrokenFileAction(Models\DocumentSession $session): ?array
	{
		$documentInfo = $session->getInfo();
		if (!$documentInfo)
		{
			$this->addError(new Error("Session {$session->getId()} doesn't have info."));

			return null;
		}

		// A key kept after a rejected save or an interrupted session is exactly the key the user comes
		// back to with a broken file: the editing behind it is over, but the document is still its own.
		if ($documentInfo->isFinished() && !$this->keepsDocumentKey($documentInfo))
		{
			$this->addError(new Error("Session {$session->getId()} is already finished."));

			return null;
		}

		$session->setAsBroken();
		Models\DocumentSessionTable::markAsBrokenByHash($session->getExternalHash());

		$restoreDocumentInteractionUrl = UrlManager::getInstance()->createByController(new DocumentService(), 'restoreDocumentInteraction', [
			'documentSessionHash' => $session->getExternalHash(),
			'documentSessionId' => $session->getId(),
		]);

		return [
			'link' => $restoreDocumentInteractionUrl,
		];
	}

	public function continueWithNewSessionAction(Models\DocumentSession $session, bool $force = false): ?array
	{
		$documentInfo = $session->getInfo();
		if (!$documentInfo)
		{
			$this->addError(new Error("Session {$session->getId()} doesn't have info."));

			return null;
		}

		if (!$force && !$documentInfo->isFinished())
		{
			$this->addError(new Error("Session {$session->getId()} in status {$documentInfo->getContentStatus()}"));

			return null;
		}

		// The editor server keeps the document of a rejected save and of an interrupted session under the
		// key it knows. A new key would open the copy Disk holds - the one without the lost editing.
		// Both of those statuses are finished ones, so the order against $force decides nothing: $force
		// only lets an unfinished session through, and an unfinished session has no kept key to come back to.
		if ($this->keepsDocumentKey($documentInfo))
		{
			return $this->makeSessionAnswer($session);
		}

		$newSession = $session->cloneWithNewHash($session->getUserId());
		if (!$newSession)
		{
			$this->addErrors($session->getErrors());

			return null;
		}

		return $this->makeSessionAnswer($newSession);
	}

	/**
	 * Whether the document behind the session is still the one the editor server holds, so the next
	 * opening has to come back to the same key instead of starting over on the copy Disk has.
	 */
	private function keepsDocumentKey(Models\DocumentInfo $documentInfo): bool
	{
		return in_array(
			$documentInfo->getContentStatus(),
			[
				Models\DocumentInfo::CONTENT_STATUS_SAVE_REJECTED,
				Models\DocumentInfo::CONTENT_STATUS_INTERRUPTED,
			],
			true,
		);
	}

	private function makeSessionAnswer(Models\DocumentSession $session): array
	{
		/** @see \DiskFileEditorOnlyOfficeController::getSliderContentAction */
		$link = UrlManager::getInstance()->create('getSliderContent', [
			'c' => 'bitrix:disk.file.editor-onlyoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
			'documentSessionId' => $session->getId(),
			'documentSessionHash' => $session->getExternalHash(),
		]);

		return [
			'documentSession' => [
				'id' => $session->getId(),
				'hash' => $session->getExternalHash(),
				'link' => $link,
			],
		];
	}

	/**
	 * Takes the word of the page that the editor is in trouble, and only writes it down.
	 *
	 * The page sees what the portal never does: the editor answering with an error, or a save the editor
	 * confirmed to nobody. Nothing about the session follows from that - the callback of the editor
	 * server is still the only word on whether the document was written - so the report changes no state
	 * and is not shown to the user; it is there to tell a silent session apart from a broken editor
	 * afterwards.
	 */
	public function reportEditorFailureAction(
		Models\DocumentSession $session,
		string $reason,
		?int $editorCode = null,
		?string $editorMessage = null,
	): ?array
	{
		if (!in_array($reason, self::EDITOR_FAILURE_REASONS, true))
		{
			$this->addError(new Error('Unknown reason of the editor failure.', self::ERROR_INVALID_REASON));

			return null;
		}

		// The key of the document is a secret of the session and never reaches the log.
		$this->getSaveLogger()->warning('OnlyOffice editor failure reported by the page: {reason}.', [
			'sessionId' => (int)$session->getId(),
			'objectId' => $session->getObjectId(),
			'userId' => (int)$this->getCurrentUser()?->getId(),
			'reason' => $reason,
			'editorCode' => $editorCode,
			'editorMessage' => $this->cutEditorMessage($editorMessage),
		]);

		return null;
	}

	/**
	 * The text comes from the editor and is only evidence: a long one is cut instead of being refused,
	 * because the record is worth more than its tail.
	 */
	private function cutEditorMessage(?string $editorMessage): ?string
	{
		if ($editorMessage === null)
		{
			return null;
		}

		return mb_substr($editorMessage, 0, self::EDITOR_MESSAGE_LIMIT);
	}

	private function getSaveLogger(): LoggerInterface
	{
		return ServiceLocator::getInstance()->get(DocumentSaveService::LOGGER_SERVICE_ID);
	}

	public function endSessionAction(Models\DocumentSession $session): array
	{
		if ($session->isEdit())
		{
			$countActiveSessions = $session->countActiveSessions();
			$documentInfo = $session->getInfo();
			if ($countActiveSessions <= 1 && $session->isActive())
			{
				if ($documentInfo)
				{
					$documentInfo->setUserCount(0);
				}
			}

			return [
				'mode' => 'edit',
				'file' => [
					'id' => $session->getObject()->getId(),
					'name' => $session->getObject()->getName(),
				],
				'documentSessionInfo' => [
					'contentStatus' => $documentInfo? $documentInfo->getContentStatus() : null,
					'isFinished' => $documentInfo && $documentInfo->isFinished(),
				],
				'activeSessions' => $countActiveSessions,
			];
		}

		return [
			'mode' => 'view',
		];
	}

	public function downloadAction(Models\DocumentSession $documentSession): ?Response\BFile
	{
		$this->enableExtendedErrorInfo();

		$bfileId = null;
		$fileName = null;
		if ($documentSession->isVersion() && $documentSession->getVersion())
		{
			$bfileId = $documentSession->getVersion()->getFileId();
			$fileName = $documentSession->getVersion()->getName();
		}
		elseif (!$documentSession->isVersion() && $documentSession->getFile())
		{
			$bfileId = $documentSession->getFile()->getFileId();
			$fileName = $documentSession->getFile()->getName();
		}

		if (!$bfileId || !$fileName)
		{
			$this->addError(new Error('Could find file or version for this document session'));

			return null;
		}

		return Response\BFile::createByFileId($bfileId, $fileName);
	}

	public function handleOnlyOfficeAction(Models\DocumentSession $documentSession, JsonPayload $payload): ?Response\Json
	{
		$processingStartedAt = microtime(true);
		$this->enableExtendedErrorInfo();

		$payloadData = $payload->getData();
		$status = $payloadData['status'];

		$this->processRestrictionLogs($status, $payloadData);

		$userDocumentSessions = [];

		$userIdsForSessions = array_map(
			callback: fn(array $action) => (int)($action['userid'] ?? null),
			array: $payloadData['actions'] ?? [],
		);

		if (!empty($userIdsForSessions))
		{
			$userDocumentSessions = $this->getDocumentSessionsByKeyForUsers(
				documentSessionHash: $documentSession->getExternalHash(),
				userIds: $userIdsForSessions,
			);
		}

		$this->logUsageMetrics($documentSession, $payloadData, $userDocumentSessions);

		$reportBuilder = new SessionReportBuilder();
		$channelReport = null;
		$channelCommand = SessionReportBuilder::COMMAND_SESSION_END;

		switch ($status)
		{
			case self::STATUS_IS_BEING_EDITED:
				$this->handleDocumentIsEditing($documentSession, $payloadData, $userDocumentSessions);
				break;

			case self::STATUS_IS_READY_FOR_SAVE:
			case self::STATUS_ERROR_WHILE_SAVING:
				$this->sendDocumentClosedWithChangesAnalytics($documentSession);
				$documentInfo = $documentSession->getInfo();
				$saveResult = $this->saveFinalDocument(
					$documentSession,
					$payloadData,
					$processingStartedAt,
					$documentInfo,
				);
				$channelReport = $reportBuilder->buildSessionEndReport(
					$documentSession,
					$documentInfo,
					$saveResult->isSuccess(),
					$this->readFailureCategory($saveResult),
				);
				break;

			case self::STATUS_CLOSE_WITHOUT_CHANGES:
				$this->handleDocumentClosedWithoutChanges($documentSession);
				$channelReport = $reportBuilder->buildSessionEndReport(
					$documentSession,
					$documentSession->getInfo(),
					wasDocumentWritten: false,
				);
				break;

			case self::STATUS_FORCE_SAVE:
			case self::STATUS_ERROR_WHILE_FORCE_SAVING:
				// No mark of a save in progress here: an intermediate save is written while the people are
				// still in the document, and a key with users in it never looks abandoned to a second opening.
				$documentInfo = $documentSession->getInfo();
				$saveResult = $this->saveDocument($documentSession, $payloadData, $processingStartedAt);
				$this->markIntermediateSave($documentInfo, $saveResult->isSuccess());
				$channelReport = $reportBuilder->buildIntermediateSaveReport(
					$documentSession,
					$documentInfo,
					$saveResult->isSuccess(),
					$this->readFailureCategory($saveResult),
				);
				$channelCommand = SessionReportBuilder::COMMAND_INTERMEDIATE_SAVE;
				break;
		}

		if ($channelReport !== null)
		{
			$this->sendEventToDocumentChannel($documentSession, $channelCommand, $channelReport);
		}

		return $this->makeCallbackResponse();
	}

	/**
	 * The answer the document server reads to learn whether it has to hand the document over again.
	 * A save that stored nothing is answered with a non-zero code, so the editor keeps the document
	 * instead of considering it saved and dropping it, and hands it over once more.
	 */
	private function makeCallbackResponse(): Response\Json
	{
		$askAgain = $this->saveFailed;

		return new Response\Json([
			'error' => $askAgain ? self::CALLBACK_ERROR_SAVE_FAILED : self::CALLBACK_ERROR_NONE,
		]);
	}

	protected function processRestrictionLogs(int $status, array $hookData): void
	{
		$restrictionManager = new Document\OnlyOffice\RestrictionManager();
		if ($restrictionManager->shouldUseRestriction())
		{
			$restrictionManager->processHookData($status, $hookData);
		}
	}

	/**
	 * The state of the content of an intermediate save, which the editing goes on after. The code the
	 * editor server sent says nothing here: only the version written on Disk does.
	 */
	private function markIntermediateSave(?Models\DocumentInfo $documentInfo, bool $wasWritten): void
	{
		if (!$documentInfo)
		{
			return;
		}

		if ($wasWritten)
		{
			$documentInfo->markAsForceSaved();
		}
		else
		{
			$documentInfo->markAsForceSavedWithError();
		}
	}

	private function readFailureCategory(Result $saveResult): ?SaveFailureCategory
	{
		$failureCategory = $saveResult->getData()[DocumentSaveService::DATA_FAILURE_CATEGORY] ?? null;

		return $failureCategory instanceof SaveFailureCategory ? $failureCategory : null;
	}

	protected function handleDocumentClosedWithoutChanges(Models\DocumentSession $documentSession): void
	{
		AddEventToStatFile(
			'disk',
			'disk_oo_doc_closed',
			$documentSession->getExternalHash(),
			ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getServer(),
			'without_changes',
			0
		);

		$this->sendSessionEndAnalyticsEvent($documentSession);

		$this->deactivateDocumentSessions($documentSession->getExternalHash());
		$documentInfo = $documentSession->getInfo();
		if (!$documentInfo)
		{
			return;
		}

		// Read before the state of the content is written over: an intermediate save left a version
		// which the attached objects have not been told about yet.
		if ($documentInfo->wasForceSaved())
		{
			$this->commentAttachedObjectsOnBackground($documentSession);
		}

		$documentInfo->markAsNoChanges();
	}

	protected function handleDocumentIsEditing(
		Models\DocumentSession $documentSession,
		array $payloadData,
		array $userDocumentSessions,
	): void
	{
		if ($payloadData['status'] !== self::STATUS_IS_BEING_EDITED)
		{
			return;
		}

		$onlineUsers = $payloadData['users'] ?? [];
		$onlineUsers = array_map('intval', $onlineUsers);

		$actions = $payloadData['actions'] ?? [];

		$documentInfo = $documentSession->getInfo();
		if ($documentInfo)
		{
			// Read before the state is written over: it is the only trace of a document the editor server kept.
			$wasDocumentKept = $this->keepsDocumentKey($documentInfo);

			$documentInfo->markAsEditing();
			$documentInfo->setUserCount(count($onlineUsers));

			if ($wasDocumentKept && $this->hasConnectedUser($actions))
			{
				ServiceLocator::getInstance()->get(KeptDocumentRecovery::class)->scheduleRecovery($documentSession);
			}
		}

		foreach ($actions as $action)
		{
			$type = $action['type'] ?? null;
			$userId = (int)($action['userid'] ?? null);
			$userSession = $userDocumentSessions[$userId] ?? null;

			if (!$userSession)
			{
				continue;
			}

			if ($type === self::ACTION_TYPE_DISCONNECT)
			{
				if (!in_array($userId, $onlineUsers, true))
				{
					$userSession->setAsNonActive();
				}
			}
			elseif (($type === self::STATUS_IS_BEING_EDITED) && $userSession->isNonActive())
			{
				$userSession->setAsActive();
			}
		}
	}

	private function hasConnectedUser(array $actions): bool
	{
		foreach ($actions as $action)
		{
			if ((int)($action['type'] ?? -1) === self::ACTION_TYPE_CONNECT)
			{
				return true;
			}
		}

		return false;
	}

	protected function getDocumentSessionsByKeyForUsers(string $documentSessionHash, array $userIds): array
	{
		$sessions = DocumentSession::getModelList([
			'filter' => [
				'=EXTERNAL_HASH' => $documentSessionHash,
				'@USER_ID' => $userIds,
			]
		]);

		$byUser = [];
		foreach ($sessions as $session)
		{
			$byUser[$session->getUserId()] = $session;
		}

		return $byUser;
	}

	/**
	 * @param string $documentSessionHash
	 * @return DocumentSession[]
	 */
	protected function getDocumentSessionsByKey(string $documentSessionHash): array
	{
		if ($this->documentSessions === null)
		{
			$this->documentSessions = DocumentSession::getModelList([
				'filter' => [
					'=EXTERNAL_HASH' => $documentSessionHash,
				]
			]);
		}

		return $this->documentSessions;
	}

	protected function commentAttachedObjectsOnBackground(Models\DocumentSession $documentSession): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($documentSession){
			$this->commentAttachedObjects($documentSession);
		});
	}

	protected function commentAttachedObjects(DocumentSession $documentSession): void
	{
		$file = $documentSession->getFile();
		if (!$file)
		{
			return;
		}

		$lastVersion = $file->getLastVersion();
		if (!$lastVersion)
		{
			return;
		}

		$file->commentAttachedObjects($lastVersion);
	}

	protected function deactivateDocumentSessions(string $documentSessionHash): void
	{
		Models\DocumentSessionTable::deactivateByHash($documentSessionHash);
	}

	/**
	 * The final save of the session, with the key marked as being saved while it goes on.
	 *
	 * Without the mark a save slower than the two minutes of inactivity looks abandoned to a second
	 * opening, which then edits the stale document under a new key and overwrites the version this very
	 * webhook is writing. The mark is set again right before the version is written - the writing of a
	 * document of up to a hundred megabytes into a cloud storage is bounded by nothing - and released
	 * after the state of the content is written, so nothing between the two sees a key which is neither
	 * saving nor finished.
	 */
	private function saveFinalDocument(
		DocumentSession $documentSession,
		array $payloadData,
		float $processingStartedAt,
		?Models\DocumentInfo $documentInfo,
	): Result
	{
		$savingMarker = ServiceLocator::getInstance()->get(SavingMarker::class);
		$externalHash = $documentSession->getExternalHash();
		$wasMarkedAsSaving = $savingMarker->trySet($externalHash);

		$renewMarkBeforeWrite =
			$wasMarkedAsSaving
				? static fn(): bool => $savingMarker->trySet($externalHash)
				: null
		;

		try
		{
			$saveResult = $this->saveDocument(
				$documentSession,
				$payloadData,
				$processingStartedAt,
				$renewMarkBeforeWrite,
			);
			if ($saveResult->isSuccess())
			{
				$documentInfo?->markAsSaved();
				$this->deactivateDocumentSessions($externalHash);
				$this->commentAttachedObjectsOnBackground($documentSession);
			}
			else
			{
				// The sessions of the key stay active and the key stays the same: the editor server keeps
				// the document of a rejected save, and the next opening has to find that same document.
				$documentInfo?->markAsSaveRejected();
			}

			return $saveResult;
		}
		finally
		{
			if ($wasMarkedAsSaving)
			{
				$savingMarker->tryDelete($externalHash);
			}
		}
	}

	/**
	 * The outcome of the writing of the version on Disk.
	 *
	 * The limit of execution is raised before the download: the default one of the portal can be shorter
	 * than the budget of the download plus the writing, and then the webhook dies without an answer. It
	 * bounds the processor time of the webhook and nothing by the clock, which is why the lifetime of
	 * the mark of a save in progress is not derived from it. An intermediate save is raised just as far:
	 * it downloads and writes the same document, and a limit cut for it would kill the webhook of a large
	 * document exactly where the answer matters.
	 *
	 * @param \Closure|null $beforeWrite called between the downloading and the writing of the version.
	 */
	private function saveDocument(
		DocumentSession $documentSession,
		array $payloadData,
		float $processingStartedAt,
		?\Closure $beforeWrite = null,
	): Result
	{
		$serviceLocator = ServiceLocator::getInstance();
		set_time_limit($serviceLocator->get(SaveTimingConfig::class)->executionLimit);

		$saveResult = $serviceLocator
			->get(DocumentSaveService::class)
			->save(
				$documentSession,
				$payloadData,
				$processingStartedAt,
				$beforeWrite,
				$this->buildEditingSessionWriteOptions($documentSession),
			)
		;

		if ($saveResult->isSuccess())
		{
			$this->sendEventToParticipants($documentSession->getExternalHash(), 'saved');
		}
		else
		{
			$this->saveFailed = true;
		}

		return $saveResult;
	}

	/**
	 * Options of the writing of a version coming out of an editing session.
	 */
	private function buildEditingSessionWriteOptions(DocumentSession $documentSession): array
	{
		return ['commentAttachedObjects' => false];
	}

	private function sendDocumentClosedWithChangesAnalytics(DocumentSession $documentSession): void
	{
		AddEventToStatFile(
			'disk',
			'disk_oo_doc_closed',
			$documentSession->getExternalHash(),
			ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getServer(),
			'with_changes',
			0
		);

		$this->sendSessionEndAnalyticsEvent($documentSession);
	}

	/**
	 * The agent of interrupted sessions reports to the same channel, so the delivery and its answer to a
	 * document which is already gone live in {@see DocumentChannelReporter}.
	 */
	protected function sendEventToDocumentChannel(
		Models\DocumentSession $documentSession,
		string $command,
		array $report,
	): void
	{
		ServiceLocator::getInstance()
			->get(DocumentChannelReporter::class)
			->send($documentSession, $command, $report)
		;
	}

	protected function sendEventToParticipants(string $documentSessionHash, string $event): void
	{
		foreach ($this->getDocumentSessionsByKey($documentSessionHash) as $session)
		{
			Driver::getInstance()->sendEvent($session->getUserId(), 'onlyoffice', [
				'object' => [
					'id' => $session->getObjectId(),
				],
				'documentSession' => [
					'hash' => $documentSessionHash,
				],
				'event' => $event,
			]);
		}
	}

	private function logUsageMetrics(
		Models\DocumentSession $documentSession,
		array $payloadData,
		array $userDocumentSessions,
	): void
	{
		$server = ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getServer();
		$actions = $payloadData['actions'] ?? [];
		foreach ($actions as $action)
		{
			$type = $action['type'] ?? null;
			$userId = (int)($action['userid'] ?? null);
			$userDocumentSession = $userDocumentSessions[$userId] ?? null;

			if ($type === self::ACTION_TYPE_DISCONNECT)
			{
				AddEventToStatFile('disk', 'disk_oo_user_disconnect', $documentSession->getExternalHash(), $server, '', $userId);

				if ($userDocumentSession instanceof DocumentSession)
				{
					$this->sendUserSessionEndAnalyticsEvent($userDocumentSession, $userId);
				}
			}
			elseif ($type === self::STATUS_IS_BEING_EDITED)
			{
				AddEventToStatFile('disk', 'disk_oo_user_join', $documentSession->getExternalHash(), $server, '', $userId);

				if ($userDocumentSession instanceof DocumentSession)
				{
					$this->sendUserSessionStartAnalyticsEvent($userDocumentSession, $userId);
				}
			}
		}
	}

	public function createDocumentAction(
		string $typeFile,
		?\Bitrix\Disk\Folder $targetFolder = null,
		array $analytics = [],
	): ?array
	{
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->request);
		$newFile = $this->createDocument($typeFile, $targetFolder, $analytics, $uls);

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
		?\Bitrix\Disk\Folder $targetFolder = null,
		array $analytics = [],
	): ?HttpResponse
	{
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->request);
		$newFile = $this->createDocument($typeFile, $targetFolder, $analytics, $uls);

		if (!$newFile)
		{
			return null;
		}

		return $this->loadDocumentEditorAction($newFile);
	}

	private function createDocument(
		string $typeFile,
		?\Bitrix\Disk\Folder $targetFolder = null,
		array $analytics = [],
		?string $uls = null,
	): ?Disk\File
	{
		$createBlankDocumentScenario = new Document\OnlyOffice\CreateBlankDocumentScenario(
			(int)($this->getCurrentUser()?->getId() ?? 0),
			Context::getCurrent()?->getLanguage(),
		);

		if ($targetFolder)
		{
			$shouldCheckUnifiedLink =
				is_string($uls)
				&& $this->unifiedLinkAccessService->validateUlsForObjectId(
					objectId: $targetFolder->getId(),
					uls: $uls,
				)
			;

			$result = $createBlankDocumentScenario->createBlank(
				typeFile: $typeFile,
				targetFolder: $targetFolder,
				analytics: $analytics,
				shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
			);
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

	public function loadDocumentEditorByViewSessionAction(Models\DocumentSession $documentSession): ?HttpResponse
	{
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

		$createdSession = $documentSession->createEditSession();
		if (!$createdSession)
		{
			$this->addErrors($documentSession->getErrors());

			return null;
		}

		if ($createdSession->getId() != $documentSession->getId())
		{
			$documentSession->delete();
		}

		$documentSession = $createdSession;

		/** @see \DiskFileEditorOnlyOfficeController::getSliderContentAction */
		$link = UrlManager::getInstance()->create('getSliderContent', [
			'c' => 'bitrix:disk.file.editor-onlyoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
			'documentSessionId' => $documentSession->getId(),
			'documentSessionHash' => $documentSession->getExternalHash(),
		]);

		return $this->redirectTo($link);
	}

	public function loadDocumentEditorAction(
		Disk\File $object = null,
		Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL
	): ?HttpResponse
	{
		$canEdit = false;
		if ($attachedObject && !$attachedObject->canUpdate(User::resolveUserId($this->getCurrentUser())))
		{
			$attachedObject = null;
		}
		elseif ($attachedObject)
		{
			$canEdit = true;
		}

		if ($object && $attachedObject && ($object->getId() != $attachedObject->getObjectId()))
		{
			$object = null;
		}

		$uls = $object ? $this->getUnifiedLinkSignatureForFile($object) : null;

		if ($object && !$canEdit)
		{
			$securityContext = $object->getStorage()->getCurrentUserSecurityContext(
				shouldCheckUnifiedLink: is_string($uls),
			);

			if ($object->canUpdate($securityContext))
			{
				$canEdit = true;
			}
		}

		if (!$canEdit)
		{
			return $this->showNotFoundPageAction();
		}

		return $this->loadDocumentEditor($object, null, $attachedObject, DocumentSession::TYPE_EDIT, $editorMode, $uls);
	}

	private function getUnifiedLinkSignatureForFile(Disk\File $object): ?string
	{
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->request);

		if (!is_string($uls))
		{
			return null;
		}

		if (
			!$this->unifiedLinkAccessService->validateUlsForObjectId(
				objectId: $object->getId(),
				uls: $uls,
			)
		)
		{
			return null;
		}

		return $uls;
	}

	public function loadDocumentViewerAction(
		Disk\File $object = null,
		Disk\Version $version = null,
		Disk\AttachedObject $attachedObject = null,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL
	): ?HttpResponse
	{
		if ($object && $version && $object->getId() != $version->getObjectId())
		{
			$object = null;
		}

		if ($object === null && $version)
		{
			$object = $version->getObject();
		}

		$canRead = false;
		if ($attachedObject && !$attachedObject->canRead(User::resolveUserId($this->getCurrentUser())))
		{
			$attachedObject = null;
		}
		elseif ($attachedObject)
		{
			$canRead = true;
		}

		if ($object && $attachedObject && ($object->getId() != $attachedObject->getObjectId()))
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

		return $this->loadDocumentEditor($object, $version, $attachedObject, DocumentSession::TYPE_VIEW, $editorMode);
	}

	protected function loadDocumentEditor(
		?Disk\File $object,
		?Disk\Version $version,
		?Disk\AttachedObject $attachedObject,
		int $type,
		int $editorMode = Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
		?string $uls = null,
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
		if (!($file instanceof \Bitrix\Disk\File))
		{
			$this->addError(new Error('Could not find the file (content).'));

			return null;
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

		$sessionManager = new Document\OnlyOffice\DocumentSessionManager();
		$sessionManager
			->setUserId($this->getCurrentUser()->getId())
			->setSessionType($type)
			->setSessionContext($documentSessionContext)
			->setFile($object)
			->setVersion($version)
			->setAttachedObject($attachedObject)
		;

		if (!$sessionManager->lock())
		{
			$this->addError(new Error('Could not getting lock for the session.'));

			return null;
		}
		$documentSession = $sessionManager->findOrCreateSession();
		if (!$documentSession)
		{
			$this->addErrors($documentSession->getErrors());

			return null;
		}
		$sessionManager->unlock();

		$sliderContentParams = [
			'c' => 'bitrix:disk.file.editor-onlyoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
			'documentSessionId' => $documentSession->getId(),
			'documentSessionHash' => $documentSession->getExternalHash(),
			'editorMode' => $editorMode,
		];

		if (is_string($uls))
		{
			$sliderContentParams = $this->unifiedLinkUrl->addUlsToParams($sliderContentParams, $uls);
		}

		/** @see \DiskFileEditorOnlyOfficeController::getSliderContentAction */
		$link = UrlManager::getInstance()->create('getSliderContent', $sliderContentParams);

		return $this->redirectTo($link);
	}

	public function showNotFoundPageAction()
	{
		/** @see \DiskFileEditorOnlyOfficeController::showNotFoundAction() */
		$link = UrlManager::getInstance()->create('showNotFound', [
			'c' => 'bitrix:disk.file.editor-onlyoffice',
			'mode' => Router::COMPONENT_MODE_AJAX,
		]);

		return $this->redirectTo($link);
	}

	private function enableExtendedErrorInfo(): void
	{
		$this->addExtendedErrorInfo = true;
	}

	protected function runProcessingThrowable(\Throwable $throwable)
	{
		parent::runProcessingThrowable($throwable);

		if ($this->addExtendedErrorInfo)
		{
			$httpRequest = Context::getCurrent()->getRequest();
			$this->addError(new Disk\Internals\Error\Error(
				'Detailed info',
				'onlyoffice-01',
				[
					'r' => $httpRequest->getDecodedUri(),
					'h' => $httpRequest->getHttpHost(),
				]
			));
		}
	}

	protected function sendSessionEndAnalyticsEvent(DocumentSession $documentSession): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($documentSession) {
			$analyticsEvent = (new AnalyticsEvent(
				event: 'session_end',
				tool: 'docs',
				category: 'docs',
			))
				->setUserId(0)
				->setP2("sessionHash_{$documentSession->getExternalHash()}")
			;

			$openType = Disk\Analytics\Enum\OpenTypeEnum::getByDocumentSessionType($documentSession->getType());

			if ($openType instanceof Disk\Analytics\Enum\OpenTypeEnum)
			{
				$analyticsEvent->setType($openType->value);
			}

			$domain = ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getDomain();

			if (is_string($domain))
			{
				$analyticsEvent->setSection($domain);
			}

			$file = $documentSession->getFile();

			if ($file instanceof Disk\File)
			{
				$docType = Disk\Analytics\Enum\DocumentTypeEnum::getByExtension($file->getExtension());

				if ($docType instanceof Disk\Analytics\Enum\DocumentTypeEnum)
				{
					$analyticsEvent->setP3($docType->value);
				}

				$analyticsEvent->setP4("fileId_{$file->getId()}");
			}

			$analyticsEvent->send();
		});
	}

	protected function sendUserSessionStartAnalyticsEvent(DocumentSession $documentSession, int $userId): void
	{
		$this->sendUserSessionAnalyticsEvent($documentSession, $userId, true);
	}

	protected function sendUserSessionEndAnalyticsEvent(DocumentSession $documentSession, int $userId): void
	{
		$this->sendUserSessionAnalyticsEvent($documentSession, $userId, false);
	}

	protected function sendUserSessionAnalyticsEvent(
		DocumentSession $documentSession,
		int $userId,
		bool $isStart,
	): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($documentSession, $userId, $isStart) {
			$event = $isStart ? 'user_session_start' : 'user_session_end';

			$analyticsEvent = (new AnalyticsEvent(
				event: $event,
				tool: 'docs',
				category: 'docs',
			))
				->setUserId($userId)
				->setP2("sessionHash_{$documentSession->getExternalHash()}")
				->setP5("sessionId_{$documentSession->getId()}")
			;

			$openType = Disk\Analytics\Enum\OpenTypeEnum::getByDocumentSessionType($documentSession->getType());

			if ($openType instanceof Disk\Analytics\Enum\OpenTypeEnum)
			{
				$analyticsEvent->setType($openType->value);
			}

			$domain = ServiceLocator::getInstance()->get('disk.onlyofficeConfiguration')->getDomain();

			if (is_string($domain))
			{
				$analyticsEvent->setSection($domain);
			}

			$file = $documentSession->getFile();

			if ($file instanceof Disk\File)
			{
				$docType = Disk\Analytics\Enum\DocumentTypeEnum::getByExtension($file->getExtension());

				if ($docType instanceof Disk\Analytics\Enum\DocumentTypeEnum)
				{
					$analyticsEvent->setP3($docType->value);
				}

				$analyticsEvent->setP4("fileId_{$file->getId()}");
			}

			$analyticsEvent->send();
		});
	}
}
