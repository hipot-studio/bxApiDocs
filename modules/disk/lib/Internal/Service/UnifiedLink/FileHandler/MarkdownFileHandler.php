<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\MarkdownViewerPageService;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\SystemException;

/**
 * Opens a Markdown file of Disk by its unified link: a portal page of its own that asks for the rendered
 * document by itself. The page of a revision shows that revision, the page of the file the revision it was
 * built on.
 */
class MarkdownFileHandler implements HtmlRenderableHandler
{
	private MarkdownViewerPageService $pageService;
	private ExceptionHandler $exceptionHandler;

	/**
	 * The access level is the answer the caller was already given, handed over so the page is not made to
	 * ask the chain behind it again; a caller holding none leaves the page to ask for itself.
	 */
	public function __construct(
		private readonly File $file,
		private readonly ?Version $version = null,
		private readonly ?UnifiedLinkAccessLevel $accessLevel = null,
	)
	{
		$this->pageService = ServiceLocator::getInstance()->get(MarkdownViewerPageService::class);
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	public function view(): HandlerOperationResult
	{
		$params = $this->version !== null
			? $this->pageService->buildParamsByVersion($this->version, $this->accessLevel)
			: $this->pageService->buildParamsByFile($this->file, $this->accessLevel)
		;

		$content = $this->pageService->renderPage($params);
		if ($content === '')
		{
			// A blank page would be served with a 200: the renderer failing is a server error instead.
			return $this->createErrorResult('empty render of markdown viewer page');
		}

		return HandlerOperationResult::createSuccess($content);
	}

	/**
	 * The page has no editing mode, so an edit link opens the same page as a view link.
	 */
	public function edit(): HandlerOperationResult
	{
		return $this->view();
	}

	/**
	 * The reader gets the generic server error page, so the reason is only ever visible in the log, as for
	 * the html page next door.
	 */
	private function createErrorResult(string $message): HandlerOperationResult
	{
		$this->exceptionHandler->writeToLog(new SystemException($message));

		return HandlerOperationResult::createError(new ErrorCollection([new Error($message)]));
	}
}
