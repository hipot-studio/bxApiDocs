<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Document\Flipchart\DocumentDownloadUrlService;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotLog;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\Document\Flipchart\SessionManager;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\SessionCommandFactory;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Main\Application;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Web\Uri;

class BoardHtmlRenderableFileHandler implements HtmlRenderableHandler
{
	private TrackedObjectManager $trackedObjectManager;
	private SessionCommandFactory $sessionCommandFactory;
	private ExceptionHandler $exceptionHandler;

	public function __construct(
		private readonly File $file,
		private readonly DocumentSource $documentSource,
	) {
		$this->trackedObjectManager = Driver::getInstance()->getTrackedObjectManager();

		$this->sessionCommandFactory = new SessionCommandFactory($documentSource, new SessionManager());
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	public function view(): HandlerOperationResult
	{
		return $this->handle(DocumentSession::TYPE_VIEW);
	}

	public function edit(): HandlerOperationResult
	{
		return $this->handle(DocumentSession::TYPE_EDIT);
	}

	private function handle(int $type): HandlerOperationResult
	{
		$createInternalSessionCommand = $this->sessionCommandFactory->createCreateInternalSessionCommand($type, true);

		try
		{
			$sessionCreationResult = $createInternalSessionCommand->run();
		}
		catch (CommandException|CommandValidationException $e)
		{
			$this->exceptionHandler->writeToLog($e);

			return HandlerOperationResult::createFromException($e);
		}

		if (!$sessionCreationResult->isSuccess())
		{
			return HandlerOperationResult::createError($sessionCreationResult->getErrorCollection());
		}

		/** @var DocumentSession $documentSession */
		$documentSession = $sessionCreationResult->getDocumentSession();

		// The editor component decides the same way and throws, but includeComponent() swallows that on
		// an ordinary request: without this the visitor of a unified link is answered with a blank page.
		if (ServiceProfileResolver::createFromOptions()->resolveForEditor($documentSession->getObject()) === null)
		{
			PilotLog::error(
				'Board service profile is not resolved: {entryPoint}, object {objectId}',
				[
					'entryPoint' => 'unifiedLink',
					'objectId' => (int)$documentSession->getObjectId(),
				],
			);

			return HandlerOperationResult::createError(
				new ErrorCollection([new Error('Board service profile is not resolved')]),
			);
		}

		if ($documentSession->canUserRead($this->getCurrentUser()))
		{
			$this->trackObject();
		}

		return $this->showEditor($documentSession);
	}

	private function trackObject(): void
	{
		$userId = $this->getCurrentUser()->getId();
		if ($this->documentSource->getAttachedObject() !== null)
		{
			$this->trackedObjectManager->pushAttachedObject($userId, $this->documentSource->getAttachedObject(), true);
		}
		else
		{
			$this->trackedObjectManager->pushFile($userId, $this->file, true);
		}
	}

	private function showEditor(DocumentSession $documentSession): HandlerOperationResult
	{
		$downloadUrl = (new DocumentDownloadUrlService())->build($documentSession, applyLegacyHttpDowngrade: true);

		$avatarUri = (new Uri($documentSession->getUser()->getAvatarSrc()))->toAbsolute();

		$file = $documentSession->getFile();
		$showTemplatesModal = isset($file) && BoardService::shouldShowTemplateModal($file);

		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.flipchart.editor',
				'POPUP_COMPONENT_PARAMS' => [
					'DOCUMENT_SESSION' => $documentSession,
					'DOCUMENT_URL' => $downloadUrl,
					'STORAGE_ID' => $this->file->getStorageId(),
					'USER_ID' => (string)$documentSession->getUserId(),
					'USERNAME' => $documentSession->getUser()->getFormattedName(),
					'AVATAR_URL' => $avatarUri,
					'SHOW_TEMPLATES_MODAL' => $showTemplatesModal,
					'EXTERNAL_LINK_MODE' => false,
					// The chain has answered for the uniqueCode by now, so the page of the board is the
					// proof of the link the description of its header is told about.
					'UNIFIED_LINK_MODE' => true,
					'FILE_UNIQUE_CODE' => $documentSession->getFile()?->getUniqueCode(),
					'ORIGINAL_FILE' => $this->file,
				],
				'PLAIN_VIEW' => true,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => false,
			],
		);

		return HandlerOperationResult::createSuccess($content);
	}

	private function getCurrentUser(): CurrentUser
	{
		return CurrentUser::get();
	}
}
