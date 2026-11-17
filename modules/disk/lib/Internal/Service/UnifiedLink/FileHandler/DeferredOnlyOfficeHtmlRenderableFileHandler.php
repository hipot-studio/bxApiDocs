<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\DeferredDocumentLoaderRenderer;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;

class DeferredOnlyOfficeHtmlRenderableFileHandler implements HtmlRenderableHandler
{
	public function __construct(private readonly ?ShortLinkOpening $shortLinkOpening = null)
	{
	}

	public function getShortLinkOpening(): ?ShortLinkOpening
	{
		return $this->shortLinkOpening;
	}

	public function view(): HandlerOperationResult
	{
		return $this->render(UnifiedLinkAccessLevel::Read);
	}

	public function edit(): HandlerOperationResult
	{
		return $this->render(UnifiedLinkAccessLevel::Edit);
	}

	private function render(UnifiedLinkAccessLevel $accessLevel): HandlerOperationResult
	{
		$renderer = new DeferredDocumentLoaderRenderer($this->shortLinkOpening, $accessLevel);

		return HandlerOperationResult::createSuccess(
			value: $renderer->render(),
			headers: $renderer->getHeaders(),
		);
	}
}
