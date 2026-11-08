<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\BaseObject;

readonly class DefaultHtmlRenderableHandler implements HtmlRenderableHandler
{
	public function __construct(
		private BaseObject $baseObject,
	)
	{
	}

	public function view(): HandlerOperationResult
	{
		return HandlerOperationResult::createSuccess("view {$this->baseObject->getId()}");
	}

	public function edit(): HandlerOperationResult
	{
		return HandlerOperationResult::createSuccess("edit {$this->baseObject->getId()}");
	}
}
