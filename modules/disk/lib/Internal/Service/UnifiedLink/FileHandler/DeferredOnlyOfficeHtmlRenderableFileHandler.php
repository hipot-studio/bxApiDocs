<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Internal\Service\UnifiedLink\Render\DeferredDocumentLoaderRenderer;

class DeferredOnlyOfficeHtmlRenderableFileHandler implements HtmlRenderableFileHandler
{
	public function view(): FileHandlerOperationResult
	{
		return $this->render();
	}

	public function edit(): FileHandlerOperationResult
	{
		return $this->render();
	}

	private function render(): FileHandlerOperationResult
	{
		$renderer = new DeferredDocumentLoaderRenderer();

		return FileHandlerOperationResult::createSuccess(
			value: $renderer->render(),
			headers: $renderer->getHeaders(),
		);
	}
}
