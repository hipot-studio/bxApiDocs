<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;
use Bitrix\Disk\Analytics\Enum\OpenTypeEnum;
use Bitrix\Disk\Document\DocumentSessionResult;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Document\Vibeoffice\SavedContentSynchronizer;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Internal\Service\SessionCommandFactory;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Engine\CurrentUser;

class VibeofficeHtmlRenderableHandler implements
	HtmlRenderableHandler,
	AutomaticEditModeCapable,
	OpeningNoticeCodeAware,
	EditTransitionAware
{
	private TrackedObjectManager $trackedObjectManager;
	private SessionCommandFactory $sessionCommandFactory;
	private ExceptionHandler $exceptionHandler;
	private SavedContentSynchronizer $savedContentSynchronizer;
	private DocumentSessionManager $documentSessionManager;
	private ?string $openingNoticeCode = null;
	private ?string $editTransitionUrl = null;

	public function __construct(
		private readonly File $file,
		private readonly DocumentSource $documentSource,
		private readonly array $analytics = [],
		?SavedContentSynchronizer $savedContentSynchronizer = null,
		?DocumentSessionManager $documentSessionManager = null,
	) {
		$this->trackedObjectManager = Driver::getInstance()->getTrackedObjectManager();

		$this->documentSessionManager = $documentSessionManager ?? new DocumentSessionManager();
		$this->sessionCommandFactory = new SessionCommandFactory($documentSource, $this->documentSessionManager);
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
		$this->savedContentSynchronizer = $savedContentSynchronizer ?? new SavedContentSynchronizer();
	}

	public function view(): HandlerOperationResult
	{
		return $this->handle(DocumentSession::TYPE_VIEW);
	}

	public function edit(): HandlerOperationResult
	{
		return $this->handle(DocumentSession::TYPE_EDIT);
	}

	public function setOpeningNoticeCode(?string $code): void
	{
		$this->openingNoticeCode = in_array($code, OpeningNoticeCodeAware::ALLOWED_CODES, true) ? $code : null;
	}

	public function setEditTransitionUrl(?string $url): void
	{
		$this->editTransitionUrl = ($url === null || $url === '') ? null : $url;
	}

	/**
	 * The pair the address of editing itself asks for: the type the file is stored as and the extension
	 * the engine can edit. The factory tells apart the type "document" and the type "pdf" only to pick
	 * this handler for both, while the address of editing accepts the first of them alone - and the
	 * stored type is not recomputed when the file is renamed. The extension is asked of the source that
	 * is opened, the way the factory asks it: a revision keeps the name it was saved under.
	 */
	public function supportsAutomaticEditMode(): bool
	{
		if ((int)$this->file->getTypeFile() !== TypeFile::DOCUMENT)
		{
			return false;
		}

		$revision = $this->documentSource->getVersion()
			?? $this->documentSource->getAttachedObject()?->getVersion()
		;

		return VibeofficeHandler::isEditable($revision?->getExtension() ?? $this->file->getExtension());
	}

	private function handle(int $type): HandlerOperationResult
	{
		if ($type === DocumentSession::TYPE_VIEW && $this->documentSource->getVersion() === null)
		{
			try
			{
				// Unified links create the session here, before the Vibeoffice controller and the
				// editor component get a chance to synchronize a delayed document.saved webhook.
				$this->savedContentSynchronizer->synchronize($this->file);
			}
			catch (\Throwable)
			{
				// A delayed save must not turn a readable unified link into an error page.
			}
		}

		$createInternalSessionCommand = $this->sessionCommandFactory->createCreateInternalSessionCommand($type);

		try
		{
			/** @var DocumentSessionResult $sessionCreationResult */
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

		if ($documentSession->canUserRead($this->getCurrentUser()))
		{
			$this->trackObject();
		}

		$actualType = $documentSession->getType();
		if (in_array($actualType, [DocumentSession::TYPE_VIEW, DocumentSession::TYPE_EDIT], true))
		{
			Application::getInstance()->addBackgroundJob(function () use ($actualType, $documentSession) {
				$analyticsDocsOpenEvent = (new AnalyticsEvent(
					event: 'open',
					tool: 'docs',
					category: 'docs',
				))
					->setSubSection($this->file->isNewByCreateTime() ? 'new_element' : 'old_element')
					->setP4("fileId_{$this->file->getId()}")
				;

				$element = $this->analytics['c_element'] ?? 'docs_attach';
				$openType = OpenTypeEnum::getByDocumentSessionType($actualType);

				if ($openType instanceof OpenTypeEnum)
				{
					$analyticsDocsOpenEvent->setType($openType->value);

					if ($openType === OpenTypeEnum::Edit && !$this->getCurrentUser()->getId())
					{
						$element = 'view_mode';
					}
				}

				$analyticsDocsOpenEvent->setElement($element);

				$analyticsDocType = DocumentTypeEnum::getByExtension($this->file->getExtension());

				if ($analyticsDocType instanceof DocumentTypeEnum)
				{
					$analyticsDocsOpenEvent->setP3($analyticsDocType->value);
				}

				$analyticsDocsOpenEvent->send();
			});
		}

		return $this->showEditor($documentSession, $this->documentSessionManager->getRestrictionOutcome());
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

	protected function showEditor(
		DocumentSession $documentSession,
		?string $restrictionOutcome,
	): HandlerOperationResult
	{
		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.file.editor-vibeoffice',
				'POPUP_COMPONENT_TEMPLATE_NAME' => '',
				'POPUP_COMPONENT_PARAMS' => $this->buildShellParams($documentSession, $restrictionOutcome),
				'PLAIN_VIEW' => true,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => false,
			],
		);

		return HandlerOperationResult::createSuccess($content);
	}

	/** What the render told this opening is read once: a second page of its own gets a clean handler. */
	protected function buildShellParams(
		DocumentSession $documentSession,
		?string $restrictionOutcome,
	): array
	{
		$openingNoticeCode = $this->openingNoticeCode;
		$this->openingNoticeCode = null;

		$editTransitionUrl = $this->editTransitionUrl;
		$this->editTransitionUrl = null;

		return [
			'DOCUMENT_SESSION' => $documentSession,
			'TRUSTED_OPENING' => [
				'channel' => 'unified',
				'file' => $this->file,
				'objectId' => (int)$this->file->getId(),
				'attachedObjectId' => (int)$this->documentSource->getAttachedObject()?->getId(),
				'versionId' => (int)$documentSession->getVersionId(),
				'type' => $documentSession->getType(),
				'editorMode' => \Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL,
			],
			'RESTRICTION_OUTCOME' => $restrictionOutcome,
			'OPENING_NOTICE_CODE' => $openingNoticeCode,
			'LINK_TO_EDIT' => $editTransitionUrl,
			'UNIFIED_LINK_MODE' => true,
			'FILE_UNIQUE_CODE' => $documentSession->getFile()?->getUniqueCode(),
		];
	}

	private function getCurrentUser(): CurrentUser
	{
		return CurrentUser::get();
	}
}
