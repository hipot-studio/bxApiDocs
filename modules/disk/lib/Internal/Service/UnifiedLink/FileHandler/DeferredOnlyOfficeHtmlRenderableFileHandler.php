<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Internal\Service\UnifiedLink\Render\DeferredDocumentLoaderRenderer;

class DeferredOnlyOfficeHtmlRenderableFileHandler implements HtmlRenderableHandler
{
	public function view(): HandlerOperationResult
	{
		return $this->render();
	}

	public function edit(): HandlerOperationResult
	{
		return $this->render();
	}

	private function render(): HandlerOperationResult
	{
		$renderer = new DeferredDocumentLoaderRenderer();

		return HandlerOperationResult::createSuccess(
			value: $renderer->render(),
			headers: $renderer->getHeaders(),
		);
	}
}
