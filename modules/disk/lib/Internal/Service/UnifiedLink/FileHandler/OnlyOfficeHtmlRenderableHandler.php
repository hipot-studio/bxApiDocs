<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;
use Bitrix\Disk\Controller\ShortLink;
use Bitrix\Disk\Analytics\Enum\OpenTypeEnum;
use Bitrix\Disk\Document\DocumentSessionResult;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\DocumentSessionManager;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Disk\Internal\Service\SessionCommandFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Engine\CurrentUser;

class OnlyOfficeHtmlRenderableHandler implements HtmlRenderableHandler
{
	private TrackedObjectManager $trackedObjectManager;
	private SessionCommandFactory $sessionCommandFactory;
	private ExceptionHandler $exceptionHandler;
	private ?ShortLinkOpening $shortLinkOpening = null;

	public function __construct(
		private readonly File $file,
		private readonly DocumentSource $documentSource,
		private readonly array $analytics = [],
	) {
		$this->trackedObjectManager = Driver::getInstance()->getTrackedObjectManager();

		$this->sessionCommandFactory = new SessionCommandFactory($documentSource, new DocumentSessionManager());
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	/** @see VibeofficeHtmlRenderableHandler::setShortLinkOpening() */
	public function setShortLinkOpening(ShortLinkOpening $shortLinkOpening): void
	{
		$this->shortLinkOpening = $shortLinkOpening;
	}

	public function getShortLinkOpening(): ?ShortLinkOpening
	{
		return $this->shortLinkOpening;
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

		if (in_array($type, [DocumentSession::TYPE_VIEW, DocumentSession::TYPE_EDIT], true))
		{
			Application::getInstance()->addBackgroundJob(function () use ($type, $documentSession) {
				$analyticsDocsOpenEvent = (new AnalyticsEvent(
					event: 'open',
					tool: 'docs',
					category: 'docs',
				))
					->setSubSection($this->file->isNewByCreateTime() ? 'new_element' : 'old_element')
					->setP4("fileId_{$this->file->getId()}")
				;

				$element = $this->analytics['c_element'] ?? 'docs_attach';
				$openType = OpenTypeEnum::getByDocumentSessionType($type);

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

		return $this->showEditor($documentSession, $type);
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

	private function showEditor(DocumentSession $documentSession, int $type): HandlerOperationResult
	{
		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.file.editor-onlyoffice',
				'POPUP_COMPONENT_TEMPLATE_NAME' => '',
				'POPUP_COMPONENT_PARAMS' => [
					'DOCUMENT_SESSION' => $documentSession,
					'UNIFIED_LINK_MODE' => true,
					'FILE_UNIQUE_CODE' => $documentSession->getFile()?->getUniqueCode(),
					'ANALYTICS' => $this->analytics,
					'SHORT_LINK_OPENING' => $this->shortLinkOpening,
					'SHORT_LINK_MODE' => $type === DocumentSession::TYPE_EDIT
						? ShortLink::MODE_EDIT
						: ShortLink::MODE_VIEW
					,
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
