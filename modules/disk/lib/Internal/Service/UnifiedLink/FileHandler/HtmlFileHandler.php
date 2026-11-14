<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\HtmlEditorPageService;
use Bitrix\Disk\Internal\Service\HtmlViewerPageService;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\SystemException;

/**
 * Opens an html file by its unified link: a trusted portal page with the document in a sandboxed
 * iframe, rendered through the same shell as the office editors.
 */
class HtmlFileHandler implements HtmlRenderableHandler
{
	private HtmlViewerPageService $pageService;
	private HtmlEditorPageService $editorPageService;
	private ExceptionHandler $exceptionHandler;

	/**
	 * The access level is the answer the caller was already given, handed over so the page is not made to
	 * ask the chain behind it again; a caller holding none leaves the page to ask for itself.
	 */
	public function __construct(
		private readonly DocumentSource $documentSource,
		private readonly ?UnifiedLinkAccessLevel $accessLevel = null,
	)
	{
		$this->pageService = ServiceLocator::getInstance()->get(HtmlViewerPageService::class);
		$this->editorPageService = ServiceLocator::getInstance()->get(HtmlEditorPageService::class);
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	public function view(): HandlerOperationResult
	{
		$content = $this->pageService->renderPage($this->buildParams());
		if ($content === '')
		{
			// A blank page would be served with a 200: the renderer failing is a server error instead.
			return $this->createErrorResult('empty render of html viewer page');
		}

		return HandlerOperationResult::createSuccess($content);
	}

	/**
	 * A portal with the editor disabled keeps the read-only behavior of its html links.
	 */
	public function edit(): HandlerOperationResult
	{
		if (!Configuration::isEnabledHtmlEditor())
		{
			return $this->view();
		}

		$attachedObject = $this->documentSource->getAttachedObject();
		$result = $attachedObject !== null
			? $this->editorPageService->buildParamsByAttachedObject($attachedObject)
			: $this->editorPageService->buildParamsByFile($this->documentSource->getFile())
		;
		if (!$result->isSuccess())
		{
			return HandlerOperationResult::createError($result->getErrorCollection());
		}

		$content = $this->editorPageService->renderPage($result->getData());
		if ($content === '')
		{
			return $this->createErrorResult('empty render of html editor page');
		}

		return HandlerOperationResult::createSuccess($content);
	}

	/**
	 * A DocumentSource is built from exactly one of the three, so the file answers once the other two
	 * do not.
	 */
	private function buildParams(): array
	{
		$attachedObject = $this->documentSource->getAttachedObject();
		if ($attachedObject !== null)
		{
			return $this->pageService->buildParamsByAttachedObject($attachedObject, $this->accessLevel);
		}

		$version = $this->documentSource->getVersion();
		if ($version !== null)
		{
			return $this->pageService->buildParamsByVersion($version, $this->accessLevel);
		}

		return $this->pageService->buildParamsByFile($this->documentSource->getFile(), $this->accessLevel);
	}

	/**
	 * The reader gets the generic server error page, so the reason is only ever visible in the log —
	 * the same place the office handlers next door report their failures to.
	 */
	private function createErrorResult(string $message): HandlerOperationResult
	{
		$this->exceptionHandler->writeToLog(new SystemException($message));

		return HandlerOperationResult::createError(new ErrorCollection([new Error($message)]));
	}
}
