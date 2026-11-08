<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

interface HtmlRenderableHandler
{
	public function view(): HandlerOperationResult;

	public function edit(): HandlerOperationResult;
}
