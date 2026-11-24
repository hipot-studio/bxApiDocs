<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;
use Bitrix\Disk\Controller\ShortLink;
use Bitrix\Disk\Analytics\Enum\OpenTypeEnum;
use Bitrix\Disk\Document\DocumentSessionResult;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Document\Vibeoffice\SavedContentSynchronizer;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Internal\Service\SessionCommandFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
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
	private ?ShortLinkOpening $shortLinkOpening = null;
	private ?ExternalLink $classicLinkSource = null;
	private bool $usesClassicSharedSource = false;
	private ?int $requestedType = null;

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

	/**
	 * The short opening the editor is shown for: it reaches the shell apart from the access context of the
	 * session, together with the mode the opening was allowed.
	 */
	public function setShortLinkOpening(ShortLinkOpening $shortLinkOpening): void
	{
		$this->shortLinkOpening = $shortLinkOpening;
	}

	public function getShortLinkOpening(): ?ShortLinkOpening
	{
		return $this->shortLinkOpening;
	}

	public function setClassicLinkSource(?ExternalLink $externalLink): void
	{
		$this->classicLinkSource = $externalLink;
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
		if (!$this->documentSource->hasValidFile())
		{
			return HandlerOperationResult::createError(new \Bitrix\Main\ErrorCollection([
				new \Bitrix\Main\Error('File not found in source.'),
			]));
		}

		$this->requestedType = $type;
		$this->usesClassicSharedSource = false;
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

		$this->documentSessionManager->setLegacyUnifiedLinkAccessService(
			$this->shortLinkOpening === null
				? \Bitrix\Main\DI\ServiceLocator::getInstance()->get(
					\Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService::class,
				) : null,
		);
		$this->documentSessionManager->setShortLinkOpening(
			$this->shortLinkOpening,
			$type === DocumentSession::TYPE_EDIT ? 'edit' : 'view',
		);
		$this->documentSessionManager
			->setUserId((int)CurrentUser::get()->getId())
			->setSessionType($type)
			->setSessionContext(\Bitrix\Disk\Document\Models\DocumentSessionContext::tryBuildByAttachedObject(
				$this->documentSource->getAttachedObject(), $this->documentSource->getFileFromSource(),
			))
			->setFile($this->documentSource->getFile())
			->setVersion($this->documentSource->getVersion())
			->setAttachedObject($this->documentSource->getAttachedObject())
		;
		if ($this->documentSessionManager->requiresSharedAdmission())
		{
			if ($this->classicLinkSource !== null && $this->shortLinkOpening === null)
			{
				$context = \Bitrix\Disk\Document\Models\DocumentSessionContext::tryBuildByAttachedObject(
					$this->documentSource->getAttachedObject(), $this->documentSource->getFileFromSource(),
				);
				$this->documentSessionManager->setSessionContext(new \Bitrix\Disk\Document\Models\DocumentSessionContext(
					$context->getObjectId(), $context->getAttachedObjectId(), (int)$this->classicLinkSource->getId(),
				));
				$this->usesClassicSharedSource = true;
			}
			$admission = $this->documentSessionManager->admitSharedEdit(
				new \Bitrix\Disk\Document\Vibeoffice\OpeningBudget(
					\Bitrix\Main\DI\ServiceLocator::getInstance()
						->get('disk.vibeofficeConfiguration')->getOpenBudgetSeconds() * 1000,
				),
			);
			$sessionCreationResult = new DocumentSessionResult();
			if ($admission->isSuccess())
			{
				$sessionCreationResult->setDocumentSession($admission->getData()['session']);
			}
			else
			{
				$sessionCreationResult->addErrors($admission->getErrors());
			}
		}
		else
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

		$params = [
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
			'SHORT_LINK_OPENING' => $this->shortLinkOpening,
			'SHORT_LINK_MODE' => $this->requestedType === DocumentSession::TYPE_EDIT
				? ShortLink::MODE_EDIT
				: ShortLink::MODE_VIEW
			,
		];
		if ($this->usesClassicSharedSource)
		{
			$params['CLASSIC_EXTERNAL_LINK'] = $this->classicLinkSource;
			$params['TRUSTED_OPENING']['unifiedOptions']['additionalQueryParams'] = [
				ExternalLinkContext::getParameterName() => ExternalLinkContext::create(
					$this->classicLinkSource, $this->file,
					(int)$this->documentSource->getAttachedObject()?->getId(), (int)$documentSession->getVersionId(),
				),
			];
		}

		return $params;
	}

	private function getCurrentUser(): CurrentUser
	{
		return CurrentUser::get();
	}
}
